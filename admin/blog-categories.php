<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/blog-storage.php';

require_auth();
?>
<!DOCTYPE html>
<html lang="en-PH">
<head>
  <meta name="robots" content="noindex, nofollow">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Blog Categories | GperyaPH Admin</title>
  <link rel="preload" href="/assets/css/styles.min.css" as="style"><link rel="stylesheet" href="/assets/css/styles.min.css">
  <link rel="icon" href="/assets/icons/favicon.ico">
  <style>
    .admin-container {
      max-width: 980px;
      margin: 40px auto;
      padding: 24px;
      background: var(--surface);
      border: 1px solid var(--border-strong);
      border-radius: var(--radius-lg);
      box-shadow: var(--shadow);
    }
    .admin-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 16px;
      margin-bottom: 24px;
      padding-bottom: 16px;
      border-bottom: 2px solid var(--border);
    }
    .category-layout {
      display: grid;
      grid-template-columns: minmax(0, 1fr) 320px;
      gap: 18px;
      align-items: start;
    }
    .category-row {
      display: grid;
      grid-template-columns: minmax(0, 1fr) auto;
      gap: 14px;
      align-items: center;
      padding: 16px;
      margin-bottom: 12px;
      background: var(--surface-soft);
      border: 1px solid var(--border);
      border-radius: 8px;
    }
    .category-name {
      display: flex;
      align-items: center;
      gap: 10px;
      color: var(--brand-dark);
      font-weight: 900;
      font-size: 1rem;
    }
    .category-chip {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 34px;
      height: 24px;
      padding: 0 8px;
      border-radius: 999px;
      background: var(--surface-warm);
      color: var(--brand);
      border: 1px solid var(--border-strong);
      font-size: 0.74rem;
      font-weight: 900;
    }
    .category-meta {
      margin-top: 4px;
      color: var(--text-muted);
      font-size: 0.82rem;
    }
    .category-actions {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
      justify-content: flex-end;
    }
    .category-panel {
      position: sticky;
      top: 92px;
      padding: 16px;
      background: var(--surface-soft);
      border: 1px solid var(--border);
      border-radius: 8px;
    }
    .form-group {
      display: grid;
      gap: 6px;
      margin-bottom: 12px;
    }
    .form-control {
      width: 100%;
      padding: 10px 12px;
      border: 1px solid var(--border-strong);
      border-radius: var(--radius-sm);
      font: inherit;
      background: #fff;
      color: var(--text);
    }
    .notice {
      display: none;
      padding: 10px 12px;
      border-radius: var(--radius-sm);
      margin-bottom: 14px;
      font-size: 0.9rem;
    }
    .notice.ok { display: block; background: #e9f8ef; color: #0d6630; border: 1px solid #9bd5af; }
    .notice.error { display: block; background: #fff1f1; color: var(--danger); border: 1px solid #e5aaaa; }
    @media (max-width: 820px) {
      .admin-header,
      .category-row {
        align-items: flex-start;
        grid-template-columns: 1fr;
      }
      .category-layout {
        grid-template-columns: 1fr;
      }
      .category-panel {
        position: static;
      }
      .category-actions {
        justify-content: flex-start;
      }
    }
  </style>
</head>
<body>
  <div class="page-shell">
    <?php require __DIR__ . '/partials/admin-header.php'; ?>

    <main id="main-content" style="padding: 20px 16px;">
      <div class="admin-container">
        <div class="admin-header">
          <div>
            <h1 style="font-size:1.6rem; color:var(--brand-dark);">Blog Categories</h1>
            <p style="font-size:0.88rem; color:var(--text-muted);">Create, rename, sort, and remove categories used by blog posts.</p>
          </div>
          <a href="/admin/blogs.php" class="btn btn-secondary btn-sm">Back to Blogs</a>
        </div>

        <input type="hidden" id="csrf-token" value="<?= h(csrf_token()) ?>">
        <div id="notice" class="notice" role="status"></div>

        <div class="category-layout">
          <section>
            <div id="category-list">
              <p style="color:var(--text-muted); font-size:0.9rem;">Loading categories...</p>
            </div>
          </section>

          <aside class="category-panel" aria-label="Category form">
            <h2 id="category-form-title" style="font-size:1.1rem; color:var(--brand-dark); margin-bottom:10px;">Add Category</h2>
            <form id="category-form">
              <input type="hidden" id="category-id" name="id">
              <div class="form-group">
                <label for="category-name">Name *</label>
                <input type="text" id="category-name" name="name" class="form-control" maxlength="50" required>
              </div>
              <div class="form-group">
                <label for="category-description">Description</label>
                <textarea id="category-description" name="description" class="form-control" maxlength="180" rows="3"></textarea>
              </div>
              <div class="form-group">
                <label for="category-sort-order">Sort order</label>
                <input type="number" id="category-sort-order" name="sort_order" class="form-control" min="0" max="100000" step="1" value="0">
              </div>
              <div style="display:flex; gap:8px; flex-wrap:wrap;">
                <button type="submit" class="btn btn-primary btn-sm">Save Category</button>
                <button type="button" id="category-reset" class="btn btn-secondary btn-sm">Clear</button>
              </div>
            </form>
          </aside>
        </div>
      </div>
    </main>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', () => {
      let csrfToken = document.getElementById('csrf-token').value;
      let categories = [];
      const list = document.getElementById('category-list');
      const notice = document.getElementById('notice');
      const form = document.getElementById('category-form');
      const formTitle = document.getElementById('category-form-title');
      const idField = document.getElementById('category-id');
      const nameField = document.getElementById('category-name');
      const descriptionField = document.getElementById('category-description');
      const sortOrderField = document.getElementById('category-sort-order');
      const resetButton = document.getElementById('category-reset');

      function escapeHtml(value) {
        return String(value || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
      }

      function showNotice(message, type) {
        notice.textContent = message;
        notice.className = 'notice ' + type;
      }

      function updateCsrf(value) {
        if (!value) return;
        csrfToken = value;
        document.getElementById('csrf-token').value = value;
      }

      async function parseJsonResponse(response) {
        const contentType = response.headers.get('content-type') || '';
        const text = await response.text();
        if (!contentType.includes('application/json')) {
          throw new Error(text.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim() || 'Server returned a non-JSON response.');
        }
        return JSON.parse(text);
      }

      function resetForm() {
        idField.value = '';
        nameField.value = '';
        descriptionField.value = '';
        sortOrderField.value = '0';
        formTitle.textContent = 'Add Category';
        nameField.focus();
      }

      function renderCategories() {
        if (!categories.length) {
          list.innerHTML = '<p style="color:var(--text-muted); font-size:0.9rem; padding:16px; background:var(--surface-soft); border-radius:8px;">No categories yet.</p>';
          return;
        }

        list.innerHTML = categories.map((category) => {
          const postCount = Number(category.postCount || 0);
          return `
            <article class="category-row">
              <div>
                <div class="category-name">
                  ${escapeHtml(category.name)}
                  <span class="category-chip">${escapeHtml(String(postCount))} ${postCount === 1 ? 'post' : 'posts'}</span>
                </div>
                <div class="category-meta">
                  Slug: <code>${escapeHtml(category.id)}</code> · Sort: ${escapeHtml(String(category.sortOrder || 0))}
                  ${category.description ? '<br>' + escapeHtml(category.description) : ''}
                </div>
              </div>
              <div class="category-actions">
                <button type="button" class="btn btn-secondary btn-sm" data-edit="${escapeHtml(category.id)}">Edit</button>
                <button type="button" class="btn btn-secondary btn-sm" data-delete="${escapeHtml(category.id)}" ${postCount > 0 ? 'disabled title="This category has posts."' : ''} style="color:var(--danger); border-color:var(--danger);">Delete</button>
              </div>
            </article>
          `;
        }).join('');
      }

      async function loadCategories() {
        const data = new FormData();
        data.append('csrf_token', csrfToken);
        const response = await fetch('/admin/blog-category-list.php', {
          method: 'POST',
          headers: { 'Accept': 'application/json', 'X-Requested-With': 'fetch' },
          body: data
        });
        const result = await parseJsonResponse(response);
        updateCsrf(result.csrfToken);
        if (!response.ok || !result.ok) {
          throw new Error(result.error || 'Categories could not be loaded.');
        }
        categories = result.categories || [];
        renderCategories();
      }

      form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const data = new FormData(form);
        data.append('csrf_token', csrfToken);
        data.append('action', 'save');
        const response = await fetch('/admin/blog-category-save.php', {
          method: 'POST',
          headers: { 'Accept': 'application/json', 'X-Requested-With': 'fetch' },
          body: data
        });
        const result = await parseJsonResponse(response);
        updateCsrf(result.csrfToken);
        if (!response.ok || !result.ok) {
          showNotice((result.errors || [result.error || 'Category could not be saved.']).join(' '), 'error');
          return;
        }
        showNotice('Category saved.', 'ok');
        resetForm();
        await loadCategories().catch((error) => showNotice(error.message || 'Categories could not be loaded.', 'error'));
      });

      list.addEventListener('click', async (event) => {
        const editButton = event.target.closest('[data-edit]');
        const deleteButton = event.target.closest('[data-delete]');

        if (editButton) {
          const category = categories.find((item) => item.id === editButton.dataset.edit);
          if (!category) return;
          idField.value = category.id || '';
          nameField.value = category.name || '';
          descriptionField.value = category.description || '';
          sortOrderField.value = String(category.sortOrder || 0);
          formTitle.textContent = 'Edit Category';
          nameField.focus();
          return;
        }

        if (deleteButton) {
          const category = categories.find((item) => item.id === deleteButton.dataset.delete);
          if (!category || !confirm('Delete category "' + category.name + '"?')) return;
          const data = new FormData();
          data.append('csrf_token', csrfToken);
          data.append('action', 'delete');
          data.append('id', category.id);
          const response = await fetch('/admin/blog-category-save.php', {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'fetch' },
            body: data
          });
          const result = await parseJsonResponse(response);
          updateCsrf(result.csrfToken);
          if (!response.ok || !result.ok) {
            showNotice(result.error || 'Category could not be deleted.', 'error');
            return;
          }
          showNotice('Category deleted.', 'ok');
          await loadCategories().catch((error) => showNotice(error.message || 'Categories could not be loaded.', 'error'));
        }
      });

      resetButton.addEventListener('click', resetForm);
      loadCategories().catch((error) => showNotice(error.message || 'Categories could not be loaded.', 'error'));
    });
  </script>
</body>
</html>
