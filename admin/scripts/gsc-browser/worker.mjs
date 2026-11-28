import { mkdir, open, readFile, realpath, rename, stat, unlink } from 'node:fs/promises';
import path from 'node:path';

export class Stop extends Error {
  constructor(reason) { super(reason); this.reason = reason; }
}

export function publicUrl(value) {
  if (typeof value !== 'string' || value.length > 2048 || /[\\\x00-\x20\x7f]/.test(value)) throw new Error('Invalid URL text.');
  if (/(?:^|\/)\.{1,2}(?:\/|$)|%2e|%2f|%5c/i.test(value)) throw new Error('Unsafe URL path.');
  const url = new URL(value);
  if (url.protocol !== 'https:' || url.username || url.password || url.search || url.hash
      || url.port || !/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/i.test(url.hostname)
      || /(?:^|\.)(?:localhost|local|internal|test|example|invalid)$/i.test(url.hostname)) {
    throw new Error('Use a public HTTPS URL without credentials, query, fragment, or custom port.');
  }
  const decoded = decodeURIComponent(url.pathname);
  if (/[\\\x00-\x20\x7f]/.test(decoded) || decoded.includes('//') || /%[0-9a-f]{2}/i.test(decoded)
      || /(?:^|\/)\./.test(decoded)
      || /^\/(?:admin|api|storage|uploads|chats|scripts|includes|private|promo|promo-code|bonus|login[^/]*|logout[^/]*)(?:\/|$)/i.test(decoded)) {
    throw new Error('Private or unsupported URL path.');
  }
  return url;
}

export function validateQueue(queue, property) {
  if (queue?.version !== 1 || !Array.isArray(queue.urls) || queue.urls.length > 10000) {
    throw new Error('Expected queue JSON {version:1,site:"https://…",urls:[…]} (at most 10000 URLs).');
  }
  const site = publicUrl(queue.site);
  if (site.pathname !== '/') throw new Error('Queue site must be an HTTPS origin.');
  let prefix;
  if (property.startsWith('sc-domain:')) {
    const domain = property.slice(10);
    publicUrl(`https://${domain}/`);
    if (site.hostname !== domain && !site.hostname.endsWith(`.${domain}`)) throw new Error('Site is outside property.');
  } else {
    prefix = publicUrl(property);
    if (!property.endsWith('/') || prefix.origin !== site.origin) throw new Error('Use the exact GSC URL-prefix property, including its trailing slash.');
  }
  return [...new Set(queue.urls)].map(value => {
    const url = publicUrl(value);
    if (url.origin !== site.origin || (prefix && !url.pathname.startsWith(prefix.pathname))) {
      throw new Error('Queue URL is outside the configured site/property.');
    }
    return url.href;
  });
}

export async function privateDirectory(directory, webRoot) {
  if (!path.isAbsolute(directory)) throw new Error('--state-dir must be an absolute path outside the webroot.');
  await mkdir(directory, { recursive: true, mode: 0o700 });
  const actual = await realpath(directory);
  const root = await realpath(webRoot);
  const relative = path.relative(root, actual);
  if (!relative || (!relative.startsWith(`..${path.sep}`) && relative !== '..' && !path.isAbsolute(relative))) {
    throw new Error('Browser state must be outside the public site directory.');
  }
  const info = await stat(actual);
  if ((info.mode & 0o077) !== 0 || (process.getuid && info.uid !== process.getuid())) {
    throw new Error('State directory must belong to this worker user and have mode 0700.');
  }
  return actual;
}

export async function acquireLock(directory) {
  const file = path.join(directory, 'worker.lock');
  let handle;
  try { handle = await open(file, 'wx', 0o600); }
  catch (error) {
    if (error.code === 'EEXIST') throw new Error('Worker/login is already running, or worker.lock needs review after a crash.');
    throw error;
  }
  try { await handle.writeFile(JSON.stringify({ pid: process.pid, started: new Date().toISOString() })); }
  catch (error) { await handle.close(); await unlink(file); throw error; }
  return async () => { await handle.close(); await unlink(file); };
}

export async function loadState(file, property) {
  try {
    const state = JSON.parse(await readFile(file, 'utf8'));
    if (state.version !== 1 || state.property !== property || !state.records
        || typeof state.records !== 'object' || Array.isArray(state.records) || !Array.isArray(state.attempts)
        || state.attempts.some(time => !Number.isFinite(time))
        || Object.values(state.records).some(record => !record || typeof record.status !== 'string')) {
      throw new Error('Invalid ledger; inspect it rather than resetting submission history.');
    }
    return state;
  } catch (error) {
    if (error.code !== 'ENOENT') throw error;
    return { version: 1, property, records: {}, attempts: [], paused: null };
  }
}

export async function saveState(file, state) {
  const temporary = `${file}.${process.pid}.tmp`;
  const handle = await open(temporary, 'w', 0o600);
  try { await handle.writeFile(JSON.stringify(state, null, 2) + '\n'); await handle.sync(); }
  finally { await handle.close(); }
  await rename(temporary, file);
}

// Persist before clicking: an interrupted request may already have reached Google.
// Uncertain requests require review and are never automatically submitted again.
export async function runQueue({ urls, state, limit = 5, inspect, request, persist, now = Date.now, maxInspections = 20, maxDurationMs = 600000 }) {
  const report = { confirmed: 0, alreadyIndexed: 0, skipped: 0 };
  let inspections = 0;
  const began = now();
  let recovered = false;
  for (const record of Object.values(state.records)) {
    if (record.status === 'request_started') { record.status = 'uncertain'; recovered = true; }
  }
  if (recovered) state.paused = { reason: 'review_required', at: now() };
  if (state.paused?.reason === 'quota' && now() >= state.paused.until) state.paused = null;
  if (state.paused) { await persist(); throw new Stop(state.paused.reason); }
  state.attempts = state.attempts.filter(time => Number.isFinite(time) && time > now() - 86400000);
  for (const url of urls) {
    if (state.records[url]) { report.skipped++; continue; }
    if (state.attempts.length >= limit || inspections >= maxInspections || now() - began >= maxDurationMs) break;
    inspections++;
    let started = false;
    try {
      const result = await inspect(url);
      if (['indexed', 'already_requested', 'not_indexable'].includes(result)) {
        state.records[url] = { status: result, at: now() };
        if (result === 'indexed') report.alreadyIndexed++;
        await persist();
        continue;
      }
      if (result !== 'ready') throw new Stop('unexpected_ui');
      const at = now();
      state.records[url] = { status: 'request_started', at };
      state.attempts.push(at);
      await persist();
      started = true;
      if (await request(url) !== 'confirmed') throw new Stop('confirmation_missing');
      state.records[url] = { status: 'confirmed', at: now() };
      await persist();
      report.confirmed++;
    } catch (error) {
      if (started) state.records[url] = { status: 'uncertain', at: now() };
      const reason = error instanceof Stop ? error.reason : 'worker_error';
      state.paused = { reason, at: now(), ...(reason === 'quota' ? { until: now() + 86400000 } : {}) };
      await persist();
      throw new Stop(reason);
    }
  }
  await persist();
  return report;
}

export function screenState(text, pageUrl, challenge = false) {
  if (new URL(pageUrl).hostname === 'accounts.google.com') return 'login_required';
  if (new URL(pageUrl).hostname !== 'search.google.com') return 'unexpected_origin';
  if (challenge || /verify (?:that )?you are (?:a )?human|unusual traffic|could not connect to (?:the )?recaptcha|complete the captcha/i.test(text)) return 'captcha';
  if (/quota exceeded|exceeded (?:your |the )?(?:daily )?quota|daily limit|try (?:submitting .* )?again tomorrow|too many requests/i.test(text)) return 'quota';
  if (/you (?:don't|do not) have access|not a verified owner|permission denied|verify (?:your )?ownership/i.test(text)) return 'property_access';
  if (/sign in to (?:google )?search console/i.test(text)) return 'login_required';
  if (/indexing request rejected|url is not available to google|page cannot be indexed|page can't be indexed/i.test(text)) return 'not_indexable';
  if (/indexing requested/i.test(text)) return 'confirmed';
  if (/url is on google/i.test(text)) return 'indexed';
  if (/url is not on google/i.test(text)) return 'not_indexed';
  return 'loading';
}

export function browserAdapter(page, property, { timeout = 180000 } = {}) {
  const stopReasons = ['login_required', 'unexpected_origin', 'captcha', 'quota', 'property_access'];
  let current = null;
  async function screen() {
    const text = await page.locator('body').innerText({ timeout: 10000 });
    const challenge = await page.locator('iframe[src*="/bframe"]').evaluateAll(frames => frames.some(frame => {
      const box = frame.getBoundingClientRect();
      return box.width > 0 && box.height > 0 && getComputedStyle(frame).visibility !== 'hidden';
    }));
    const state = screenState(text, page.url(), challenge);
    if (stopReasons.includes(state)) throw new Stop(state);
    return state;
  }
  async function waitFor(check, maximum = timeout) {
    const end = Date.now() + maximum;
    do {
      const result = await check(await screen());
      if (result) return result;
      await new Promise(resolve => setTimeout(resolve, 1000));
    } while (Date.now() < end);
    throw new Stop('ui_timeout');
  }
  const input = () => page.getByRole('textbox', { name: /inspect any url/i })
    .or(page.getByRole('combobox', { name: /inspect any url/i }))
    .or(page.locator('input[placeholder*="Inspect any URL"]')).first();
  const button = () => page.getByRole('button', { name: /^request indexing$/i });
  async function verifyUrl(url) {
    // The entered value alone cannot prove that the resulting inspection is for it.
    // Require the URL in the rendered result, separate from the search field.
    if (!(await page.getByText(url, { exact: true }).first().isVisible())) throw new Stop('url_mismatch');
  }
  return {
    async inspect(url) {
      current = null;
      await page.goto(`https://search.google.com/search-console?resource_id=${encodeURIComponent(property)}&hl=en`, { waitUntil: 'domcontentloaded' });
      await waitFor(async () => await input().isVisible() ? 'input' : null, 60000);
      await input().fill(url);
      await input().press('Enter');
      const result = await waitFor(async state => {
        if (!['indexed', 'not_indexed', 'confirmed', 'not_indexable'].includes(state)) return null;
        await verifyUrl(url);
        if (state === 'not_indexed' && !(await button().isVisible())) return null;
        return state;
      });
      current = url;
      return { indexed: 'indexed', confirmed: 'already_requested', not_indexable: 'not_indexable', not_indexed: 'ready' }[result];
    },
    async request(url) {
      if (current !== url) throw new Stop('url_mismatch');
      await verifyUrl(url);
      if (await screen() !== 'not_indexed') throw new Stop('unexpected_ui');
      await button().click({ timeout: 10000 });
      return waitFor(state => {
        if (state === 'not_indexable') throw new Stop('request_rejected');
        return state === 'confirmed' ? 'confirmed' : null;
      });
    },
  };
}
