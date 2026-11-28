import { parseArgs } from 'node:util';
import { createHash } from 'node:crypto';
import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { createInterface } from 'node:readline/promises';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { acquireLock, browserAdapter, loadState, privateDirectory, runQueue, saveState, Stop, validateQueue } from './worker.mjs';

const help = `GSC browser worker (Node 22+, separate Google browser login required)
  node cli.mjs login  --state-dir /private/path --property https://your-site.com/
  node cli.mjs run    --state-dir /private/path --property https://your-site.com/ --queue /private/queue.json
  node cli.mjs run    --state-dir /private/path --property https://your-site.com/ --site https://your-site.com --db /site/admin/storage/blogs.sqlite --php /usr/bin/php
  node cli.mjs status --state-dir /private/path --property https://your-site.com/
  node cli.mjs resume --state-dir /private/path --property https://your-site.com/
Options: --limit 5 (local maximum clicks per rolling 24 hours; Google's quota differs)
         --headed (requires an interactive server desktop); --channel chrome|chromium
         --dry-run (validate/list the queue without opening a browser)
Confirmed, already indexed, rejected and uncertain URLs are not automatically resubmitted.
Login and CAPTCHA need a person. No API keys, password arguments or CAPTCHA bypasses.
`;

async function main() {
  const { values, positionals } = parseArgs({ allowPositionals: true, options: {
    'state-dir': { type: 'string' }, property: { type: 'string' }, queue: { type: 'string' },
    limit: { type: 'string', default: '5' }, channel: { type: 'string', default: 'chromium' },
    headed: { type: 'boolean', default: false }, 'dry-run': { type: 'boolean', default: false },
    db: { type: 'string' }, site: { type: 'string' }, php: { type: 'string', default: '/usr/bin/php' },
    type: { type: 'string', default: 'all' },
    help: { type: 'boolean', default: false },
  } });
  if (values.help) { console.log(help); return; }
  const command = positionals[0];
  if (positionals.length !== 1 || !['login', 'run', 'status', 'resume'].includes(command)
      || !values['state-dir'] || !values.property) throw new Error(help);
  if (!path.isAbsolute(values['state-dir'])) throw new Error('--state-dir must be an absolute private path.');
  const limit = Number(values.limit);
  if (!/^\d+$/.test(values.limit) || limit < 1 || limit > 20) throw new Error('--limit must be between 1 and 20 (local safety budget).');
  if (!['chrome', 'chromium'].includes(values.channel)) throw new Error('--channel must be chrome or chromium.');
  // Even login/status need a valid property; no queue URL is transmitted by this check.
  const site = values.property.startsWith('sc-domain:') ? `https://${values.property.slice(10)}/` : values.property;
  validateQueue({ version: 1, site: new URL(site).origin, urls: [] }, values.property);
  if (command === 'run' && Boolean(values.queue) === Boolean(values.db)) throw new Error('Use either --queue or --db with --site.');
  const key = createHash('sha256').update(values.property).digest('hex').slice(0, 24);
  async function readUrls(ledgerFile) {
    let raw;
    if (values.db) {
      if (!values.site || !path.isAbsolute(values.php)) throw new Error('--db requires --site and an absolute --php executable path.');
      const args = [fileURLToPath(new URL('../export-gsc-browser-queue.php', import.meta.url)),
        '--db', values.db, '--site', values.site, '--type', values.type, '--limit', '1000', '--ledger', ledgerFile];
      const { stdout, stderr } = await promisify(execFile)(values.php, args, { timeout: 60000, maxBuffer: 8 * 1024 * 1024 });
      if (stderr) console.error(stderr.trim());
      raw = stdout;
    } else { raw = await readFile(values.queue, 'utf8'); }
    return validateQueue(JSON.parse(raw), values.property);
  }
  if (values['dry-run']) {
    if (command !== 'run') throw new Error('--dry-run applies only to run.');
    const urls = await readUrls(path.join(values['state-dir'], `ledger-${key}.json`));
    console.log(JSON.stringify({ property: values.property, urls, limit, browserOpened: false }, null, 2));
    return;
  }
  const webRoot = fileURLToPath(new URL('../../../', import.meta.url));
  const directory = await privateDirectory(values['state-dir'], webRoot);
  const release = await acquireLock(directory);
  let context;
  try {
    const ledgerFile = path.join(directory, `ledger-${key}.json`);
    const state = await loadState(ledgerFile, values.property);
    const persist = () => saveState(ledgerFile, state);
    if (command === 'status') { console.log(JSON.stringify(state, null, 2)); return; }
    if (command === 'resume') {
      for (const record of Object.values(state.records)) {
        if (record.status === 'request_started') record.status = 'uncertain';
      }
      state.paused = null;
      await persist();
      console.log('Pause cleared. Uncertain and previously processed URLs remain excluded.');
      return;
    }
    if (command === 'run' && Object.values(state.records).some(record => record.status === 'request_started')) {
      for (const record of Object.values(state.records)) {
        if (record.status === 'request_started') record.status = 'uncertain';
      }
      state.paused = { reason: 'review_required', at: Date.now() };
      await persist();
    }
    // Refuse to launch on paused runs, before opening the authenticated profile.
    if (command === 'run' && state.paused && !(state.paused.reason === 'quota' && Date.now() >= state.paused.until)) {
      throw new Stop(state.paused.reason);
    }
    const urls = command === 'run' ? await readUrls(ledgerFile) : [];
    if (command === 'run' && !urls.length) { console.log('No eligible URLs in queue.'); return; }
    const { chromium } = await import('playwright');
    context = await chromium.launchPersistentContext(path.join(directory, 'profile'), {
      headless: command === 'login' ? false : !values.headed,
      channel: values.channel, locale: 'en-US', acceptDownloads: false,
      chromiumSandbox: true, timeout: 60000,
    });
    context.setDefaultTimeout(10000);
    const page = context.pages()[0] || await context.newPage();
    if (command === 'login') {
      if (!process.stdin.isTTY) throw new Error('Run login in an interactive terminal with a secured desktop session.');
      await page.goto(`https://search.google.com/search-console?resource_id=${encodeURIComponent(values.property)}&hl=en`);
      console.log('Sign in yourself in the SERVER browser. Keep this profile private. Confirm the property opens.');
      const terminal = createInterface({ input: process.stdin, output: process.stdout });
      try { await terminal.question('Press Enter after signing in, or Ctrl+C to cancel. '); }
      finally { terminal.close(); }
      if (new URL(page.url()).hostname !== 'search.google.com') throw new Error('GSC is not open; login is incomplete.');
      const field = page.getByRole('textbox', { name: /inspect any url/i })
        .or(page.getByRole('combobox', { name: /inspect any url/i }))
        .or(page.locator('input[placeholder*="Inspect any URL"]')).first();
      if (!(await field.isVisible())) throw new Error('Could not verify property access. Keep the worker paused.');
      console.log('GSC browser login verified. Use resume after resolving a recorded pause.');
    } else {
      const adapter = browserAdapter(page, values.property);
      const report = await runQueue({ urls, state, limit, ...adapter, persist });
      console.log(JSON.stringify({ ...report, localRequestsLast24h: state.attempts.length, ledger: ledgerFile }));
    }
  } finally {
    try { if (context) await context.close(); }
    finally { await release(); }
  }
}

main().catch(error => {
  // Do not dump browser internals, cookies, or authentication details into cron logs.
  console.error(error instanceof Stop
    ? `GSC worker paused: ${error.reason}. Review status; resolve in a headed server session, then use resume.`
    : `GSC worker stopped: ${error.message}`);
  process.exitCode = 1;
});
