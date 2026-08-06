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
  <title>Blog Manager | GperyaPH Admin</title>
  <link rel="preload" href="/assets/css/styles.min.css" as="style"><link rel="stylesheet" href="/assets/css/styles.min.css">
  <link rel="icon" href="/assets/icons/favicon.ico">
  <style>
    .admin-container {
      max-width: 960px;
      margin: 40px auto;
      padding: 24px;
      background: var(--surface);
      border: 1px solid var(--border-strong);
      border-radius: var(--radius-lg);
      box-shadow: var(--shadow);
    }
    .admin-panel {
      background: var(--surface-soft);
      padding: 20px;
      border-radius: var(--radius-md);
      border: 1px solid var(--border);
      margin-bottom: 32px;
    }
    .form-group { margin-bottom: 16px; text-align: left; }
    .form-group label {
      display: block;
      font-weight: 700;
      margin-bottom: 6px;
      font-size: 0.9rem;
      color: var(--brand-dark);
    }
    .form-control {
      width: 100%;
      padding: 10px 14px;
      border: 1px solid var(--border-strong);
      border-radius: var(--radius-sm);
      font-size: 0.95rem;
      font-family: inherit;
    }
    textarea.form-control {
      min-height: 160px;
      resize: vertical;
    }
    .blog-item-row {
      display: flex;
      gap: 16px;
      align-items: center;
      padding: 16px;
      background: var(--surface-soft);
      border: 1px solid var(--border);
      border-radius: var(--radius-md);
      margin-bottom: 12px;
    }
    .admin-actions {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
      justify-content: flex-end;
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
    .image-dropzone {
      position: relative;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 8px;
      min-height: 180px;
      padding: 18px;
      border: 2px dashed var(--border-strong);
      border-radius: var(--radius-md);
      background: var(--surface-soft);
      cursor: pointer;
      text-align: center;
      transition: border-color 0.15s ease, background-color 0.15s ease, box-shadow 0.15s ease;
    }
    .image-dropzone:hover,
    .image-dropzone.is-dragover,
    .editor-block-inserter.is-dragover {
      border-color: var(--brand);
      background: var(--surface);
      box-shadow: 0 0 0 3px rgba(166, 47, 61, 0.12);
    }
    .image-dropzone.is-uploading,
    .editor-block-inserter.is-uploading {
      cursor: wait;
      opacity: 0.82;
    }
    .image-dropzone-icon {
      width: 42px;
      height: 42px;
      display: grid;
      place-items: center;
      border-radius: 50%;
      background: var(--brand);
      color: #fff;
      font-weight: 900;
      font-size: 1.2rem;
    }
    .image-dropzone strong {
      color: var(--brand-dark);
      font-size: 0.98rem;
    }
    .image-dropzone span {
      color: var(--text-muted);
      font-size: 0.82rem;
      line-height: 1.45;
    }
    .image-upload-status {
      min-height: 20px;
      margin-top: 8px;
      color: var(--text-muted);
      font-size: 0.82rem;
    }
    .editor-tabs {
      display: inline-flex;
      gap: 4px;
      padding: 3px;
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: var(--radius-sm);
    }
    .editor-tab {
      border: 0;
      background: transparent;
      color: var(--text);
      cursor: pointer;
      padding: 7px 10px;
      border-radius: var(--radius-sm);
      font-weight: 700;
      font-size: 0.82rem;
    }
    .editor-tab.active {
      background: var(--brand);
      color: #fff;
    }
    .wysiwyg-toolbar {
      display: flex;
      flex-wrap: wrap;
      gap: 6px;
      align-items: center;
      padding: 8px;
      margin-bottom: 8px;
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: var(--radius-sm);
    }
    .wysiwyg-btn {
      min-width: 34px;
      min-height: 34px;
      padding: 6px 9px;
      border: 1px solid var(--border);
      border-radius: var(--radius-sm);
      background: var(--surface-soft);
      color: var(--text);
      cursor: pointer;
      font-weight: 800;
      font-size: 0.82rem;
    }
    .wysiwyg-btn:hover,
    .wysiwyg-btn:focus {
      border-color: var(--brand);
      outline: none;
    }
    .wysiwyg-editor {
      min-height: 300px;
      padding: 14px;
      background: var(--surface);
      border: 1px solid var(--border-strong);
      border-radius: var(--radius-sm);
      line-height: 1.65;
      overflow: auto;
    }
    .editor-block-inserter {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
      margin-bottom: 8px;
      padding: 10px 12px;
      background: #fff8ea;
      border: 1px dashed #d6a03a;
      border-radius: var(--radius-sm);
      color: var(--text);
    }
    .editor-block-inserter strong {
      display: block;
      color: var(--brand-dark);
      font-size: 0.9rem;
    }
    .editor-block-inserter span {
      display: block;
      color: var(--text-muted);
      font-size: 0.78rem;
      line-height: 1.35;
    }
    .editor-upload-status {
      min-height: 20px;
      margin: 6px 0 8px;
      color: var(--text-muted);
      font-size: 0.82rem;
    }
    .wysiwyg-editor:focus {
      outline: none;
      border-color: var(--brand);
      box-shadow: 0 0 0 3px rgba(166, 47, 61, 0.15);
    }
    .wysiwyg-editor.is-dragover {
      border-color: #d6a03a;
      box-shadow: 0 0 0 3px rgba(214, 160, 58, 0.2);
      background: #fffaf0;
    }
    .wysiwyg-editor:empty::before {
      content: attr(data-placeholder);
      color: var(--text-muted);
      font-style: italic;
    }
    .wysiwyg-editor img {
      max-width: 100%;
      height: auto;
      border-radius: var(--radius-sm);
      display: block;
      margin: 12px 0;
    }
    .markdown-preview {
      display: none;
      min-height: 280px;
      padding: 16px;
      background: var(--surface);
      border: 1px solid var(--border-strong);
      border-radius: var(--radius-sm);
      overflow: auto;
    }
    .markdown-preview img {
      max-width: 100%;
      height: auto;
      border-radius: var(--radius-sm);
    }
    #blog-content {
      display: none;
      min-height: 300px;
      padding: 14px;
      background: var(--surface);
      border: 1px solid var(--border-strong);
      border-radius: var(--radius-sm);
      line-height: 1.6;
      font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
      font-size: 0.9rem;
      resize: vertical;
    }
    #blog-content:focus {
      outline: none;
      border-color: var(--brand);
      box-shadow: 0 0 0 3px rgba(166, 47, 61, 0.15);
    }
    .editor-shell.editor-mode-markdown #blog-content {
      display: block;
      width: 100%;
    }
    .editor-shell.editor-mode-markdown .wysiwyg-editor,
    .editor-shell.editor-mode-markdown .markdown-preview {
      display: none;
    }
    .editor-shell.editor-mode-preview .wysiwyg-editor {
      display: none;
    }
    .editor-shell.editor-mode-preview .markdown-preview {
      display: block;
    }
    .editor-shell.editor-mode-split {
      display: grid;
      grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
      gap: 12px;
    }
    .editor-shell.editor-mode-split #blog-content {
      display: block;
      width: 100%;
    }
    .editor-shell.editor-mode-split .wysiwyg-editor {
      display: none;
    }
    .editor-shell.editor-mode-split .markdown-preview {
      display: block;
    }
    .content-word-counter {
      color: var(--text-muted);
      font-size: 0.82rem;
      font-weight: 600;
      white-space: nowrap;
    }
    @media (max-width: 760px) {
      .blog-item-row { align-items: flex-start; flex-direction: column; }
      .admin-actions { justify-content: flex-start; }
      .editor-shell.editor-mode-split { grid-template-columns: 1fr; }
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
        <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; margin-bottom:24px; padding-bottom:16px; border-bottom:2px solid var(--border);">
          <div>
            <h1 style="font-size:1.6rem; color:var(--brand-dark);">Blog Post Publishing Console</h1>
            <p style="font-size:0.88rem; color:var(--text-muted);">Manage custom Markdown blog articles for GperyaPH.</p>
          </div>
        </div>

        <div id="notice" class="notice" role="status"></div>

        <div class="admin-panel" id="blog-form-wrapper">
          <h2 id="form-title-text" style="font-size:1.2rem; color:var(--brand-dark); margin-bottom:16px;">Create New Blog Article</h2>

          <form id="create-blog-form" method="post">
            <input type="hidden" name="csrf_token" id="csrf-token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" id="editing-blog-id" name="id" value="">

            <div style="display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:16px;">
              <div class="form-group">
                <label for="blog-title">Article Title *</label>
                <input type="text" id="blog-title" name="title" class="form-control" maxlength="160" required>
              </div>
              <div class="form-group">
                <label for="blog-slug">SEO Slug *</label>
                <input type="text" id="blog-slug" name="slug" class="form-control" maxlength="96" pattern="[a-z0-9-]+" required>
                <span style="font-size:0.75rem; color:var(--text-muted); display:block; margin-top:4px;">Page URL: <code>/blog/<span id="slug-preview-text">gperya-article</span>/</code></span>
              </div>
            </div>

            <div style="display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:16px;">
              <div class="form-group">
                <label for="blog-category">Category Tag *</label>
                <select id="blog-category" name="category" class="form-control" required>
                  <?php foreach (BLOG_CATEGORIES as $category): ?>
                    <option value="<?= h($category) ?>"><?= h($category) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group">
                <label for="blog-author">Author Name</label>
                <input type="text" id="blog-author" name="author" class="form-control" value="<?= h(BLOG_DEFAULT_AUTHOR) ?>" maxlength="80">
              </div>
            </div>

            <div class="form-group" style="background:var(--surface); padding:16px; border-radius:var(--radius-md); border:1px solid var(--border);">
              <label for="blog-image-upload">Featured Image</label>
              <div style="display:grid; grid-template-columns:minmax(0, 1fr) 220px; gap:16px; align-items:start;">
                <div>
                  <div id="image-dropzone" class="image-dropzone" role="button" tabindex="0" aria-controls="blog-image-upload">
                    <div class="image-dropzone-icon">+</div>
                    <strong>Drop image to upload</strong>
                    <span>or click to choose a JPEG, PNG, or WebP file up to 3 MB.</span>
                  </div>
                  <input type="file" id="blog-image-upload" accept="image/jpeg,image/png,image/webp" style="position:absolute; width:1px; height:1px; opacity:0; pointer-events:none;" tabindex="-1">
                  <div id="image-upload-status" class="image-upload-status"></div>
                  <select id="blog-image-preset" class="form-control" style="margin-top:12px;">
                    <option value="/uploads/blogs/default-featured.svg">/uploads/blogs/default-featured.svg</option>
                    <option value="/uploads/blogs/blog-login-featured.svg">/uploads/blogs/blog-login-featured.svg</option>
                    <option value="/uploads/blogs/blog-withdrawal-featured.svg">/uploads/blogs/blog-withdrawal-featured.svg</option>
                    <option value="/uploads/blogs/blog-reddit-featured.svg">/uploads/blogs/blog-reddit-featured.svg</option>
                  </select>
                  <input type="text" id="blog-image-url" name="featured_image" class="form-control" value="<?= h(BLOG_DEFAULT_IMAGE) ?>" maxlength="180" style="margin-top:12px; font-size:0.85rem;">
                </div>
                <div style="text-align:center;">
                  <div style="width:100%; height:120px; border-radius:var(--radius-sm); border:2px dashed var(--border); overflow:hidden; background:#0d0104; display:flex; align-items:center; justify-content:center;">
                    <img id="featured-image-preview" src="<?= h(BLOG_DEFAULT_IMAGE) ?>" alt="Featured image preview" style="width:100%; height:100%; object-fit:cover;">
                  </div>
                  <span id="image-path-badge" class="badge badge-yellow" style="font-size:0.75rem; margin-top:6px; display:inline-block; max-width:100%; overflow-wrap:anywhere;"><?= h(BLOG_DEFAULT_IMAGE) ?></span>
                </div>
              </div>
            </div>

            <div class="form-group">
              <label for="blog-excerpt">Short Excerpt *</label>
              <textarea id="blog-excerpt" name="excerpt" class="form-control" maxlength="360" style="min-height:70px;" required></textarea>
            </div>

            <div class="form-group">
              <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:6px;">
                <div style="display:flex; align-items:baseline; gap:10px; flex-wrap:wrap;">
                  <label for="blog-content" style="margin-bottom:0;">Full Article Content *</label>
                  <span id="content-word-counter" class="content-word-counter" aria-live="polite">0 words</span>
                </div>
                <div class="editor-tabs" aria-label="Markdown editor view">
                  <button type="button" class="editor-tab active" id="tab-write">WYSIWYG</button>
                  <button type="button" class="editor-tab" id="tab-markdown">Markdown</button>
                  <button type="button" class="editor-tab" id="tab-preview">Preview</button>
                  <button type="button" class="editor-tab" id="tab-split">Split View</button>
                </div>
              </div>
              <div class="wysiwyg-toolbar" id="editor-toolbar" aria-label="Article formatting toolbar">
                <button type="button" class="wysiwyg-btn" data-command="h2" title="Heading">H2</button>
                <button type="button" class="wysiwyg-btn" data-command="h3" title="Subheading">H3</button>
                <button type="button" class="wysiwyg-btn" data-command="bold" title="Bold">B</button>
                <button type="button" class="wysiwyg-btn" data-command="italic" title="Italic"><em>I</em></button>
                <button type="button" class="wysiwyg-btn" data-command="ul" title="Bullet list">List</button>
                <button type="button" class="wysiwyg-btn" data-command="ol" title="Numbered list">1.</button>
                <button type="button" class="wysiwyg-btn" data-command="quote" title="Quote">Quote</button>
                <button type="button" class="wysiwyg-btn" data-command="link" title="Insert link">Link</button>
                <button type="button" class="wysiwyg-btn" data-command="image" title="Upload image block">Image</button>
                <button type="button" class="wysiwyg-btn" data-command="clear" title="Clear formatting">Clear</button>
              </div>
              <div class="editor-block-inserter" id="article-image-dropzone" role="button" tabindex="0" aria-controls="article-image-upload">
                <div>
                  <strong>Add image block</strong>
                  <span>Drop an image here, paste an image into the editor, or click Add Image.</span>
                </div>
                <button type="button" class="btn btn-secondary btn-sm" id="article-image-button">Add Image</button>
              </div>
              <input type="file" id="article-image-upload" accept="image/jpeg,image/png,image/webp" style="position:absolute; width:1px; height:1px; opacity:0; pointer-events:none;" tabindex="-1">
              <div id="article-image-status" class="editor-upload-status"></div>
              <div id="editor-shell" class="editor-shell editor-mode-write">
                <textarea id="blog-content" name="content" maxlength="60000" aria-label="Markdown article content"></textarea>
                <div id="wysiwyg-editor" class="wysiwyg-editor" contenteditable="true" role="textbox" aria-multiline="true" data-placeholder="Write your article here. Use the toolbar for headings, links, lists, quotes, and images."></div>
                <div id="markdown-preview" class="markdown-preview">
                  <p style="color:var(--text-muted); font-style:italic;">Markdown preview will render here.</p>
                </div>
              </div>
            </div>

            <div style="display:flex; gap:12px; flex-wrap:wrap;">
              <button type="submit" id="submit-blog-btn" class="btn btn-primary">Publish Blog Post</button>
              <button type="button" id="cancel-edit-btn" class="btn btn-secondary" style="display:none;">Cancel Editing</button>
              <button type="reset" id="reset-form-btn" class="btn btn-secondary">Clear Form</button>
            </div>
          </form>
        </div>

        <div>
          <h2 style="font-size:1.2rem; color:var(--brand-dark); margin-bottom:16px;">Published Custom Blogs</h2>
          <div id="custom-blog-list">
            <p style="color:var(--text-muted); font-size:0.9rem;">Loading blog posts...</p>
          </div>
          <div id="blog-pagination" style="display:none; align-items:center; justify-content:flex-end; gap:8px; margin-top:14px;">
            <button type="button" id="blog-prev-page" class="btn btn-secondary btn-sm">Previous</button>
            <span id="blog-page-status" style="font-size:0.85rem; color:var(--text-muted);"></span>
            <button type="button" id="blog-next-page" class="btn btn-secondary btn-sm">Next</button>
          </div>
        </div>
      </div>
    </main>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', () => {
      let csrfToken = document.getElementById('csrf-token').value;
      const form = document.getElementById('create-blog-form');
      const list = document.getElementById('custom-blog-list');
      const notice = document.getElementById('notice');
      const title = document.getElementById('blog-title');
      const slug = document.getElementById('blog-slug');
      const slugPreview = document.getElementById('slug-preview-text');
      const editingId = document.getElementById('editing-blog-id');
      const submitBtn = document.getElementById('submit-blog-btn');
      const cancelBtn = document.getElementById('cancel-edit-btn');
      const formTitle = document.getElementById('form-title-text');
      const imageUpload = document.getElementById('blog-image-upload');
      const imageDropzone = document.getElementById('image-dropzone');
      const imageUploadStatus = document.getElementById('image-upload-status');
      const imagePreset = document.getElementById('blog-image-preset');
      const imageUrl = document.getElementById('blog-image-url');
      const imagePreview = document.getElementById('featured-image-preview');
      const imageBadge = document.getElementById('image-path-badge');
      const blogContent = document.getElementById('blog-content');
      const wysiwygEditor = document.getElementById('wysiwyg-editor');
      const editorToolbar = document.getElementById('editor-toolbar');
      const editorShell = document.getElementById('editor-shell');
      const markdownPreview = document.getElementById('markdown-preview');
      const articleImageUpload = document.getElementById('article-image-upload');
      const articleImageButton = document.getElementById('article-image-button');
      const articleImageDropzone = document.getElementById('article-image-dropzone');
      const articleImageStatus = document.getElementById('article-image-status');
      const contentWordCounter = document.getElementById('content-word-counter');
      const tabWrite = document.getElementById('tab-write');
      const tabMarkdown = document.getElementById('tab-markdown');
      const tabPreview = document.getElementById('tab-preview');
      const tabSplit = document.getElementById('tab-split');
      const pagination = document.getElementById('blog-pagination');
      const prevPageBtn = document.getElementById('blog-prev-page');
      const nextPageBtn = document.getElementById('blog-next-page');
      const pageStatus = document.getElementById('blog-page-status');
      let manualSlug = false;
      let blogs = [];
      let currentPage = 1;
      const pageSize = 10;
      let totalPages = 1;
      let savedEditorRange = null;

      function showNotice(message, type) {
        notice.textContent = message;
        notice.className = 'notice ' + type;
      }

      function updateCsrf(value) {
        if (!value) return;
        csrfToken = value;
        document.getElementById('csrf-token').value = value;
      }

      function escapeHtml(value) {
        return (value || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
      }

      function countWords(value) {
        const plainText = (value || '')
          .replace(/```[\s\S]*?```/g, ' ')
          .replace(/!\[[^\]]*\]\([^)]+\)/g, ' ')
          .replace(/\[([^\]]+)\]\([^)]+\)/g, '$1')
          .replace(/[#>*_`~|[\](){}-]/g, ' ');
        const words = plainText.match(/[\p{L}\p{N}]+(?:['’.-][\p{L}\p{N}]+)*/gu);
        return words ? words.length : 0;
      }

      function updateWordCounter() {
        const words = countWords(blogContent.value);
        contentWordCounter.textContent = `${words.toLocaleString()} ${words === 1 ? 'word' : 'words'}`;
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

      function parseMarkdown(markdown) {
        let html = escapeHtml(markdown || '');
        html = html.replace(/```([\s\S]*?)```/g, '<pre><code>$1</code></pre>');
        html = html.replace(/^### (.*)$/gim, '<h3>$1</h3>');
        html = html.replace(/^## (.*)$/gim, '<h2>$1</h2>');
        html = html.replace(/^# (.*)$/gim, '<h1>$1</h1>');
        html = html.replace(/^&gt; (.*)$/gim, '<blockquote>$1</blockquote>');
        html = html.replace(/!\[([^\]]*)\]\((\/uploads\/blogs\/[a-zA-Z0-9._/-]+)\)/g, '<img src="$2" alt="$1">');
        html = html.replace(/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>');
        html = html.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
        html = html.replace(/\*([^*]+)\*/g, '<em>$1</em>');
        html = html.replace(/`([^`]+)`/g, '<code>$1</code>');

        return html
          .split(/\n\s*\n/)
          .map((block) => {
            const trimmed = block.trim();
            if (!trimmed) return '';
            if (/^<(h1|h2|h3|blockquote|pre|img)/.test(trimmed)) return trimmed;
            if (/^[-*] /.test(trimmed)) {
              return '<ul>' + trimmed.replace(/^[-*] (.*)$/gim, '<li>$1</li>') + '</ul>';
            }
            if (/^\d+\. /.test(trimmed)) {
              return '<ol>' + trimmed.replace(/^\d+\. (.*)$/gim, '<li>$1</li>') + '</ol>';
            }
            return '<p>' + trimmed.replace(/\n/g, '<br>') + '</p>';
          })
          .join('');
      }

      function nodeToMarkdown(node) {
        if (node.nodeType === Node.TEXT_NODE) {
          return node.textContent || '';
        }

        if (node.nodeType !== Node.ELEMENT_NODE) {
          return '';
        }

        const tag = node.tagName.toLowerCase();
        const children = Array.from(node.childNodes).map(nodeToMarkdown).join('');

        if (tag === 'strong' || tag === 'b') return `**${children}**`;
        if (tag === 'em' || tag === 'i') return `*${children}*`;
        if (tag === 'code') return `\`${children}\``;
        if (tag === 'a') {
          const href = node.getAttribute('href') || '';
          return href ? `[${children}](${href})` : children;
        }
        if (tag === 'img') {
          let src = node.getAttribute('src') || '';
          if (src.startsWith(window.location.origin + '/uploads/blogs/')) {
            src = src.replace(window.location.origin, '');
          }
          const alt = node.getAttribute('alt') || 'Article Image';
          return src ? `![${alt}](${src})` : '';
        }
        if (tag === 'br') return '\n';
        if (tag === 'h1') return `# ${children.trim()}\n\n`;
        if (tag === 'h2') return `## ${children.trim()}\n\n`;
        if (tag === 'h3') return `### ${children.trim()}\n\n`;
        if (tag === 'blockquote') return children.split('\n').filter(Boolean).map((line) => `> ${line.trim()}`).join('\n') + '\n\n';
        if (tag === 'li') return children.trim();
        if (tag === 'ul') {
          return Array.from(node.children).map((item) => `- ${nodeToMarkdown(item)}`).join('\n') + '\n\n';
        }
        if (tag === 'ol') {
          return Array.from(node.children).map((item, index) => `${index + 1}. ${nodeToMarkdown(item)}`).join('\n') + '\n\n';
        }
        if (tag === 'pre') return '```\n' + node.textContent.trim() + '\n```\n\n';
        if (tag === 'p' || tag === 'div') return children.trim() ? children.trim() + '\n\n' : '';

        return children;
      }

      function editorHtmlToMarkdown() {
        return Array.from(wysiwygEditor.childNodes)
          .map(nodeToMarkdown)
          .join('')
          .replace(/\n{3,}/g, '\n\n')
          .trim();
      }

      function syncMarkdownFromEditor() {
        blogContent.value = editorHtmlToMarkdown();
        updateWordCounter();
      }

      function setEditorMarkdown(markdown) {
        blogContent.value = markdown || '';
        wysiwygEditor.innerHTML = parseMarkdown(blogContent.value);
        updateWordCounter();
      }

      function syncEditorFromMarkdown() {
        wysiwygEditor.innerHTML = parseMarkdown(blogContent.value);
        updateWordCounter();
      }

      function renderMarkdownPreview() {
        const rendered = parseMarkdown(blogContent.value);
        markdownPreview.innerHTML = rendered || '<p style="color:var(--text-muted); font-style:italic;">Markdown preview will render here.</p>';
      }

      function setEditorMode(mode) {
        if (editorShell.classList.contains('editor-mode-write')) {
          syncMarkdownFromEditor();
        } else if (editorShell.classList.contains('editor-mode-markdown') || editorShell.classList.contains('editor-mode-split')) {
          syncEditorFromMarkdown();
        }

        editorShell.className = 'editor-shell editor-mode-' + mode;
        tabWrite.classList.toggle('active', mode === 'write');
        tabMarkdown.classList.toggle('active', mode === 'markdown');
        tabPreview.classList.toggle('active', mode === 'preview');
        tabSplit.classList.toggle('active', mode === 'split');
        if (mode === 'preview' || mode === 'split') {
          renderMarkdownPreview();
        }
        if (mode === 'markdown' || mode === 'split') {
          blogContent.focus();
        }
      }

      function focusEditor() {
        wysiwygEditor.focus();
      }

      function saveEditorSelection() {
        const selection = window.getSelection();
        if (!selection || selection.rangeCount === 0) return;
        const range = selection.getRangeAt(0);
        if (wysiwygEditor.contains(range.commonAncestorContainer)) {
          savedEditorRange = range.cloneRange();
        }
      }

      function restoreEditorSelection() {
        focusEditor();
        if (!savedEditorRange) return;
        const selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(savedEditorRange);
      }

      function setArticleImageStatus(message, type) {
        articleImageStatus.textContent = message || '';
        articleImageStatus.style.color = type === 'error' ? 'var(--danger)' : 'var(--text-muted)';
      }

      function insertArticleImage(path, altText) {
        if (!path || !path.startsWith('/uploads/blogs/')) return;
        if (editorShell.classList.contains('editor-mode-markdown') || editorShell.classList.contains('editor-mode-split')) {
          const markdown = `![${altText || 'Article Image'}](${path})`;
          const start = blogContent.selectionStart || 0;
          const end = blogContent.selectionEnd || start;
          const before = blogContent.value.slice(0, start);
          const after = blogContent.value.slice(end);
          const prefix = before && !before.endsWith('\n\n') ? '\n\n' : '';
          const suffix = after && !after.startsWith('\n\n') ? '\n\n' : '';
          blogContent.value = before + prefix + markdown + suffix + after;
          const cursor = (before + prefix + markdown).length;
          blogContent.focus();
          blogContent.setSelectionRange(cursor, cursor);
          syncEditorFromMarkdown();
          if (editorShell.classList.contains('editor-mode-split')) {
            renderMarkdownPreview();
          }
          return;
        }
        restoreEditorSelection();
        const img = document.createElement('img');
        img.src = path;
        img.alt = altText || 'Article Image';
        const paragraph = document.createElement('p');
        paragraph.appendChild(img);
        const range = window.getSelection().rangeCount ? window.getSelection().getRangeAt(0) : null;
        if (range) {
          range.deleteContents();
          range.insertNode(paragraph);
          range.setStartAfter(paragraph);
          range.collapse(true);
          const selection = window.getSelection();
          selection.removeAllRanges();
          selection.addRange(range);
        } else {
          wysiwygEditor.appendChild(paragraph);
        }
        savedEditorRange = null;
        syncMarkdownFromEditor();
        if (!editorShell.classList.contains('editor-mode-write')) {
          renderMarkdownPreview();
        }
      }

      async function uploadArticleImage(file) {
        if (!file) return;
        if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
          setArticleImageStatus('Please upload a JPEG, PNG, or WebP image.', 'error');
          return;
        }

        const data = new FormData();
        data.append('csrf_token', csrfToken);
        data.append('image', file);
        articleImageDropzone.classList.add('is-uploading');
        setArticleImageStatus('Uploading image block...');

        try {
          const response = await fetch('/admin/upload-image.php', {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'fetch' },
            body: data
          });
          const result = await parseJsonResponse(response);
          updateCsrf(result.csrfToken);
          if (!response.ok || !result.ok) throw new Error(result.error || 'Image upload failed.');
          insertArticleImage(result.path, file.name.replace(/\.[^.]+$/, '').replace(/[-_]+/g, ' '));
          setArticleImageStatus('Image inserted into the article.');
          showNotice('Article image uploaded and inserted.', 'ok');
        } catch (error) {
          setArticleImageStatus(error.message || 'Image upload failed.', 'error');
          showNotice(error.message || 'Image upload failed.', 'error');
        } finally {
          articleImageDropzone.classList.remove('is-uploading');
          articleImageUpload.value = '';
        }
      }

      function applyEditorCommand(command) {
        if (command === 'image') {
          if (editorShell.classList.contains('editor-mode-write')) {
            saveEditorSelection();
          }
          articleImageUpload.click();
          return;
        }

        focusEditor();

        if (command === 'h2') {
          document.execCommand('formatBlock', false, 'h2');
        } else if (command === 'h3') {
          document.execCommand('formatBlock', false, 'h3');
        } else if (command === 'bold') {
          document.execCommand('bold');
        } else if (command === 'italic') {
          document.execCommand('italic');
        } else if (command === 'ul') {
          document.execCommand('insertUnorderedList');
        } else if (command === 'ol') {
          document.execCommand('insertOrderedList');
        } else if (command === 'quote') {
          document.execCommand('formatBlock', false, 'blockquote');
        } else if (command === 'link') {
          const url = window.prompt('URL', 'https://');
          if (url && /^https?:\/\//i.test(url)) {
            document.execCommand('createLink', false, url);
          }
        } else if (command === 'clear') {
          document.execCommand('removeFormat');
          document.execCommand('formatBlock', false, 'p');
        }

        syncMarkdownFromEditor();
        if (!editorShell.classList.contains('editor-mode-write')) {
          renderMarkdownPreview();
        }
      }

      function slugify(value) {
        return (value || '').toLowerCase().trim().replace(/[^a-z0-9-]+/g, '-').replace(/-+/g, '-').replace(/^-|-$/g, '').slice(0, 96);
      }

      function setImage(path) {
        const value = path || '/uploads/blogs/default-featured.svg';
        imageUrl.value = value;
        imagePreview.src = value;
        imageBadge.textContent = value;
      }

      function setUploadStatus(message, type) {
        imageUploadStatus.textContent = message || '';
        imageUploadStatus.style.color = type === 'error' ? 'var(--danger)' : 'var(--text-muted)';
      }

      async function uploadFeaturedImage(file) {
        if (!file) return;
        if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
          setUploadStatus('Please upload a JPEG, PNG, or WebP image.', 'error');
          return;
        }

        const data = new FormData();
        data.append('csrf_token', csrfToken);
        data.append('image', file);
        imageDropzone.classList.add('is-uploading');
        setUploadStatus('Uploading image...');

        try {
          const response = await fetch('/admin/upload-image.php', {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'fetch' },
            body: data
          });
          const result = await parseJsonResponse(response);
          updateCsrf(result.csrfToken);
          if (!response.ok || !result.ok) throw new Error(result.error || 'Image upload failed.');
          setImage(result.path);
          setUploadStatus('Image uploaded and selected.');
          showNotice('Image uploaded successfully.', 'ok');
        } catch (error) {
          setUploadStatus(error.message || 'Image upload failed.', 'error');
          showNotice(error.message || 'Image upload failed.', 'error');
        } finally {
          imageDropzone.classList.remove('is-uploading');
          imageUpload.value = '';
        }
      }

      title.addEventListener('input', () => {
        if (manualSlug) return;
        const generated = slugify(title.value);
        slug.value = generated;
        slugPreview.textContent = generated || 'gperya-article';
      });

      slug.addEventListener('input', () => {
        manualSlug = true;
        const clean = slugify(slug.value);
        slug.value = clean;
        slugPreview.textContent = clean || 'gperya-article';
      });

      imagePreset.addEventListener('change', () => setImage(imagePreset.value));
      imageUrl.addEventListener('input', () => {
        imagePreview.src = imageUrl.value || '/uploads/blogs/default-featured.svg';
        imageBadge.textContent = imageUrl.value || '/uploads/blogs/default-featured.svg';
      });

      tabWrite.addEventListener('click', () => setEditorMode('write'));
      tabMarkdown.addEventListener('click', () => setEditorMode('markdown'));
      tabPreview.addEventListener('click', () => setEditorMode('preview'));
      tabSplit.addEventListener('click', () => setEditorMode('split'));
      blogContent.addEventListener('input', () => {
        syncEditorFromMarkdown();
        updateWordCounter();
        if (editorShell.classList.contains('editor-mode-split')) {
          renderMarkdownPreview();
        }
      });
      wysiwygEditor.addEventListener('input', () => {
        syncMarkdownFromEditor();
        updateWordCounter();
        if (!editorShell.classList.contains('editor-mode-write')) {
          renderMarkdownPreview();
        }
      });
      wysiwygEditor.addEventListener('keyup', saveEditorSelection);
      wysiwygEditor.addEventListener('mouseup', saveEditorSelection);
      wysiwygEditor.addEventListener('focus', saveEditorSelection);
      wysiwygEditor.addEventListener('dragover', (event) => {
        if (!event.dataTransfer || !event.dataTransfer.files.length) return;
        event.preventDefault();
        saveEditorSelection();
        wysiwygEditor.classList.add('is-dragover');
      });
      wysiwygEditor.addEventListener('dragleave', (event) => {
        if (!wysiwygEditor.contains(event.relatedTarget)) {
          wysiwygEditor.classList.remove('is-dragover');
        }
      });
      wysiwygEditor.addEventListener('drop', (event) => {
        if (!event.dataTransfer || !event.dataTransfer.files.length) return;
        event.preventDefault();
        wysiwygEditor.classList.remove('is-dragover');
        saveEditorSelection();
        uploadArticleImage(event.dataTransfer.files[0]);
      });
      wysiwygEditor.addEventListener('paste', (event) => {
        const items = event.clipboardData ? Array.from(event.clipboardData.items) : [];
        const imageItem = items.find((item) => item.kind === 'file' && item.type.startsWith('image/'));
        if (!imageItem) return;
        event.preventDefault();
        saveEditorSelection();
        uploadArticleImage(imageItem.getAsFile());
      });
      editorToolbar.addEventListener('click', (event) => {
        const button = event.target.closest('[data-command]');
        if (!button) return;
        applyEditorCommand(button.dataset.command);
      });
      editorToolbar.addEventListener('mousedown', (event) => {
        if (event.target.closest('[data-command]')) {
          event.preventDefault();
        }
      });

      articleImageButton.addEventListener('click', () => {
        saveEditorSelection();
        articleImageUpload.click();
      });
      articleImageDropzone.addEventListener('click', (event) => {
        if (event.target.closest('button')) return;
        saveEditorSelection();
        articleImageUpload.click();
      });
      articleImageDropzone.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          saveEditorSelection();
          articleImageUpload.click();
        }
      });
      articleImageDropzone.addEventListener('dragover', (event) => {
        event.preventDefault();
        saveEditorSelection();
        articleImageDropzone.classList.add('is-dragover');
      });
      articleImageDropzone.addEventListener('dragleave', (event) => {
        if (!articleImageDropzone.contains(event.relatedTarget)) {
          articleImageDropzone.classList.remove('is-dragover');
        }
      });
      articleImageDropzone.addEventListener('drop', (event) => {
        event.preventDefault();
        articleImageDropzone.classList.remove('is-dragover');
        saveEditorSelection();
        uploadArticleImage(event.dataTransfer.files[0]);
      });
      articleImageUpload.addEventListener('change', () => {
        uploadArticleImage(articleImageUpload.files[0]);
      });

      imageDropzone.addEventListener('click', () => imageUpload.click());
      imageDropzone.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          imageUpload.click();
        }
      });
      imageDropzone.addEventListener('dragover', (event) => {
        event.preventDefault();
        imageDropzone.classList.add('is-dragover');
      });
      imageDropzone.addEventListener('dragleave', (event) => {
        if (!imageDropzone.contains(event.relatedTarget)) {
          imageDropzone.classList.remove('is-dragover');
        }
      });
      imageDropzone.addEventListener('drop', (event) => {
        event.preventDefault();
        imageDropzone.classList.remove('is-dragover');
        uploadFeaturedImage(event.dataTransfer.files[0]);
      });
      imageUpload.addEventListener('change', () => {
        uploadFeaturedImage(imageUpload.files[0]);
      });

      function resetForm() {
        form.reset();
        editingId.value = '';
        manualSlug = false;
        slugPreview.textContent = 'gperya-article';
        setImage('/uploads/blogs/default-featured.svg');
        setUploadStatus('');
        setArticleImageStatus('');
        setEditorMarkdown('');
        submitBtn.textContent = 'Publish Blog Post';
        formTitle.textContent = 'Create New Blog Article';
        cancelBtn.style.display = 'none';
        setEditorMode('write');
      }

      cancelBtn.addEventListener('click', resetForm);
      form.addEventListener('reset', () => {
        window.setTimeout(() => {
          editingId.value = '';
          manualSlug = false;
          slugPreview.textContent = 'gperya-article';
          setImage('/uploads/blogs/default-featured.svg');
          setUploadStatus('');
          setArticleImageStatus('');
          setEditorMarkdown('');
          submitBtn.textContent = 'Publish Blog Post';
          formTitle.textContent = 'Create New Blog Article';
          cancelBtn.style.display = 'none';
          setEditorMode('write');
        }, 0);
      });

      form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (editorShell.classList.contains('editor-mode-write')) {
          syncMarkdownFromEditor();
        }
        updateWordCounter();
        const data = new FormData(form);
        data.set('slug', slugify(data.get('slug') || data.get('title') || ''));
        data.set('csrf_token', csrfToken);

        try {
          const response = await fetch('/admin/blog-save.php', {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'fetch' },
            body: data
          });
          const result = await parseJsonResponse(response);
          updateCsrf(result.csrfToken);
          if (!response.ok || !result.ok) throw new Error((result.errors || [result.error || 'Blog post could not be saved.']).join(' '));
          showNotice('Blog post saved successfully.', 'ok');
          resetForm();
          currentPage = 1;
          await loadBlogs();
        } catch (error) {
          showNotice(error.message || 'Blog post could not be saved.', 'error');
        }
      });

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
          return `
            <div class="blog-item-row">
              <img src="${escapeHtml(blog.featuredImage || '/uploads/blogs/default-featured.svg')}" alt="${escapeHtml(blog.title)}" style="width:100px; height:70px; object-fit:cover; border-radius:6px; border:1px solid var(--border); flex-shrink:0;">
              <div style="flex:1; min-width:220px;">
                <strong style="font-size:1rem; color:var(--brand-dark); display:block;">${escapeHtml(blog.title)}</strong>
                <span style="font-size:0.8rem; color:var(--text-muted); display:block; margin-top:2px;">
                  ${escapeHtml(blog.category)} | Slug: <code style="color:var(--brand);">${escapeHtml(blog.slug || id)}</code> | ${escapeHtml(blog.date || '')} | By ${escapeHtml(blog.author || '')}
                </span>
                <p style="font-size:0.85rem; color:var(--text); margin-top:4px;">${escapeHtml(blog.excerpt || '')}</p>
              </div>
              <div class="admin-actions">
                <a href="/blog/${encodeURIComponent(blog.slug || id)}/" target="_blank" class="btn btn-primary btn-sm" style="text-decoration:none;">View</a>
                <button type="button" class="btn btn-secondary btn-sm" data-edit="${escapeHtml(id)}">Edit</button>
                <button type="button" class="btn btn-secondary btn-sm" data-delete="${escapeHtml(id)}" style="color:var(--danger); border-color:var(--danger);">Delete</button>
              </div>
            </div>
          `;
        }).join('');

        pagination.style.display = 'flex';
        pageStatus.textContent = `Page ${currentPage} of ${totalPages}`;
        prevPageBtn.disabled = currentPage <= 1;
        nextPageBtn.disabled = currentPage >= totalPages;
      }

      prevPageBtn.addEventListener('click', async () => {
        if (currentPage <= 1) return;
        currentPage -= 1;
        await loadBlogs();
      });

      nextPageBtn.addEventListener('click', async () => {
        if (currentPage >= totalPages) return;
        currentPage += 1;
        await loadBlogs();
      });

      list.addEventListener('click', async (event) => {
        const editButton = event.target.closest('[data-edit]');
        const deleteButton = event.target.closest('[data-delete]');

        if (editButton) {
          const data = new FormData();
          data.append('csrf_token', csrfToken);
          data.append('id', editButton.dataset.edit);
          const response = await fetch('/admin/blog-edit.php', {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'fetch' },
            body: data
          });
          const result = await parseJsonResponse(response);
          updateCsrf(result.csrfToken);
          if (!response.ok || !result.ok) {
            showNotice(result.error || 'Blog post could not be loaded.', 'error');
            return;
          }
          const blog = result.blog;
          editingId.value = blog.id || blog.slug || '';
          title.value = blog.title || '';
          slug.value = blog.slug || blog.id || '';
          slugPreview.textContent = slug.value || 'gperya-article';
          document.getElementById('blog-category').value = blog.category || 'Guides';
          document.getElementById('blog-author').value = blog.author || 'GperyaPH Editorial Team';
          document.getElementById('blog-excerpt').value = blog.excerpt || '';
          setEditorMarkdown(blog.content || '');
          renderMarkdownPreview();
          setImage(blog.featuredImage || '/uploads/blogs/default-featured.svg');
          manualSlug = true;
          submitBtn.textContent = 'Save Blog Changes';
          formTitle.textContent = 'Edit Blog Article';
          cancelBtn.style.display = 'inline-block';
          document.getElementById('blog-form-wrapper').scrollIntoView({ behavior: 'smooth' });
        }

        if (deleteButton && confirm('Delete this custom blog post?')) {
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
          await loadBlogs();
        }
      });

      loadBlogs().catch(() => showNotice('Blog list could not be loaded.', 'error'));
    });
  </script>
</body>
</html>
