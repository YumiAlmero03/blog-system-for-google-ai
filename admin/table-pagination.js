(() => {
  const states = new WeakMap();
  const style = document.createElement('style');
  style.textContent = 'tr[data-page-hidden]{display:none!important}.table-pagination{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:14px 0}.table-pagination input{width:5em}.table-pagination label{display:flex;align-items:center;gap:6px}';
  document.head.append(style);

  function create(table) {
    if (states.has(table)) return states.get(table);
    const body = table.tBodies[0];
    if (!body) return null;
    const state = { page: 1, size: 25, data: null, renderer: null };
    const nav = document.createElement('nav');
    nav.className = 'table-pagination';
    nav.setAttribute('aria-label', (table.dataset.paginationLabel || 'Table') + ' pagination');
    const button = text => {
      const node = document.createElement('button'); node.type = 'button'; node.textContent = text;
      node.className = 'btn btn-secondary btn-sm'; nav.append(node); return node;
    };
    const previous = button('Previous');
    const pageLabel = document.createElement('label'); pageLabel.append('Page ');
    const page = document.createElement('input'); page.type = 'number'; page.min = '1'; page.value = '1';
    pageLabel.append(page); nav.append(pageLabel);
    const totalPages = document.createElement('span'); nav.append(totalPages);
    const next = button('Next');
    const sizeLabel = document.createElement('label'); sizeLabel.append('Rows ');
    const size = document.createElement('select');
    for (const value of [25, 50, 100]) size.add(new Option(String(value), String(value)));
    sizeLabel.append(size); nav.append(sizeLabel);
    const summary = document.createElement('span'); summary.setAttribute('role', 'status'); nav.append(summary);
    table.after(nav);
    const observer = new MutationObserver(records => {
      if (records.some(record => record.type === 'attributes')) state.page = 1;
      render();
    });
    function render() {
      observer.disconnect();
      const rows = Array.from(body.rows);
      const placeholder = rows.length === 1 && rows[0].cells.length === 1 && rows[0].cells[0].colSpan > 1;
      const filtered = placeholder ? [] : rows.filter(row => !row.hidden);
      const count = state.data === null ? filtered.length : state.data.length;
      const pages = Math.max(1, Math.ceil(count / state.size));
      state.page = Math.min(Math.max(1, state.page), pages);
      const start = (state.page - 1) * state.size;
      if (state.data !== null) {
        body.innerHTML = state.data.slice(start, start + state.size).map(state.renderer).join('');
      } else {
        rows.forEach(row => row.removeAttribute('data-page-hidden'));
        filtered.forEach((row, index) => row.toggleAttribute('data-page-hidden', index < start || index >= start + state.size));
      }
      page.value = String(state.page); page.max = String(pages);
      totalPages.textContent = `of ${pages}`;
      previous.disabled = state.page === 1; next.disabled = state.page === pages;
      summary.textContent = count ? `${start + 1}–${Math.min(start + state.size, count)} of ${count} rows` : '0 rows';
      observer.observe(body, { childList: true, subtree: true, attributes: true, attributeFilter: ['hidden'] });
    }
    previous.addEventListener('click', () => { state.page--; render(); });
    next.addEventListener('click', () => { state.page++; render(); });
    page.addEventListener('change', () => { state.page = Number.parseInt(page.value, 10) || 1; render(); });
    size.addEventListener('change', () => { state.size = Number(size.value); state.page = 1; render(); });
    state.setRows = (data, renderer) => { state.data = data; state.renderer = renderer; render(); };
    states.set(table, state); render(); return state;
  }
  window.AdminTables = { setRows(table, data, renderer) { create(table).setRows(data, renderer); } };
  document.querySelectorAll('table[data-paginate]').forEach(create);
})();
