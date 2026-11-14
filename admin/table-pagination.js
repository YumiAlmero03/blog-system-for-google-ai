(() => {
  const states = new WeakMap();
  const style = document.createElement('style');
  style.textContent = `
    tr[data-page-hidden], tr[data-filter-hidden] { display: none !important; }
    .table-controls {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
      margin: 10px 0;
      padding: 8px 10px;
      background: var(--surface-soft, #fafafa);
      border: 1px solid var(--border, #e4e7ec);
      border-radius: var(--radius-sm, 6px);
      font-size: 0.84rem;
    }
    .table-control-label {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      font-weight: 700;
      color: var(--text-muted, #667085);
      margin: 0;
    }
    .table-controls input[type="search"],
    .table-controls select {
      min-height: 32px;
      padding: 4px 8px;
      border: 1px solid var(--border-strong, #d0d5dd);
      border-radius: var(--radius-sm, 6px);
      background: var(--surface, #fff);
      color: var(--text, #101828);
      font: inherit;
      font-weight: 400;
      font-size: 0.84rem;
    }
    .table-controls input[type="search"] {
      min-width: 170px;
    }
    .table-controls select {
      max-width: 210px;
    }
    .table-filter-status {
      margin-left: auto;
      color: var(--text-muted, #667085);
      font-size: 0.82rem;
    }
    th.table-sortable-th {
      cursor: pointer;
      user-select: none;
      white-space: nowrap;
      transition: background-color 0.12s ease, color 0.12s ease;
    }
    th.table-sortable-th:hover,
    th.table-sortable-th:focus-visible {
      background-color: rgba(166, 47, 61, 0.06);
      color: var(--brand-dark, #741f2b);
      outline: none;
    }
    th.table-sortable-th[aria-sort="ascending"],
    th.table-sortable-th[aria-sort="descending"] {
      color: var(--brand-dark, #741f2b);
    }
    .table-sort-indicator {
      display: inline-block;
      margin-left: 5px;
      font-size: 0.78em;
      opacity: 0.45;
      vertical-align: baseline;
    }
    th.table-sortable-th[aria-sort="ascending"] .table-sort-indicator,
    th.table-sortable-th[aria-sort="descending"] .table-sort-indicator {
      opacity: 1;
      color: var(--brand, #a62f3d);
      font-weight: 900;
    }
    .table-pagination {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
      margin: 14px 0;
    }
    .table-pagination input {
      width: 5em;
    }
    .table-pagination label {
      display: flex;
      align-items: center;
      gap: 6px;
    }
  `;
  document.head.append(style);

  const NON_SORTABLE_HEADERS = new Set([
    '', 'select', 'action', 'actions', 'edit', 'image editor', 'logo', 'image'
  ]);
  const EMPTY_SORT_STRINGS = new Set([
    '', '—', '-', '–', 'never', 'none', 'n/a', 'not recorded', 'unpublished'
  ]);

  const parseTemplate = document.createElement('template');

  function cleanText(value) {
    return String(value ?? '').replace(/\s+/g, ' ').trim();
  }

  function getCellFilterText(cell) {
    if (!cell) return '';
    if (cell.dataset && cell.dataset.filterValue !== undefined) {
      return cleanText(cell.dataset.filterValue);
    }
    const checkbox = cell.querySelector('input[type="checkbox"]');
    if (checkbox && checkbox.name === 'approved') {
      return checkbox.checked ? 'Approved' : 'Not Approved';
    }
    let text = cleanText(cell.textContent);
    cell.querySelectorAll('input[type="text"], input[type="url"], input[type="search"]').forEach((input) => {
      if (input.value) text += ' ' + cleanText(input.value);
    });
    return cleanText(text);
  }

  function getCellPrimaryValue(cell) {
    if (!cell) return '';
    if (cell.dataset && cell.dataset.filterValue !== undefined) {
      return cleanText(cell.dataset.filterValue);
    }
    const checkbox = cell.querySelector('input[type="checkbox"]');
    if (checkbox && checkbox.name === 'approved') {
      return checkbox.checked ? 'Approved' : 'Not Approved';
    }
    const badge = cell.querySelector('.badge, .blog-status-badge, .seo-pill, .player-presence');
    if (badge) {
      const badgeText = cleanText(badge.textContent);
      if (badgeText && badgeText.length <= 40) return badgeText;
    }
    return cleanText(cell.textContent);
  }

  function extractSortDescriptor(cell) {
    if (!cell) return { empty: true, type: 'str', val: '' };
    if (cell.dataset && cell.dataset.sortValue !== undefined) {
      const raw = cleanText(cell.dataset.sortValue);
      const num = Number(raw);
      if (raw !== '' && Number.isFinite(num)) return { empty: false, type: 'num', val: num };
      return { empty: raw === '', type: 'str', val: raw.toLowerCase() };
    }
    const checkbox = cell.querySelector('input[type="checkbox"]');
    if (checkbox) {
      return { empty: false, type: 'num', val: checkbox.checked ? 1 : 0 };
    }
    const timeEl = cell.querySelector('time[datetime]');
    if (timeEl) {
      const dt = timeEl.getAttribute('datetime') || '';
      const parsed = Date.parse(dt);
      if (Number.isFinite(parsed)) return { empty: false, type: 'num', val: parsed };
    }
    const rawText = cleanText(cell.textContent);
    const lower = rawText.toLowerCase();
    if (EMPTY_SORT_STRINGS.has(lower)) {
      return { empty: true, type: 'str', val: '' };
    }

    // Check for Yoast score or fraction like "80/100"
    const scoreMatch = rawText.match(/\b(\d{1,3})\s*\/\s*100\b/);
    if (scoreMatch) {
      return { empty: false, type: 'num', val: Number(scoreMatch[1]) };
    }

    // Check for pure number or number with unit/count (avoiding dates like 2026-10-12 or IPs like 127.0.0.1)
    if (!/^\d{4}-\d{2}-\d{2}/.test(rawText) && !/^\d{1,3}(?:\.\d{1,3}){3}/.test(rawText)) {
      const numMatch = rawText.match(/^[$₱€£]?(-?\d[\d,]*(?:\.\d+)?)\s*(?:%|bytes|kb|mb|rows?|matches|match|posts?|games?|tournaments?|tournament\(s\)|participants?|participant\(s\)|clicks?|views?|likes?)?$/i);
      if (numMatch) {
        const n = Number(numMatch[1].replace(/,/g, ''));
        if (Number.isFinite(n)) return { empty: false, type: 'num', val: n };
      }
    }

    // Check for dates (e.g. "2026-10-12 14:30", "Oct 12, 2026", "Scheduled: 2026-10-12 10:00", "10/12/2026")
    const dateCandidate = rawText
      .replace(/^(?:Scheduled:\s*|Draft\s*\(|\s*Updated:.*$)/gi, '')
      .replace(/\).*$/, '')
      .trim();
    if (
      /^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2})?(?:\s*UTC)?)?$/i.test(dateCandidate) ||
      /^(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\s+\d{1,2},?\s+\d{4}/i.test(dateCandidate) ||
      /^\d{1,2}\/\d{1,2}\/\d{4}/.test(dateCandidate)
    ) {
      const normalized = /^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}$/.test(dateCandidate)
        ? dateCandidate.replace(' ', 'T')
        : dateCandidate;
      const ts = Date.parse(normalized);
      if (Number.isFinite(ts)) return { empty: false, type: 'num', val: ts };
    }

    return { empty: false, type: 'str', val: rawText };
  }

  function compareDescriptors(a, b, dir) {
    if (a.empty && b.empty) return 0;
    if (a.empty) return 1;
    if (b.empty) return -1;
    let cmp = 0;
    if (a.type === 'num' && b.type === 'num') {
      cmp = a.val - b.val;
    } else {
      cmp = String(a.val).localeCompare(String(b.val), undefined, { numeric: true, sensitivity: 'base' });
    }
    return dir === 'desc' ? -cmp : cmp;
  }

  function isEligibleTable(table) {
    if (!table || !table.tBodies || !table.tBodies[0]) return false;
    if (table.classList.contains('editor-table') || table.hasAttribute('data-no-table-tools')) return false;
    if (table.closest('.editor-block, .content-editor, [contenteditable="true"]')) return false;
    return true;
  }

  function isPlaceholderRow(row) {
    return (
      row.cells.length === 1 &&
      row.cells[0].colSpan > 1 &&
      !row.classList.contains('quick-edit-row') &&
      !row.classList.contains('table-empty-filter-row')
    );
  }

  function create(table) {
    if (states.has(table)) return states.get(table);
    const body = table.tBodies[0];
    if (!body) return null;

    const isPaginated = table.hasAttribute('data-paginate');
    const state = {
      page: 1,
      size: 25,
      data: null,
      renderedItems: null,
      renderer: null,
      filterText: '',
      filterCol: -1,
      filterVal: '',
      sortCol: -1,
      sortDir: '',
      nextRowIndex: 0,
      headers: [],
    };

    // Inspect table headers
    const headRow = table.tHead && table.tHead.rows[0] ? table.tHead.rows[0] : null;
    const colCount = headRow ? headRow.cells.length : (body.rows[0] ? body.rows[0].cells.length : 1);

    // Build Filter + Sort Toolbar
    const controls = document.createElement('div');
    controls.className = 'table-controls';
    controls.setAttribute('role', 'region');
    controls.setAttribute('aria-label', (table.dataset.paginationLabel || 'Table') + ' filter and sort');

    // 1. Search / Filter input
    const filterLabel = document.createElement('label');
    filterLabel.className = 'table-control-label';
    filterLabel.append('Filter ');
    const filterInput = document.createElement('input');
    filterInput.type = 'search';
    filterInput.className = 'table-filter-input';
    filterInput.placeholder = 'Search table...';
    filterInput.autocomplete = 'off';
    filterInput.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') e.preventDefault();
    });
    filterLabel.append(filterInput);
    controls.append(filterLabel);

    // 2. Column filter select
    const colLabel = document.createElement('label');
    colLabel.className = 'table-control-label';
    colLabel.append('Column ');
    const colSelect = document.createElement('select');
    colSelect.className = 'table-filter-col';
    colSelect.add(new Option('All columns', '-1'));
    colLabel.append(colSelect);
    controls.append(colLabel);

    // 3. Value filter select
    const valLabel = document.createElement('label');
    valLabel.className = 'table-control-label';
    valLabel.append('Value ');
    const valSelect = document.createElement('select');
    valSelect.className = 'table-filter-val';
    valSelect.add(new Option('All values', ''));
    valLabel.append(valSelect);
    controls.append(valLabel);

    // 4. Sort select
    const sortLabel = document.createElement('label');
    sortLabel.className = 'table-control-label';
    sortLabel.append('Sort by ');
    const sortSelect = document.createElement('select');
    sortSelect.className = 'table-sort-select';
    sortSelect.add(new Option('Default order', ''));
    sortLabel.append(sortSelect);
    controls.append(sortLabel);

    // 5. Reset button
    const resetBtn = document.createElement('button');
    resetBtn.type = 'button';
    resetBtn.className = 'btn btn-secondary btn-sm table-reset-btn';
    resetBtn.textContent = 'Reset';
    resetBtn.hidden = true;
    controls.append(resetBtn);

    // 6. Status text (for non-paginated tables or filtered feedback)
    const filterStatus = document.createElement('span');
    filterStatus.className = 'table-filter-status';
    filterStatus.setAttribute('role', 'status');
    controls.append(filterStatus);

    // Wire up column headers in <thead>
    if (headRow) {
      Array.from(headRow.cells).forEach((th, idx) => {
        const label = cleanText(th.textContent);
        const lower = label.toLowerCase();
        const sortable = label !== '' && !NON_SORTABLE_HEADERS.has(lower);
        const filterable = label !== '' && !new Set(['select', 'action', 'actions', 'edit', 'image editor', 'logo', 'image']).has(lower);
        state.headers.push({ th, idx, label, sortable, filterable, indicator: null });

        if (filterable) {
          colSelect.add(new Option(label, String(idx)));
        }
        if (sortable) {
          sortSelect.add(new Option(`${label} (↑ Asc)`, `${idx}:asc`));
          sortSelect.add(new Option(`${label} (↓ Desc)`, `${idx}:desc`));

          th.classList.add('table-sortable-th');
          th.tabIndex = 0;
          th.setAttribute('aria-sort', 'none');
          th.title = `Sort by ${label}`;
          const indicator = document.createElement('span');
          indicator.className = 'table-sort-indicator';
          indicator.setAttribute('aria-hidden', 'true');
          indicator.textContent = '↕';
          th.append(indicator);
          state.headers[idx].indicator = indicator;

          const toggleColumnSort = (event) => {
            if (event.target.closest('input, button, select, a')) return;
            if (state.sortCol !== idx) {
              state.sortCol = idx;
              state.sortDir = 'asc';
            } else if (state.sortDir === 'asc') {
              state.sortDir = 'desc';
            } else {
              state.sortCol = -1;
              state.sortDir = '';
            }
            state.page = 1;
            syncSortUi();
            render();
          };

          th.addEventListener('click', toggleColumnSort);
          th.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
              event.preventDefault();
              toggleColumnSort(event);
            }
          });
        }
      });
    }

    function syncSortUi() {
      const selectVal = state.sortCol >= 0 && state.sortDir ? `${state.sortCol}:${state.sortDir}` : '';
      if (sortSelect.value !== selectVal) {
        sortSelect.value = selectVal;
      }
      state.headers.forEach((h) => {
        if (!h.sortable || !h.indicator) return;
        if (h.idx === state.sortCol && state.sortDir === 'asc') {
          h.th.setAttribute('aria-sort', 'ascending');
          h.indicator.textContent = '↑';
        } else if (h.idx === state.sortCol && state.sortDir === 'desc') {
          h.th.setAttribute('aria-sort', 'descending');
          h.indicator.textContent = '↓';
        } else {
          h.th.setAttribute('aria-sort', 'none');
          h.indicator.textContent = '↕';
        }
      });
    }

    table.before(controls);

    // Optional Pagination UI (when table[data-paginate] is present)
    let nav = null;
    let previous = null;
    let page = null;
    let totalPages = null;
    let next = null;
    let size = null;
    let summary = null;

    if (isPaginated) {
      nav = document.createElement('nav');
      nav.className = 'table-pagination';
      nav.setAttribute('aria-label', (table.dataset.paginationLabel || 'Table') + ' pagination');
      const button = (text) => {
        const node = document.createElement('button');
        node.type = 'button';
        node.textContent = text;
        node.className = 'btn btn-secondary btn-sm';
        nav.append(node);
        return node;
      };
      previous = button('Previous');
      const pageLabel = document.createElement('label');
      pageLabel.append('Page ');
      page = document.createElement('input');
      page.type = 'number';
      page.min = '1';
      page.value = '1';
      page.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
          e.preventDefault();
          state.page = Number.parseInt(page.value, 10) || 1;
          render();
        }
      });
      pageLabel.append(page);
      nav.append(pageLabel);
      totalPages = document.createElement('span');
      nav.append(totalPages);
      next = button('Next');
      const sizeLabel = document.createElement('label');
      sizeLabel.append('Rows ');
      size = document.createElement('select');
      for (const value of [25, 50, 100]) size.add(new Option(String(value), String(value)));
      sizeLabel.append(size);
      nav.append(sizeLabel);
      summary = document.createElement('span');
      summary.setAttribute('role', 'status');
      nav.append(summary);
      table.after(nav);

      previous.addEventListener('click', () => { state.page--; render(); });
      next.addEventListener('click', () => { state.page++; render(); });
      page.addEventListener('change', () => { state.page = Number.parseInt(page.value, 10) || 1; render(); });
      size.addEventListener('change', () => { state.size = Number(size.value); state.page = 1; render(); });
    }

    function populateValueFilterOptions(rowsOrItems, isDataMode) {
      const currentVal = state.filterVal;
      valSelect.innerHTML = '';
      valSelect.add(new Option('All values', ''));

      if (state.filterCol >= 0) {
        const colIdx = state.filterCol;
        const distinct = new Map();
        for (const item of rowsOrItems) {
          const val = isDataMode
            ? (item.cells[colIdx] ? item.cells[colIdx].primaryVal : '')
            : getCellPrimaryValue(item.cells[colIdx]);
          if (!val || val.length > 70 || EMPTY_SORT_STRINGS.has(val.toLowerCase())) continue;
          const key = val.toLowerCase();
          if (!distinct.has(key)) distinct.set(key, val);
        }
        const sortedVals = Array.from(distinct.values()).sort((a, b) =>
          a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' })
        );
        for (const val of sortedVals.slice(0, 50)) {
          valSelect.add(new Option(val, `${colIdx}:${val.toLowerCase()}`));
        }
      } else {
        // Group categorical columns (1..15 distinct short values)
        for (const h of state.headers) {
          if (!h.filterable) continue;
          const distinct = new Map();
          let tooLongOrMany = false;
          for (const item of rowsOrItems) {
            const val = isDataMode
              ? (item.cells[h.idx] ? item.cells[h.idx].primaryVal : '')
              : getCellPrimaryValue(item.cells[h.idx]);
            if (!val || EMPTY_SORT_STRINGS.has(val.toLowerCase())) continue;
            if (val.length > 48) {
              tooLongOrMany = true;
              break;
            }
            const key = val.toLowerCase();
            if (!distinct.has(key)) {
              distinct.set(key, val);
              if (distinct.size > 15) {
                tooLongOrMany = true;
                break;
              }
            }
          }
          if (!tooLongOrMany && distinct.size >= 1 && (distinct.size <= 15 && rowsOrItems.length >= 1)) {
            const group = document.createElement('optgroup');
            group.label = h.label;
            const sortedVals = Array.from(distinct.values()).sort((a, b) =>
              a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' })
            );
            for (const val of sortedVals) {
              group.append(new Option(`${h.label}: ${val}`, `${h.idx}:${val.toLowerCase()}`));
            }
            valSelect.append(group);
          }
        }
      }

      if (currentVal && Array.from(valSelect.options).some((opt) => opt.value === currentVal)) {
        valSelect.value = currentVal;
      } else {
        state.filterVal = '';
        valSelect.value = '';
      }
      valLabel.hidden = valSelect.options.length <= 1;
    }

    const observer = new MutationObserver((records) => {
      const relevant = records.some(
        (record) =>
          (record.type === 'childList' && record.target === body) ||
          (record.type === 'attributes' && record.attributeName === 'hidden' && record.target.tagName === 'TR')
      );
      if (!relevant) return;
      if (records.some((record) => record.type === 'attributes')) state.page = 1;
      render();
    });

    function ensureEmptyFilterRow(show) {
      let emptyRow = body.querySelector(':scope > tr.table-empty-filter-row');
      if (show) {
        if (!emptyRow) {
          emptyRow = document.createElement('tr');
          emptyRow.className = 'table-empty-filter-row';
          const td = document.createElement('td');
          td.colSpan = Math.max(1, colCount);
          td.style.cssText = 'text-align:center;padding:16px;color:var(--text-muted, #667085);';
          td.textContent = 'No matching rows found.';
          emptyRow.append(td);
        }
        body.append(emptyRow);
      } else if (emptyRow) {
        emptyRow.remove();
      }
    }

    function matchesFilter(itemOrRow, isDataMode, term, filterCol, filterValParsed) {
      if (filterValParsed) {
        const { colIdx, targetVal } = filterValParsed;
        const primary = isDataMode
          ? (itemOrRow.cells[colIdx] ? itemOrRow.cells[colIdx].primaryVal : '')
          : getCellPrimaryValue(itemOrRow.cells[colIdx]);
        const full = isDataMode
          ? (itemOrRow.cells[colIdx] ? itemOrRow.cells[colIdx].filterText : '')
          : getCellFilterText(itemOrRow.cells[colIdx]);
        if (primary.toLowerCase() !== targetVal && !full.toLowerCase().includes(targetVal)) {
          return false;
        }
      }
      if (term !== '') {
        if (filterCol >= 0) {
          const text = isDataMode
            ? (itemOrRow.cells[filterCol] ? itemOrRow.cells[filterCol].filterText : '')
            : getCellFilterText(itemOrRow.cells[filterCol]);
          if (!text.toLowerCase().includes(term)) return false;
        } else {
          const allText = isDataMode
            ? itemOrRow.allText
            : Array.from(itemOrRow.cells).map(getCellFilterText).join(' ').toLowerCase();
          if (!allText.includes(term)) return false;
        }
      }
      return true;
    }

    function render(skipValueRefresh = false) {
      observer.disconnect();

      const term = state.filterText.trim().toLowerCase();
      let filterValParsed = null;
      if (state.filterVal) {
        const sep = state.filterVal.indexOf(':');
        if (sep > 0) {
          filterValParsed = {
            colIdx: Number.parseInt(state.filterVal.slice(0, sep), 10),
            targetVal: state.filterVal.slice(sep + 1),
          };
        }
      }
      const hasActiveFilterOrSort =
        term !== '' || state.filterCol >= 0 || state.filterVal !== '' || (state.sortCol >= 0 && state.sortDir !== '');
      resetBtn.hidden = !hasActiveFilterOrSort;

      if (state.data !== null && Array.isArray(state.renderedItems)) {
        const totalAll = state.renderedItems.length;
        if (!skipValueRefresh) {
          populateValueFilterOptions(state.renderedItems, true);
        }
        let matching = state.renderedItems.filter((item) =>
          matchesFilter(item, true, term, state.filterCol, filterValParsed)
        );
        if (state.sortCol >= 0 && state.sortDir) {
          const col = state.sortCol;
          const dir = state.sortDir;
          matching = [...matching].sort((a, b) => {
            const descA = a.cells[col] ? a.cells[col].sortDesc : { empty: true, type: 'str', val: '' };
            const descB = b.cells[col] ? b.cells[col].sortDesc : { empty: true, type: 'str', val: '' };
            const cmp = compareDescriptors(descA, descB, dir);
            return cmp !== 0 ? cmp : a.index - b.index;
          });
        }
        const count = matching.length;
        const pages = isPaginated ? Math.max(1, Math.ceil(count / state.size)) : 1;
        state.page = Math.min(Math.max(1, state.page), pages);
        const start = isPaginated ? (state.page - 1) * state.size : 0;
        const visibleItems = isPaginated ? matching.slice(start, start + state.size) : matching;
        if (visibleItems.length > 0) {
          body.innerHTML = visibleItems.map((item) => item.html).join('');
        } else if (totalAll > 0) {
          body.innerHTML = `<tr class="table-empty-filter-row"><td colspan="${Math.max(1, colCount)}" style="text-align:center;padding:16px;color:var(--text-muted, #667085);">No matching rows found.</td></tr>`;
        } else {
          body.innerHTML = '';
        }

        const filteredNote = count !== totalAll ? ` (filtered from ${totalAll})` : '';
        if (isPaginated && nav) {
          page.value = String(state.page);
          page.max = String(pages);
          totalPages.textContent = `of ${pages}`;
          previous.disabled = state.page === 1;
          next.disabled = state.page === pages;
          summary.textContent = count
            ? `${start + 1}–${Math.min(start + state.size, count)} of ${count} rows${filteredNote}`
            : `0 rows${filteredNote}`;
          filterStatus.textContent = count !== totalAll ? `${count} of ${totalAll} rows` : `${totalAll} rows`;
        } else {
          filterStatus.textContent = `${count} of ${totalAll} rows`;
        }
      } else {
        const allDomRows = Array.from(body.rows).filter((r) => !r.classList.contains('table-empty-filter-row'));
        const placeholder = allDomRows.length === 1 && isPlaceholderRow(allDomRows[0]);
        const dataRows = [];

        if (!placeholder) {
          let lastDataRow = null;
          for (const row of allDomRows) {
            if (row.classList.contains('quick-edit-row')) {
              if (lastDataRow) {
                lastDataRow._detailRow = row;
                row._parentRow = lastDataRow;
              }
              continue;
            }
            if (!row.hasAttribute('data-initial-index')) {
              row.setAttribute('data-initial-index', String(state.nextRowIndex++));
            }
            row._detailRow = null;
            dataRows.push(row);
            lastDataRow = row;
          }
        }

        const unhiddenRows = dataRows.filter((row) => !row.hidden);
        if (!skipValueRefresh) {
          populateValueFilterOptions(unhiddenRows, false);
        }

        // Sort DOM rows (or restore initial order)
        if (dataRows.length > 1) {
          const sortedRows = [...dataRows].sort((rowA, rowB) => {
            if (state.sortCol >= 0 && state.sortDir) {
              const descA = extractSortDescriptor(rowA.cells[state.sortCol]);
              const descB = extractSortDescriptor(rowB.cells[state.sortCol]);
              const cmp = compareDescriptors(descA, descB, state.sortDir);
              if (cmp !== 0) return cmp;
            }
            return (
              Number(rowA.getAttribute('data-initial-index') || 0) -
              Number(rowB.getAttribute('data-initial-index') || 0)
            );
          });
          for (const row of sortedRows) {
            body.append(row);
            if (row._detailRow) {
              body.append(row._detailRow);
            }
          }
        }

        // Apply filter visibility
        const filtered = [];
        for (const row of dataRows) {
          const isExternallyHidden = row.hidden;
          const match = !isExternallyHidden && matchesFilter(row, false, term, state.filterCol, filterValParsed);
          row.toggleAttribute('data-filter-hidden', !isExternallyHidden && !match);
          if (row._detailRow) {
            row._detailRow.toggleAttribute('data-filter-hidden', !isExternallyHidden && !match);
          }
          if (match) filtered.push(row);
        }

        const totalAvailable = unhiddenRows.length;
        const count = filtered.length;
        ensureEmptyFilterRow(!placeholder && totalAvailable > 0 && count === 0);

        if (isPaginated && nav) {
          const pages = Math.max(1, Math.ceil(count / state.size));
          state.page = Math.min(Math.max(1, state.page), pages);
          const start = (state.page - 1) * state.size;
          dataRows.forEach((row) => {
            row.removeAttribute('data-page-hidden');
            if (row._detailRow) row._detailRow.removeAttribute('data-page-hidden');
          });
          filtered.forEach((row, index) => {
            const hideForPage = index < start || index >= start + state.size;
            row.toggleAttribute('data-page-hidden', hideForPage);
            if (row._detailRow) row._detailRow.toggleAttribute('data-page-hidden', hideForPage);
          });
          const filteredNote = count !== totalAvailable ? ` (filtered from ${totalAvailable})` : '';
          page.value = String(state.page);
          page.max = String(pages);
          totalPages.textContent = `of ${pages}`;
          previous.disabled = state.page === 1;
          next.disabled = state.page === pages;
          summary.textContent = count
            ? `${start + 1}–${Math.min(start + state.size, count)} of ${count} rows${filteredNote}`
            : `0 rows${filteredNote}`;
          filterStatus.textContent = count !== totalAvailable ? `${count} of ${totalAvailable} rows` : `${totalAvailable} rows`;
        } else {
          filterStatus.textContent =
            count !== totalAvailable
              ? `${count} of ${totalAvailable} rows`
              : `${totalAvailable} ${totalAvailable === 1 ? 'row' : 'rows'}`;
        }
      }

      observer.observe(body, { childList: true, subtree: true, attributes: true, attributeFilter: ['hidden'] });
    }

    filterInput.addEventListener('input', () => {
      state.filterText = filterInput.value;
      state.page = 1;
      render(true);
    });

    colSelect.addEventListener('change', () => {
      state.filterCol = Number.parseInt(colSelect.value, 10);
      state.filterVal = '';
      state.page = 1;
      render(false);
    });

    valSelect.addEventListener('change', () => {
      state.filterVal = valSelect.value;
      state.page = 1;
      render(true);
    });

    sortSelect.addEventListener('change', () => {
      const val = sortSelect.value;
      if (!val) {
        state.sortCol = -1;
        state.sortDir = '';
      } else {
        const [colStr, dir] = val.split(':');
        state.sortCol = Number.parseInt(colStr, 10);
        state.sortDir = dir === 'desc' ? 'desc' : 'asc';
      }
      state.page = 1;
      syncSortUi();
      render(true);
    });

    resetBtn.addEventListener('click', () => {
      state.filterText = '';
      state.filterCol = -1;
      state.filterVal = '';
      state.sortCol = -1;
      state.sortDir = '';
      state.page = 1;
      filterInput.value = '';
      colSelect.value = '-1';
      valSelect.value = '';
      syncSortUi();
      render(false);
    });

    state.setRows = (data, renderer) => {
      state.data = Array.isArray(data) ? data : [];
      state.renderer = renderer;
      state.renderedItems = state.data.map((item, index) => {
        const html = String(renderer(item) || '').trim();
        parseTemplate.innerHTML = `<table><tbody>${html}</tbody></table>`;
        const tr = parseTemplate.content.querySelector('tr');
        const cells = tr
          ? Array.from(tr.cells).map((cell) => ({
              filterText: getCellFilterText(cell),
              primaryVal: getCellPrimaryValue(cell),
              sortDesc: extractSortDescriptor(cell),
            }))
          : [];
        const allText = cells.map((c) => c.filterText).join(' ').toLowerCase();
        return { html, cells, allText, index };
      });
      render(false);
    };

    states.set(table, state);
    render(false);
    return state;
  }

  function enhanceAllTables(root = document) {
    const tables = root.querySelectorAll ? root.querySelectorAll('table') : [];
    tables.forEach((table) => {
      if (isEligibleTable(table)) create(table);
    });
  }

  window.AdminTables = {
    setRows(table, data, renderer) {
      const instance = create(table);
      if (instance) instance.setRows(data, renderer);
    },
    enhance: enhanceAllTables,
  };

  enhanceAllTables(document);

  if (document.body) {
    const bodyObserver = new MutationObserver((records) => {
      for (const record of records) {
        for (const node of record.addedNodes) {
          if (node.nodeType !== 1) continue;
          if (node.tagName === 'TABLE') {
            if (isEligibleTable(node)) create(node);
          } else if (node.querySelectorAll) {
            enhanceAllTables(node);
          }
        }
      }
    });
    bodyObserver.observe(document.body, { childList: true, subtree: true });
  }
})();
