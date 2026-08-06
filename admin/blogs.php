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
  <title>Blogs | GperyaPH Admin</title>
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
    .blog-item-row {
      display: flex;
      gap: 16px;
      align-items: center;
      padding: 16px;
      background: var(--surface-soft);
      border: 1px solid var(--border);
      border-radius: 8px;
      margin-bottom: 12px;
    }
    .blog-item-row img {
      width: 112px;
      height: 72px;
      object-fit: cover;
      border-radius: 6px;
      border: 1px solid var(--border);
      flex-shrink: 0;
      background: var(--surface-warm);
    }
    .admin-actions {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
      justify-content: flex-end;
    }
    .seo-rating {
      min-width: 112px;
      padding: 10px;
      border: 1px solid #dcdcde;
      border-radius: 8px;
      background: #fff;
      text-align: center;
      flex-shrink: 0;
    }
    .seo-rating-score {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 64px;
      height: 30px;
      border-radius: 4px;
      background: #fff1f1;
      border: 1px solid #e5aaaa;
      color: var(--danger);
      font-weight: 900;
      font-size: 0.9rem;
    }
    .seo-rating-score.ok {
      background: #e9f8ef;
      border-color: #9bd5af;
      color: #008a20;
    }
    .seo-rating-score.warn {
      background: #fff8e5;
      border-color: #f0b849;
      color: #996800;
    }
    .seo-rating-label {
      display: block;
      margin-top: 6px;
      color: var(--text-muted);
      font-size: 0.74rem;
      font-weight: 800;
    }
    .seo-rating-details {
      margin-top: 8px;
      color: var(--text-muted);
      font-size: 0.72rem;
      line-height: 1.35;
    }
    .blog-status-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      margin-top: 6px;
      padding: 3px 8px;
      border-radius: 999px;
      background: #e9f8ef;
      color: #008a20;
      border: 1px solid #9bd5af;
      font-size: 0.72rem;
      font-weight: 900;
      text-transform: uppercase;
    }
    .blog-status-badge.draft {
      background: #fff8e5;
      color: #996800;
      border-color: #f0b849;
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
    @media (max-width: 760px) {
      .admin-header,
      .blog-item-row {
        align-items: flex-start;
        flex-direction: column;
      }
      .admin-actions {
        justify-content: flex-start;
      }
    }
  </style>
</head>
<body>
  <div class="page-shell">
    <header class="site-header">
      <div class="header-inner">
        <a href="/" class="brand-logo">
          GperyaPH <span class="badge-tag" style="background-color: var(--brand); color:#fff;">ADMIN</span>
        </a>
        <div style="display:flex; gap:8px; align-items:center;">
          <a href="/admin/playnow-tracker.php" class="btn btn-secondary btn-sm">Play Now Tracker</a>
          <a href="/admin/settings.php" class="btn btn-secondary btn-sm">Settings</a>
          <a href="/admin/blog-publish.php" class="btn btn-primary btn-sm">Publish Post</a>
          <a href="/blog/" class="btn btn-secondary btn-sm">View Blog Hub</a>
          <form action="/logout.php" method="post" style="margin:0;">
            <?= csrf_input() ?>
            <button type="submit" class="btn btn-secondary btn-sm">Logout</button>
          </form>
        </div>
      </div>
    </header>

    <main id="main-content" style="padding: 20px 16px;">
      <div class="admin-container">
        <div class="admin-header">
          <div>
            <h1 style="font-size:1.6rem; color:var(--brand-dark);">Blogs</h1>
            <p style="font-size:0.88rem; color:var(--text-muted);">Review published posts, open posts for editing, or delete old entries.</p>
          </div>
          <a href="/admin/blog-publish.php" class="btn btn-primary btn-sm">New Blog Post</a>
        </div>

        <input type="hidden" id="csrf-token" value="<?= h(csrf_token()) ?>">
        <div id="notice" class="notice" role="status"></div>
        <div id="custom-blog-list">
          <p style="color:var(--text-muted); font-size:0.9rem;">Loading blog posts...</p>
        </div>
        <div id="blog-pagination" style="display:none; align-items:center; justify-content:flex-end; gap:8px; margin-top:14px;">
          <button type="button" id="blog-prev-page" class="btn btn-secondary btn-sm">Previous</button>
          <span id="blog-page-status" style="font-size:0.85rem; color:var(--text-muted);"></span>
          <button type="button" id="blog-next-page" class="btn btn-secondary btn-sm">Next</button>
        </div>
      </div>
    </main>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', () => {
      let csrfToken = document.getElementById('csrf-token').value;
      const list = document.getElementById('custom-blog-list');
      const notice = document.getElementById('notice');
      const pagination = document.getElementById('blog-pagination');
      const prevPageBtn = document.getElementById('blog-prev-page');
      const nextPageBtn = document.getElementById('blog-next-page');
      const pageStatus = document.getElementById('blog-page-status');
      const params = new URLSearchParams(window.location.search);
      let blogs = [];
      let currentPage = Math.max(1, Number.parseInt(params.get('page') || '1', 10) || 1);
      const pageSize = 10;
      let totalPages = 1;

      function showNotice(message, type) {
        notice.textContent = message;
        notice.className = 'notice ' + type;
      }

      if (params.get('saved') === '1') {
        showNotice('Blog post saved successfully.', 'ok');
      }

      function updateCsrf(value) {
        if (!value) return;
        csrfToken = value;
        document.getElementById('csrf-token').value = value;
      }

      function escapeHtml(value) {
        return String(value || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
      }

      function plainTextFromMarkdown(value) {
        return String(value || '')
          .replace(/```[\s\S]*?```/g, ' ')
          .replace(/!\[[^\]]*\]\([^)]+\)/g, ' ')
          .replace(/\[([^\]]+)\]\([^)]+\)/g, '$1')
          .replace(/[#>*_`~|[\](){}-]/g, ' ')
          .replace(/\s+/g, ' ')
          .trim();
      }

      function countWords(value) {
        const words = plainTextFromMarkdown(value).match(/[\p{L}\p{N}]+(?:['’.-][\p{L}\p{N}]+)*/gu);
        return words ? words.length : 0;
      }

      function normalizeText(value) {
        return String(value || '').toLowerCase().replace(/[^a-z0-9\s-]+/g, ' ').replace(/\s+/g, ' ').trim();
      }

      function countPhrase(text, phrase) {
        const cleanPhrase = normalizeText(phrase);
        if (!cleanPhrase) return 0;
        const words = cleanPhrase.split(/\s+/).map((word) => word.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));
        const pattern = new RegExp('\\b' + words.join('\\s+') + '\\b', 'gi');
        return (normalizeText(text).match(pattern) || []).length;
      }

      function deriveFocusKeyphrase(blog) {
        const savedKeyphrase = normalizeText(blog.focusKeyphrase || '');
        if (savedKeyphrase) return savedKeyphrase;
        const titleWords = normalizeText(blog.title || '')
          .split(/\s+/)
          .filter((word) => word.length > 2 && !['the', 'and', 'for', 'with', 'your', 'guide', 'how'].includes(word));
        return titleWords.slice(0, 3).join(' ') || normalizeText(blog.category || '');
      }

      function scoreState(score) {
        if (score >= 80) return { label: 'Good', className: 'ok' };
        if (score >= 55) return { label: 'OK', className: 'warn' };
        return { label: 'Needs work', className: '' };
      }

      function analyzeBlogSeo(blog) {
        const keyphrase = deriveFocusKeyphrase(blog);
        const titleText = blog.title || '';
        const slugText = blog.slug || blog.id || '';
        const excerptText = blog.excerpt || '';
        const markdown = blog.content || '';
        const plainText = plainTextFromMarkdown(markdown);
        const wordCount = countWords(markdown);
        const paragraphs = markdown.split(/\n\s*\n/).map((item) => plainTextFromMarkdown(item)).filter(Boolean);
        const firstParagraph = paragraphs[0] || '';
        const headings = (markdown.match(/^#{2,3}\s+/gm) || []).length;
        const images = markdown.match(/!\[[^\]]*\]\([^)]+\)/g) || [];
        const links = markdown.match(/\[[^\]]+\]\((https?:\/\/|\/)[^)]+\)/g) || [];
        const density = wordCount > 0 && keyphrase ? (countPhrase(plainText, keyphrase) / wordCount) * 100 : 0;

        const checks = [
          { ok: Boolean(keyphrase), points: 12 },
          { ok: keyphrase && countPhrase(titleText, keyphrase), points: 12 },
          { ok: keyphrase && countPhrase(slugText.replace(/-/g, ' '), keyphrase), points: 10 },
          { ok: keyphrase && countPhrase(excerptText, keyphrase), points: 10 },
          { ok: keyphrase && countPhrase(firstParagraph, keyphrase), points: 10 },
          { ok: wordCount >= 300, points: 12 },
          { ok: density >= 0.5 && density <= 3, points: 10 },
          { ok: headings > 0, points: 8 },
          { ok: images.length > 0 || (blog.featuredImage && !blog.featuredImage.includes('default-featured')), points: 6 },
          { ok: links.length > 0, points: 10 },
        ];
        const score = checks.reduce((total, check) => total + (check.ok ? check.points : 0), 0);
        const state = scoreState(score);
        return {
          score,
          label: state.label,
          className: state.className,
          keyphrase,
          wordCount,
          passed: checks.filter((check) => check.ok).length,
          total: checks.length,
        };
      }

      async function parseJsonResponse(response) {
        const contentType = response.headers.get('content-type') || '';
        const text = await response.text();

        if (!contentType.includes('application/json')) {
          const cleanText = text.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
          throw new Error(cleanText || 'Server returned a non-JSON response.');
        }

        try {
          return JSON.parse(text);
        } catch (error) {
          throw new Error('Server returned invalid JSON.');
        }
      }

      async function loadBlogs() {
        const data = new FormData();
        data.append('csrf_token', csrfToken);
        data.append('count', String(pageSize));
        data.append('page', String(currentPage));

        const response = await fetch('/admin/blog-list.php', {
          method: 'POST',
          headers: { 'Accept': 'application/json', 'X-Requested-With': 'fetch' },
          body: data
        });
        const result = await parseJsonResponse(response);
        updateCsrf(result.csrfToken);
        if (!response.ok || !result.ok) {
          throw new Error(result.error || 'Blog list could not be loaded.');
        }
        blogs = result.blogs || [];
        totalPages = result.pagination ? result.pagination.totalPages : 1;
        currentPage = result.pagination ? result.pagination.page : currentPage;
        if (!blogs.length && currentPage > 1) {
          currentPage = totalPages;
          await loadBlogs();
          return;
        }
        renderList();
      }

      function renderList() {
        if (!blogs.length) {
          list.innerHTML = '<p style="color:var(--text-muted); font-size:0.9rem; padding:16px; background:var(--surface-soft); border-radius:8px;">No custom blogs added yet.</p>';
          pagination.style.display = 'none';
          return;
        }

        list.innerHTML = blogs.map((blog) => {
          const id = blog.id || blog.slug;
          const slug = blog.slug || id;
          const status = blog.status === 'draft' ? 'draft' : 'published';
          const seo = analyzeBlogSeo(blog);
          return `
            <div class="blog-item-row">
              <img src="${escapeHtml(blog.featuredImage || '/uploads/blogs/default-featured.svg')}" alt="${escapeHtml(blog.title)}">
              <div style="flex:1; min-width:220px;">
                <strong style="font-size:1rem; color:var(--brand-dark); display:block;">${escapeHtml(blog.title)}</strong>
                <span style="font-size:0.8rem; color:var(--text-muted); display:block; margin-top:2px;">
                  ${escapeHtml(blog.category)} | Slug: <code style="color:var(--brand);">${escapeHtml(slug)}</code> | ${escapeHtml(blog.date || '')} | By ${escapeHtml(blog.author || '')}
                </span>
                <span class="blog-status-badge ${status}">${escapeHtml(status)}</span>
                <p style="font-size:0.85rem; color:var(--text); margin-top:4px;">${escapeHtml(blog.excerpt || '')}</p>
              </div>
              <div class="seo-rating" title="Estimated Yoast-style SEO rating">
                <span class="seo-rating-score ${seo.className}">${seo.score}/100</span>
                <span class="seo-rating-label">${escapeHtml(seo.label)}</span>
                <div class="seo-rating-details">${escapeHtml(seo.passed + '/' + seo.total)} checks · ${escapeHtml(String(seo.wordCount))} words<br>Keyphrase: ${escapeHtml(seo.keyphrase || 'none')}</div>
              </div>
              <div class="admin-actions">
                ${status === 'published' ? `<a href="/blog/${encodeURIComponent(slug)}/" target="_blank" class="btn btn-primary btn-sm" style="text-decoration:none;">View</a>` : '<span class="btn btn-secondary btn-sm" style="opacity:0.55; cursor:not-allowed;">Draft</span>'}
                <a href="/admin/blog-publish.php?id=${encodeURIComponent(id)}" class="btn btn-secondary btn-sm" style="text-decoration:none;">Edit</a>
                <button type="button" class="btn btn-secondary btn-sm" data-delete="${escapeHtml(id)}" style="color:var(--danger); border-color:var(--danger);">Delete</button>
              </div>
            </div>
          `;
        }).join('');

        pagination.style.display = totalPages > 1 ? 'flex' : 'none';
        pageStatus.textContent = `Page ${currentPage} of ${totalPages}`;
        prevPageBtn.disabled = currentPage <= 1;
        nextPageBtn.disabled = currentPage >= totalPages;
      }

      prevPageBtn.addEventListener('click', async () => {
        if (currentPage <= 1) return;
        currentPage -= 1;
        await loadBlogs().catch((error) => showNotice(error.message || 'Blog list could not be loaded.', 'error'));
      });

      nextPageBtn.addEventListener('click', async () => {
        if (currentPage >= totalPages) return;
        currentPage += 1;
        await loadBlogs().catch((error) => showNotice(error.message || 'Blog list could not be loaded.', 'error'));
      });

      list.addEventListener('click', async (event) => {
        const deleteButton = event.target.closest('[data-delete]');
        if (!deleteButton || !confirm('Delete this custom blog post?')) return;

        const data = new FormData();
        data.append('csrf_token', csrfToken);
        data.append('id', deleteButton.dataset.delete);
        const response = await fetch('/admin/blog-delete.php', {
          method: 'POST',
          headers: { 'Accept': 'application/json', 'X-Requested-With': 'fetch' },
          body: data
        });
        const result = await parseJsonResponse(response);
        updateCsrf(result.csrfToken);
        if (!response.ok || !result.ok) {
          showNotice(result.error || 'Blog post could not be deleted.', 'error');
          return;
        }
        showNotice('Blog post deleted.', 'ok');
        await loadBlogs().catch((error) => showNotice(error.message || 'Blog list could not be loaded.', 'error'));
      });

      loadBlogs().catch((error) => showNotice(error.message || 'Blog list could not be loaded.', 'error'));
    });
  </script>
</body>
</html>
