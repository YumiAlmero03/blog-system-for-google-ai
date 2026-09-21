<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/blog-storage.php';

require_auth();
?>
<!DOCTYPE html>
<html lang="en-PH">
<head>
  <meta name="robots" content="noindex, nofollow">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Blog Tags | Admin</title>
  <link rel="preload" href="/admin/style.css" as="style"><link rel="stylesheet" href="/admin/style.css">
  <link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
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
    .tag-layout {
      display: grid;
      grid-template-columns: minmax(0, 1fr) 320px;
      gap: 18px;
      align-items: start;
    }
    .tag-row {
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
    .tag-name {
      display: flex;
      align-items: center;
      gap: 10px;
      color: var(--brand-dark);
      font-weight: 900;
      font-size: 1rem;
    }
    .tag-chip {
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
    .tag-meta {
      margin-top: 4px;
      color: var(--text-muted);
      font-size: 0.82rem;
    }
    .tag-actions {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
      justify-content: flex-end;
    }
    .tag-panel {
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
      .tag-row {
        align-items: flex-start;
        grid-template-columns: 1fr;
      }
      .tag-layout {
        grid-template-columns: 1fr;
      }
      .tag-panel {
        position: static;
      }
      .tag-actions {
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
            <h1 style="font-size:1.6rem; color:var(--brand-dark);">Blog Tags</h1>
            <p style="font-size:0.88rem; color:var(--text-muted);">Create, rename, and remove tags used by blog posts.</p>
          </div>
          <a href="/admin/blogs.php" class="btn btn-secondary btn-sm">Back to Blogs</a>
        </div>

        <input type="hidden" id="csrf-token" value="<?= h(csrf_token()) ?>">
        <div id="notice" class="notice" role="status"></div>

        <div class="tag-layout">
          <section>
            <div id="tag-list">
              <p style="color:var(--text-muted); font-size:0.9rem;">Loading tags...</p>
            </div>
          </section>

          <aside class="tag-panel" aria-label="Tag form">
            <h2 id="tag-form-title" style="font-size:1.1rem; color:var(--brand-dark); margin-bottom:10px;">Add Tag</h2>
            <form id="tag-form">
              <input type="hidden" id="tag-id" name="id">
              <div class="form-group">
                <label for="tag-name">Name *</label>
                <input type="text" id="tag-name" name="name" class="form-control" maxlength="50" required>
              </div>
              <div class="form-group">
                <label for="tag-slug">Slug</label>
                <input id="tag-slug" name="slug" class="form-control" maxlength="96">
              </div>
              <div style="display:flex; gap:8px; flex-wrap:wrap;">
                <button type="submit" class="btn btn-primary btn-sm">Save Tag</button>
                <button type="button" id="tag-reset" class="btn btn-secondary btn-sm">Clear</button>
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
      let tags = [];
      const list = document.getElementById('tag-list');
      const notice = document.getElementById('notice');
      const form = document.getElementById('tag-form');
      const formTitle = document.getElementById('tag-form-title');
      const idField = document.getElementById('tag-id');
      const nameField = document.getElementById('tag-name');
      const slugField = document.getElementById('tag-slug');
      const resetButton = document.getElementById('tag-reset');

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
        slugField.value = '';
        nameField.value = '';
        formTitle.textContent = 'Add Tag';
        nameField.focus();
      }


      function renderTags() {

        if (!tags.length) {
          list.innerHTML = '<p style="color:var(--text-muted); font-size:0.9rem; padding:16px; background:var(--surface-soft); border-radius:8px;">No tags yet.</p>';
          return;
        }

        list.innerHTML = tags.map((tag) => {
          const postCount = Number(tag.postCount || 0);
          return `
            <article class="tag-row">
              <div>
                <div class="tag-name">
                  ${escapeHtml(tag.name)}
                  <span class="tag-chip">${escapeHtml(String(postCount))} ${postCount === 1 ? 'post' : 'posts'}</span>
                </div>
                <div class="tag-meta">
                  Slug: <code>${escapeHtml(tag.slug)}</code> · Updated: ${escapeHtml(new Date(tag.updatedAt * 1000).toLocaleDateString())}
                  ${tag.description ? '<br>' + escapeHtml(tag.description) : ''}
                </div>
              </div>
              <div class="tag-actions">
                <button type="button" class="btn btn-secondary btn-sm" data-edit="${escapeHtml(tag.id)}">Edit</button>
                <button type="button" class="btn btn-secondary btn-sm" data-delete="${escapeHtml(tag.id)}"  style="color:var(--danger); border-color:var(--danger);">Delete</button>
              </div>
            </article>
          `;
        }).join('');
      }

      async function loadTags() {
        const data = new FormData();
        data.append('csrf_token', csrfToken);
        const response = await fetch('/admin/blog-tag-list.php', {
          method: 'POST',
          headers: { 'Accept': 'application/json', 'X-Requested-With': 'fetch' },
          body: data
        });
        const result = await parseJsonResponse(response);
        updateCsrf(result.csrfToken);
        if (!response.ok || !result.ok) {
          throw new Error(result.error || 'Tags could not be loaded.');
        }
        tags = result.tags || [];
        renderTags();
      }

      form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const data = new FormData(form);
        data.append('csrf_token', csrfToken);
        data.append('action', 'save');
        const response = await fetch('/admin/blog-tag-save.php', {
          method: 'POST',
          headers: { 'Accept': 'application/json', 'X-Requested-With': 'fetch' },
          body: data
        });
        const result = await parseJsonResponse(response);
        updateCsrf(result.csrfToken);
        if (!response.ok || !result.ok) {
          showNotice((result.errors || [result.error || 'Tag could not be saved.']).join(' '), 'error');
          return;
        }
        showNotice('Tag saved.', 'ok');
        resetForm();
        await loadTags().catch((error) => showNotice(error.message || 'Tags could not be loaded.', 'error'));
      });

      list.addEventListener('click', async (event) => {
        const editButton = event.target.closest('[data-edit]');
        const deleteButton = event.target.closest('[data-delete]');

        if (editButton) {
          const tag = tags.find((item) => item.id === editButton.dataset.edit);
          if (!tag) return;
          idField.value = tag.id || '';
          nameField.value = tag.name || '';
          slugField.value = tag.slug || '';
              formTitle.textContent = 'Edit Tag';
          nameField.focus();
          return;
        }

        if (deleteButton) {
          const tag = tags.find((item) => item.id === deleteButton.dataset.delete);
          if (!tag || !confirm('Delete tag "' + tag.name + '"?')) return;
          const data = new FormData();
          data.append('csrf_token', csrfToken);
          data.append('action', 'delete');
          data.append('id', tag.id);
          const response = await fetch('/admin/blog-tag-save.php', {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'fetch' },
            body: data
          });
          const result = await parseJsonResponse(response);
          updateCsrf(result.csrfToken);
          if (!response.ok || !result.ok) {
            showNotice(result.error || 'Tag could not be deleted.', 'error');
            return;
          }
          showNotice('Tag deleted.', 'ok');
          await loadTags().catch((error) => showNotice(error.message || 'Tags could not be loaded.', 'error'));
        }
      });

      resetButton.addEventListener('click', resetForm);
      loadTags().catch((error) => showNotice(error.message || 'Tags could not be loaded.', 'error'));
    });
  </script>
</body>
</html>
