<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/blog-storage.php';

require_auth();
$blogCategoryOptions = blog_categories_all();
?>
<!DOCTYPE html>
<html lang="en-PH">
<head>
  <meta name="robots" content="noindex, nofollow">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Blogs | Admin</title>
  <link rel="preload" href="/admin/style.css" as="style"><link rel="stylesheet" href="/admin/style.css">
  <link rel="icon" href="/assets/favicon.svg">
  <style>
    .admin-container {
      width: 100%;
      margin: 0;
      padding: 18px 20px;
      background: var(--surface);
    }
    .admin-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 16px;
      margin-bottom: 16px;
      padding-bottom: 12px;
      border-bottom: 1px solid var(--border);
    }
    .blog-table-wrap {
      width: 100%;
      overflow-x: auto;
      border: 1px solid var(--border);
      border-radius: 8px;
      background: var(--surface);
    }
    .blog-table {
      width: 100%;
      min-width: 1080px;
      border-collapse: collapse;
      font-size: 0.86rem;
    }
    .blog-table th,
    .blog-table td {
      padding: 10px 12px;
      border-bottom: 1px solid var(--border);
      text-align: left;
      vertical-align: middle;
    }
    .blog-table th {
      background: var(--surface-soft);
      color: var(--brand-dark);
      font-size: 0.74rem;
      font-weight: 900;
      text-transform: uppercase;
      white-space: nowrap;
    }
    .blog-table tbody tr:last-child td {
      border-bottom: 0;
    }
    .blog-title-cell strong {
      display: block;
      color: var(--brand-dark);
      font-size: 0.92rem;
      line-height: 1.3;
    }
    .blog-title-cell small,
    .blog-date-cell small {
      display: block;
      margin-top: 3px;
      color: var(--text-muted);
      line-height: 1.35;
    }
    .blog-number-cell {
      text-align: right;
      white-space: nowrap;
    }
    .seo-rating-label {
      display: block;
      font-weight: 900;
      white-space: nowrap;
    }
    .seo-rating-score,
    .seo-rating-details {
      display: block;
      margin-top: 3px;
      color: var(--text-muted);
      font-size: 0.72rem;
    }
    .quick-edit-row td {
      background: var(--surface-soft);
      padding: 14px;
    }
    .quick-edit-form {
      display: grid;
      grid-template-columns: minmax(180px, 1.4fr) minmax(160px, 1fr) minmax(150px, 0.9fr) minmax(130px, 0.7fr) minmax(170px, 0.9fr) minmax(170px, 0.9fr) auto;
      gap: 10px;
      align-items: end;
    }
    .quick-edit-form label {
      display: grid;
      gap: 4px;
      color: var(--brand-dark);
      font-size: 0.72rem;
      font-weight: 900;
      text-transform: uppercase;
    }
    .quick-edit-form input,
    .quick-edit-form select {
      min-width: 0;
      padding: 8px 9px;
      border: 1px solid var(--border-strong);
      border-radius: var(--radius-sm);
      font: inherit;
      font-size: 0.84rem;
      text-transform: none;
      color: var(--text);
      background: #fff;
    }
    .quick-edit-actions {
      display: flex;
      gap: 8px;
      white-space: nowrap;
    }
    .admin-actions {
      display: flex;
      gap: 8px;
      flex-wrap: nowrap;
      justify-content: flex-end;
      white-space: nowrap;
    }
    .blog-status-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 3px 8px;
      border-radius: 999px;
      background: #e9f8ef;
      color: #008a20;
      border: 1px solid #9bd5af;
      font-size: 0.72rem;
      font-weight: 900;
      text-transform: uppercase;
    }
    .blog-status-badge.draft,
    .blog-status-badge.scheduled {
      background: #fff8e5;
      color: #996800;
      border-color: #f0b849;
    }
    .blog-stats {
      display: flex;
      flex-wrap: wrap;
      gap: 6px;
    }
    .blog-stat {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      padding: 4px 8px;
      border: 1px solid var(--border);
      border-radius: 999px;
      background: #fff;
      color: var(--text-muted);
      font-size: 0.74rem;
      font-weight: 900;
    }
    .blog-list-tools {
      display: flex;
      gap: 8px;
      align-items: center;
      margin-bottom: 12px;
    }
    .blog-search-input {
      flex: 1;
      min-width: 180px;
      padding: 9px 12px;
      border: 1px solid var(--border-strong);
      border-radius: var(--radius-sm);
      font: inherit;
    }
    .blog-search-status {
      color: var(--text-muted);
      font-size: 0.82rem;
      min-width: 120px;
      text-align: right;
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
      .admin-header {
        align-items: flex-start;
        flex-direction: column;
      }
      .admin-container {
        padding: 14px 10px;
      }
      .admin-actions {
        justify-content: flex-start;
      }
      .quick-edit-form {
        grid-template-columns: minmax(180px, 1fr) minmax(180px, 1fr);
      }
      .quick-edit-actions {
        grid-column: 1 / -1;
      }
      .blog-list-tools {
        align-items: stretch;
        flex-direction: column;
      }
      .blog-search-status {
        min-width: 0;
        text-align: left;
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
            <h1 style="font-size:1.6rem; color:var(--brand-dark);">Blogs</h1>
            <p style="font-size:0.88rem; color:var(--text-muted);">Review published posts, open posts for editing, or delete old entries.</p>
          </div>
          <a href="/admin/blog-publish.php" class="btn btn-primary btn-sm">New Blog Post</a>
        </div>

        <input type="hidden" id="csrf-token" value="<?= h(csrf_token()) ?>">
        <input type="file" id="blog-import-file" accept=".zip,application/zip" hidden>
        <div id="notice" class="notice" role="status"></div>
        <div class="admin-actions" style="justify-content:flex-start; margin-bottom:12px;">
          <a href="/admin/blog-export.php" class="btn btn-secondary btn-sm">Download Blogs</a>
          <button type="button" id="blog-import-btn" class="btn btn-secondary btn-sm">Import Blogs</button>
        </div>
        <div class="blog-list-tools">
          <input type="search" id="blog-search" class="blog-search-input" placeholder="Search blogs..." autocomplete="off">
          <button type="button" id="blog-search-clear" class="btn btn-secondary btn-sm">Clear</button>
          <span id="blog-search-status" class="blog-search-status" aria-live="polite"></span>
        </div>
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
      const searchInput = document.getElementById('blog-search');
      const clearSearchBtn = document.getElementById('blog-search-clear');
      const searchStatus = document.getElementById('blog-search-status');
      const importButton = document.getElementById('blog-import-btn');
      const importFile = document.getElementById('blog-import-file');
      const params = new URLSearchParams(window.location.search);
      let blogs = [];
      let currentPage = Math.max(1, Number.parseInt(params.get('page') || '1', 10) || 1);
      const pageSize = 10;
      let totalPages = 1;
      let totalBlogs = 0;
      let searchTimer = 0;
      let yoastLoaderPromise = null;
      let yoastAnalysisRun = 0;
      const yoastRatingCache = new Map();
      let quickEditId = '';
      const defaultCategory = <?= json_encode(blog_default_category(), JSON_UNESCAPED_SLASHES) ?>;
      const categoryOptions = <?= json_encode(array_values(array_map(static fn (array $category): string => $category['name'], $blogCategoryOptions)), JSON_UNESCAPED_SLASHES) ?>;
      const yoastModuleUrl = '/assets/vendor/yoastseo/yoastseo.bundle.js?v=3.6.0';
      const yoastResearcherUrl = '/assets/vendor/yoastseo/researcher.bundle.js?v=3.6.0';

      function showNotice(message, type) {
        notice.textContent = message;
        notice.className = 'notice ' + type;
      }

      if (params.get('saved') === '1') {
        showNotice('Blog post saved successfully.', 'ok');
      }
      if (params.get('search') && searchInput) {
        searchInput.value = params.get('search');
      }

      function updateCsrf(value) {
        if (!value) return;
        csrfToken = value;
        document.getElementById('csrf-token').value = value;
      }

      function currentSearchTerm() {
        return searchInput ? searchInput.value.trim() : '';
      }

      function updateBrowserUrl() {
        const nextParams = new URLSearchParams(window.location.search);
        const searchTerm = currentSearchTerm();
        if (currentPage > 1) {
          nextParams.set('page', String(currentPage));
        } else {
          nextParams.delete('page');
        }
        if (searchTerm) {
          nextParams.set('search', searchTerm);
        } else {
          nextParams.delete('search');
        }
        nextParams.delete('saved');
        const nextQuery = nextParams.toString();
        const nextUrl = window.location.pathname + (nextQuery ? '?' + nextQuery : '');
        window.history.replaceState(null, '', nextUrl);
      }

      function updateSearchStatus() {
        const searchTerm = currentSearchTerm();
        if (!searchStatus) return;
        if (searchTerm) {
          searchStatus.textContent = `${totalBlogs.toLocaleString()} ${totalBlogs === 1 ? 'match' : 'matches'}`;
        } else {
          searchStatus.textContent = `${totalBlogs.toLocaleString()} ${totalBlogs === 1 ? 'blog' : 'blogs'}`;
        }
      }

      function escapeHtml(value) {
        return String(value || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
      }

      function cssEscape(value) {
        if (window.CSS && typeof window.CSS.escape === 'function') return window.CSS.escape(String(value || ''));
        return String(value || '').replace(/["\\]/g, '\\$&');
      }

      function slugify(value) {
        return String(value || '')
          .toLowerCase()
          .trim()
          .replace(/[^a-z0-9-]+/g, '-')
          .replace(/-+/g, '-')
          .replace(/^-|-$/g, '')
          .slice(0, 96);
      }

      function optionList(options, current) {
        const values = options.includes(current) ? options : [current, ...options].filter(Boolean);
        return values.map((option) => `<option value="${escapeHtml(option)}" ${option === current ? 'selected' : ''}>${escapeHtml(option)}</option>`).join('');
      }

      function quickEditRow(blog, id, status, category) {
        const publishedAt = blog.publishedAtInput || '';
        const scheduledAt = blog.scheduledAtInput || '';
        return `
          <tr class="quick-edit-row" data-quick-edit-row="${escapeHtml(id)}">
            <td colspan="8">
              <form class="quick-edit-form" data-quick-edit-form="${escapeHtml(id)}">
                <label>Title
                  <input type="text" name="title" value="${escapeHtml(blog.title || '')}" maxlength="160" required>
                </label>
                <label>Slug
                  <input type="text" name="slug" value="${escapeHtml(blog.slug || id)}" maxlength="96" pattern="[a-z0-9-]+" required>
                </label>
                <label>Category
                  <select name="category" required>${optionList(categoryOptions, category)}</select>
                </label>
                <label>Status
                  <select name="status" required>
                    <option value="draft" ${status === 'draft' ? 'selected' : ''}>Draft</option>
                    <option value="published" ${status === 'published' ? 'selected' : ''}>Publish now</option>
                    <option value="scheduled" ${status === 'scheduled' ? 'selected' : ''}>Schedule</option>
                  </select>
                </label>
                <label>Publish date/time
                  <input type="datetime-local" name="published_at" value="${escapeHtml(publishedAt)}">
                </label>
                <label>Scheduled date/time
                  <input type="datetime-local" name="scheduled_at" value="${escapeHtml(scheduledAt)}">
                </label>
                <div class="quick-edit-actions">
                  <button type="submit" class="btn btn-primary btn-sm">Save</button>
                  <button type="button" class="btn btn-secondary btn-sm" data-quick-cancel="true">Cancel</button>
                </div>
              </form>
            </td>
          </tr>
        `;
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

      function parseMarkdown(markdown) {
        let html = escapeHtml(markdown || '');
        html = html.replace(/```([\s\S]*?)```/g, '<pre><code>$1</code></pre>');
        html = html.replace(/^### (.*)$/gim, '<h3>$1</h3>');
        html = html.replace(/^## (.*)$/gim, '<h2>$1</h2>');
        html = html.replace(/^# (.*)$/gim, '<h1>$1</h1>');
        html = html.replace(/^&gt; (.*)$/gim, '<blockquote>$1</blockquote>');
        html = html.replace(/\[!\[([^\]]*)\]\((\/uploads\/blogs\/[a-zA-Z0-9._/-]+)\)\]\((https?:\/\/[^)\s]+|\/[^)\s]*)\)/g, '<a href="$3"><img src="$2" alt="$1"></a>');
        html = html.replace(/!\[([^\]]*)\]\((\/uploads\/blogs\/[a-zA-Z0-9._/-]+)\)/g, '<img src="$2" alt="$1">');
        html = html.replace(/\[([^\]]+)\]\((https?:\/\/[^)\s]+|\/[^)\s]*)\)/g, '<a href="$2">$1</a>');
        html = html.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
        html = html.replace(/\*([^*]+)\*/g, '<em>$1</em>');
        html = html.replace(/`([^`]+)`/g, '<code>$1</code>');
        return html
          .split(/\n\s*\n/)
          .map((block) => {
            const trimmed = block.trim();
            if (!trimmed) return '';
            if (/^<(h1|h2|h3|blockquote|pre|ul|ol|p|a|img)/i.test(trimmed)) return trimmed;
            if (/^[-*] /.test(trimmed)) return '<ul>' + trimmed.replace(/^[-*] (.*)$/gim, '<li>$1</li>') + '</ul>';
            if (/^\d+\. /.test(trimmed)) return '<ol>' + trimmed.replace(/^\d+\. (.*)$/gim, '<li>$1</li>') + '</ol>';
            return '<p>' + trimmed.replace(/\n/g, '<br>') + '</p>';
          })
          .join('');
      }

      function deriveFocusKeyphrase(blog) {
        return normalizeText(blog.focusKeyphrase || '');
      }

      function normalizeYoastScore(score) {
        if (typeof score !== 'number' || !Number.isFinite(score)) return 0;
        return Math.max(0, Math.min(100, Math.round(score <= 10 ? score * 10 : score)));
      }

      function getYoastAssessorResults(assessor) {
        if (!assessor) return [];
        if (Array.isArray(assessor.results)) return assessor.results;
        if (typeof assessor.getValidResults === 'function') return assessor.getValidResults();
        if (typeof assessor.getAllResults === 'function') return assessor.getAllResults();
        if (typeof assessor.getResults === 'function') return assessor.getResults();
        return [];
      }

      function getYoastOverallScore(assessor) {
        if (!assessor) return 0;
        if (typeof assessor.calculateOverallScore === 'function') return normalizeYoastScore(assessor.calculateOverallScore());
        if (typeof assessor.getOverallScore === 'function') return normalizeYoastScore(assessor.getOverallScore());
        return normalizeYoastScore(assessor.overallScore || assessor.score || 0);
      }

      function measureSeoTitleWidth(value) {
        const text = String(value || '').trim();
        if (!text) return 0;
        const canvas = measureSeoTitleWidth.canvas || document.createElement('canvas');
        measureSeoTitleWidth.canvas = canvas;
        const context = canvas.getContext ? canvas.getContext('2d') : null;
        if (!context) return text.length * 10;
        context.font = '20px Arial, sans-serif';
        return Math.round(context.measureText(text).width);
      }

      function getYoastModules() {
        if (!yoastLoaderPromise) {
          yoastLoaderPromise = Promise.all([
            import(yoastModuleUrl),
            import(yoastResearcherUrl),
          ]).then(([yoastModule, researcherModule]) => ({
            yoast: Object.assign({}, yoastModule.default || {}, yoastModule),
            EnglishResearcher: researcherModule.default || researcherModule.EnglishResearcher || researcherModule.Researcher || researcherModule,
          }));
        }
        return yoastLoaderPromise;
      }

      async function analyzeBlogWithYoast(blog) {
        const modules = await getYoastModules();
        const Paper = modules.yoast.Paper;
        const SeoAssessor = modules.yoast.SeoAssessor;
        const ContentAssessor = modules.yoast.ContentAssessor;
        const EnglishResearcher = modules.EnglishResearcher;
        if (!Paper || !SeoAssessor || !ContentAssessor || !EnglishResearcher) {
          throw new Error('YoastSEO.js modules are incomplete.');
        }

        const keyphrase = deriveFocusKeyphrase(blog);
        const titleText = String(blog.seoTitle || blog.title || '').trim();
        const slugText = String(blog.slug || blog.id || '').trim();
        const excerptText = String(blog.excerpt || '').trim();
        const html = parseMarkdown(blog.content || '');
        const paper = new Paper(html, {
          keyword: keyphrase,
          synonyms: '',
          description: excerptText,
          slug: slugText,
          seoTitle: titleText,
          title: titleText,
          textTitle: titleText,
          titleWidth: measureSeoTitleWidth(titleText),
          locale: 'en_US',
          permalink: window.location.origin + '/blog/' + (slugText || 'blog-article') + '/',
        });
        const researcher = new EnglishResearcher(paper);
        const seoAssessor = new SeoAssessor(researcher);
        const contentAssessor = new ContentAssessor(researcher, { locale: 'en_US' });
        seoAssessor.assess(paper);
        contentAssessor.assess(paper);

        const seoResults = getYoastAssessorResults(seoAssessor);
        const readabilityResults = getYoastAssessorResults(contentAssessor);
        const seoScore = getYoastOverallScore(seoAssessor);
        const readabilityScore = getYoastOverallScore(contentAssessor);
        const state = seoScore >= 80 ? { label: 'Good', className: 'ok' } : (seoScore >= 55 ? { label: 'OK', className: 'warn' } : { label: 'Needs Improvement', className: '' });
        return {
          score: seoScore,
          readabilityScore,
          label: state.label,
          className: state.className,
          keyphrase,
          wordCount: countWords(blog.content || ''),
          passed: seoResults.filter((result) => Number(result.getScore ? result.getScore() : result.score || 0) >= 6).length,
          total: seoResults.length,
          readabilityTotal: readabilityResults.length,
        };
      }

      function updateSeoRating(id, seo, source) {
        const panel = list.querySelector(`[data-seo-id="${cssEscape(id)}"]`);
        if (!panel) return;
        const score = panel.querySelector('.seo-rating-score');
        const label = panel.querySelector('.seo-rating-label');
        const details = panel.querySelector('.seo-rating-details');
        if (score) {
          score.className = 'seo-rating-score ' + seo.className;
          score.textContent = seo.score + '/100';
        }
        if (label) label.textContent = seo.label;
        if (details) {
          details.innerHTML = `${escapeHtml(String(seo.passed) + '/' + String(seo.total))} Yoast checks · ${escapeHtml(String(seo.wordCount))} words<br>Keyphrase: ${escapeHtml(seo.keyphrase || 'none')}<br>${escapeHtml(source)}`;
        }
      }

      function refreshYoastRatings() {
        const runId = ++yoastAnalysisRun;
        blogs.forEach((blog) => {
          const id = blog.id || blog.slug;
          if (!id) return;
          const cacheKey = JSON.stringify([blog.id, blog.slug, blog.updatedAt, blog.title, blog.seoTitle, blog.excerpt, blog.content, blog.focusKeyphrase]);
          if (yoastRatingCache.has(cacheKey)) {
            updateSeoRating(id, yoastRatingCache.get(cacheKey), 'YoastSEO.js local · cached');
            return;
          }
          analyzeBlogWithYoast(blog).then((seo) => {
            yoastRatingCache.set(cacheKey, seo);
            if (runId === yoastAnalysisRun) updateSeoRating(id, seo, 'YoastSEO.js local');
          }).catch(() => {});
        });
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
        data.append('search', currentSearchTerm());

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
        totalBlogs = result.pagination ? Number(result.pagination.total || 0) : blogs.length;
        totalPages = result.pagination ? result.pagination.totalPages : 1;
        currentPage = result.pagination ? result.pagination.page : currentPage;
        if (!blogs.length && currentPage > 1) {
          currentPage = totalPages;
          await loadBlogs();
          return;
        }
        updateBrowserUrl();
        renderList();
      }

      function renderList() {
        updateSearchStatus();
        if (!blogs.length) {
          const message = currentSearchTerm() ? 'No blogs match your search.' : 'No custom blogs added yet.';
          list.innerHTML = `<p style="color:var(--text-muted); font-size:0.9rem; padding:16px;">${escapeHtml(message)}</p>`;
          pagination.style.display = 'none';
          return;
        }

        const rows = blogs.map((blog) => {
          const id = blog.id || blog.slug;
          const slug = blog.slug || id;
          const status = blog.status === 'scheduled' ? 'scheduled' : (blog.status === 'draft' ? 'draft' : 'published');
          const isPublic = Boolean(blog.isPublic || status === 'published');
          const category = blog.category || 'Uncategorized';
          const quickEditCategory = blog.category || defaultCategory;
          const internalLinkTitles = Array.isArray(blog.internalLinkTitles) ? blog.internalLinkTitles.join(', ') : '';
          const linkedFromTitles = Array.isArray(blog.linkedFromTitles) ? blog.linkedFromTitles.join(', ') : '';
          const publishedDate = blog.publishedAtInput ? blog.publishedAtInput.replace('T', ' ') : (blog.date || '');
          const editRow = quickEditId === id ? quickEditRow(blog, id, status, quickEditCategory) : '';
          return `
            <tr>
              <td class="blog-title-cell">
                <strong>${escapeHtml(blog.title)}</strong>
                <small>Slug: <code style="color:var(--brand);">${escapeHtml(slug)}</code><br>${escapeHtml(blog.excerpt || '')}</small>
              </td>
              <td>${escapeHtml(category)}</td>
              <td><span class="blog-status-badge ${status}">${escapeHtml(status)}</span></td>
              <td class="blog-date-cell">
                ${escapeHtml(publishedDate)}
                <small>Updated: ${escapeHtml(blog.updatedAt ? new Date(Number(blog.updatedAt) * 1000).toLocaleDateString() : '')}</small>
              </td>
              <td class="blog-number-cell" title="${escapeHtml(internalLinkTitles || 'No internal blog links')}">${Number(blog.internalLinks || 0).toLocaleString()}</td>
              <td class="blog-number-cell" title="${escapeHtml(linkedFromTitles || 'No inbound blog links')}">${Number(blog.linkedFrom || 0).toLocaleString()}</td>
              <td data-seo-id="${escapeHtml(id)}"><span class="seo-rating-label">Checking...</span><span class="seo-rating-score"></span><small class="seo-rating-details"></small></td>
              <td>
                <div class="admin-actions">
                  ${isPublic ? `<a href="/blog/${encodeURIComponent(slug)}/" target="_blank" class="btn btn-primary btn-sm" style="text-decoration:none;">View</a>` : `<span class="btn btn-secondary btn-sm" style="opacity:0.55; cursor:not-allowed;">${status === 'scheduled' ? 'Scheduled' : 'Draft'}</span>`}
                  <button type="button" class="btn btn-secondary btn-sm" data-quick-edit="${escapeHtml(id)}">Quick Edit</button>
                  <a href="/admin/blog-publish.php?id=${encodeURIComponent(id)}" class="btn btn-secondary btn-sm" style="text-decoration:none;">Edit</a>
                  <button type="button" class="btn btn-secondary btn-sm" data-delete="${escapeHtml(id)}" style="color:var(--danger); border-color:var(--danger);">Delete</button>
                </div>
              </td>
            </tr>
            ${editRow}
          `;
        }).join('');
        list.innerHTML = `
          <div class="blog-table-wrap">
            <table class="blog-table">
              <thead>
                <tr>
                  <th>Title</th>
                  <th>Category</th>
                  <th>Status</th>
                  <th>Publish/Updated</th>
                  <th>Internal Links</th>
                  <th>Linked From</th>
                  <th>Yoast SEO</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>${rows}</tbody>
            </table>
          </div>
        `;

        pagination.style.display = totalPages > 1 ? 'flex' : 'none';
        pageStatus.textContent = `Page ${currentPage} of ${totalPages}`;
        prevPageBtn.disabled = currentPage <= 1;
        nextPageBtn.disabled = currentPage >= totalPages;
        refreshYoastRatings();
      }

      function scheduleSearchLoad() {
        window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(async () => {
          currentPage = 1;
          await loadBlogs().catch((error) => showNotice(error.message || 'Blog list could not be loaded.', 'error'));
        }, 260);
      }

      searchInput.addEventListener('input', scheduleSearchLoad);
      clearSearchBtn.addEventListener('click', async () => {
        if (!searchInput.value) return;
        searchInput.value = '';
        currentPage = 1;
        await loadBlogs().catch((error) => showNotice(error.message || 'Blog list could not be loaded.', 'error'));
        searchInput.focus();
      });

      importButton.addEventListener('click', () => importFile.click());
      importFile.addEventListener('change', async () => {
        const file = importFile.files && importFile.files[0];
        if (!file) return;
        if (!file.name.toLowerCase().endsWith('.zip') || file.size <= 0 || file.size > 100 * 1024 * 1024) {
          showNotice('Select a valid blog export ZIP file up to 100 MB.', 'error');
          importFile.value = '';
          return;
        }
        const data = new FormData();
        data.append('csrf_token', csrfToken);
        data.append('import_file', file);
        importButton.disabled = true;
        showNotice('Importing blogs...', 'ok');
        try {
          const response = await fetch('/admin/blog-import.php', {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'fetch' },
            body: data
          });
          const result = await parseJsonResponse(response);
          updateCsrf(result.csrfToken);
          if (!response.ok || !result.ok) throw new Error(result.error || 'Blog import failed.');
          const counts = result.counts || {};
          showNotice(`Imported: ${Number(counts.imported || 0)} · Updated: ${Number(counts.updated || 0)} · Skipped: ${Number(counts.skipped || 0)} · Images restored: ${Number(counts.imagesRestored || 0)} · Failed: ${Number(counts.failed || 0)}`, 'ok');
          await loadBlogs();
        } catch (error) {
          showNotice(error.message || 'Blog import failed.', 'error');
        } finally {
          importButton.disabled = false;
          importFile.value = '';
        }
      });

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
        const quickEditButton = event.target.closest('[data-quick-edit]');
        if (quickEditButton) {
          quickEditId = quickEditButton.dataset.quickEdit || '';
          renderList();
          return;
        }

        const quickCancelButton = event.target.closest('[data-quick-cancel]');
        if (quickCancelButton) {
          quickEditId = '';
          renderList();
          return;
        }

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

      list.addEventListener('submit', async (event) => {
        const form = event.target.closest('[data-quick-edit-form]');
        if (!form) return;
        event.preventDefault();

        const id = form.dataset.quickEditForm || '';
        const blog = blogs.find((item) => (item.id || item.slug) === id);
        if (!blog) {
          showNotice('Blog post could not be found in the current list.', 'error');
          return;
        }

        const data = new FormData(form);
        const status = String(data.get('status') || 'published');
        const publishedAt = String(data.get('published_at') || '');
        const scheduledAt = String(data.get('scheduled_at') || '');
        const cleanSlug = slugify(data.get('slug') || data.get('title') || '');

        data.set('csrf_token', csrfToken);
        data.set('id', blog.id || blog.slug || id);
        data.set('slug', cleanSlug);
        data.set('seo_title', blog.seoTitle || blog.title || '');
        data.set('author', blog.author || 'Editorial Team');
        data.set('excerpt', blog.excerpt || '');
        data.set('content', blog.content || '');
        data.set('featured_image', blog.featuredImage || '/uploads/blogs/default-featured.svg');
        data.set('focus_keyphrase', blog.focusKeyphrase || '');
        data.set('published_at', publishedAt);
        data.set('scheduled_at', status === 'scheduled' ? (scheduledAt || publishedAt) : '');

        try {
          const response = await fetch('/admin/blog-save.php', {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'fetch' },
            body: data
          });
          const result = await parseJsonResponse(response);
          updateCsrf(result.csrfToken);
          if (!response.ok || !result.ok) {
            throw new Error((result.errors || [result.error || 'Blog post could not be saved.']).join(' '));
          }
          quickEditId = '';
          showNotice('Blog post updated.', 'ok');
          await loadBlogs();
        } catch (error) {
          showNotice(error.message || 'Blog post could not be saved.', 'error');
        }
      });

      loadBlogs().catch((error) => showNotice(error.message || 'Blog list could not be loaded.', 'error'));
    });
  </script>
</body>
</html>
