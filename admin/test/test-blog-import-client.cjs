/**
 * Checks browser import progress, CSRF updates, connection retries, and rate-limit retries.
 *
 * Runs blog-import.js in a Node VM with mocked fetch, timers, and window events; no browser or
 * server is needed. Requires Node with FormData.
 *
 * Run from www: node admin/test/test-blog-import-client.cjs
 * A successful run prints a pass message; failed assertions exit with an error.
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
(async () => {
  const calls = [], notices = [], listeners = new Set();
  let csrf = 'first', batchCalls = 0;
  const context = {
    FormData, Error, Promise, Number, String, Math,
    setTimeout: (fn) => { fn(); },
    window: {
      addEventListener: (event) => listeners.add(event),
      removeEventListener: (event) => listeners.delete(event),
    },
    fetch: async (_url, options) => {
      calls.push(Object.fromEntries(options.body));
      if (!options.body.has('job_id')) return { ok: true, status: 200, result: { ok: true, jobId: 'job', revision: 0, done: false, phase: 'posts', processed: 0, total: 15, csrfToken: 'next' } };
      batchCalls++;
      if (batchCalls === 1) throw new Error('Connection dropped after server committed');
      if (batchCalls === 2) return { ok: true, status: 200, result: { ok: true, jobId: 'job', revision: 1, done: false, phase: 'posts', processed: 10, total: 15 } };
      if (batchCalls === 3) return { ok: false, status: 429, headers: new Headers({ 'Retry-After': '60' }) };
      return { ok: true, status: 200, result: { ok: true, done: true, processed: 15, total: 15, counts: { imported: 15 } } };
    },
  };
  vm.runInNewContext(fs.readFileSync(require('node:path').join(__dirname, '../blog-import.js'), 'utf8'), context);
  const result = await context.window.runBlogImport({
    file: 'fixture', getCsrf: () => csrf, updateCsrf: (token) => { if (token) csrf = token; },
    parseJsonResponse: async (response) => response.result, onProgress: (message) => notices.push(message),
  });
  assert.equal(result.counts.imported, 15);
  assert.equal(calls[1].revision, '0');
  assert.equal(calls[2].revision, '0', 'Network retry keeps revision');
  assert.equal(calls[3].revision, '1');
  assert.equal(calls[4].revision, '1', 'Rate limit retry keeps revision');
  assert.equal(calls[1].csrf_token, 'next');
  assert(notices.some((message) => message.includes('10 / 15')));
  assert.equal(listeners.size, 0, 'Leave-page warning removed after completion');
  console.log('Batch import client tests passed.');
})().catch((error) => { console.error(error); process.exitCode = 1; });
