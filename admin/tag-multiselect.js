(() => {
  'use strict';

  function escapeHtml(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  class TagMultiSelect {
    constructor(container, options = {}) {
      if (!container) return;
      this.container = container;
      this.name = options.name || 'tag_ids[]';
      this.placeholder = options.placeholder || 'Select tags...';
      this.onChange = typeof options.onChange === 'function' ? options.onChange : null;
      this.tags = Array.isArray(options.tags) ? [...options.tags] : [];
      this.selectedIds = new Set((options.selectedIds || []).map(String));
      this.isOpen = false;

      this.init();
    }

    init() {
      this.container.innerHTML = `
        <div class="tag-multiselect">
          <input type="hidden" name="tags_present" value="1">
          <div class="tag-hidden-inputs"></div>
          <div class="tag-dropdown-wrapper">
            <button type="button" class="tag-dropdown-toggle" aria-haspopup="listbox" aria-expanded="false">
              <span class="tag-dropdown-label">${escapeHtml(this.placeholder)}</span>
              <span class="tag-dropdown-arrow">&#9662;</span>
            </button>
            <div class="tag-dropdown-panel" hidden>
              <div class="tag-dropdown-search-wrap">
                <input type="text" class="tag-dropdown-search" placeholder="Search tags..." autocomplete="off">
              </div>
              <div class="tag-dropdown-actions">
                <button type="button" class="tag-action-btn tag-select-all-btn">Select All</button>
                <button type="button" class="tag-action-btn tag-clear-all-btn">Clear All</button>
              </div>
              <div class="tag-dropdown-list" role="listbox"></div>
              <div class="tag-dropdown-empty" hidden>No tags found</div>
            </div>
          </div>
          <div class="tag-pills-wrap" aria-live="polite"></div>
        </div>
      `;

      this.wrapper = this.container.querySelector('.tag-multiselect');
      this.hiddenInputs = this.container.querySelector('.tag-hidden-inputs');
      this.toggleBtn = this.container.querySelector('.tag-dropdown-toggle');
      this.labelSpan = this.container.querySelector('.tag-dropdown-label');
      this.panel = this.container.querySelector('.tag-dropdown-panel');
      this.searchInput = this.container.querySelector('.tag-dropdown-search');
      this.selectAllBtn = this.container.querySelector('.tag-select-all-btn');
      this.clearAllBtn = this.container.querySelector('.tag-clear-all-btn');
      this.listEl = this.container.querySelector('.tag-dropdown-list');
      this.emptyEl = this.container.querySelector('.tag-dropdown-empty');
      this.pillsWrap = this.container.querySelector('.tag-pills-wrap');

      this.bindEvents();
      this.render();
    }

    bindEvents() {
      this.toggleBtn.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        this.toggle();
      });

      this.searchInput.addEventListener('input', () => {
        this.filterTags(this.searchInput.value.trim().toLowerCase());
      });
      this.searchInput.addEventListener('click', (e) => e.stopPropagation());

      this.selectAllBtn.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        const query = this.searchInput.value.trim().toLowerCase();
        this.tags.forEach(t => {
          if (!query || t.name.toLowerCase().includes(query)) {
            this.selectedIds.add(String(t.id));
          }
        });
        this.syncState();
        if (this.onChange) this.onChange(this.getSelected());
      });

      this.clearAllBtn.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        this.selectedIds.clear();
        this.syncState();
        if (this.onChange) this.onChange(this.getSelected());
      });

      this.listEl.addEventListener('change', (e) => {
        const checkbox = e.target.closest('input[type="checkbox"]');
        if (!checkbox) return;
        const id = String(checkbox.value);
        if (checkbox.checked) {
          this.selectedIds.add(id);
        } else {
          this.selectedIds.delete(id);
        }
        this.syncState();
        if (this.onChange) this.onChange(this.getSelected());
      });

      this.panel.addEventListener('click', (e) => {
        e.stopPropagation();
      });

      this.pillsWrap.addEventListener('click', (e) => {
        const removeBtn = e.target.closest('.tag-pill-remove');
        if (!removeBtn) return;
        e.preventDefault();
        e.stopPropagation();
        const id = String(removeBtn.dataset.id || '');
        if (id) {
          this.selectedIds.delete(id);
          this.syncState();
          if (this.onChange) this.onChange(this.getSelected());
        }
      });

      this._onDocumentClick = (e) => {
        if (!this.wrapper.contains(e.target)) {
          this.close();
        }
      };
      document.addEventListener('click', this._onDocumentClick);

      this._onKeydown = (e) => {
        if (e.key === 'Escape' && this.isOpen) {
          this.close();
          this.toggleBtn.focus();
        }
      };
      document.addEventListener('keydown', this._onKeydown);
    }

    toggle() {
      if (this.isOpen) {
        this.close();
      } else {
        this.open();
      }
    }

    open() {
      this.isOpen = true;
      this.panel.hidden = false;
      this.toggleBtn.setAttribute('aria-expanded', 'true');
      this.searchInput.value = '';
      this.filterTags('');
      setTimeout(() => this.searchInput.focus(), 50);
    }

    close() {
      this.isOpen = false;
      this.panel.hidden = true;
      this.toggleBtn.setAttribute('aria-expanded', 'false');
    }

    filterTags(query) {
      let visibleCount = 0;
      const items = this.listEl.querySelectorAll('.tag-dropdown-item');
      items.forEach(item => {
        const name = (item.dataset.name || '').toLowerCase();
        const matches = !query || name.includes(query);
        item.style.display = matches ? 'flex' : 'none';
        if (matches) visibleCount++;
      });
      this.emptyEl.hidden = visibleCount > 0;
    }

    render() {
      this.listEl.innerHTML = this.tags.map(t => {
        const id = String(t.id);
        const isChecked = this.selectedIds.has(id);
        return `
          <label class="tag-dropdown-item ${isChecked ? 'is-selected' : ''}" data-id="${escapeHtml(id)}" data-name="${escapeHtml(t.name)}">
            <input type="checkbox" value="${escapeHtml(id)}" ${isChecked ? 'checked' : ''}>
            <span class="tag-dropdown-item-name">${escapeHtml(t.name)}</span>
          </label>
        `;
      }).join('');

      this.syncState();
    }

    syncState() {
      // 1. Update hidden inputs for form serialization
      this.hiddenInputs.innerHTML = Array.from(this.selectedIds).map(id =>
        `<input type="hidden" name="${escapeHtml(this.name)}" value="${escapeHtml(id)}">`
      ).join('');

      // 2. Update toggle button label
      const count = this.selectedIds.size;
      if (count === 0) {
        this.labelSpan.textContent = this.placeholder;
        this.labelSpan.classList.remove('has-selection');
      } else if (count === 1) {
        const firstId = Array.from(this.selectedIds)[0];
        const tag = this.tags.find(t => String(t.id) === firstId);
        this.labelSpan.textContent = tag ? tag.name : '1 tag selected';
        this.labelSpan.classList.add('has-selection');
      } else {
        this.labelSpan.textContent = `${count} tags selected`;
        this.labelSpan.classList.add('has-selection');
      }

      // 3. Update checkboxes inside dropdown list
      this.listEl.querySelectorAll('.tag-dropdown-item').forEach(item => {
        const id = item.dataset.id;
        const cb = item.querySelector('input[type="checkbox"]');
        const isSelected = this.selectedIds.has(id);
        if (cb) cb.checked = isSelected;
        item.classList.toggle('is-selected', isSelected);
      });

      // 4. Render pills
      this.pillsWrap.innerHTML = Array.from(this.selectedIds).map(id => {
        const tag = this.tags.find(t => String(t.id) === id);
        const name = tag ? tag.name : id;
        return `
          <span class="tag-pill" data-id="${escapeHtml(id)}">
            <span class="tag-pill-text">${escapeHtml(name)}</span>
            <button type="button" class="tag-pill-remove" data-id="${escapeHtml(id)}" aria-label="Remove ${escapeHtml(name)}">&times;</button>
          </span>
        `;
      }).join('');
    }

    setSelected(ids) {
      this.selectedIds = new Set((ids || []).map(String));
      this.syncState();
    }

    getSelected() {
      return Array.from(this.selectedIds);
    }

    setAvailableTags(tags, selectedIds) {
      this.tags = Array.isArray(tags) ? [...tags] : [];
      if (Array.isArray(selectedIds)) {
        this.selectedIds = new Set(selectedIds.map(String));
      }
      this.render();
    }

    destroy() {
      document.removeEventListener('click', this._onDocumentClick);
      document.removeEventListener('keydown', this._onKeydown);
    }
  }

  window.TagMultiSelect = TagMultiSelect;
})();

