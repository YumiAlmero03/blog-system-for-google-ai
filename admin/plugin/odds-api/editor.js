const { layout: initial, catalog } = JSON.parse(document.getElementById('iframe-editor-data').textContent);
const form = document.getElementById('iframe-form');
const frame = document.getElementById('iframe-preview');
const title = document.getElementById('iframe-name');
const status = document.getElementById('iframe-order-status');
const lists = [...document.querySelectorAll('[data-field-group]')];
let blocks = structuredClone(initial.blocks);
let selected = null, dragged = null, dirty = false;
const undo = [], redo = [];
const node = (tag, text, className) => { const el = document.createElement(tag); if (text !== undefined) el.textContent = text; if (className) el.className = className; return el; };
const selectedBlock = () => selected && blocks[selected.group].find(block => block.field === selected.field);
function select(group, field) {
  selected = { group, field };
  for (const item of document.querySelectorAll('.iframe-field')) item.classList.toggle('is-selected', item.dataset.field === field);
  inspector();
  if (matchMedia('(max-width:650px)').matches) document.querySelector('.block-inspector').scrollIntoView({ block: 'nearest', behavior: 'smooth' });
}
function inspector() {
  const block = selectedBlock();
  document.getElementById('block-settings').hidden = !block;
  document.getElementById('no-block-selected').hidden = !!block;
  if (!block) return;
  document.getElementById('selected-source').textContent = block.field;
  document.getElementById('block-label').value = block.label;
  document.getElementById('block-role').value = block.role;
}
function commit(next, announce = '', refreshInspector = true) {
  undo.push(structuredClone(blocks)); if (undo.length > 50) undo.shift(); redo.length = 0;
  blocks = next; dirty = true;
  render(refreshInspector); if (announce) status.textContent = announce;
}
function updatePreview() {
  document.getElementById('iframe-blocks').value = JSON.stringify(blocks);
  frame.contentWindow?.postMessage({ type: 'odds-layout-preview', layout: { title: title.value, blocks } }, location.origin);
  if (initial.id) {
    const embed = document.createElement('iframe');
    embed.src = `/odds-api/?iframe=${initial.id}`; embed.title = title.value; embed.width = '100%'; embed.height = '650'; embed.style.border = '0'; embed.loading = 'lazy';
    document.getElementById('iframe-embed').value = embed.outerHTML;
  }
  const all = Object.values(blocks).flat();
  document.getElementById('save-iframe').disabled = !all.length || all.some(block => !block.label.trim());
  document.getElementById('block-undo').disabled = !undo.length;
  document.getElementById('block-redo').disabled = !redo.length;
}
function render(refreshInspector = true) {
  for (const list of lists) {
    const group = list.dataset.fieldGroup;
    list.replaceChildren();
    blocks[group].forEach((block, index) => {
      const item = node('li', undefined, 'iframe-field block-card'); item.draggable = true; item.tabIndex = 0; item.dataset.field = block.field; item.dataset.role = block.role;
      item.setAttribute('aria-label', `${block.label}, ${block.role} block`);
      item.classList.toggle('is-selected', selected?.field === block.field);
      const controls = node('div', undefined, 'block-actions');
      const handle = node('button', '⠿', 'field-handle'); handle.type = 'button'; handle.setAttribute('aria-label', `Drag ${block.label}`);
      controls.append(handle, node('span', block.role, 'block-type'));
      for (const [move, symbol] of [['up', '↑'], ['down', '↓']]) {
        const button = node('button', symbol, 'btn btn-secondary btn-sm'); button.type = 'button'; button.dataset.move = move; button.setAttribute('aria-label', `Move ${block.label} ${move}`);
        button.disabled = move === 'up' ? index === 0 : index === blocks[group].length - 1; controls.append(button);
      }
      const remove = node('button', '×', 'btn btn-secondary btn-sm'); remove.type = 'button'; remove.dataset.remove = ''; remove.setAttribute('aria-label', `Remove ${block.label}`); controls.append(remove);
      const name = node('div', undefined, 'field-name'); name.append(node('strong', block.label || 'Untitled block'), node('code', block.field));
      item.append(controls, name); list.append(item);
    });
    document.querySelector(`[data-empty-group="${group}"]`).hidden = !!blocks[group].length;
  }
  const inserter = document.getElementById('available-blocks'); inserter.replaceChildren();
  for (const [group, fields] of Object.entries(catalog)) {
    inserter.append(node('h4', group === 'event_fields' ? 'Event details' : 'Outcome columns'));
    let available = 0;
    for (const [field, label] of Object.entries(fields)) {
      if (blocks[group].some(block => block.field === field)) continue;
      const button = node('button', '＋ ' + label, 'add-field'); button.type = 'button'; button.dataset.addField = field; button.dataset.group = group; button.title = field;
      inserter.append(button); available++;
    }
    if (!available) inserter.append(node('p', 'All fields are in this layout.'));
  }
  if (refreshInspector) inspector();
  updatePreview();
}
function remove(group, field) {
  const next = structuredClone(blocks); const block = next[group].find(b => b.field === field);
  next[group] = next[group].filter(b => b.field !== field);
  if (selected?.field === field) selected = null;
  commit(next, `${block.label} removed. Use Add block or Undo to restore it.`);
}
form.addEventListener('click', event => {
  const add = event.target.closest('[data-add-field]');
  if (add) {
    const { group, addField: field } = add.dataset; const next = structuredClone(blocks);
    next[group].push({ field, label: catalog[group][field], role: 'data' }); selected = { group, field };
    commit(next, `${catalog[group][field]} added.`); return;
  }
  const item = event.target.closest('.iframe-field'); if (!item) return;
  const group = item.parentElement.dataset.fieldGroup, field = item.dataset.field;
  if (event.target.closest('[data-remove]')) { remove(group, field); return; }
  const move = event.target.closest('[data-move]');
  if (move) {
    const next = structuredClone(blocks); const index = next[group].findIndex(b => b.field === field); const to = index + (move.dataset.move === 'up' ? -1 : 1);
    if (to < 0 || to >= next[group].length) return;
    [next[group][index], next[group][to]] = [next[group][to], next[group][index]]; selected = { group, field };
    commit(next, `${next[group][to].label} moved to position ${to + 1}.`);
    listItem(field)?.focus(); return;
  }
  select(group, field);
});
const listItem = field => [...document.querySelectorAll('.iframe-field')].find(item => item.dataset.field === field);
form.addEventListener('keydown', event => {
  if (!event.target.matches('.iframe-field') || !['Enter', ' '].includes(event.key)) return;
  event.preventDefault(); select(event.target.parentElement.dataset.fieldGroup, event.target.dataset.field);
});
document.getElementById('remove-block').addEventListener('click', () => { if (selected) remove(selected.group, selected.field); });
for (const [id, property, type] of [['block-label', 'label', 'input'], ['block-role', 'role', 'change']]) {
  document.getElementById(id).addEventListener(type, event => {
    if (!selectedBlock()) return;
    const next = structuredClone(blocks); next[selected.group].find(b => b.field === selected.field)[property] = event.target.value;
    commit(next, '', false);
  });
}
for (const [id, source, target] of [['block-undo', undo, redo], ['block-redo', redo, undo]]) {
  document.getElementById(id).addEventListener('click', () => {
    if (!source.length) return; target.push(structuredClone(blocks)); blocks = source.pop(); dirty = true; render();
  });
}
const toggle = document.getElementById('toggle-inserter');
toggle.addEventListener('click', () => { const panel = document.getElementById('block-inserter'); panel.hidden = !panel.hidden; toggle.setAttribute('aria-expanded', String(!panel.hidden)); });
function reorder(target, y) {
  if (!dragged || !target || dragged === target || dragged.parentElement !== target.parentElement) return;
  const box = target.getBoundingClientRect(); target.parentElement.insertBefore(dragged, y < box.top + box.height / 2 ? target : target.nextSibling);
}
function finishDrag() {
  if (!dragged) return;
  const list = dragged.parentElement; const group = list.dataset.fieldGroup; const field = dragged.dataset.field;
  const next = structuredClone(blocks); const byField = new Map(next[group].map(block => [block.field, block]));
  next[group] = [...list.children].map(item => byField.get(item.dataset.field));
  dragged.classList.remove('is-dragging'); dragged = null; selected = { group, field };
  if (JSON.stringify(next[group]) !== JSON.stringify(blocks[group])) commit(next, 'Block order updated. Save iframe to publish.');
  else render();
}
for (const list of lists) {
  list.addEventListener('dragstart', event => {
    dragged = event.target.closest('.iframe-field'); if (!dragged) return;
    event.dataTransfer.effectAllowed = 'move'; event.dataTransfer.setData('text/plain', dragged.dataset.field); dragged.classList.add('is-dragging');
  });
  list.addEventListener('dragover', event => {
    if (dragged?.parentElement !== list) return; event.preventDefault(); event.dataTransfer.dropEffect = 'move'; reorder(event.target.closest('.iframe-field'), event.clientY);
  });
  list.addEventListener('drop', event => { if (dragged?.parentElement === list) { event.preventDefault(); finishDrag(); } });
  list.addEventListener('dragend', finishDrag);
  list.addEventListener('pointerdown', event => {
    const handle = event.target.closest('.field-handle'); if (!handle || event.pointerType === 'mouse') return;
    dragged = handle.closest('.iframe-field'); dragged.classList.add('is-dragging'); handle.setPointerCapture(event.pointerId); event.preventDefault();
  });
  list.addEventListener('pointermove', event => { if (dragged && event.pointerType !== 'mouse') reorder(document.elementFromPoint(event.clientX, event.clientY)?.closest('.iframe-field'), event.clientY); });
  for (const type of ['pointerup', 'pointercancel']) list.addEventListener(type, event => { if (event.pointerType !== 'mouse') finishDrag(); });
}
title.addEventListener('input', () => { dirty = true; updatePreview(); });
form.elements.markets.addEventListener('input', () => { dirty = true; });
frame.addEventListener('load', updatePreview);
form.addEventListener('submit', () => { document.getElementById('iframe-blocks').value = JSON.stringify(blocks); dirty = false; });
window.addEventListener('beforeunload', event => { if (dirty) { event.preventDefault(); event.returnValue = ''; } });
render();
