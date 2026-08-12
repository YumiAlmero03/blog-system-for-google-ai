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
    .blog-list-tools {
      display: flex;
      gap: 8px;
      align-items: center;
      margin-bottom: 14px;
      padding: 12px;
      background: var(--surface-soft);
      border: 1px solid var(--border);
      border-radius: 8px;
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
      .admin-header,
      .blog-item-row {
        align-items: flex-start;
        flex-direction: column;
      }
      .admin-actions {
        justify-content: flex-start;
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
        <div id="notice" class="notice" role="status"></div>
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
      const params = new URLSearchParams(window.location.search);
      let blogs = [];
      let currentPage = Math.max(1, Number.parseInt(params.get('page') || '1', 10) || 1);
      const pageSize = 10;
      let totalPages = 1;
      let totalBlogs = 0;
      let searchTimer = 0;
      let yoastLoaderPromise = null;
      let yoastAnalysisRun = 0;
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
        const titleText = blog.seoTitle || blog.title || '';
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
        const titleText = String(blog.title || '').trim();
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
          permalink: window.location.origin + '/blog/' + (slugText || 'gperya-article') + '/',
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
        const state = scoreState(seoScore);
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
          analyzeBlogWithYoast(blog).then((seo) => {
            if (runId === yoastAnalysisRun) updateSeoRating(id, seo, 'YoastSEO.js local');
          }).catch(() => {
            if (runId === yoastAnalysisRun) {
              const fallback = analyzeBlogSeo(blog);
              updateSeoRating(id, fallback, 'Estimated fallback');
            }
          });
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
          list.innerHTML = `<p style="color:var(--text-muted); font-size:0.9rem; padding:16px; background:var(--surface-soft); border-radius:8px;">${escapeHtml(message)}</p>`;
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
              <div class="seo-rating" data-seo-id="${escapeHtml(id)}" title="YoastSEO.js SEO rating">
                <span class="seo-rating-score ${seo.className}">${seo.score}/100</span>
                <span class="seo-rating-label">${escapeHtml(seo.label)}</span>
                <div class="seo-rating-details">${escapeHtml(seo.passed + '/' + seo.total)} estimated checks · ${escapeHtml(String(seo.wordCount))} words<br>Keyphrase: ${escapeHtml(seo.keyphrase || 'none')}<br>Loading YoastSEO.js</div>
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
