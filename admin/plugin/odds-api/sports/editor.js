import {renderLayout, isDateField} from '/admin/plugin/odds-api/public/render.js';

// Real sample output for The Odds API (2 NBA rows provided by user)
const sampleOddsData = [
  {
    "id": "26b036ff107f3c658258eaf4a6f26228",
    "sport_key": "basketball_nba",
    "sport_category": "Basketball",
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
              { "name": "Detroit Pistons", "price": 1.93, "point": 1.5 }
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
    "sport_category": "Basketball",
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
              { "name": "New York Knicks", "price": 1.96, "point": 5.5 },
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

const sampleOutrightsData = [
  {
    "id": "bfb75c30bceadf7a0e5277f59ec11eac",
    "has_outrights": true,
    "sport_key": "basketball_nba_championship_winner",
    "sport_category": "Basketball",
    "sport_title": "NBA Championship Winner",
    "commence_time": "2027-06-19T00:40:00Z",
    "home_team": null,
    "away_team": null,
    "bookmakers": [
      {
        "key": "fanduel",
        "title": "FanDuel",
        "last_update": "2026-10-08T06:05:51Z",
        "markets": [
          {
            "key": "outrights",
            "last_update": "2026-10-08T06:05:51Z",
            "outcomes": [
              { "name": "Oklahoma City Thunder", "price": 3.4 },
              { "name": "San Antonio Spurs", "price": 3.8 },
              { "name": "Philadelphia 76ers", "price": 11.0 },
              { "name": "New York Knicks", "price": 11.0 },
              { "name": "Boston Celtics", "price": 15.0 },
              { "name": "Toronto Raptors", "price": 23.0 },
              { "name": "Cleveland Cavaliers", "price": 26.0 },
              { "name": "Denver Nuggets", "price": 28.0 },
              { "name": "Detroit Pistons", "price": 28.0 },
              { "name": "Houston Rockets", "price": 28.0 },
              { "name": "Miami Heat", "price": 31.0 },
              { "name": "Minnesota Timberwolves", "price": 31.0 },
              { "name": "Atlanta Hawks", "price": 41.0 },
              { "name": "Orlando Magic", "price": 41.0 },
              { "name": "Indiana Pacers", "price": 41.0 },
              { "name": "Los Angeles Lakers", "price": 41.0 },
              { "name": "Golden State Warriors", "price": 76.0 },
              { "name": "Portland Trail Blazers", "price": 81.0 },
              { "name": "Charlotte Hornets", "price": 101.0 },
              { "name": "Utah Jazz", "price": 126.0 },
              { "name": "Phoenix Suns", "price": 126.0 },
              { "name": "Washington Wizards", "price": 176.0 },
              { "name": "Dallas Mavericks", "price": 401.0 },
              { "name": "Memphis Grizzlies", "price": 601.0 },
              { "name": "New Orleans Pelicans", "price": 751.0 },
              { "name": "Brooklyn Nets", "price": 1001.0 },
              { "name": "Milwaukee Bucks", "price": 1001.0 },
              { "name": "Los Angeles Clippers", "price": 1001.0 },
              { "name": "Sacramento Kings", "price": 1001.0 },
              { "name": "Chicago Bulls", "price": 1001.0 }
            ]
          }
        ]
      }
    ]
  }
];

function mergeBookmakers(data) {
  if (!data) return;
  const list = Array.isArray(data) ? data : [data];
  for (const item of list) {
    if (!item || !Array.isArray(item.bookmakers) || item.bookmakers.length === 0) continue;
    // Normalize points on all outcomes in all bookmakers
    for (const bm of item.bookmakers) {
      for (const m of (bm.markets || [])) {
        for (const o of (m.outcomes || [])) {
          if (o.point !== undefined && o.point !== null && !isNaN(Number(o.point)) && Number(o.point) < 0) {
            o.point = Math.abs(Number(o.point));
          }
        }
      }
    }
    if (item.bookmakers.length > 1) {
      const primary = item.bookmakers[0];
      const allMarketKeys = [];
      for (const bm of item.bookmakers) {
        for (const m of (bm.markets || [])) {
          if (m.key && !allMarketKeys.includes(m.key)) allMarketKeys.push(m.key);
        }
      }
      const primaryMarketMap = {};
      for (const m of (primary.markets || [])) {
        if (m.key) primaryMarketMap[m.key] = m;
      }
      for (const mKey of allMarketKeys) {
        if (!primaryMarketMap[mKey]) {
          for (let i = 1; i < item.bookmakers.length; i++) {
            const other = item.bookmakers[i];
            const found = (other.markets || []).find(m => m.key === mKey);
            if (found) {
              const cloned = JSON.parse(JSON.stringify(found));
              primaryMarketMap[mKey] = cloned;
              break;
            }
          }
        } else {
          const pm = primaryMarketMap[mKey];
          const outcomesMap = {};
          (pm.outcomes || []).forEach((o, idx) => {
            if (o.name) outcomesMap[o.name.trim().toLowerCase()] = idx;
          });
          for (let i = 1; i < item.bookmakers.length; i++) {
            const other = item.bookmakers[i];
            const found = (other.markets || []).find(m => m.key === mKey);
            if (found && Array.isArray(found.outcomes)) {
              for (const oo of found.outcomes) {
                const nameKey = (oo.name || '').trim().toLowerCase();
                if (!nameKey) continue;
                if (outcomesMap[nameKey] === undefined) {
                  const clonedOutcome = JSON.parse(JSON.stringify(oo));
                  if (clonedOutcome.point !== undefined && !isNaN(Number(clonedOutcome.point)) && Number(clonedOutcome.point) < 0) {
                    clonedOutcome.point = Math.abs(Number(clonedOutcome.point));
                  }
                  pm.outcomes.push(clonedOutcome);
                  outcomesMap[nameKey] = pm.outcomes.length - 1;
                } else {
                  const targetIdx = outcomesMap[nameKey];
                  const cur = pm.outcomes[targetIdx];
                  if ((cur.price === null || cur.price === undefined || cur.price === '' || cur.price === 0) && oo.price) {
                    cur.price = oo.price;
                  }
                  if ((cur.point === null || cur.point === undefined || cur.point === '') && oo.point !== undefined) {
                    cur.point = Math.abs(Number(oo.point));
                  }
                }
              }
            }
          }
        }
      }
      const priority = { h2h: 1, spreads: 2, totals: 3, outrights: 4 };
      const sortedKeys = Object.keys(primaryMarketMap).sort((a, b) => (priority[a] || 99) - (priority[b] || 99));
      primary.markets = sortedKeys.map(k => primaryMarketMap[k]);
      for (const [k, m] of Object.entries(primaryMarketMap)) {
        primary[k] = m;
      }
    }
  }
}

mergeBookmakers(sampleOddsData);
mergeBookmakers(sampleOutrightsData);

function getActiveSampleData() {
  let data;
  if (cachedApiResponse && cachedApiResponse.raw_data && Array.isArray(cachedApiResponse.raw_data) && cachedApiResponse.raw_data.length) {
    data = cachedApiResponse.raw_data;
  } else {
    const marketsVal = document.querySelector('input[name="markets"]')?.value || '';
    const sportVal = document.querySelector('#sport-select')?.value || '';
    const hasOutrightsLoop = nodes.some(n => n.type === 'loop' || (n.path && n.path.includes('outcomes')));
    const isOutrights = marketsVal.includes('outrights') || sportVal.includes('championship') || sportVal.includes('winner') || hasOutrightsLoop;
    data = isOutrights ? sampleOutrightsData : sampleOddsData;
  }
  mergeBookmakers(data);
  return data;
}

const defaultImage = JSON.parse(document.querySelector('#default-image-data')?.textContent || '""');
let registeredSportsMap = {};
let registeredTeamsMap = {};
try {
  registeredSportsMap = JSON.parse(document.querySelector('#registered-sports-data')?.textContent || '{}');
} catch (_) {}
try {
  registeredTeamsMap = JSON.parse(document.querySelector('#registered-teams-data')?.textContent || '{}');
} catch (_) {}

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
// Keep the public theme and Tailwind reset out of the admin document.
const previewHost = document.querySelector('#layout-preview');
const previewContainer = previewHost ? document.createElement('div') : null;
if (previewHost) {
  const shadow = previewHost.attachShadow({mode: 'open'});
  for (const file of ['index-Iycxdcl7.css', 'tailwind.min.css', 'layout.css']) {
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = '/admin/plugin/odds-api/public/' + file;
    shadow.append(link);
  }
  previewContainer.className = 'sports-preview-theme text-white antialiased';
  shadow.append(previewContainer);
}
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

// Sync initial columns from loop if present
const existingLoop = nodes.find(n => n.type === 'loop') || nodes.flatMap(n => n.children || []).find(c => c && c.type === 'loop');
if (existingLoop && existingLoop.columns) {
  if (wireframeColumns) wireframeColumns.value = String(existingLoop.columns);
  if (columnsSelect) columnsSelect.value = String(existingLoop.columns);
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

function createBlock(type, path = '', label = '', section = 'loop', imageSource = 'custom') {
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
    return { ...base, url: '', style: 'gold', target: '_blank', align: 'center', width: 'auto', btn_width: '' };
  }
  if (type === 'text') {
    return { ...base, text: cleanLabel, role: section === 'title' ? 'title' : 'description' };
  }
  if (type === 'image') {
    return { ...base, url: '', alt: cleanLabel, width: 'auto', image_width: '48px', height: '', align: section === 'title' ? 'center' : 'left', image_source: imageSource };
  }
  return {
    ...base,
    role: section === 'title' ? 'title' : 'data',
    format: isDateField(path, cleanLabel) ? 'datetime' : 'text',
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
      if (match && (match.node.type === 'container' || match.node.type === 'loop')) {
        const targetList = match.node.children || (match.node.children = []);
        if (insertIndex >= 0) targetList.splice(insertIndex, 0, block);
        else targetList.push(block);
        selected = block.id;
        return;
      }
    }

    // Default loop insertion: into active container or last container
    const loopContainers = nodes.filter(n => (n.type === 'container' || n.type === 'loop') && (n.section || 'loop') === 'loop');
    if (loopContainers.length && block.type !== 'container' && block.type !== 'loop') {
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
      if (targetMatch && (targetMatch.node.type === 'container' || targetMatch.node.type === 'loop')) {
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
  if (path === '$.sport_category' || path === 'sport_category') {
    if (obj.sport_category) return obj.sport_category;
    const sportKey = obj.sport_key || document.querySelector('#sport-select')?.value || '';
    if (sportKey) {
      const reg = registeredSportsMap[sportKey] || registeredSportsMap[sportKey.split('_')[0]];
      if (reg && reg.name) return reg.name;
    }
    return 'Basketball';
  }

  // If resolving inside a loop item context
  if (obj._loopItem) {
    const relProp = path.replace(/^\$\.?/, '');
    if (obj._loopItem[relProp] !== undefined) return obj._loopItem[relProp];
    if (path.endsWith('.name') && obj._loopItem.name !== undefined) return obj._loopItem.name;
    if (path.endsWith('.price') && obj._loopItem.price !== undefined) return obj._loopItem.price;
    if (path.endsWith('.point') && obj._loopItem.point !== undefined) return obj._loopItem.point;
  }

  const clean = path.replace(/^\$\.?/, '');
  const tokens = clean.match(/[^.\[\]]+|\[\d+\]/g) || [];
  let current = obj;
  for (const token of tokens) {
    if (current === null || current === undefined) return null;
    const isIndex = token.startsWith('[');
    const key = isIndex ? parseInt(token.slice(1, -1), 10) : token;
    if (!isIndex && Array.isArray(current)) {
      current = current[0] ? current[0][key] : null;
    } else {
      current = current[key];
    }
  }
  return current;
}

function projectNode(node, record) {
  if (node.visible === false) return null;
  let raw = node.path ? resolvePath(record, node.path) : null;
  if ((node.path?.endsWith('.point') || node.path?.endsWith('point')) && raw !== null && raw !== undefined && !isNaN(Number(raw)) && Number(raw) < 0) {
    raw = Math.abs(Number(raw));
  }
  let val = raw !== null && raw !== undefined && raw !== '' ? raw : (node.fallback || node.label || '');
  if ((node.path?.endsWith('.point') || node.path?.endsWith('point')) && val !== null && val !== undefined && !isNaN(Number(val)) && Number(val) < 0) {
    val = Math.abs(Number(val));
  }

  if (node.type === 'container') {
    return {
      type: 'container',
      direction: node.direction || 'row',
      label: node.label,
      width: node.width || '',
      align: node.align || '',
      blocks: (node.children || []).map(child => projectNode(child, record)).filter(Boolean)
    };
  }
  if (node.type === 'loop') {
    let loopItems = [];
    if (node.path) {
      const resolvedArray = resolvePath(record, node.path);
      if (Array.isArray(resolvedArray)) {
        loopItems = resolvedArray;
      }
    }
    return {
      type: 'loop',
      path: node.path,
      label: node.label,
      columns: node.columns || 3,
      direction: node.direction || 'grid',
      width: node.width || '',
      items: loopItems.map(item => {
        const itemRecord = { ...record, ...item, _loopItem: item };
        return {
          blocks: (node.children || []).map(child => projectNode(child, itemRecord)).filter(Boolean)
        };
      })
    };
  }
  if (node.type === 'button') {
    let btnLabel = raw !== null && raw !== undefined && raw !== '' ? String(raw) : (node.label || 'Bet');
    if ((node.path?.endsWith('.point') || node.path?.endsWith('point')) && !isNaN(Number(btnLabel)) && Number(btnLabel) < 0) {
      btnLabel = String(Math.abs(Number(btnLabel)));
    }
    return {
      type: 'button',
      label: btnLabel,
      url: node.url || '',
      style: node.style || 'gold',
      target: node.target || '_blank',
      width: node.width || '',
      btn_width: node.btn_width || '',
      align: node.align || 'center'
    };
  }
  if (node.type === 'link') {
    return {
      type: 'link',
      label: raw !== null && raw !== undefined && raw !== '' ? String(raw) : (node.label || 'Link'),
      url: node.url || '',
      target: node.target || '_blank',
      width: node.width || '',
      align: node.align || 'left'
    };
  }
  if (node.type === 'text') {
    return {
      type: 'text',
      role: node.role || (node.section === 'title' ? 'title' : 'description'),
      value: node.text || node.label || String(val),
      width: node.width || '',
      align: node.align || 'left'
    };
  }
  if (node.type === 'image') {
    const sourceMode = node.image_source || 'custom';
    let imgUrl = '';

    const currentSportVal = document.querySelector('#sport-select')?.value || record.sport_key || '';

    if (sourceMode === 'sport' || sourceMode === 'sport_image') {
      const spKey = (currentSportVal || '').toLowerCase();
      const spPrefix = spKey.split('_')[0];
      const foundSport = registeredSportsMap[spKey] || registeredSportsMap[spPrefix] || Object.values(registeredSportsMap).find(s => spKey.includes(s.slug));
      if (foundSport && (foundSport.image_url || foundSport.image_path)) {
        imgUrl = foundSport.image_url || foundSport.image_path;
      }
    } else if (sourceMode === 'home_team' || sourceMode === 'home_team_image') {
      const homeName = record.home_team || (record.home && record.home.name) || '';
      if (homeName) {
        const slug = homeName.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
        imgUrl = registeredTeamsMap[slug] || registeredTeamsMap[homeName.toLowerCase()] || '';
      }
    } else if (sourceMode === 'away_team' || sourceMode === 'away_team_image') {
      const awayName = record.away_team || (record.away && record.away.name) || '';
      if (awayName) {
        const slug = awayName.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
        imgUrl = registeredTeamsMap[slug] || registeredTeamsMap[awayName.toLowerCase()] || '';
      }
    } else if (sourceMode === 'team' || sourceMode === 'team_image') {
      const teamIdent = raw || (node.path ? resolvePath(record, node.path) : '') || record.name || '';
      if (teamIdent) {
        const slug = String(teamIdent).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
        imgUrl = registeredTeamsMap[slug] || registeredTeamsMap[String(teamIdent).toLowerCase()] || '';
      }
    }

    if (!imgUrl) {
      imgUrl = sourceMode === 'custom' && raw ? String(raw) : (node.url || '');
    }

    return {
      type: 'image',
      url: imgUrl || defaultImage,
      fallback_url: defaultImage,
      alt: node.alt || node.label || '',
      width: node.width || '',
      image_width: node.image_width || (node.width && !['100%', '50%', '33.3%', '25%'].includes(node.width) ? node.width : (sourceMode === 'team' || sourceMode === 'team_image' ? '36px' : '48px')),
      height: node.height || '',
      image_height: node.image_height || node.height || '',
      image_source: sourceMode,
      align: node.align || (node.section === 'loop' ? 'left' : 'center')
    };
  }
  return {
    type: 'field',
    label: node.label,
    path: node.path || '',
    value: val,
    format: node.format || (isDateField(node.path, node.label) ? 'datetime' : 'text'),
    role: node.role || (node.section === 'title' ? 'title' : 'data'),
    url: node.url || '',
    width: node.width || '',
    align: node.align || 'left'
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
  const w = node.width || 'equal';
  pill.dataset.width = w;
  if (w === '100%') pill.classList.add('w-full', 'flex-none');
  else if (w === '50%') pill.classList.add('w-1/2', 'flex-none');
  else if (w === '33.3%' || w === '33%') pill.classList.add('w-1/3', 'flex-none');
  else if (w === '66.7%' || w === '66%') pill.classList.add('w-2/3', 'flex-none');
  else if (w === '25%') pill.classList.add('w-1/4', 'flex-none');
  else if (w === '75%') pill.classList.add('w-3/4', 'flex-none');
  else if (w === 'equal') pill.classList.add('flex-1', 'min-w-0');
  else if (w === 'auto') pill.classList.add('w-auto', 'flex-initial');

  const match = locate(node.id);

  // Left reorder button
  const leftBtn = el('button', '←', 'pill-reorder-btn');
  leftBtn.type = 'button';
  leftBtn.title = 'Move Left / Up';
  leftBtn.disabled = !match || match.index === 0;
  leftBtn.onclick = (e) => {
    e.stopPropagation();
    if (match && match.index > 0) {
      change(() => {
        match.list.splice(match.index, 1);
        match.list.splice(match.index - 1, 0, node);
      }, true);
    }
  };
  pill.append(leftBtn);

  // Drag handle
  const handle = el('span', '⋮⋮', 'cursor-grab text-slate-400 font-mono text-xs');
  pill.append(handle);

  const icon = node.type === 'button' ? '🔘 ' : (node.type === 'link' ? '🔗 ' : (node.type === 'image' ? '🖼 ' : (node.type === 'text' ? '📝 ' : (node.type === 'container' ? '📦 ' : '📊 '))));
  const labelSpan = el('span', icon + (node.label || node.path || node.id));
  if (node.path && (node.path.includes('outcome') || node.path.includes('price'))) {
    labelSpan.className = 'wireframe-pill-code';
  }
  pill.append(labelSpan);

  // Image size & align badges
  if (node.type === 'image') {
    const imgSizeVal = node.image_width || (node.width && !['100%', '50%', '33.3%', '25%'].includes(node.width) ? node.width : '48px');
    const imgBadge = el('span', `🖼 ${imgSizeVal}`, 'wireframe-width-badge');
    imgBadge.title = 'Image size: ' + imgSizeVal;
    pill.append(imgBadge);
    if (node.align) {
      const alignBadge = el('span', node.align, 'wireframe-width-badge');
      alignBadge.title = 'Image align: ' + node.align;
      pill.append(alignBadge);
    }
  }

  // Button align & width badges
  if (node.type === 'button') {
    if (node.align && node.align !== 'center') {
      const alignBadge = el('span', node.align, 'wireframe-width-badge');
      alignBadge.title = 'Button align: ' + node.align;
      pill.append(alignBadge);
    }
    if (node.btn_width) {
      const bwBadge = el('span', node.btn_width, 'wireframe-width-badge');
      bwBadge.title = 'Button width: ' + node.btn_width;
      pill.append(bwBadge);
    }
  }

  // Field / Text align badge
  if ((node.type === 'field' || node.type === 'text') && node.align && node.align !== 'left') {
    const alignBadge = el('span', node.align, 'wireframe-width-badge');
    alignBadge.title = 'Text align: ' + node.align;
    pill.append(alignBadge);
  }

  // Width badge
  if (w && w !== 'equal') {
    const widthBadge = el('span', w, 'wireframe-width-badge');
    widthBadge.title = 'Column width: ' + w;
    pill.append(widthBadge);
  }

  // Right reorder button
  const rightBtn = el('button', '→', 'pill-reorder-btn');
  rightBtn.type = 'button';
  rightBtn.title = 'Move Right / Down';
  rightBtn.disabled = !match || match.index >= match.list.length - 1;
  rightBtn.onclick = (e) => {
    e.stopPropagation();
    if (match && match.index < match.list.length - 1) {
      change(() => {
        match.list.splice(match.index, 1);
        match.list.splice(match.index + 1, 0, node);
      }, true);
    }
  };
  pill.append(rightBtn);

  // Remove button
  const del = el('button', '×', 'pill-remove-btn');
  del.type = 'button';
  del.title = 'Remove block';
  del.onclick = (e) => {
    e.stopPropagation();
    change(() => {
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

  function renderContainerNode(containerNode) {
    const isLoop = containerNode.type === 'loop';
    const boxClass = isLoop ? 'visual-loop-box' : ('visual-container-' + (containerNode.direction || 'row'));
    const containerBox = el('div', '', boxClass);
    containerBox.dataset.id = containerNode.id;
    if (activeContainerId === containerNode.id) containerBox.classList.add('container-active-indicator');

    const match = locate(containerNode.id);

    // WordPress-style header toolbar for rows and loops
    const header = el('div', '', 'wp-row-header');

    // Left: Drag handle, Up/Down reorder, Icon & Label
    const leftWrap = el('div', '', 'flex items-center gap-1.5 flex-wrap');

    const handle = el('span', '⋮⋮', 'cursor-grab text-slate-400 font-mono text-xs');
    leftWrap.append(handle);

    const upBtn = el('button', '↑', 'wp-tool-btn');
    upBtn.type = 'button';
    upBtn.title = 'Move Row Up';
    upBtn.disabled = !match || match.index === 0;
    upBtn.onclick = (e) => {
      e.stopPropagation();
      if (match && match.index > 0) {
        change(() => {
          match.list.splice(match.index, 1);
          match.list.splice(match.index - 1, 0, containerNode);
        }, true);
      }
    };

    const downBtn = el('button', '↓', 'wp-tool-btn');
    downBtn.type = 'button';
    downBtn.title = 'Move Row Down';
    downBtn.disabled = !match || match.index >= match.list.length - 1;
    downBtn.onclick = (e) => {
      e.stopPropagation();
      if (match && match.index < match.list.length - 1) {
        change(() => {
          match.list.splice(match.index, 1);
          match.list.splice(match.index + 1, 0, containerNode);
        }, true);
      }
    };
    leftWrap.append(upBtn, downBtn);

    const prefixIcon = isLoop ? '🔁 ' : '📦 ';
    const descText = isLoop ? `${containerNode.label || 'Loop'} (${containerNode.columns || 3} cols)` : `${containerNode.label || 'Row'} (${containerNode.direction || 'row'})`;
    const tagBadge = el('span', `${prefixIcon}${descText}`, 'wireframe-pill-tag');
    tagBadge.style.cursor = 'pointer';
    tagBadge.onclick = (e) => {
      e.stopPropagation();
      selected = containerNode.id;
      activeContainerId = containerNode.id;
      draw(true);
    };
    leftWrap.append(tagBadge);
    header.append(leftWrap);

    // Center: Quick Column Width Distribution Presets (like WordPress Columns Block)
    if (!isLoop && (containerNode.direction || 'row') === 'row' && (containerNode.children || []).length > 1) {
      const presetsWrap = el('div', '', 'flex items-center gap-1');
      const pLabel = el('span', 'Cols:', 'text-slate-400 text-xs hidden sm:inline');
      presetsWrap.append(pLabel);

      const presets = [
        { label: '50:50', widths: ['50%', '50%'], count: 2 },
        { label: '33:33:33', widths: ['33.3%', '33.3%', '33.3%'], count: 3 },
        { label: '70:30', widths: ['70%', '30%'], count: 2 },
        { label: '30:70', widths: ['30%', '70%'], count: 2 },
        { label: '25:50:25', widths: ['25%', '50%', '25%'], count: 3 },
        { label: 'Equal', widths: null, count: 0 },
        { label: 'Auto', widths: 'auto', count: 0 }
      ];

      for (const p of presets) {
        if (p.count > 0 && containerNode.children.length !== p.count) continue;
        const pBtn = el('button', p.label, 'col-preset-btn');
        pBtn.type = 'button';
        pBtn.title = 'Apply ' + p.label + ' column distribution';
        pBtn.onclick = (e) => {
          e.stopPropagation();
          change(() => {
            if (p.widths === 'auto') {
              containerNode.children.forEach(c => c.width = 'auto');
            } else if (p.widths === null) {
              containerNode.children.forEach(c => c.width = 'equal');
            } else {
              containerNode.children.forEach((c, idx) => {
                c.width = p.widths[idx] || 'equal';
              });
            }
          }, true);
        };
        presetsWrap.append(pBtn);
      }
      header.append(presetsWrap);
    }

    // Right: Actions (+ Add, Duplicate, Delete)
    const rightWrap = el('div', '', 'flex items-center gap-1');

    const addBtn = el('button', activeContainerId === containerNode.id ? '✓ Active target' : (isLoop ? '+ Add Row' : '+ Add'), 'wp-tool-btn');
    addBtn.type = 'button';
    addBtn.style.color = '#fbbf24';
    addBtn.onclick = (e) => {
      e.stopPropagation();
      activeContainerId = containerNode.id;
      selected = containerNode.id;
      draw(true);
    };

    const dupBtn = el('button', '⎘', 'wp-tool-btn');
    dupBtn.type = 'button';
    dupBtn.title = 'Duplicate Row';
    dupBtn.onclick = (e) => {
      e.stopPropagation();
      change(() => {
        const copy = JSON.parse(JSON.stringify(containerNode));
        function reId(n) {
          n.id = makeId(n.type.charAt(0));
          if (n.children) n.children.forEach(reId);
        }
        reId(copy);
        copy.label = (containerNode.label || 'Row') + ' (copy)';
        if (match) match.list.splice(match.index + 1, 0, copy);
      }, true);
    };

    const delBtn = el('button', '🗑', 'wp-tool-btn');
    delBtn.type = 'button';
    delBtn.title = 'Delete Row';
    delBtn.style.color = '#ef4444';
    delBtn.onclick = (e) => {
      e.stopPropagation();
      change(() => {
        if (match) {
          match.list.splice(match.index, 1);
          if (selected === containerNode.id) selected = '';
          if (activeContainerId === containerNode.id) activeContainerId = null;
        }
      }, true);
    };

    rightWrap.append(addBtn, dupBtn, delBtn);
    header.append(rightWrap);
    containerBox.append(header);

    // Children
    for (const child of containerNode.children || []) {
      if (child.type === 'container' || child.type === 'loop') {
        containerBox.append(renderContainerNode(child));
      } else {
        containerBox.append(renderPill(child));
      }
    }

    // Drag and Drop into container or loop
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
        if (draggedPayload.id === containerNode.id) return;
        moveNode(draggedPayload.id, containerNode.id, 'loop');
      } else if (draggedPayload.source === 'component') {
        const newBlock = createBlock(draggedPayload.type, draggedPayload.path || '', draggedPayload.label || '', 'loop', draggedPayload.imageSource || 'custom');
        insertBlock(newBlock, containerNode.id, 'loop');
      } else if (draggedPayload.source === 'path') {
        const type = draggedPayload.kind === 'button' ? 'button' : 'field';
        const newBlock = createBlock(type, draggedPayload.path, draggedPayload.label, 'loop');
        insertBlock(newBlock, containerNode.id, 'loop');
      }
    };

    return containerBox;
  }

  for (const node of loopNodes) {
    if (node.type === 'container' || node.type === 'loop') {
      loopCanvas.append(renderContainerNode(node));
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
      insertBlock(createBlock(draggedPayload.type, draggedPayload.path || '', draggedPayload.label || '', 'loop', draggedPayload.imageSource || 'custom'), null, 'loop');
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
      insertBlock(createBlock(draggedPayload.type, draggedPayload.path || '', draggedPayload.label || '', 'title', draggedPayload.imageSource || 'custom'), null, 'title');
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

  // Data format (for fields - date & time, date only, decimal, text)
  if (node.type === 'field') {
    const fmtWrap = el('label', 'Data Format:');
    const fmtSel = el('select');
    if (isDateField(node.path, node.label)) {
      fmtSel.append(
        new Option('Date & Time (e.g. December 13, 2026, 7:00 PM)', 'datetime'),
        new Option('Date Only (e.g. December 13, 2026)', 'date_only')
      );
      fmtSel.value = node.format === 'date_only' ? 'date_only' : 'datetime';
    } else {
      fmtSel.append(
        new Option('Text (Raw / Default)', 'text'),
        new Option('Decimal Number (e.g. 1.95)', 'decimal')
      );
      fmtSel.value = node.format === 'decimal' ? 'decimal' : 'text';
    }
    fmtSel.onchange = () => change(() => {
      node.format = fmtSel.value;
    }, true);
    fmtWrap.append(fmtSel);
    grid.append(fmtWrap);
  }

  // Text Alignment (for field, text, and link)
  if (node.type === 'field' || node.type === 'text' || node.type === 'link') {
    const alignWrap = el('label', 'Text Alignment:');
    const alignChips = el('div', '', 'width-presets-row');
    const currentAlign = node.align || 'left';
    const alignPresets = [
      { label: 'Left', val: 'left' },
      { label: 'Center', val: 'center' },
      { label: 'Right', val: 'right' }
    ];
    for (const opt of alignPresets) {
      const chip = el('button', opt.label, 'width-preset-chip');
      chip.type = 'button';
      chip.dataset.active = String(currentAlign === opt.val);
      chip.onclick = () => change(() => {
        node.align = opt.val;
      }, true);
      alignChips.append(chip);
    }
    alignWrap.append(alignChips);
    grid.append(alignWrap);
  }

  // Column Width Control (for all blocks: field, button, link, image, text, container)
  const widthWrap = el('label', 'Column Width:');
  const widthChips = el('div', '', 'width-presets-row');
  const currentW = node.width || 'equal';

  const widthOptions = [
    { label: 'Auto', val: 'auto' },
    { label: 'Equal (1fr)', val: 'equal' },
    { label: '25% (1/4)', val: '25%' },
    { label: '33.3% (1/3)', val: '33.3%' },
    { label: '50% (1/2)', val: '50%' },
    { label: '66.7% (2/3)', val: '66.7%' },
    { label: '75% (3/4)', val: '75%' },
    { label: '100% (Full)', val: '100%' }
  ];

  for (const opt of widthOptions) {
    const chip = el('button', opt.label, 'width-preset-chip');
    chip.type = 'button';
    chip.dataset.active = String(currentW === opt.val);
    chip.onclick = () => change(() => {
      node.width = opt.val;
    }, true);
    widthChips.append(chip);
  }

  const customInput = el('input');
  customInput.placeholder = 'Custom width, e.g. 120px or 40%';
  customInput.value = ['auto', 'equal', '25%', '33.3%', '50%', '66.7%', '75%', '100%'].includes(currentW) ? '' : (node.width || '');
  customInput.oninput = () => {
    node.width = customInput.value.trim();
    onFieldInput();
  };
  customInput.onchange = snapshot;
  widthWrap.append(widthChips, customInput);
  grid.append(widthWrap);

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
  for (const t of ['field', 'button', 'container', 'loop', 'link', 'text', 'image']) {
    typeSel.append(new Option(t, t));
  }
  typeSel.value = node.type;
  typeSel.onchange = () => change(() => {
    node.type = typeSel.value;
    if (node.type === 'container') {
      node.children || (node.children = []);
      node.direction || (node.direction = 'row');
    } else if (node.type === 'loop') {
      node.children || (node.children = []);
      node.columns = node.columns || 3;
      node.direction || (node.direction = 'grid');
    }
  }, true);
  typeWrap.append(typeSel);
  grid.append(typeWrap);

  // Container direction and column presets
  if (node.type === 'container') {
    const dirWrap = el('label', 'Container layout:');
    const dirSel = el('select');
    dirSel.append(new Option('Horizontal Row', 'row'), new Option('Vertical Column', 'column'));
    dirSel.value = node.direction || 'row';
    dirSel.onchange = () => change(() => { node.direction = dirSel.value; }, true);
    dirWrap.append(dirSel);
    grid.append(dirWrap);

    if ((node.direction || 'row') === 'row' && (node.children || []).length > 1) {
      const cPresetsWrap = el('label', 'Row Column Presets:');
      const pRow = el('div', '', 'width-presets-row');
      const pList = [
        { label: '50% / 50%', widths: ['50%', '50%'], count: 2 },
        { label: '33% / 33% / 33%', widths: ['33.3%', '33.3%', '33.3%'], count: 3 },
        { label: '70% / 30%', widths: ['70%', '30%'], count: 2 },
        { label: '30% / 70%', widths: ['30%', '70%'], count: 2 },
        { label: '25% / 50% / 25%', widths: ['25%', '50%', '25%'], count: 3 },
        { label: 'Equal (1fr)', widths: null },
        { label: 'Auto (Fit)', widths: 'auto' }
      ];
      for (const p of pList) {
        if (p.count && node.children.length !== p.count) continue;
        const pBtn = el('button', p.label, 'width-preset-chip');
        pBtn.type = 'button';
        pBtn.onclick = () => change(() => {
          if (p.widths === 'auto') {
            (node.children || []).forEach(c => c.width = 'auto');
          } else if (p.widths === null) {
            (node.children || []).forEach(c => c.width = 'equal');
          } else {
            (node.children || []).forEach((c, idx) => {
              c.width = p.widths[idx] || 'equal';
            });
          }
        }, true);
        pRow.append(pBtn);
      }
      cPresetsWrap.append(pRow);
      grid.append(cPresetsWrap);
    }
  }

  // Loop columns and display style
  if (node.type === 'loop') {
    const colsWrap = el('label', 'Loop columns:');
    const colsSel = el('select');
    colsSel.append(
      new Option('1 Column', '1'),
      new Option('2 Columns', '2'),
      new Option('3 Columns', '3')
    );
    colsSel.value = String(node.columns || 3);
    colsSel.onchange = () => change(() => {
      node.columns = Number(colsSel.value);
      if (wireframeColumns) wireframeColumns.value = String(node.columns);
      if (columnsSelect) columnsSelect.value = String(node.columns);
    }, true);
    colsWrap.append(colsSel);
    grid.append(colsWrap);

    const dirWrap = el('label', 'Display style:');
    const dirSel = el('select');
    dirSel.append(new Option('Grid', 'grid'), new Option('Horizontal Row', 'row'));
    dirSel.value = node.direction || 'grid';
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

    if (node.type === 'button') {
      const alignWrap = el('label', 'Button Alignment:');
      const alignChips = el('div', '', 'width-presets-row');
      const currentAlign = node.align || 'center';
      const alignPresets = [
        { label: 'Left', val: 'left' },
        { label: 'Center', val: 'center' },
        { label: 'Right', val: 'right' },
        { label: 'Justify (Full)', val: 'justify' }
      ];
      for (const opt of alignPresets) {
        const chip = el('button', opt.label, 'width-preset-chip');
        chip.type = 'button';
        chip.dataset.active = String(currentAlign === opt.val);
        chip.onclick = () => change(() => {
          node.align = opt.val;
        }, true);
        alignChips.append(chip);
      }
      alignWrap.append(alignChips);
      grid.append(alignWrap);

      const btnWidthWrap = el('label', 'Button Size / Width:');
      const btnWidthChips = el('div', '', 'width-presets-row');
      const currentBtnW = node.btn_width || 'auto';
      const btnWidthPresets = [
        { label: 'Auto', val: 'auto' },
        { label: '80px', val: '80px' },
        { label: '100px', val: '100px' },
        { label: '120px', val: '120px' },
        { label: '160px', val: '160px' },
        { label: 'Full', val: '100%' }
      ];
      for (const opt of btnWidthPresets) {
        const chip = el('button', opt.label, 'width-preset-chip');
        chip.type = 'button';
        chip.dataset.active = String((currentBtnW === opt.val) || (!node.btn_width && opt.val === 'auto'));
        chip.onclick = () => change(() => {
          node.btn_width = opt.val === 'auto' ? '' : opt.val;
        }, true);
        btnWidthChips.append(chip);
      }
      btnWidthWrap.append(btnWidthChips);

      const customBtnWidthInput = el('input');
      customBtnWidthInput.style.marginTop = '6px';
      customBtnWidthInput.value = node.btn_width || '';
      customBtnWidthInput.placeholder = 'Custom button width e.g. 90px, 140px, 100%';
      customBtnWidthInput.oninput = () => {
        node.btn_width = customBtnWidthInput.value.trim();
        onFieldInput();
      };
      customBtnWidthInput.onchange = snapshot;
      btnWidthWrap.append(customBtnWidthInput);
      grid.append(btnWidthWrap);
    }
  }

  // Image properties
  if (node.type === 'image') {
    const alignWrap = el('label', 'Image Alignment:');
    const alignChips = el('div', '', 'width-presets-row');
    const currentImgAlign = node.align || (node.section === 'loop' ? 'left' : 'center');
    const alignPresets = [
      { label: 'Left', val: 'left' },
      { label: 'Center', val: 'center' },
      { label: 'Right', val: 'right' }
    ];
    for (const opt of alignPresets) {
      const chip = el('button', opt.label, 'width-preset-chip');
      chip.type = 'button';
      chip.dataset.active = String(currentImgAlign === opt.val);
      chip.onclick = () => change(() => {
        node.align = opt.val;
      }, true);
      alignChips.append(chip);
    }
    alignWrap.append(alignChips);
    grid.append(alignWrap);

    const srcWrap = el('label', 'Image source:');
    const srcSel = el('select');
    srcSel.append(
      new Option('Sport Image ({slug}_sports)', 'sport'),
      new Option('Home Team Logo', 'home_team'),
      new Option('Away Team Logo', 'away_team'),
      new Option('Team Logo from Path', 'team'),
      new Option('Custom URL', 'custom')
    );
    srcSel.value = node.image_source || 'custom';
    srcSel.onchange = () => change(() => {
      node.image_source = srcSel.value;
      if (node.image_source === 'sport' && !node.path) node.path = '$.sport_title';
      if (node.image_source === 'home_team' && !node.path) node.path = '$.home_team';
      if (node.image_source === 'away_team' && !node.path) node.path = '$.away_team';
    }, true);
    srcWrap.append(srcSel);
    grid.append(srcWrap);

    const urlWrap = el('label', 'Custom / Fallback Image URL:');
    const urlInput = el('input');
    urlInput.value = node.url || '';
    urlInput.placeholder = 'https://... or /uploads/...';
    urlInput.oninput = () => {
      node.url = urlInput.value;
      onFieldInput();
    };
    urlInput.onchange = snapshot;
    urlWrap.append(urlInput);
    grid.append(urlWrap);

    const imgSizeWrap = el('label', 'Image Display Width / Size:');
    const imgSizeChips = el('div', '', 'width-presets-row');
    const currentImgW = node.image_width || (node.width && !['100%', '50%', '33.3%', '25%'].includes(node.width) ? node.width : '48px');
    const imgSizePresets = [
      { label: '24px', val: '24px' },
      { label: '32px', val: '32px' },
      { label: '40px', val: '40px' },
      { label: '48px', val: '48px' },
      { label: '64px', val: '64px' },
      { label: '80px', val: '80px' },
      { label: '120px', val: '120px' },
      { label: 'Full', val: '100%' }
    ];
    for (const opt of imgSizePresets) {
      const chip = el('button', opt.label, 'width-preset-chip');
      chip.type = 'button';
      chip.dataset.active = String(currentImgW === opt.val);
      chip.onclick = () => change(() => {
        node.image_width = opt.val;
      }, true);
      imgSizeChips.append(chip);
    }
    imgSizeWrap.append(imgSizeChips);

    const customImgWidthInput = el('input');
    customImgWidthInput.style.marginTop = '6px';
    customImgWidthInput.value = node.image_width || (node.width && !['100%', '50%', '33.3%', '25%'].includes(node.width) ? node.width : '');
    customImgWidthInput.placeholder = 'Custom size e.g. 48px, 64px, 100%';
    customImgWidthInput.oninput = () => {
      node.image_width = customImgWidthInput.value.trim();
      onFieldInput();
    };
    customImgWidthInput.onchange = snapshot;
    imgSizeWrap.append(customImgWidthInput);
    grid.append(imgSizeWrap);

    const heightInputWrap = el('label', 'Image Height (Optional):');
    const heightInput = el('input');
    heightInput.value = node.image_height || node.height || '';
    heightInput.placeholder = 'e.g. 48px or auto';
    heightInput.oninput = () => {
      node.image_height = heightInput.value.trim();
      node.height = node.image_height;
      onFieldInput();
    };
    heightInput.onchange = snapshot;
    heightInputWrap.append(heightInput);
    grid.append(heightInputWrap);
  }

  // Actions
  const actions = el('div', '', 'support-actions');
  actions.style.marginTop = '12px';

  const moveLeft = el('button', '← Move before / up', 'btn btn-secondary btn-sm');
  moveLeft.type = 'button';
  moveLeft.disabled = match.index === 0;
  moveLeft.onclick = () => {
    if (match.index > 0) {
      change(() => {
        match.list.splice(match.index, 1);
        match.list.splice(match.index - 1, 0, match.node);
      }, true);
    }
  };

  const moveRight = el('button', 'Move after / down →', 'btn btn-secondary btn-sm');
  moveRight.type = 'button';
  moveRight.disabled = match.index >= match.list.length - 1;
  moveRight.onclick = () => {
    if (match.index < match.list.length - 1) {
      change(() => {
        match.list.splice(match.index, 1);
        match.list.splice(match.index + 1, 0, match.node);
      }, true);
    }
  };

  const duplicateBtn = el('button', '⎘ Duplicate block', 'btn btn-secondary btn-sm');
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

  const removeBtn = el('button', '🗑 Delete block', 'btn btn-danger btn-sm');
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
    { label: 'Sport Category', path: '$.sport_category', kind: 'field', isTitle: true },
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
  const dataSource = getActiveSampleData();

  const payload = projectLayoutLocally(dataSource, nodes, cols, limit);
  renderLayout(previewContainer, payload);
}

function getMatchCardPreset() {
  return [
    { id: 'i_sport', type: 'image', path: '$.sport_title', label: 'Sport Image', section: 'title', visible: true, image_source: 'sport' },
    { id: 'f_sport_cat', type: 'field', path: '$.sport_category', label: 'Sport Category', role: 'title', section: 'title', visible: true, format: 'text', fallback: 'Basketball' },
    { id: 't_sport', type: 'field', path: '$.sport_title', label: 'League', role: 'title', section: 'title', visible: true, format: 'text', fallback: 'NBA' },
    { id: 'c_teams', type: 'container', label: 'Teams & Date', direction: 'row', section: 'loop', visible: true, children: [
      { id: 'f_away', type: 'field', path: '$.away_team', label: 'Away Team', role: 'data', section: 'loop', visible: true, format: 'text', fallback: 'Away', width: '33.3%' },
      { id: 'f_home', type: 'field', path: '$.home_team', label: 'Home Team', role: 'data', section: 'loop', visible: true, format: 'text', fallback: 'Home', width: '33.3%' },
      { id: 'f_date', type: 'field', path: '$.commence_time', label: 'Game Date', role: 'data', section: 'loop', visible: true, format: 'datetime', fallback: 'TBD', width: '33.3%' }
    ]},
    { id: 'c_odds', type: 'container', label: 'H2H Odds (1xBet)', direction: 'row', section: 'loop', visible: true, children: [
      { id: 'b_away_h2h', type: 'button', path: '$.bookmakers[0].markets[0].outcomes[0].price', label: 'Away H2H', style: 'gold', section: 'loop', visible: true, url: '', target: '_blank', width: '50%' },
      { id: 'b_home_h2h', type: 'button', path: '$.bookmakers[0].markets[0].outcomes[1].price', label: 'Home H2H', style: 'gold', section: 'loop', visible: true, url: '', target: '_blank', width: '50%' }
    ]},
    { id: 'c_spreads', type: 'container', label: 'Spreads & Totals', direction: 'row', section: 'loop', visible: true, children: [
      { id: 'f_spread_pt', type: 'field', path: '$.bookmakers[0].markets[1].outcomes[0].point', label: 'Spread Line', role: 'data', section: 'loop', visible: true, format: 'text', fallback: '0', width: '25%' },
      { id: 'b_spread_odds', type: 'button', path: '$.bookmakers[0].markets[1].outcomes[0].price', label: 'Spread Odds', style: 'brand', section: 'loop', visible: true, url: '', target: '_blank', width: '25%' },
      { id: 'f_total_pt', type: 'field', path: '$.bookmakers[0].markets[2].outcomes[0].point', label: 'Total O/U', role: 'data', section: 'loop', visible: true, format: 'text', fallback: '0', width: '25%' },
      { id: 'b_total_odds', type: 'button', path: '$.bookmakers[0].markets[2].outcomes[0].price', label: 'Total Odds', style: 'primary', section: 'loop', visible: true, url: '', target: '_blank', width: '25%' }
    ]}
  ];
}

function getOutrightsPreset() {
  const currentCols = Math.min(3, Math.max(1, Number(wireframeColumns?.value || columnsSelect?.value || 3)));
  return [
    { id: 'i_sport', type: 'image', path: '$.sport_title', label: 'Sport Image', section: 'title', visible: true, image_source: 'sport' },
    { id: 'f_sport_cat', type: 'field', path: '$.sport_category', label: 'Sport Category', role: 'title', section: 'title', visible: true, format: 'text', fallback: 'Basketball' },
    { id: 't_sport', type: 'field', path: '$.sport_title', label: 'Tournament / Event', role: 'title', section: 'title', visible: true, format: 'text', fallback: 'NBA Championship Winner' },
    { id: 'f_date', type: 'field', path: '$.commence_time', label: 'Cutoff Date', role: 'data', section: 'title', visible: true, format: 'datetime', fallback: 'TBD' },
    { id: 'f_bookmaker', type: 'field', path: '$.bookmakers[0].title', label: 'Bookmaker', role: 'data', section: 'title', visible: true, format: 'text', fallback: 'FanDuel' },
    { id: 'l_outcomes', type: 'loop', path: '$.bookmakers[0].markets[0].outcomes[]', label: 'Contenders', columns: currentCols, direction: 'grid', section: 'loop', visible: true, children: [
      { id: 'c_contender', type: 'container', path: '', label: 'Contender Card', direction: 'row', section: 'loop', visible: true, children: [
        { id: 'i_team', type: 'image', path: '$.name', label: 'Team Logo', image_source: 'team', image_width: '36px', width: 'auto', align: 'left', height: '', section: 'loop', visible: true },
        { id: 'f_name', type: 'field', path: '$.name', label: 'Team Name', role: 'data', format: 'text', section: 'loop', visible: true, fallback: '', width: 'equal' },
        { id: 'b_price', type: 'button', path: '$.price', label: 'Odds', style: 'gold', section: 'loop', visible: true, url: '', target: '_blank', width: 'auto', align: 'right' }
      ]}
    ]}
  ];
}

// Setup Dragging on Sidebar Component Buttons
function setupComponentDraggables() {
  const components = [
    { id: '#btn-add-text', type: 'text', label: 'Note', imageSource: 'custom', section: 'loop' },
    { id: '#btn-add-button', type: 'button', label: 'Bet Now', imageSource: 'custom', section: 'loop' },
    { id: '#btn-add-container', type: 'container', label: 'Container row', imageSource: 'custom', section: 'loop' },
    { id: '#btn-add-image', type: 'image', label: 'Custom Image', imageSource: 'custom', section: 'loop' },
    { id: '#btn-add-sport-cat', type: 'field', path: '$.sport_category', label: 'Sport Category', section: 'title' },
    { id: '#btn-add-sport-img', type: 'image', path: '$.sport_title', label: 'Sport Image', imageSource: 'sport', section: 'title' },
    { id: '#btn-add-home-img', type: 'image', path: '$.home_team', label: 'Home Team Logo', imageSource: 'home_team', section: 'loop' },
    { id: '#btn-add-away-img', type: 'image', path: '$.away_team', label: 'Away Team Logo', imageSource: 'away_team', section: 'loop' },
    { id: '#btn-add-team-img', type: 'image', path: '$.name', label: 'Contender / Team Logo', imageSource: 'team', section: 'loop' }
  ];

  for (const comp of components) {
    const btn = document.querySelector(comp.id);
    if (!btn) continue;
    btn.draggable = true;

    btn.onclick = () => {
      const targetSec = comp.section || 'loop';
      insertBlock(createBlock(comp.type, comp.path || '', comp.label, targetSec, comp.imageSource || 'custom'), targetSec === 'title' ? null : activeContainerId, targetSec);
    };

    btn.ondragstart = (e) => {
      draggedPayload = {
        source: 'component',
        type: comp.type,
        path: comp.path || '',
        label: comp.label,
        section: comp.section || 'loop',
        imageSource: comp.imageSource || 'custom'
      };
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
document.querySelector('#btn-preset-outrights')?.addEventListener('click', () => {
  nodes = getOutrightsPreset();
  const marketsInput = document.querySelector('input[name="markets"]');
  if (marketsInput && (!marketsInput.value || marketsInput.value === 'h2h,spreads,totals')) {
    marketsInput.value = 'outrights';
  }
  selected = '';
  activeContainerId = '';
  draw(true);
  updateLivePreview();
});

document.querySelector('#btn-preset-match')?.addEventListener('click', () => {
  nodes = getMatchCardPreset();
  const marketsInput = document.querySelector('input[name="markets"]');
  if (marketsInput && marketsInput.value === 'outrights') {
    marketsInput.value = 'h2h,spreads,totals';
  }
  selected = '';
  activeContainerId = '';
  draw(true);
  updateLivePreview();
});

document.querySelector('#btn-add-sample-row')?.addEventListener('click', () => {
  insertBlock(createBlock('container', '', 'New Container Row', 'loop'), null, 'loop');
});

if (fieldSearch) fieldSearch.oninput = renderGeneratedPaths;

function updateLayoutColumns(cols) {
  cols = Math.min(3, Math.max(1, Number(cols) || 1));
  if (wireframeColumns) wireframeColumns.value = String(cols);
  if (columnsSelect) columnsSelect.value = String(cols);

  // Update loop nodes in layout
  const updateLoopNodes = (list) => {
    for (const n of list) {
      if (n.type === 'loop') {
        n.columns = cols;
      }
      if (n.children && Array.isArray(n.children)) {
        updateLoopNodes(n.children);
      }
    }
  };
  updateLoopNodes(nodes);

  sync();
  draw(false);
  updateLivePreview();
}

if (wireframeColumns) {
  wireframeColumns.onchange = () => {
    updateLayoutColumns(wireframeColumns.value);
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
    updateLayoutColumns(columnsSelect.value);
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

function updateSportPreviewBadge() {
  const sportSel = document.querySelector('#sport-select');
  const imgEl = document.querySelector('#sport-image-preview-img');
  const lblEl = document.querySelector('#sport-image-preview-label');
  if (!sportSel || !imgEl || !lblEl) return;

  const val = (sportSel.value || '').toLowerCase();
  if (!val) {
    imgEl.style.display = 'none';
    lblEl.textContent = '';
    return;
  }

  const prefix = val.split('_')[0];
  const sport = registeredSportsMap[val] || registeredSportsMap[prefix] || Object.values(registeredSportsMap).find(s => val.includes(s.slug));

  if (sport && (sport.image_url || sport.image_path)) {
    imgEl.src = sport.image_url || sport.image_path;
    imgEl.style.display = 'inline-block';
    lblEl.textContent = `${sport.name} (${sport.image_key || sport.slug + '_sports'})`;
  } else if (sport) {
    imgEl.style.display = 'none';
    lblEl.textContent = `${sport.name} — No image uploaded`;
  } else {
    imgEl.style.display = 'none';
    lblEl.textContent = '';
  }
}

const sportSelect = document.querySelector('#sport-select');
if (sportSelect) {
  sportSelect.addEventListener('change', () => {
    updateSportPreviewBadge();
    updateLivePreview();
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
updateSportPreviewBadge();
draw(true);
updateLivePreview();
