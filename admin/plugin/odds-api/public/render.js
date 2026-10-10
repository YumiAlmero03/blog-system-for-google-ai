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

export function isDateField(path = '', label = '') {
  const key = String(path).split('.').pop().replace(/[\[\]'" ]/g, '').toLowerCase();
  const lbl = String(label).trim().toLowerCase();
  // Only date fields can interpret a number as a Unix timestamp.
  return key.includes('date') || /^(commence_time|game_time|cutoff_time|cutoff|match_time|start_time)$/.test(key)
    || lbl.includes('date') || /^(game|cutoff|match|start)(?:_|\s+)?(?:date|time)/i.test(lbl);
}

export function formatted(value, format, path = '', label = '') {
  if (value === null || value === undefined || value === '') return '';
  const isDate = isDateField(path, label) || format === 'datetime' || format === 'date' || format === 'date_only';
  if (isDate) {
    const raw = typeof value === 'string' ? value.trim() : value;
    const isIsoDateStr = typeof raw === 'string' && /^\d{4}-\d{2}-\d{2}/.test(raw);
    const numeric = typeof raw === 'number' || /^\d{9,13}$/.test(raw);
    // Do not interpret small numbers such as odds or points as calendar dates.
    const timestamp = numeric ? Number(raw) : null;
    const date = numeric
      ? (Math.abs(timestamp) >= 1e8 ? new Date(Math.abs(timestamp) > 1e11 ? timestamp : timestamp * 1000) : null)
      : (isIsoDateStr ? (/^\d{4}-\d{2}-\d{2}$/.test(raw) ? new Date(raw + 'T00:00:00') : new Date(raw)) : (isNaN(Date.parse(raw)) ? null : new Date(raw)));
    if (date && !Number.isNaN(date.getTime())) {
      const datePart = date.toLocaleDateString('en-US', {month: 'long', day: 'numeric', year: 'numeric'});
      const isDateOnly = format === 'date_only' || (typeof raw === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(raw));
      if (isDateOnly) {
        return datePart;
      }
      const timePart = date.toLocaleTimeString('en-US', {hour: 'numeric', minute: '2-digit'});
      if (format === 'time' || format === 'time_only') {
        return timePart;
      }
      return `${datePart}, ${timePart}`;
    }
  } else if (format === 'decimal' && Number.isFinite(Number(value))) {
    return Number(value).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 4});
  }
  return String(value);
}

function applyWidth(node, width) {
  if (!node || !width || typeof width !== 'string') return;
  const w = width.trim().toLowerCase();
  if (w === '100%' || w === '1/1' || w === 'full') {
    node.classList.add('w-full', 'flex-none');
  } else if (w === '50%' || w === '1/2') {
    node.classList.add('w-1/2', 'flex-none');
  } else if (w === '33.3%' || w === '33%' || w === '1/3') {
    node.classList.add('w-1/3', 'flex-none');
  } else if (w === '66.7%' || w === '66%' || w === '2/3') {
    node.classList.add('w-2/3', 'flex-none');
  } else if (w === '25%' || w === '1/4') {
    node.classList.add('w-1/4', 'flex-none');
  } else if (w === '75%' || w === '3/4') {
    node.classList.add('w-3/4', 'flex-none');
  } else if (/^(?:[1-9]\d?|100)(?:\.\d+)?%$/.test(w) && parseFloat(w) <= 100) {
    node.classList.add('api-custom-width');
    node.style.setProperty('--block-width', w);
  } else if (w === 'auto') {
    node.classList.add('w-auto', 'flex-initial');
  } else if (w === 'equal' || w === '1fr' || w === 'flex-1') {
    node.classList.add('flex-1', 'min-w-0');
  }
}

function getImageSizeClasses(size, source, section) {
  let s = size || '';
  if (!s) {
    s = (source === 'sport' || section === 'title') ? '48px' : '36px';
  }
  s = s.trim().toLowerCase();
  if (s === '24px' || s === 'w-6') return 'w-6 h-6';
  if (s === '32px' || s === 'w-8') return 'w-8 h-8';
  if (s === '36px' || s === 'w-9') return 'w-9 h-9';
  if (s === '40px' || s === 'w-10') return 'w-10 h-10';
  if (s === '48px' || s === 'w-12') return 'w-12 h-12';
  if (s === '64px' || s === 'w-16') return 'w-16 h-16';
  if (s === '80px' || s === 'w-20') return 'w-20 h-20';
  if (s === '96px' || s === 'w-24') return 'w-24 h-24';
  if (s === '112px' || s === 'w-28') return 'w-28 h-28';
  if (s === '120px' || s === '128px' || s === 'w-32') return 'w-32 h-32';
  if (s === '100%' || s === 'full' || s === 'w-full') return 'w-full h-auto';
  return (source === 'sport' || section === 'title') ? 'w-12 h-12' : 'w-9 h-9';
}

function getButtonWidthClass(width) {
  if (!width) return '';
  const w = width.trim().toLowerCase();
  if (w === '80px' || w === 'w-20') return 'w-20';
  if (w === '96px' || w === '100px' || w === 'w-24') return 'w-24';
  if (w === '112px' || w === 'w-28') return 'w-28';
  if (w === '120px' || w === '128px' || w === 'w-32') return 'w-32';
  if (w === '160px' || w === 'w-40') return 'w-40';
  if (w === '100%' || w === 'full' || w === 'w-full') return 'w-full';
  if (w === 'auto') return 'w-auto';
  return '';
}

function renderBlockNode(block, target) {
  if (block.type === 'container') {
    const isRow = (block.direction || 'row') === 'row';
    let alignClass = '';
    if (block.align === 'center') alignClass = ' justify-center';
    else if (block.align === 'right') alignClass = ' justify-end';
    else if (block.align === 'left') alignClass = ' justify-start';

    const wrap = element('div', 'api-container api-container-' + (block.direction || 'row') + (isRow ? ' flex flex-wrap items-center gap-3 w-full' : ' flex flex-col gap-2 w-full') + alignClass);
    if (block.label) wrap.dataset.label = block.label;
    if (block.width) applyWidth(wrap, block.width);
    for (const child of block.blocks || []) {
      renderBlockNode(child, wrap);
    }
    target.append(wrap);
  } else if (block.type === 'loop') {
    const group = element('section', 'api-loop w-full rounded-xl border border-emerald-500/30 p-3 sm:p-4');
    if (block.label) {
      group.append(element('p', 'api-group-title text-xs uppercase tracking-wider font-bold text-emerald-400 mb-2 border-b border-white/10 pb-1', block.label));
    }
    if (block.width) applyWidth(group, block.width);
    group.append(grid(block.items || [], block.columns, block.direction || 'grid'));
    target.append(group);
  } else if (block.type === 'button') {
    const align = block.align || 'center';
    const isJustify = align === 'justify' || align === 'full';
    let alignWrapClass = 'justify-center';
    if (isJustify) {
      alignWrapClass = 'w-full justify-stretch';
    } else if (align === 'left') {
      alignWrapClass = 'justify-start mr-auto';
    } else if (align === 'right') {
      alignWrapClass = 'justify-end ml-auto';
    }

    const wrap = element('div', 'api-block-button flex items-center ' + alignWrapClass);
    if (block.width) applyWidth(wrap, block.width);
    if (isJustify) wrap.classList.add('w-full');

    const href = safeUrl(block.url);
    const btnStyle = block.style || 'primary';

    let themeClass = 'gold-button';
    if (btnStyle === 'brand') {
      themeClass = 'primary-emerald-button';
    } else if (btnStyle === 'secondary') {
      themeClass = 'secondary-glass-button';
    } else if (btnStyle === 'outline') {
      themeClass = 'border border-emerald-500/60 hover:bg-emerald-500/15 text-emerald-400 font-bold backdrop-blur-sm';
    } else if (btnStyle === 'primary' || btnStyle === 'gold') {
      themeClass = 'gold-button';
    }

    let btnText = block.label || 'Action';
    if ((block.path?.endsWith('.point') || block.path?.endsWith('point')) && !isNaN(Number(btnText)) && Number(btnText) < 0) {
      btnText = String(Math.abs(Number(btnText)));
    }

    const btnWidthClass = isJustify ? 'w-full justify-center' : getButtonWidthClass(block.btn_width);
    const styleClass = 'api-btn api-btn-' + btnStyle + ' inline-flex items-center justify-center px-4 py-2 rounded-lg text-sm font-bold transition-all shadow-md active:scale-95 ' + themeClass + (btnWidthClass ? ' ' + btnWidthClass : '');

    const btn = element(href ? 'a' : 'button', styleClass, btnText);
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
    let alignClass = '';
    if (block.align === 'right') alignClass = ' text-right';
    else if (block.align === 'center') alignClass = ' text-center';
    else if (block.align === 'left') alignClass = ' text-left';

    const wrap = element('div', 'api-block-link' + alignClass);
    if (block.width) applyWidth(wrap, block.width);
    const href = safeUrl(block.url);
    const link = element(href ? 'a' : 'span', 'api-link text-emerald-400 hover:text-emerald-300 font-semibold text-sm underline transition-colors', block.label || 'Link');
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
    let roleClass = 'text-slate-300 text-sm';
    if (role === 'title') {
      roleClass = 'text-white font-bold text-lg';
    } else if (role === 'badge') {
      roleClass = 'inline-block text-xs font-bold px-2.5 py-0.5 rounded-full bg-emerald-500/20 text-emerald-400 border border-emerald-500/30';
    }
    let alignClass = '';
    if (block.align === 'right') alignClass = ' text-right';
    else if (block.align === 'center') alignClass = ' text-center';
    else if (block.align === 'left') alignClass = ' text-left';

    const textNode = element(tag, 'api-text api-role-' + role + ' ' + roleClass + alignClass, String(block.value ?? block.label ?? ''));
    if (block.width) applyWidth(textNode, block.width);
    target.append(textNode);
  } else if (block.type === 'image') {
    const src = safeUrl(block.url);
    if (src) {
      const align = block.align || (block.section === 'loop' ? 'left' : 'center');
      let alignClass = 'justify-center';
      if (align === 'left') {
        alignClass = 'justify-start mr-auto';
      } else if (align === 'right') {
        alignClass = 'justify-end ml-auto';
      }

      const wrap = element('div', 'api-block-image flex items-center ' + alignClass);
      if (block.width) applyWidth(wrap, block.width);

      const img = document.createElement('img');
      const sizeClasses = getImageSizeClasses(block.image_width || block.width, block.image_source, block.section);
      img.className = 'api-img object-contain flex-shrink-0 ' + sizeClasses;
      const fallback = safeUrl(block.fallback_url);
      if (fallback && fallback !== src) {
        img.addEventListener('error', () => { img.src = fallback; }, {once: true});
      }
      img.src = src;
      img.alt = block.alt || block.label || '';
      img.loading = 'lazy';

      wrap.append(img);
      target.append(wrap);
    }
  } else {
    const align = block.align || 'left';
    let alignClass = 'items-start text-left';
    if (align === 'right') {
      alignClass = 'items-end text-right';
    } else if (align === 'center') {
      alignClass = 'items-center text-center';
    }

    const field = element('div', 'api-field api-role-' + (block.role || 'data') + ' flex flex-col ' + alignClass);
    if (block.width) applyWidth(field, block.width);

    if (block.label) {
      field.append(element('span', 'api-label text-xs uppercase tracking-wider text-emerald-400/90 font-semibold block mb-0.5 leading-tight', block.label));
    }

    const tag = (block.role === 'title' || block.role === 'description') ? 'p' : 'div';
    const valClass = block.role === 'title' ? 'text-white font-bold text-base leading-tight' : 'text-slate-100 font-semibold text-sm leading-tight';
    const valEl = element(tag, 'api-value ' + valClass);

    let val = block.value;
    if ((block.path?.endsWith('.point') || block.path?.endsWith('point')) && val !== null && val !== undefined && val !== '' && !isNaN(Number(val)) && Number(val) < 0) {
      val = Math.abs(Number(val));
    }
    const formattedText = formatted(val, block.format, block.path, block.label);
    const fieldUrl = safeUrl(block.url);
    if (fieldUrl) {
      const a = element('a', 'api-field-link text-emerald-400 hover:text-emerald-300 font-semibold underline transition-colors', formattedText);
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
  const colCount = Math.min(4, Math.max(1, Number(columns) || 1));
  const colClass = colCount === 4 ? 'grid-cols-4' : colCount === 3 ? 'grid-cols-3' : colCount === 2 ? 'grid-cols-2' : 'grid-cols-1';
  const node = element('div', isRow ? 'api-flex-row flex flex-row overflow-x-auto gap-4 py-2 w-full' : 'api-grid api-cols-' + colCount + ' grid ' + colClass + ' gap-4 w-full');
  if (isRow) node.dataset.direction = 'row';

  for (const item of items) {
    const card = element('section', 'api-card nexa-card rounded-xl p-4 shadow-sm hover:border-[#016242] transition-colors flex flex-col gap-3 min-w-0 text-white');
    for (const block of item.blocks || []) {
      renderBlockNode(block, card);
    }
    if (card.childNodes.length) node.append(card);
  }
  return node;
}

export function renderTitleBlocks(target, blocks) {
  target.replaceChildren();
  target.className = 'flex items-center flex-wrap gap-4 w-full text-white';
  for (const block of blocks || []) {
    renderBlockNode(block, target);
  }
}

export function renderLayout(container, payload) {
  const root = element('div', 'api-layout-root w-full flex flex-col gap-4');
  const externalHeader = document.querySelector('#iframe-title-section');
  if (payload.title_blocks && payload.title_blocks.length) {
    if (externalHeader && container.id === 'events') {
      renderTitleBlocks(externalHeader, payload.title_blocks);
    } else {
      const titleSec = element('header', 'api-title-section nexa-card rounded-xl p-4 flex flex-wrap items-center gap-4 w-full mb-4 text-white');
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
