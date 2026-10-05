import { renderOdds, previewLayout } from './render.js';

const sport = document.getElementById('sport');
const status = document.getElementById('status');
const events = document.getElementById('events');
const refresh = document.getElementById('refresh');
let generation = 0;
let activeController;
let savedLayout;
let draftLayout;
let currentEvents = [];
function message(text, error = false) { status.textContent = text; status.dataset.error = String(error); }
function date(value) { const time = new Date(value); return Number.isNaN(time.getTime()) ? 'Time unavailable' : time.toLocaleString(); }
async function request(action, selected, signal) {
  const query = new URLSearchParams({ action });
  query.set('iframe', new URLSearchParams(location.search).get('iframe') || '1');
  if (selected) query.set('sport', selected);
  const response = await fetch('/api/odds-api.php?' + query, { signal, cache: 'no-store' });
  const data = await response.json();
  if (!response.ok || !data.ok) throw new Error(data.error || 'Odds are unavailable.');
  return data;
}
function render(data = currentEvents) {
  currentEvents = data;
  if (!savedLayout) return;
  const layout = previewLayout(savedLayout, draftLayout) || savedLayout;
  document.getElementById('iframe-title').textContent = layout.title;
  document.title = layout.title;
  renderOdds(events, data, layout);
}
window.addEventListener('message', event => {
  if (window.parent === window || event.source !== window.parent || event.origin !== location.origin || event.data?.type !== 'odds-layout-preview') return;
  draftLayout = event.data.layout;
  render();
});
async function load(reloadSports = false) {
  const current = ++generation;
  activeController?.abort(); activeController = new AbortController();
  const controller = activeController;
  const timeout = setTimeout(() => controller.abort(), 35000);
  refresh.disabled = true; currentEvents = []; events.replaceChildren(); message('Loading odds…');
  try {
    if (reloadSports || sport.disabled) {
      const list = await request('sports', '', controller.signal);
      if (current !== generation) return;
      savedLayout = list.layout; render();
      const previous = (!sport.disabled && sport.value) || new URLSearchParams(location.search).get('sport');
      sport.replaceChildren();
      if (!list.enabled) { sport.disabled = true; sport.append(new Option('Unavailable', '')); message('Sports odds are currently turned off.'); return; }
      for (const item of list.data) sport.append(new Option(`${item.group} · ${item.title} (${item.key})`, item.key));
      sport.disabled = !list.data.length;
      if (sport.disabled) { message('No sports are currently available.'); return; }
      if (list.data.some(item => item.key === previous)) sport.value = previous;
    }
    const result = await request('odds', sport.value, controller.signal);
    if (current !== generation) return;
    savedLayout = result.layout; render();
    if (!result.enabled) { sport.disabled = true; message('Sports odds are currently turned off.'); return; }
    render(result.data);
    message((result.data.length ? `${result.data.length} events` : 'No odds are currently available for this sport and these settings') + ` · Updated ${date(result.updatedAt)}`);
  } catch (error) {
    if (current === generation) message(controller.signal.aborted ? 'The request timed out. Please refresh.' : error.message, true);
  } finally { clearTimeout(timeout); if (current === generation) refresh.disabled = false; }
}
sport.addEventListener('change', () => load());
refresh.addEventListener('click', () => load(true));
setInterval(() => { if (!document.hidden && !refresh.disabled) load(true); }, 60000);
document.addEventListener('visibilitychange', () => { if (!document.hidden) load(true); });
load(true);
