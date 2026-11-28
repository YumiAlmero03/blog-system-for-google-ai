import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, mkdir, readFile, realpath, rm, stat, writeFile } from 'node:fs/promises';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { fileURLToPath } from 'node:url';
import os from 'node:os';
import path from 'node:path';
import { acquireLock, browserAdapter, loadState, privateDirectory, runQueue, saveState, screenState, Stop, validateQueue } from './worker.mjs';

const property = 'https://owned-site.com/';
const urls = ['https://owned-site.com/blog/first/', 'https://owned-site.com/blog/second/'];
const fresh = () => ({ version: 1, property, records: {}, attempts: [], paused: null });
const noop = async () => {};

test('only confirmed UI requests are saved; next cron does not repeat them', async () => {
  const state = fresh();
  let clicks = 0;
  const report = await runQueue({ urls, state, inspect: async () => 'ready', request: async url => {
    assert.equal(state.records[url].status, 'request_started');
    clicks++;
    return 'confirmed';
  }, persist: noop });
  assert.equal(report.confirmed, 2);
  await runQueue({ urls, state, inspect: () => assert.fail('Repeated inspection'), request: () => assert.fail('Repeated request'), persist: noop });
  assert.equal(clicks, 2);
});

test('timeout after clicking becomes uncertain and remains excluded after resume', async () => {
  const state = fresh();
  await assert.rejects(runQueue({ urls, state, inspect: async () => 'ready', request: async () => { throw new Stop('ui_timeout'); }, persist: noop }), /ui_timeout/);
  assert.equal(state.records[urls[0]].status, 'uncertain');
  assert.equal(state.records[urls[1]], undefined);
  state.paused = null;
  let submitted;
  await runQueue({ urls, state, inspect: async () => 'ready', request: async url => { submitted = url; return 'confirmed'; }, persist: noop });
  assert.equal(submitted, urls[1]);
});

test('crash checkpoint pauses on restart before touching the browser', async () => {
  const state = fresh();
  state.records[urls[0]] = { status: 'request_started', at: 1000 };
  await assert.rejects(runQueue({ urls, state, inspect: () => assert.fail('Unexpected inspection'), persist: noop }), /review_required/);
  assert.equal(state.records[urls[0]].status, 'uncertain');
});

for (const reason of ['login_required', 'captcha', 'property_access', 'unexpected_ui', 'url_mismatch']) {
  test(`${reason} pauses the queue without claiming success`, async () => {
    const state = fresh();
    await assert.rejects(runQueue({ urls, state, inspect: async () => { throw new Stop(reason); }, request: () => assert.fail('Unexpected click'), persist: noop }), new RegExp(reason));
    assert.equal(state.paused.reason, reason);
    assert.equal(state.attempts.length, 0);
    assert.deepEqual(state.records, {});
  });
}

test('quota stops and persists 24h cooldown, distinct from local click budget', async () => {
  const state = fresh();
  await assert.rejects(runQueue({ urls, state, now: () => 1000, inspect: async () => { throw new Stop('quota'); }, persist: noop }), /quota/);
  assert.equal(state.paused.until, 86401000);
  await assert.rejects(runQueue({ urls, state, now: () => 2000, inspect: () => assert.fail('Quota retry'), persist: noop }), /quota/);
  await runQueue({ urls: [], state, now: () => 86402000, persist: noop });
  assert.equal(state.paused, null);
});

test('local rolling limit survives cron runs and counts reserved attempts', async () => {
  const state = fresh();
  state.attempts = [1000];
  await runQueue({ urls, state, limit: 1, now: () => 2000, inspect: () => assert.fail('Budget exceeded'), persist: noop });
  await runQueue({ urls, state, limit: 1, now: () => 86402000, inspect: async () => 'ready', request: async () => 'confirmed', persist: noop });
  assert.equal(Object.keys(state.records).length, 1);
});

test('indexed and rejected pages never trigger Request indexing', async () => {
  const state = fresh();
  await runQueue({ urls, state, inspect: async url => url === urls[0] ? 'indexed' : 'not_indexable', request: () => assert.fail('Unexpected click'), persist: noop });
  assert.equal(state.attempts.length, 0);
  assert.equal(state.records[urls[0]].status, 'indexed');
  assert.equal(state.records[urls[1]].status, 'not_indexable');
});

test('screen classification does not mistake indexability or HTTP success for submission', () => {
  assert.equal(screenState('URL is available to Google. Page can be indexed', 'https://search.google.com/search-console'), 'loading');
  assert.equal(screenState('Indexing requested', 'https://search.google.com/search-console'), 'confirmed');
  assert.equal(screenState('URL is not on Google', 'https://search.google.com/search-console'), 'not_indexed');
  assert.equal(screenState('URL is on Google', 'https://search.google.com/search-console'), 'indexed');
  assert.equal(screenState('Indexing requested', 'https://accounts.google.com/login'), 'login_required');
  assert.equal(screenState('URL is not on Google', 'https://search.google.com/search-console', true), 'captcha');
  assert.equal(screenState('Quota exceeded', 'https://search.google.com/search-console'), 'quota');
});

test('queue validates exact origin/property and never rewrites localhost', () => {
  const queue = { version: 1, site: property, urls };
  assert.deepEqual(validateQueue(queue, property), urls);
  assert.deepEqual(validateQueue(queue, 'sc-domain:owned-site.com'), urls);
  for (const url of ['http://localhost:8082/blog/x/', 'https://other-site.com/blog/x/', 'https://owned-site.com/admin/',
    'https://owned-site.com/blog/x/?key=secret', 'https://owned-site.com/blog/x/#hash', 'https://user:pass@owned-site.com/',
    'https://owned-site.com/private/', 'https://owned-site.com//admin/',
    'https://owned-site.com/a/%2e%2e/admin/', 'https://owned-site.com/blog/%252e%252e/', 'https://owned-site.com\\@other-site.com/']) {
    assert.throws(() => validateQueue({ ...queue, urls: [url] }, property));
  }
  assert.throws(() => validateQueue(queue, 'https://owned-site.com/other/'));
  assert.throws(() => validateQueue(queue, 'sc-domain:site.com'));
});

test('profile lock prevents simultaneous login/cron and ledger survives reload', async t => {
  const temporary = await mkdtemp(path.join(os.tmpdir(), 'gsc-worker-test-'));
  t.after(() => rm(temporary, { recursive: true, force: true }));
  const release = await acquireLock(temporary);
  await assert.rejects(acquireLock(temporary), /already running/);
  await release();
  const releaseAgain = await acquireLock(temporary);
  await releaseAgain();
  const file = path.join(temporary, 'state.json');
  const state = fresh();
  await saveState(file, state);
  assert.deepEqual(await loadState(file, property), state);
  assert.equal(JSON.parse(await readFile(file, 'utf8')).version, 1);
  await assert.rejects(loadState(file, 'https://other-site.com/'), /Invalid ledger/);
});

test('authenticated browser data cannot be stored beneath public webroot', async t => {
  const temporary = await mkdtemp(path.join(os.tmpdir(), 'gsc-private-test-'));
  t.after(() => rm(temporary, { recursive: true, force: true }));
  const root = path.join(temporary, 'www');
  await mkdir(root, { mode: 0o700 });
  await assert.rejects(privateDirectory(path.join(root, 'sessions'), root), /outside/);
  assert.equal(await privateDirectory(path.join(temporary, 'private'), root), await realpath(path.join(temporary, 'private')));
});

test('adapter refuses to submit when rendered inspected URL differs from queue', async () => {
  const notVisible = { isVisible: async () => false, first() { return this; } };
  const input = { isVisible: async () => true, fill: noop, press: noop, first() { return this; }, or() { return this; } };
  const page = {
    goto: noop, url: () => 'https://search.google.com/search-console',
    locator: selector => selector === 'body' ? { innerText: async () => 'URL is not on Google' } : selector.startsWith('iframe') ? { evaluateAll: async () => false } : input,
    getByRole: (role) => role === 'button' ? { isVisible: async () => true, click: () => assert.fail('Wrong URL clicked') } : input,
    getByText: () => notVisible,
  };
  await assert.rejects(browserAdapter(page, property).inspect(urls[0]), /url_mismatch/);
});

test('adapter performs inspection and real button workflow, recording visible confirmation', async () => {
  let phase = 'home';
  let inspected;
  let clicks = 0;
  const input = {
    isVisible: async () => true,
    fill: async url => { inspected = url; },
    press: async key => { assert.equal(key, 'Enter'); phase = 'inspected'; },
    or() { return this; }, first() { return this; },
  };
  const page = {
    goto: async url => { assert.ok(url.includes('resource_id=https%3A%2F%2Fowned-site.com%2F')); phase = 'home'; },
    url: () => 'https://search.google.com/search-console',
    locator: selector => selector === 'body'
      ? { innerText: async () => phase === 'confirmed' ? 'Indexing requested' : phase === 'inspected' ? 'URL is not on Google' : 'Search Console' }
      : selector.startsWith('iframe') ? { evaluateAll: async () => false } : input,
    getByRole: role => role === 'button'
      ? { isVisible: async () => true, click: async () => { clicks++; phase = 'confirmed'; } } : input,
    getByText: url => ({ first() { return this; }, isVisible: async () => url === inspected }),
  };
  const state = fresh();
  await runQueue({ urls: [urls[0]], state, ...browserAdapter(page, property), persist: noop });
  assert.equal(clicks, 1);
  assert.equal(state.records[urls[0]].status, 'confirmed');
});

test('successful navigation alone cannot confirm an indexing request', async () => {
  const state = fresh();
  await assert.rejects(runQueue({ urls, state, inspect: async () => 'ready', request: async () => 'Page can be indexed', persist: noop }), /confirmation_missing/);
  assert.equal(state.records[urls[0]].status, 'uncertain');
});

test('CLI dry-run validates a queue without opening a browser or creating a login profile', async t => {
  const temporary = await mkdtemp(path.join(os.tmpdir(), 'gsc-cli-test-'));
  t.after(() => rm(temporary, { recursive: true, force: true }));
  const queue = path.join(temporary, 'queue.json');
  const privatePath = path.join(temporary, 'private');
  await writeFile(queue, JSON.stringify({ version: 1, site: property, urls }));
  const args = [fileURLToPath(new URL('./cli.mjs', import.meta.url)), 'run', '--dry-run', '--state-dir', privatePath,
    '--property', property, '--queue', queue, '--limit', '1'];
  const { stdout } = await promisify(execFile)(process.execPath, args);
  const result = JSON.parse(stdout);
  assert.equal(result.browserOpened, false);
  assert.deepEqual(result.urls, urls);
  await assert.rejects(stat(privatePath), error => error.code === 'ENOENT');
  await writeFile(queue, JSON.stringify({ version: 1, site: property, urls: ['http://localhost:8082/blog/x/'] }));
  await assert.rejects(promisify(execFile)(process.execPath, args));
});

test('batch bounds inspections/time even when no requests are needed', async () => {
  const state = fresh();
  await runQueue({ urls, state, maxInspections: 1, inspect: async () => 'indexed', persist: noop });
  assert.equal(Object.keys(state.records).length, 1);
  let time = 1000;
  const timed = fresh();
  await runQueue({ urls, state: timed, maxDurationMs: 1000, now: () => time,
    inspect: async () => { time += 1100; return 'indexed'; }, persist: noop });
  assert.equal(Object.keys(timed.records).length, 1);
});
