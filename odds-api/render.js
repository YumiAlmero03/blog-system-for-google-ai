export function node(tag, text, className) {
  const element = document.createElement(tag);
  if (text !== undefined) element.textContent = text;
  if (className) element.className = className;
  return element;
}

export function displayValue(key, value) {
  if (value === null || value === undefined || value === '') return '—';
  if (typeof value === 'boolean') return value ? 'Yes' : 'No';
  if (key === 'commence_time' || key.endsWith('.last_update')) {
    const time = new Date(value);
    return Number.isNaN(time.getTime()) ? String(value) : time.toLocaleString();
  }
  return String(value);
}

function fieldValue(record, key) {
  return key.split('.').reduce((value, part) => value?.[part], record);
}

export function renderOdds(container, events, layout) {
  container.replaceChildren();
  for (const event of events) {
    const card = node('section', undefined, 'event');
    card.setAttribute('aria-label', `${event.home_team || event.sport_title} ${event.away_team || ''}`.trim());
    const details = node('dl', undefined, 'event-fields');
    for (const block of layout.blocks.event_fields) {
      const key = block.field;
      const field = node('div', undefined, 'event-field'); field.dataset.field = key;
      field.dataset.role = block.role;
      const value = displayValue(key, fieldValue(event, key));
      const content = node('dd');
      if (block.role === 'title') content.append(node('h2', value));
      else if (block.role === 'description') content.append(node('p', value));
      else content.textContent = value;
      field.append(node('dt', block.label), content);
      details.append(field);
    }
    if (layout.blocks.event_fields.length) card.append(details);
    if (!layout.blocks.outcome_fields.length) { container.append(card); continue; }
    if (!event.bookmakers.length) {
      card.append(node('p', 'No bookmaker prices are available for the selected markets.'));
      container.append(card); continue;
    }
    const wrapper = node('div', undefined, 'table-wrap');
    const table = node('table');
    const head = node('thead'); const headings = node('tr');
    for (const block of layout.blocks.outcome_fields) {
      const th = node('th', block.label); th.scope = 'col'; th.dataset.field = block.field; headings.append(th);
    }
    head.append(headings); table.append(head); const body = node('tbody');
    for (const bookmaker of event.bookmakers) {
      for (const market of bookmaker.markets.length ? bookmaker.markets : [null]) {
        for (const outcome of market?.outcomes.length ? market.outcomes : [null]) {
          const row = node('tr'); const context = { bookmaker, market, outcome };
          for (const block of layout.blocks.outcome_fields) {
            const key = block.field; const value = displayValue(key, fieldValue(context, key));
            const cell = node('td', undefined, key === 'outcome.price' ? 'price' : undefined);
            if (block.role === 'title') cell.append(node('h3', value));
            else if (block.role === 'description') cell.append(node('p', value));
            else cell.textContent = value;
            cell.dataset.field = key; cell.dataset.role = block.role; row.append(cell);
          }
          body.append(row);
        }
      }
    }
    table.append(body); wrapper.append(table); card.append(wrapper); container.append(card);
  }
}

export function previewLayout(saved, candidate) {
  if (!candidate || typeof candidate.title !== 'string' || candidate.title.length > 200) return null;
  const blocks = candidate.blocks;
  if (!blocks || typeof blocks !== 'object') return null;
  const layout = { ...saved, title: candidate.title, blocks, labels: structuredClone(saved.labels) };
  for (const group of ['event_fields', 'outcome_fields']) {
    const values = blocks[group]; const keys = Object.keys(saved.labels[group]);
    if (!Array.isArray(values) || values.length > keys.length) return null;
    const seen = new Set();
    for (const block of values) {
      if (!block || !keys.includes(block.field) || seen.has(block.field) || typeof block.label !== 'string' || block.label.length > 120 || !['data', 'title', 'description'].includes(block.role)) return null;
      seen.add(block.field); layout.labels[group][block.field] = block.label;
    }
    layout[group] = values.map(block => block.field);
  }
  return layout;
}
