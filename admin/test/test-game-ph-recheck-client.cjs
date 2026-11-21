/** PH recheck: malformed HTML, preserved cursor/totals, resumable batches and sitemap warnings. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const script = fs.readFileSync(path.join(__dirname, '../plugin/games/recheck-ph.js'), 'utf8');
function fixture(responses, slotId = '') {
  const children = [];
  let click;
  const button = { dataset: { recheckPh: slotId }, textContent: 'Recheck PH Availability', addEventListener: (_event, listener) => { click = listener; } };
  const output = { replaceChildren: () => children.splice(0), append: (node) => children.push(node) };
  const calls = [];
  vm.runInNewContext(script, {
    document: {
      getElementById: (id) => id === 'ph-recheck-status' ? output : { dataset: { csrf: 'test-token' } },
      querySelectorAll: () => [button], createElement: () => ({textContent: ''}),
    },
    window: { location: { href: 'https://example.test/admin/plugin/games/' } },
    URLSearchParams,
    setTimeout: (resolve) => resolve(),
    fetch: async (_url, options) => {
      calls.push(Object.fromEntries(options.body));
      const next = responses.shift();
      assert(next, 'Unexpected extra request');
      if (next instanceof Error) throw next;
      return { ok: next.status === undefined || next.status === 200, status: next.status || 200, text: async () => typeof next.body === 'string' ? next.body : JSON.stringify(next.body) };
    },
  });
  return { click: () => click(), button, calls, message: () => children[0].textContent };
}
const batch = (extra = {}) => ({ body: { ok: true, processed: 100, checked: 100, changed: 3, available: 100, restricted: 0, unpublished: 0, unknown: 0, total: 500, max_id: 900, next_after_id: 100, done: false, ...extra } });
(async () => {
  const responses = [
    batch({ processed: 400, checked: 400, changed: 0, available: 400, next_after_id: 450 }),
    { body: '<br /><b>Warning</b>: fopen: Permission denied' },
    batch({ next_after_id: 900, done: true }),
  ];
  const run = fixture(responses);
  await run.click();
  assert(run.message().includes('invalid availability response') && !run.message().includes('Unexpected token'), 'Readable error replaces raw JSON exception');
  assert(run.message().includes('400 games processed; 0 updates already saved.'));
  assert.equal(run.button.disabled, false);
  assert.equal(run.button.textContent, 'Resume PH Recheck');
  await run.click();
  assert.equal(run.calls[2].after_id, '450', 'Retry repeats only the unconfirmed batch');
  assert.equal(run.calls[2].max_id, '900', 'Retry keeps the same catalogue snapshot');
  assert(run.message().includes('500 games checked; 3 updated.'));
  assert.equal(run.button.textContent, 'Recheck PH Availability');

  const warning = fixture([batch({ warning: 'Sitemap refresh pending.' }), batch({ next_after_id: 900, done: true })]);
  await warning.click();
  assert.equal(warning.calls.length, 2, 'Sitemap warning does not halt committed availability batches');
  assert(warning.message().includes('Recheck complete.') && warning.message().includes('Sitemap refresh pending.'));

  const auth = fixture([{ status: 403, body: { ok: false, error: 'Access denied.' } }]);
  await auth.click();
  assert(auth.message().includes('Access denied.') && !auth.button.disabled);
  const single = fixture([batch({ processed: 1, checked: 1, done: true })], '42');
  await single.click();
  assert.equal(single.calls.length, 1);
  assert.equal(single.calls[0].slot_id, '42');
  assert.equal(single.calls[0].csrf_token, 'test-token');
  console.log('PASS: PH recheck HTML errors, saved progress/resume, snapshot cursor, warnings, auth and individual checks.');
})().catch((error) => { console.error(error); process.exitCode = 1; });
