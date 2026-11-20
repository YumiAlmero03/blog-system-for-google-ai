/** Demo search errors, stale requests, cancellation and independent blocks; no server needed. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../blog-publish.php'), 'utf8');
const start = source.indexOf('      function cancelSlotDemoSearch(');
const end = source.indexOf('      function pasteIntoFaqField(', start);
assert(start > 0 && end > start, 'Use the actual editor search functions');
const requests = [], timers = new Map();
let timerId = 0;
const context = {
  URL, AbortController, window: { location: { origin: 'https://example.test' } },
  setTimeout: (fn) => { timers.set(++timerId, fn); return timerId; },
  clearTimeout: (id) => timers.delete(id),
  escapeHtml: (value) => String(value).replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;'),
  parseJsonResponse: async (response) => response.payload,
  fetch: (url, options) => new Promise((resolve, reject) => requests.push({url, options, resolve, reject})),
};
vm.createContext(context);
vm.runInContext('const slotDemoSearches = new WeakMap();\n' + source.slice(start, end), context);
const block = () => {
  const results = { innerHTML: '' };
  return { results, isConnected: true, querySelector: () => results };
};
const game = (name, id = 101) => ({ apiId: id, name, slug: 'sweet-bonanza', iframeUrl: 'https://slotslaunch.com/iframe/' + id, provider: { name: 'Pragmatic Play' } });
const resolve = (request, slots) => request.resolve({ ok: true, payload: { ok: true, slots } });
(async () => {
  const first = block();
  let search = context.searchSlotDemoGames(first, 'Sweet Bonanza');
  let request = requests.shift();
  assert.equal(new URL(request.url).searchParams.get('search'), 'Sweet Bonanza');
  resolve(request, [game('Sweet "Bonanza"')]);
  await search;
  assert(first.results.innerHTML.includes('Sweet &quot;Bonanza&quot;'));
  assert(first.results.innerHTML.includes('data-demo-id="101"') && first.results.innerHTML.includes('data-demo-slug="sweet-bonanza"'), 'Selection retains game identity');

  search = context.searchSlotDemoGames(first, 'Sweet');
  requests.shift().resolve({ ok: false, payload: { ok: false, error: 'Access denied.' } });
  await search;
  assert(first.results.innerHTML.includes('Access denied.') && !first.results.innerHTML.includes('No games found'), 'HTTP failure is visible');

  search = context.searchSlotDemoGames(first, 'Sweet');
  requests.shift().resolve({ ok: true, payload: { ok: false, error: 'Search unavailable.' } });
  await search;
  assert(first.results.innerHTML.includes('Search unavailable.'), 'API failure is visible even with HTTP 200');
  search = context.searchSlotDemoGames(first, 'Sweet');
  requests.shift().resolve({ ok: true, payload: null });
  await search;
  assert(first.results.innerHTML.includes('Unable to search games.'), 'Malformed payload has a readable error');

  const older = context.searchSlotDemoGames(first, 'sw');
  const oldRequest = requests.shift();
  const newer = context.searchSlotDemoGames(first, 'sweet');
  const newRequest = requests.shift();
  assert(oldRequest.options.signal.aborted, 'New query aborts old fetch');
  resolve(newRequest, [game('Latest game')]); await newer;
  resolve(oldRequest, [game('Stale game')]); await older;
  assert(first.results.innerHTML.includes('Latest game') && !first.results.innerHTML.includes('Stale game'), 'Late response cannot overwrite new results');

  search = context.searchSlotDemoGames(first, 'sweet');
  request = requests.shift();
  context.debounceSlotDemoSearch(first, 's');
  assert(request.options.signal.aborted && first.results.innerHTML === '', 'Clearing/shortening search cancels and clears results');
  resolve(request, [game('Stale game')]); await search;
  assert.equal(first.results.innerHTML, '');

  const second = block();
  context.debounceSlotDemoSearch(first, 'sw');
  context.debounceSlotDemoSearch(first, 'sweet');
  context.debounceSlotDemoSearch(second, 'gates');
  assert.equal(timers.size, 2, 'Each demo has its own debounce timer');
  for (const [id, run] of [...timers]) { timers.delete(id); run(); }
  assert.equal(requests.length, 2);
  const firstRequest = requests.shift(), secondRequest = requests.shift();
  context.cancelSlotDemoSearch(first);
  assert(firstRequest.options.signal.aborted && !secondRequest.options.signal.aborted, 'Closing/selecting one block does not cancel another');
  resolve(firstRequest, [game('Cancelled')]);
  resolve(secondRequest, [game('Gates')]);
  await new Promise(setImmediate);
  assert(!first.results.innerHTML.includes('Cancelled') && second.results.innerHTML.includes('Gates'));

  search = context.searchSlotDemoGames(second, 'missing');
  resolve(requests.shift(), []); await search;
  assert(second.results.innerHTML.includes('No games found.'));
  search = context.searchSlotDemoGames(second, 'offline');
  requests.shift().reject(new Error('Connection interrupted')); await search;
  assert(second.results.innerHTML.includes('Connection interrupted'));
  console.log('PASS: demo results/selection data, visible errors, stale response protection, clearing/cancellation and independent blocks.');
})().catch((error) => { console.error(error); process.exitCode = 1; });
