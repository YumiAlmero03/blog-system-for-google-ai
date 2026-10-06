import {renderLayout} from '/admin/plugin/odds-api/public/render.js';

// Real sample output for The Odds API (2 NBA rows provided by user)
const sampleOddsData = [
  {
    "id": "26b036ff107f3c658258eaf4a6f26228",
    "sport_key": "basketball_nba",
    "sport_title": "NBA",
    "commence_time": "2026-10-20T19:00:00Z",
    "home_team": "Detroit Pistons",
    "away_team": "Boston Celtics",
    "bookmakers": [
      {
        "key": "onexbet",
        "title": "1xBet",
        "last_update": "2026-10-06T06:12:44Z",
        "markets": [
          {
            "key": "h2h",
            "last_update": "2026-10-06T06:12:43Z",
            "outcomes": [
              { "name": "Boston Celtics", "price": 2.11 },
              { "name": "Detroit Pistons", "price": 1.83 }
            ]
          },
          {
            "key": "spreads",
            "last_update": "2026-10-06T06:12:43Z",
            "outcomes": [
              { "name": "Boston Celtics", "price": 1.99, "point": 1.5 },
              { "name": "Detroit Pistons", "price": 1.93, "point": -1.5 }
            ]
          },
          {
            "key": "totals",
            "last_update": "2026-10-06T06:12:43Z",
            "outcomes": [
              { "name": "Over", "price": 1.99, "point": 221.5 },
              { "name": "Under", "price": 1.93, "point": 221.5 }
            ]
          }
        ]
      }
    ]
  },
  {
    "id": "2b042ddad3d3c6c6fe5b7005dc26c775",
    "sport_key": "basketball_nba",
    "sport_title": "NBA",
    "commence_time": "2026-10-20T23:00:00Z",
    "home_team": "New York Knicks",
    "away_team": "Philadelphia 76ers",
    "bookmakers": [
      {
        "key": "onexbet",
        "title": "1xBet",
        "last_update": "2026-10-06T06:12:44Z",
        "markets": [
          {
            "key": "h2h",
            "last_update": "2026-10-06T06:12:43Z",
            "outcomes": [
              { "name": "New York Knicks", "price": 1.54 },
              { "name": "Philadelphia 76ers", "price": 2.7 }
            ]
          },
          {
            "key": "spreads",
            "last_update": "2026-10-06T06:12:43Z",
            "outcomes": [
              { "name": "New York Knicks", "price": 1.96, "point": -5.5 },
              { "name": "Philadelphia 76ers", "price": 1.96, "point": 5.5 }
            ]
          },
          {
            "key": "totals",
            "last_update": "2026-10-06T06:12:43Z",
            "outcomes": [
              { "name": "Over", "price": 1.96, "point": 230.5 },
              { "name": "Under", "price": 1.96, "point": 230.5 }
            ]
          }
        ]
      }
    ]
  }
];

const form = document.querySelector('#layout-form');
const hidden = document.querySelector('#layout-json');
const columnsSelect = document.querySelector('#columns-select');
const wireframeColumns = document.querySelector('#wireframe-loop-columns');
const wireframeLimit = document.querySelector('#wireframe-loop-limit');
const limitHidden = document.querySelector('#item-limit-input');
const titleCanvas = document.querySelector('#title-section-canvas');
const loopCanvas = document.querySelector('#loop-section-canvas');
const inspectorPanel = document.querySelector('#inspector-panel');
const inspectorContent = document.querySelector('#inspector-content');
const previewContainer = document.querySelector('#layout-preview');
const fieldsList = document.querySelector('#available-fields');
const fieldSearch = document.querySelector('#field-search');
const status = document.querySelector('#builder-status');

let nodes = [];
try {
  nodes = JSON.parse(hidden?.value || '[]');
} catch (_) {
  nodes = [];
}

// Build standard layout utilizing all sample fields if empty or missing title
if (!Array.isArray(nodes) || !nodes.length) {
  nodes = [
    { id: 't_sport', type: 'field', path: '$.sport_title', label: 'sport_title', role: 'title', section: 'title', visible: true, format: 'text', fallback: 'NBA' },
    { id: 'c_teams', type: 'container', label: 'Teams & Date', direction: 'row', section: 'loop', visible: true, children: [
      { id: 'f_away', type: 'field', path: '$.away_team', label: 'Away Team', role: 'data', section: 'loop', visible: true, format: 'text', fallback: 'Away' },
      { id: 'f_home', type: 'field', path: '$.home_team', label: 'Home Team', role: 'data', section: 'loop', visible: true, format: 'text', fallback: 'Home' },
      { id: 'f_date', type: 'field', path: '$.commence_time', label: 'Game Date', role: 'data', section: 'loop', visible: true, format: 'datetime', fallback: 'TBD' }
    ]},
    { id: 'c_odds', type: 'container', label: 'H2H Odds (1xBet)', direction: 'row', section: 'loop', visible: true, children: [
      { id: 'b_away_h2h', type: 'button', path: '$.bookmakers[0].markets[0].outcomes[0].price', label: 'Away H2H', style: 'gold', section: 'loop', visible: true, url: '', target: '_blank' },
      { id: 'b_home_h2h', type: 'button', path: '$.bookmakers[0].markets[0].outcomes[1].price', label: 'Home H2H', style: 'gold', section: 'loop', visible: true, url: '', target: '_blank' }
    ]},
    { id: 'c_spreads', type: 'container', label: 'Spreads & Totals', direction: 'row', section: 'loop', visible: true, children: [
      { id: 'f_spread_pt', type: 'field', path: '$.bookmakers[0].markets[1].outcomes[0].point', label: 'Spread Line', role: 'data', section: 'loop', visible: true, format: 'text', fallback: '0' },
      { id: 'b_spread_odds', type: 'button', path: '$.bookmakers[0].markets[1].outcomes[0].price', label: 'Spread Odds', style: 'brand', section: 'loop', visible: true, url: '', target: '_blank' },
      { id: 'f_total_pt', type: 'field', path: '$.bookmakers[0].markets[2].outcomes[0].point', label: 'Total O/U', role: 'data', section: 'loop', visible: true, format: 'text', fallback: '0' },
      { id: 'b_total_odds', type: 'button', path: '$.bookmakers[0].markets[2].outcomes[0].price', label: 'Total Odds', style: 'primary', section: 'loop', visible: true, url: '', target: '_blank' }
    ]}
  ];
} else if (!nodes.some(n => (n.section || 'loop') === 'title')) {
  nodes.unshift({ id: 't_sport', type: 'field', path: '$.sport_title', label: 'sport_title', role: 'title', section: 'title', visible: true, format: 'text', fallback: 'NBA' });
}

let selected = '';
let activeContainerId = '';
let discoveredFields = [];
let cachedApiResponse = null;
let undo = [];
let redo = [];
let draggedPayload = null;

const el = (tag, text, className = '') => {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text !== undefined) node.textContent = text;
  return node;
};

function locate(id, list = nodes, parent = null) {
  for (let i = 0; i < list.length; i++) {
    if (list[i].id === id) return { node: list[i], list, index: i, parent };
    if (list[i].children && Array.isArray(list[i].children)) {
      const match = locate(id, list[i].children, list[i]);
      if (match) return match;
    }
  }
  return null;
}

function snapshot() {
  undo.push(JSON.stringify(nodes));
  if (undo.length > 50) undo.shift();
  redo = [];
}

function sync() {
  if (hidden) hidden.value = JSON.stringify(nodes);
  if (wireframeColumns && columnsSelect) columnsSelect.value = wireframeColumns.value;
  if (wireframeLimit && limitHidden) limitHidden.value = wireframeLimit.value;
  const undoBtn = document.querySelector('#undo');
  const redoBtn = document.querySelector('#redo');
  if (undoBtn) undoBtn.disabled = !undo.length;
  if (redoBtn) redoBtn.disabled = !redo.length;
}

function change(fn, forceInspector = false) {
  snapshot();
  fn();
  sync();
  draw(forceInspector);
  updateLivePreview();
}

function updatePillText(nodeId) {
  const match = locate(nodeId);
  if (!match) return;
  const pill = document.querySelector(`.wireframe-pill[data-id="${nodeId}"]`);
  if (!pill) return;
  const labelSpan = pill.querySelector('span:not(.wireframe-pill-tag):not(.path-tag)');
  if (labelSpan) {
    const node = match.node;
    const icon = node.type === 'button' ? '🔘 ' : (node.type === 'link' ? '🔗 ' : (node.type === 'image' ? '🖼 ' : (node.type === 'text' ? '📝 ' : (node.type === 'container' ? '📦 ' : '📊 '))));
    labelSpan.textContent = icon + (node.label || node.text || node.path || node.id);
  }
}

function onFieldInput() {
  sync();
  updatePillText(selected);
  updateLivePreview();
}

function makeId(prefix = 'b') {
  return prefix + '_' + Math.random().toString(36).substring(2, 9);
}

function createBlock(type, path = '', label = '', section = 'loop') {
  const id = makeId(type.charAt(0));
  const cleanLabel = label || (path ? path.split('.').at(-1).replace(/\[\]|\[\d+\]/g, '') : (type === 'button' ? 'Bet Now' : (type === 'container' ? 'Container row' : (type === 'image' ? 'Image' : 'Text'))));
  const base = { id, type, path, label: cleanLabel, section, visible: true };

  if (type === 'container') {
    return { ...base, direction: 'row', children: [] };
  }
  if (type === 'loop') {
    return { ...base, columns: 1, direction: 'grid', children: [] };
  }
  if (type === 'button' || type === 'link') {
    return { ...base, url: '', style: 'gold', target: '_blank' };
  }
  if (type === 'text') {
    return { ...base, text: cleanLabel, role: section === 'title' ? 'title' : 'description' };
  }
  if (type === 'image') {
    return { ...base, url: '', alt: cleanLabel, width: '120px', height: '' };
  }
  return {
    ...base,
    role: section === 'title' ? 'title' : 'data',
    format: path.includes('time') || path.includes('date') ? 'datetime' : 'text',
    fallback: section === 'title' ? cleanLabel : '—'
  };
}

function insertBlock(block, targetContainerId = null, targetSection = 'loop', insertIndex = -1) {
  change(() => {
    block.section = targetSection;
    if (targetSection === 'title') {
      if (insertIndex >= 0) nodes.splice(insertIndex, 0, block);
      else nodes.push(block);
      selected = block.id;
      return;
    }

    if (targetContainerId) {
      const match = locate(targetContainerId);
      if (match && match.node.type === 'container') {
        const targetList = match.node.children || (match.node.children = []);
        if (insertIndex >= 0) targetList.splice(insertIndex, 0, block);
        else targetList.push(block);
        selected = block.id;
        return;
      }
    }

    // Default loop insertion: into active container or last container
    const loopContainers = nodes.filter(n => n.type === 'container' && (n.section || 'loop') === 'loop');
    if (loopContainers.length && block.type !== 'container') {
      const targetContainer = (activeContainerId && locate(activeContainerId)?.node) || loopContainers[loopContainers.length - 1];
      targetContainer.children || (targetContainer.children = []);
      if (insertIndex >= 0) targetContainer.children.splice(insertIndex, 0, block);
      else targetContainer.children.push(block);
    } else {
      if (insertIndex >= 0) nodes.splice(insertIndex, 0, block);
      else nodes.push(block);
    }
    selected = block.id;
  }, true);
}

function moveNode(sourceId, targetContainerId, targetSection, insertIndex = -1) {
  const match = locate(sourceId);
  if (!match) return;

  change(() => {
    // Remove from source
    const [moved] = match.list.splice(match.index, 1);
    moved.section = targetSection;

    if (targetSection === 'title') {
      if (insertIndex >= 0) nodes.splice(insertIndex, 0, moved);
      else nodes.push(moved);
      selected = moved.id;
      return;
    }

    if (targetContainerId) {
      const targetMatch = locate(targetContainerId);
      if (targetMatch && targetMatch.node.type === 'container') {
        targetMatch.node.children || (targetMatch.node.children = []);
        if (insertIndex >= 0) targetMatch.node.children.splice(insertIndex, 0, moved);
        else targetMatch.node.children.push(moved);
        selected = moved.id;
        return;
      }
    }

    // Append to loop root
    if (insertIndex >= 0) nodes.splice(insertIndex, 0, moved);
    else nodes.push(moved);
    selected = moved.id;
  }, true);
}

// Path Resolver for live preview
function resolvePath(obj, path) {
  if (!path || typeof path !== 'string' || !obj) return null;
  const clean = path.replace(/^\$\.?/, '');
  const tokens = clean.match(/[^.\[\]]+|\[\d+\]/g) || [];
  let current = obj;
  for (const token of tokens) {
    if (current === null || current === undefined) return null;
    const key = token.startsWith('[') ? parseInt(token.slice(1, -1), 10) : token;
    current = current[key];
  }
  return current;
}

function projectNode(node, record) {
  if (node.visible === false) return null;
  const raw = node.path ? resolvePath(record, node.path) : null;
  const val = raw !== null && raw !== undefined && raw !== '' ? raw : (node.fallback || node.label || '');

  if (node.type === 'container') {
    return {
      type: 'container',
      direction: node.direction || 'row',
      label: node.label,
      blocks: (node.children || []).map(child => projectNode(child, record)).filter(Boolean)
    };
  }
  if (node.type === 'button') {
    return {
      type: 'button',
      label: raw !== null && raw !== undefined && raw !== '' ? String(raw) : (node.label || 'Bet'),
      url: node.url || '',
      style: node.style || 'gold',
      target: node.target || '_blank'
    };
  }
  if (node.type === 'link') {
    return {
      type: 'link',
      label: raw !== null && raw !== undefined && raw !== '' ? String(raw) : (node.label || 'Link'),
      url: node.url || '',
      target: node.target || '_blank'
    };
  }
  if (node.type === 'text') {
    return {
      type: 'text',
      role: node.role || (node.section === 'title' ? 'title' : 'description'),
      value: node.text || node.label || String(val)
    };
  }
  if (node.type === 'image') {
    return {
      type: 'image',
      url: raw ? String(raw) : (node.url || ''),
      alt: node.alt || node.label || '',
      width: node.width || '120px',
      height: node.height || ''
    };
  }
  return {
    type: 'field',
    label: node.label,
    value: val,
    format: node.format || (node.path?.includes('time') || node.path?.includes('date') ? 'datetime' : 'text'),
    role: node.role || (node.section === 'title' ? 'title' : 'data'),
    url: node.url || ''
  };
}

function projectLayoutLocally(data, currentNodes, columns, limit) {
  const records = Array.isArray(data) ? data : [data];
  const sliced = limit > 0 ? records.slice(0, limit) : records;

  const titleNodes = currentNodes.filter(n => (n.section || 'loop') === 'title');
  const loopNodes = currentNodes.filter(n => (n.section || 'loop') !== 'title');
  const leadRecord = records[0] || {};

  const projectedTitle = titleNodes.map(n => projectNode(n, leadRecord)).filter(Boolean);
  const effectiveLoop = loopNodes.length ? loopNodes : currentNodes;

  const projectedRecords = sliced.map(record => ({
    blocks: effectiveLoop.map(n => projectNode(n, record)).filter(Boolean)
  }));

  return {
    title_blocks: projectedTitle,
    records: projectedRecords,
    columns: columns,
    limit: limit
  };
}

function renderPill(node) {
  const pill = el('div', '', 'wireframe-pill');
  pill.dataset.id = node.id;
  pill.dataset.selected = String(selected === node.id);
  pill.draggable = true;

  const icon = node.type === 'button' ? '🔘 ' : (node.type === 'link' ? '🔗 ' : (node.type === 'image' ? '🖼 ' : (node.type === 'text' ? '📝 ' : (node.type === 'container' ? '📦 ' : '📊 '))));
  const labelSpan = el('span', icon + (node.label || node.path || node.id));
  if (node.path && (node.path.includes('outcome') || node.path.includes('price'))) {
    labelSpan.className = 'wireframe-pill-code';
  }
  pill.append(labelSpan);

  const del = el('button', '×', 'pill-remove-btn');
  del.type = 'button';
  del.title = 'Remove block';
  del.onclick = (e) => {
    e.stopPropagation();
    change(() => {
      const match = locate(node.id);
      if (match) {
        match.list.splice(match.index, 1);
        if (selected === node.id) selected = '';
      }
    });
  };
  pill.append(del);

  pill.onclick = (e) => {
    e.stopPropagation();
    selected = node.id;
    if (node.type === 'container') activeContainerId = node.id;
    draw(true);
  };

  // Drag Source handlers
  pill.ondragstart = (e) => {
    e.stopPropagation();
    draggedPayload = { source: 'node', id: node.id };
    e.dataTransfer.setData('text/plain', JSON.stringify(draggedPayload));
    e.dataTransfer.effectAllowed = 'move';
    pill.classList.add('is-dragging');
  };

  pill.ondragend = () => {
    pill.classList.remove('is-dragging');
    draggedPayload = null;
    document.querySelectorAll('.drag-over').forEach(d => d.classList.remove('drag-over'));
  };

  // Reorder drop target on pill
  pill.ondragover = (e) => {
    e.preventDefault();
    e.stopPropagation();
    e.dataTransfer.dropEffect = 'move';
    pill.classList.add('drag-over');
  };

  pill.ondragleave = (e) => {
    e.stopPropagation();
    pill.classList.remove('drag-over');
  };

  pill.ondrop = (e) => {
    e.preventDefault();
    e.stopPropagation();
    pill.classList.remove('drag-over');
    if (!draggedPayload) return;

    const targetMatch = locate(node.id);
    if (!targetMatch) return;

    if (draggedPayload.source === 'node') {
      if (draggedPayload.id === node.id) return;
      moveNode(draggedPayload.id, targetMatch.parent?.id || null, node.section || 'loop', targetMatch.index);
    } else if (draggedPayload.source === 'component') {
      const newBlock = createBlock(draggedPayload.type, '', '', node.section || 'loop');
      insertBlock(newBlock, targetMatch.parent?.id || null, node.section || 'loop', targetMatch.index);
    } else if (draggedPayload.source === 'path') {
      const type = draggedPayload.kind === 'button' ? 'button' : 'field';
      const newBlock = createBlock(type, draggedPayload.path, draggedPayload.label, node.section || 'loop');
      insertBlock(newBlock, targetMatch.parent?.id || null, node.section || 'loop', targetMatch.index);
    }
  };

  return pill;
}

function renderContainerSample() {
  loopCanvas.replaceChildren();
  const loopNodes = nodes.filter(n => (n.section || 'loop') !== 'title');

  if (!loopNodes.length) {
    const emptyHint = el('div', '+ Drag components or paths here, or click "+ Add Container Row" to start.', 'drop-hint');
    loopCanvas.append(emptyHint);
  }

  for (const node of loopNodes) {
    if (node.type === 'container') {
      const containerBox = el('div', '', 'visual-container-' + (node.direction || 'row'));
      containerBox.dataset.id = node.id;
      if (activeContainerId === node.id) containerBox.classList.add('container-active-indicator');

      const tagBadge = el('span', `${node.label || 'Container'} (${node.direction || 'row'})`, 'wireframe-pill-tag');
      tagBadge.style.cursor = 'pointer';
      tagBadge.onclick = (e) => {
        e.stopPropagation();
        selected = node.id;
        activeContainerId = node.id;
        draw(true);
      };
      containerBox.append(tagBadge);

      for (const child of node.children || []) {
        containerBox.append(renderPill(child));
      }

      const addBtn = el('button', activeContainerId === node.id ? '✓ Active target' : '+ Add here', 'btn btn-secondary btn-sm');
      addBtn.type = 'button';
      addBtn.style.fontSize = '0.72rem';
      addBtn.style.padding = '2px 6px';
      addBtn.onclick = (e) => {
        e.stopPropagation();
        activeContainerId = node.id;
        selected = node.id;
        draw(true);
      };
      containerBox.append(addBtn);

      // Drag and Drop into container
      containerBox.ondragover = (e) => {
        e.preventDefault();
        e.stopPropagation();
        e.dataTransfer.dropEffect = 'copy';
        containerBox.classList.add('drag-over');
      };
      containerBox.ondragleave = (e) => {
        e.stopPropagation();
        containerBox.classList.remove('drag-over');
      };
      containerBox.ondrop = (e) => {
        e.preventDefault();
        e.stopPropagation();
        containerBox.classList.remove('drag-over');
        if (!draggedPayload) return;

        if (draggedPayload.source === 'node') {
          if (draggedPayload.id === node.id) return;
          moveNode(draggedPayload.id, node.id, 'loop');
        } else if (draggedPayload.source === 'component') {
          const newBlock = createBlock(draggedPayload.type, '', '', 'loop');
          insertBlock(newBlock, node.id, 'loop');
        } else if (draggedPayload.source === 'path') {
          const type = draggedPayload.kind === 'button' ? 'button' : 'field';
          const newBlock = createBlock(type, draggedPayload.path, draggedPayload.label, 'loop');
          insertBlock(newBlock, node.id, 'loop');
        }
      };

      loopCanvas.append(containerBox);
    } else {
      loopCanvas.append(renderPill(node));
    }
  }

  // Loop canvas drop zone
  loopCanvas.ondragover = (e) => {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'copy';
    loopCanvas.classList.add('drag-over');
  };
  loopCanvas.ondragleave = () => loopCanvas.classList.remove('drag-over');
  loopCanvas.ondrop = (e) => {
    e.preventDefault();
    loopCanvas.classList.remove('drag-over');
    if (!draggedPayload) return;
    if (draggedPayload.source === 'node') {
      moveNode(draggedPayload.id, null, 'loop');
    } else if (draggedPayload.source === 'component') {
      insertBlock(createBlock(draggedPayload.type, '', '', 'loop'), null, 'loop');
    } else if (draggedPayload.source === 'path') {
      const type = draggedPayload.kind === 'button' ? 'button' : 'field';
      insertBlock(createBlock(type, draggedPayload.path, draggedPayload.label, 'loop'), null, 'loop');
    }
  };
}

function renderTitleSection() {
  titleCanvas.replaceChildren();
  const titleNodes = nodes.filter(n => (n.section || 'loop') === 'title');

  if (!titleNodes.length) {
    const hint = el('p', 'Drop or click paths like "sport_title" here to set header.', 'seo-note');
    hint.style.margin = '4px 0';
    titleCanvas.append(hint);
  } else {
    for (const node of titleNodes) {
      titleCanvas.append(renderPill(node));
    }
  }

  const addTitleBtn = el('button', '+ Add Title Item', 'btn btn-secondary btn-sm');
  addTitleBtn.type = 'button';
  addTitleBtn.style.fontSize = '0.72rem';
  addTitleBtn.style.padding = '2px 8px';
  addTitleBtn.onclick = () => insertBlock(createBlock('field', '$.sport_title', 'sport_title', 'title'), null, 'title');
  titleCanvas.append(addTitleBtn);

  // Drag and Drop into Title Section
  titleCanvas.ondragover = (e) => {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'copy';
    titleCanvas.classList.add('drag-over');
  };
  titleCanvas.ondragleave = () => titleCanvas.classList.remove('drag-over');
  titleCanvas.ondrop = (e) => {
    e.preventDefault();
    titleCanvas.classList.remove('drag-over');
    if (!draggedPayload) return;

    if (draggedPayload.source === 'node') {
      moveNode(draggedPayload.id, null, 'title');
    } else if (draggedPayload.source === 'component') {
      insertBlock(createBlock(draggedPayload.type, '', '', 'title'), null, 'title');
    } else if (draggedPayload.source === 'path') {
      const type = draggedPayload.kind === 'button' ? 'button' : 'field';
      insertBlock(createBlock(type, draggedPayload.path, draggedPayload.label, 'title'), null, 'title');
    }
  };
}

let currentInspectedId = null;

function renderInspector(force = false) {
  const match = locate(selected);
  if (!match) {
    inspectorPanel.hidden = true;
    inspectorContent.replaceChildren();
    currentInspectedId = null;
    return;
  }

  // If already open and inspecting this exact block, keep existing DOM inputs intact so caret/focus is not lost
  if (!force && currentInspectedId === selected && !inspectorPanel.hidden) {
    return;
  }

  currentInspectedId = selected;
  inspectorPanel.hidden = false;
  inspectorContent.replaceChildren();
  const node = match.node;

  const grid = el('div', '', 'inspector-grid');

  // Display label
  const lblWrap = el('label', 'Display label:');
  const lblInput = el('input');
  lblInput.value = node.label || '';
  lblInput.oninput = () => {
    node.label = lblInput.value;
    onFieldInput();
  };
  lblInput.onchange = snapshot;
  lblWrap.append(lblInput);
  grid.append(lblWrap);

  // If text block, show explicit Text content textarea
  if (node.type === 'text') {
    const textWrap = el('label', 'Text content:');
    const textInput = el('textarea');
    textInput.rows = 2;
    textInput.value = node.text || node.label || '';
    textInput.placeholder = 'Enter text content...';
    textInput.oninput = () => {
      node.text = textInput.value;
      onFieldInput();
    };
    textInput.onchange = snapshot;
    textWrap.append(textInput);
    grid.append(textWrap);
  }

  // Path
  if (node.type === 'field' || node.type === 'button' || node.type === 'image') {
    const pathWrap = el('label', 'API path:');
    const pathInput = el('input');
    pathInput.value = node.path || '';
    pathInput.placeholder = '$.home_team or $.bookmakers[0]...';
    pathInput.oninput = () => {
      node.path = pathInput.value;
      onFieldInput();
    };
    pathInput.onchange = snapshot;
    pathWrap.append(pathInput);
    grid.append(pathWrap);
  }

  // Fallback text
  if (node.type === 'field') {
    const fbWrap = el('label', 'Fallback text:');
    const fbInput = el('input');
    fbInput.value = node.fallback || '';
    fbInput.placeholder = 'Default if data missing...';
    fbInput.oninput = () => {
      node.fallback = fbInput.value;
      onFieldInput();
    };
    fbInput.onchange = snapshot;
    fbWrap.append(fbInput);
    grid.append(fbWrap);
  }

  // Section
  const secWrap = el('label', 'Section:');
  const secSel = el('select');
  secSel.append(new Option('Loop section (Card)', 'loop'), new Option('Title Section (Header)', 'title'));
  secSel.value = node.section || 'loop';
  secSel.onchange = () => change(() => {
    node.section = secSel.value;
  }, true);
  secWrap.append(secSel);
  grid.append(secWrap);

  // Type
  const typeWrap = el('label', 'Block type:');
  const typeSel = el('select');
  for (const t of ['field', 'button', 'container', 'link', 'text', 'image']) {
    typeSel.append(new Option(t, t));
  }
  typeSel.value = node.type;
  typeSel.onchange = () => change(() => {
    node.type = typeSel.value;
    if (node.type === 'container') {
      node.children || (node.children = []);
      node.direction || (node.direction = 'row');
    }
  }, true);
  typeWrap.append(typeSel);
  grid.append(typeWrap);

  // Container direction
  if (node.type === 'container') {
    const dirWrap = el('label', 'Container layout:');
    const dirSel = el('select');
    dirSel.append(new Option('Horizontal Row', 'row'), new Option('Vertical Column', 'column'));
    dirSel.value = node.direction || 'row';
    dirSel.onchange = () => change(() => { node.direction = dirSel.value; }, true);
    dirWrap.append(dirSel);
    grid.append(dirWrap);
  }

  // Button / Link properties
  if (node.type === 'button' || node.type === 'link') {
    const styleWrap = el('label', 'Button style:');
    const styleSel = el('select');
    for (const s of ['gold', 'primary', 'brand', 'secondary', 'outline']) styleSel.append(new Option(s, s));
    styleSel.value = node.style || 'gold';
    styleSel.onchange = () => change(() => { node.style = styleSel.value; }, true);
    styleWrap.append(styleSel);
    grid.append(styleWrap);

    const urlWrap = el('label', 'URL (fixed or dynamic {$.id}):');
    const urlInput = el('input');
    urlInput.value = node.url || '';
    urlInput.placeholder = 'https://... or /bet?id={$.id}';
    urlInput.oninput = () => {
      node.url = urlInput.value;
      onFieldInput();
    };
    urlInput.onchange = snapshot;
    urlWrap.append(urlInput);
    grid.append(urlWrap);
  }

  // Image properties
  if (node.type === 'image') {
    const urlWrap = el('label', 'Image URL:');
    const urlInput = el('input');
    urlInput.value = node.url || '';
    urlInput.placeholder = 'https://... or /logo.png';
    urlInput.oninput = () => {
      node.url = urlInput.value;
      onFieldInput();
    };
    urlInput.onchange = snapshot;
    urlWrap.append(urlInput);
    grid.append(urlWrap);
  }

  // Actions
  const actions = el('div', '', 'support-actions');
  actions.style.marginTop = '12px';

  const moveLeft = el('button', '← Move before', 'btn btn-secondary btn-sm');
  moveLeft.type = 'button';
  moveLeft.onclick = () => {
    if (match.index > 0) {
      change(() => {
        match.list.splice(match.index, 1);
        match.list.splice(match.index - 1, 0, match.node);
      }, true);
    }
  };

  const moveRight = el('button', 'Move after →', 'btn btn-secondary btn-sm');
  moveRight.type = 'button';
  moveRight.onclick = () => {
    if (match.index < match.list.length - 1) {
      change(() => {
        match.list.splice(match.index, 1);
        match.list.splice(match.index + 1, 0, match.node);
      }, true);
    }
  };

  const duplicateBtn = el('button', 'Duplicate', 'btn btn-secondary btn-sm');
  duplicateBtn.type = 'button';
  duplicateBtn.onclick = () => {
    change(() => {
      const copy = JSON.parse(JSON.stringify(match.node));
      copy.id = makeId(copy.type.charAt(0));
      copy.label = copy.label + ' (copy)';
      match.list.splice(match.index + 1, 0, copy);
      selected = copy.id;
    }, true);
  };

  const removeBtn = el('button', 'Delete block', 'btn btn-danger btn-sm');
  removeBtn.type = 'button';
  removeBtn.onclick = () => change(() => {
    match.list.splice(match.index, 1);
    selected = '';
    currentInspectedId = null;
  }, true);

  actions.append(moveLeft, moveRight, duplicateBtn, removeBtn);
  inspectorContent.append(grid, actions);
}

function renderGeneratedPaths() {
  fieldsList.replaceChildren();
  const query = (fieldSearch?.value || '').toLowerCase().trim();

  // Full rich set of paths from the 2-row NBA sample
  const samplePaths = [
    { label: 'sport_title', path: '$.sport_title', kind: 'field', isTitle: true },
    { label: 'Away Team', path: '$.away_team', kind: 'field' },
    { label: 'Home Team', path: '$.home_team', kind: 'field' },
    { label: 'Game Date/Time', path: '$.commence_time', kind: 'field' },
    { label: 'Bookmaker Name', path: '$.bookmakers[0].title', kind: 'field' },
    { label: 'Away H2H Odds', path: '$.bookmakers[0].markets[0].outcomes[0].price', kind: 'button' },
    { label: 'Home H2H Odds', path: '$.bookmakers[0].markets[0].outcomes[1].price', kind: 'button' },
    { label: 'Away H2H Team', path: '$.bookmakers[0].markets[0].outcomes[0].name', kind: 'field' },
    { label: 'Home H2H Team', path: '$.bookmakers[0].markets[0].outcomes[1].name', kind: 'field' },
    { label: 'Spread Point', path: '$.bookmakers[0].markets[1].outcomes[0].point', kind: 'field' },
    { label: 'Spread Away Odds', path: '$.bookmakers[0].markets[1].outcomes[0].price', kind: 'button' },
    { label: 'Spread Home Odds', path: '$.bookmakers[0].markets[1].outcomes[1].price', kind: 'button' },
    { label: 'Total Point Line', path: '$.bookmakers[0].markets[2].outcomes[0].point', kind: 'field' },
    { label: 'Total Over Odds', path: '$.bookmakers[0].markets[2].outcomes[0].price', kind: 'button' },
    { label: 'Total Under Odds', path: '$.bookmakers[0].markets[2].outcomes[1].price', kind: 'button' },
    { label: 'Match ID', path: '$.id', kind: 'field' }
  ];

  const allItems = [...samplePaths];
  for (const f of discoveredFields) {
    if (!allItems.some(p => p.path === f.path)) {
      allItems.push({
        label: f.path.split('.').at(-1).replace(/\[\]/g, ''),
        path: f.path,
        kind: f.kind === 'array' ? 'loop' : 'field'
      });
    }
  }

  const filtered = allItems.filter(item => item.label.toLowerCase().includes(query) || item.path.toLowerCase().includes(query));

  for (const item of filtered) {
    const btn = el('button', '', 'path-pill-btn');
    btn.type = 'button';
    btn.draggable = true;

    const titleSpan = el('span', item.label);
    btn.append(titleSpan);

    const tagSpan = el('span', item.path.includes('outcome') || item.path.includes('price') ? 'price' : (item.isTitle ? 'header' : 'data'), 'path-tag');
    btn.append(tagSpan);

    // Click to add
    btn.onclick = () => {
      const isHeader = item.isTitle;
      const type = item.kind === 'button' ? 'button' : 'field';
      const targetSec = isHeader ? 'title' : 'loop';
      insertBlock(createBlock(type, item.path, item.label, targetSec), isHeader ? null : activeContainerId, targetSec);
    };

    // Drag source
    btn.ondragstart = (e) => {
      draggedPayload = {
        source: 'path',
        path: item.path,
        label: item.label,
        kind: item.kind,
        isTitle: Boolean(item.isTitle)
      };
      e.dataTransfer.setData('text/plain', JSON.stringify(draggedPayload));
      e.dataTransfer.effectAllowed = 'copy';
    };

    btn.ondragend = () => {
      draggedPayload = null;
      document.querySelectorAll('.drag-over').forEach(d => d.classList.remove('drag-over'));
    };

    fieldsList.append(btn);
  }
}

function draw(forceInspector = false) {
  renderTitleSection();
  renderContainerSample();
  renderInspector(forceInspector);
  renderGeneratedPaths();
  sync();
}

function updateLivePreview() {
  if (!previewContainer) return;

  const cols = Number(wireframeColumns?.value || columnsSelect?.value || 1);
  const limit = Number(wireframeLimit?.value || limitHidden?.value || 2);

  // Evaluate the REAL nodes against sample data or loaded API response
  const dataSource = (cachedApiResponse && cachedApiResponse.raw_data && Array.isArray(cachedApiResponse.raw_data))
    ? cachedApiResponse.raw_data
    : sampleOddsData;

  const payload = projectLayoutLocally(dataSource, nodes, cols, limit);
  renderLayout(previewContainer, payload);
}

// Setup Dragging on Sidebar Component Buttons
function setupComponentDraggables() {
  const components = [
    { id: '#btn-add-text', type: 'text', label: 'Note' },
    { id: '#btn-add-button', type: 'button', label: 'Bet Now' },
    { id: '#btn-add-container', type: 'container', label: 'Container row' },
    { id: '#btn-add-image', type: 'image', label: 'Team Logo' }
  ];

  for (const comp of components) {
    const btn = document.querySelector(comp.id);
    if (!btn) continue;
    btn.draggable = true;

    btn.onclick = () => {
      insertBlock(createBlock(comp.type, '', comp.label, 'loop'), activeContainerId, 'loop');
    };

    btn.ondragstart = (e) => {
      draggedPayload = { source: 'component', type: comp.type };
      e.dataTransfer.setData('text/plain', JSON.stringify(draggedPayload));
      e.dataTransfer.effectAllowed = 'copy';
    };

    btn.ondragend = () => {
      draggedPayload = null;
      document.querySelectorAll('.drag-over').forEach(d => d.classList.remove('drag-over'));
    };
  }
}

// Event Listeners
document.querySelector('#btn-add-sample-row')?.addEventListener('click', () => {
  insertBlock(createBlock('container', '', 'New Container Row', 'loop'), null, 'loop');
});

if (fieldSearch) fieldSearch.oninput = renderGeneratedPaths;

if (wireframeColumns) {
  wireframeColumns.onchange = () => {
    if (columnsSelect) columnsSelect.value = wireframeColumns.value;
    sync();
    updateLivePreview();
  };
}

if (wireframeLimit) {
  wireframeLimit.oninput = () => {
    if (limitHidden) limitHidden.value = wireframeLimit.value;
    sync();
    updateLivePreview();
  };
}

if (columnsSelect) {
  columnsSelect.onchange = () => {
    if (wireframeColumns) wireframeColumns.value = columnsSelect.value;
    sync();
    updateLivePreview();
  };
}

document.querySelector('#undo')?.addEventListener('click', () => {
  if (!undo.length) return;
  redo.push(JSON.stringify(nodes));
  nodes = JSON.parse(undo.pop());
  draw(true);
  updateLivePreview();
});

document.querySelector('#redo')?.addEventListener('click', () => {
  if (!redo.length) return;
  undo.push(JSON.stringify(nodes));
  nodes = JSON.parse(redo.pop());
  draw(true);
  updateLivePreview();
});

document.querySelector('#load-api')?.addEventListener('click', async () => {
  if (!form.reportValidity()) return;
  sync();
  const b = document.querySelector('#load-api');
  b.disabled = true;
  status.textContent = 'Loading API…';
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), 40000);

  try {
    const data = new FormData(form);
    data.set('action', 'preview');
    data.set('columns_per_row', wireframeColumns ? wireframeColumns.value : (columnsSelect ? columnsSelect.value : '1'));
    data.set('item_limit', wireframeLimit ? wireframeLimit.value : '2');
    const sportSel = document.querySelector('#sport-select');
    if (sportSel && sportSel.value) data.set('sport', sportSel.value);

    const response = await fetch(location.pathname, { method: 'POST', body: data, signal: controller.signal });
    const result = await response.json();
    if (!response.ok || !result.ok) throw new Error(result.error || 'Preview unavailable.');

    cachedApiResponse = result;
    discoveredFields = result.fields || [];
    renderGeneratedPaths();
    updateLivePreview();
    status.textContent = `Loaded ${discoveredFields.length} API paths. Preview updated with live data.`;
  } catch (error) {
    status.textContent = error.name === 'AbortError' ? 'API request timed out.' : error.message;
  } finally {
    clearTimeout(timeout);
    b.disabled = false;
  }
});

const sportSelect = document.querySelector('#sport-select');
if (sportSelect) {
  sportSelect.addEventListener('change', () => {
    const b = document.querySelector('#load-api');
    if (b && !b.disabled) b.click();
  });
}

document.addEventListener('submit', (event) => {
  const message = event.target?.dataset?.confirm;
  if (message && !window.confirm(message)) event.preventDefault();
});

form?.addEventListener('submit', sync);

setupComponentDraggables();
draw(true);
updateLivePreview();
