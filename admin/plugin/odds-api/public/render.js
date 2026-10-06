function element(tag, className, text) {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text !== undefined) node.textContent = text;
  return node;
}

function safeUrl(url) {
  if (!url || typeof url !== 'string') return '';
  const trimmed = url.trim();
  if (trimmed.startsWith('/') || trimmed.startsWith('#')) return trimmed;
  if (/^https?:\/\//i.test(trimmed)) return trimmed;
  return '';
}

function formatted(value, format) {
  if (format === 'decimal' && value !== '' && Number.isFinite(Number(value))) {
    return Number(value).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 4});
  }
  if ((format === 'date' || format === 'datetime') && typeof value === 'string') {
    const date = new Date(value);
    if (!Number.isNaN(date.getTime())) return format === 'date' ? date.toLocaleDateString() : date.toLocaleString();
  }
  return String(value ?? '');
}

function renderBlockNode(block, target) {
  if (block.type === 'container') {
    const wrap = element('div', 'api-container api-container-' + (block.direction || 'row'));
    if (block.label) wrap.dataset.label = block.label;
    for (const child of block.blocks || []) {
      renderBlockNode(child, wrap);
    }
    target.append(wrap);
  } else if (block.type === 'loop') {
    const group = element('section', 'api-loop');
    if (block.label) group.append(element('p', 'api-group-title', block.label));
    group.append(grid(block.items || [], block.columns, block.direction || 'grid'));
    target.append(group);
  } else if (block.type === 'button') {
    const wrap = element('div', 'api-block-button');
    const href = safeUrl(block.url);
    const btn = element(href ? 'a' : 'button', 'api-btn api-btn-' + (block.style || 'primary'), block.label || 'Action');
    if (href) {
      btn.href = href;
      btn.target = block.target || '_blank';
      btn.rel = 'noopener';
    } else {
      btn.type = 'button';
    }
    wrap.append(btn);
    target.append(wrap);
  } else if (block.type === 'link') {
    const wrap = element('div', 'api-block-link');
    const href = safeUrl(block.url);
    const link = element(href ? 'a' : 'span', 'api-link', block.label || 'Link');
    if (href) {
      link.href = href;
      link.target = block.target || '_blank';
      link.rel = 'noopener';
    }
    wrap.append(link);
    target.append(wrap);
  } else if (block.type === 'text') {
    const role = block.role || 'description';
    const tag = role === 'badge' ? 'span' : (role === 'title' || role === 'description') ? 'p' : 'div';
    const textNode = element(tag, 'api-text api-role-' + role, String(block.value ?? block.label ?? ''));
    target.append(textNode);
  } else if (block.type === 'image') {
    const src = safeUrl(block.url);
    if (src) {
      const wrap = element('div', 'api-block-image');
      const img = document.createElement('img');
      img.className = 'api-img';
      img.src = src;
      img.alt = block.alt || block.label || '';
      img.loading = 'lazy';
      if (block.width) img.style.maxWidth = block.width;
      if (block.height) img.style.maxHeight = block.height;
      wrap.append(img);
      target.append(wrap);
    }
  } else {
    const field = element('div', 'api-field api-role-' + (block.role || 'data'));
    if (block.label) field.append(element('span', 'api-label', block.label));
    const tag = (block.role === 'title' || block.role === 'description') ? 'p' : 'div';
    const valEl = element(tag, 'api-value');
    const formattedText = formatted(block.value, block.format);
    const fieldUrl = safeUrl(block.url);
    if (fieldUrl) {
      const a = element('a', 'api-field-link', formattedText);
      a.href = fieldUrl;
      a.target = block.target || '_blank';
      a.rel = 'noopener';
      valEl.append(a);
    } else {
      valEl.textContent = formattedText;
    }
    field.append(valEl);
    target.append(field);
  }
}

function grid(items, columns, direction = 'grid') {
  const isRow = direction === 'row';
  const colClass = [1, 2, 3, 4].includes(Number(columns)) ? 'api-cols-' + columns : (columns === 'auto' ? 'api-cols-auto' : 'api-cols-1');
  const node = element('div', isRow ? 'api-flex-row api-direction-row' : 'api-grid ' + colClass);
  if (isRow) node.dataset.direction = 'row';

  for (const item of items) {
    const card = element('section', 'api-card');
    for (const block of item.blocks || []) {
      renderBlockNode(block, card);
    }
    if (card.childNodes.length) node.append(card);
  }
  return node;
}

export function renderTitleBlocks(target, blocks) {
  target.replaceChildren();
  for (const block of blocks || []) {
    renderBlockNode(block, target);
  }
}

export function renderLayout(container, payload) {
  const root = element('div', 'api-layout-root');
  const externalHeader = document.querySelector('#iframe-title-section');
  if (payload.title_blocks && payload.title_blocks.length) {
    if (externalHeader && container.id === 'events') {
      renderTitleBlocks(externalHeader, payload.title_blocks);
    } else {
      const titleSec = element('header', 'api-title-section');
      for (const block of payload.title_blocks) {
        renderBlockNode(block, titleSec);
      }
      root.append(titleSec);
    }
  } else if (externalHeader && container.id === 'events') {
    externalHeader.replaceChildren();
  }
  const cards = grid(payload.records || [], payload.columns, payload.direction || 'grid');
  root.append(cards);
  container.replaceChildren(root);
}
