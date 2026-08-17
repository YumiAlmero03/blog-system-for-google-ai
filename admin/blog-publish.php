<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/blog-storage.php';

require_auth();

$initialEditId = isset($_GET['id']) && is_string($_GET['id']) ? normalize_slug($_GET['id']) : '';
$blogCategoryOptions = blog_categories_all();
$websiteTitle = blog_website_title();
?>
<!DOCTYPE html>
<html lang="en-PH">
<head>
  <meta name="robots" content="noindex, nofollow">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Publish Blog Post | GperyaPH Admin</title>
  <link rel="preload" href="/admin/style.css" as="style"><link rel="stylesheet" href="/admin/style.css">
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
    .editor-tabs-bar {
      position: relative;
      z-index: 18;
      display: flex;
      flex-direction: column;
      align-items: stretch;
      gap: 12px;
      margin: 34px -12px 0;
      padding: 10px 12px;
      background: rgba(118, 0, 0, 0.96);
      border-bottom: 1px solid rgba(255, 255, 255, 0.18);
      box-shadow: 0 10px 22px rgba(0, 0, 0, 0.18);
      backdrop-filter: blur(10px);
    }
    .editor-tabs-bar.is-floating {
      position: fixed;
      top: 58px;
      left: var(--editor-tabs-left, 20px);
      width: var(--editor-tabs-width, calc(100vw - 40px));
      margin: 0;
      z-index: 45;
    }
    .editor-tabs-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 12px;
      flex-wrap: wrap;
    }
    .editor-floating-tools {
      display: flex;
      align-items: stretch;
      gap: 10px;
      flex-wrap: wrap;
    }
    .editor-floating-tools .wysiwyg-toolbar {
      flex: 1 1 420px;
      margin: 0;
    }
    .editor-floating-tools .editor-block-inserter {
      flex: 0 1 360px;
      margin: 0;
      min-height: 52px;
    }
    .editor-tabs-bar .editor-upload-status {
      min-height: 0;
      color: rgba(255,255,255,0.78);
      font-size: 0.8rem;
    }
    .editor-tabs-placeholder {
      display: block;
      height: 0;
    }
    .editor-tabs-placeholder.is-active {
      height: var(--editor-tabs-height, 56px);
    }
    .editor-tabs-actions {
      position: relative;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
    }
    .shortcut-helper-toggle {
      border: 1px solid var(--border);
      border-radius: var(--radius-sm);
      background: rgba(255,255,255,0.96);
      color: var(--brand-dark);
      cursor: pointer;
      padding: 8px 10px;
      font: inherit;
      font-size: 0.78rem;
      font-weight: 800;
    }
    .shortcut-helper-toggle:hover,
    .shortcut-helper-toggle[aria-expanded="true"] {
      background: var(--surface-soft);
      border-color: var(--brand);
    }
    .shortcut-helper {
      display: none;
      position: absolute;
      top: calc(100% + 8px);
      right: 0;
      width: min(320px, calc(100vw - 32px));
      padding: 12px;
      background: #fff;
      border: 1px solid #dcdcde;
      border-radius: 8px;
      box-shadow: 0 16px 34px rgba(0,0,0,0.18);
      color: #1e1e1e;
      font-size: 0.82rem;
      line-height: 1.45;
      z-index: 60;
    }
    .shortcut-helper.is-open {
      display: grid;
      gap: 8px;
    }
    .shortcut-helper-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
    }
    .shortcut-helper kbd {
      display: inline-flex;
      align-items: center;
      min-height: 22px;
      padding: 2px 6px;
      border: 1px solid #c3c4c7;
      border-bottom-width: 2px;
      border-radius: 4px;
      background: #f6f7f7;
      color: #1e1e1e;
      font-family: inherit;
      font-size: 0.74rem;
      font-weight: 800;
      white-space: nowrap;
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
    .link-toolbox {
      display: none;
      grid-template-columns: minmax(240px, 1fr) 44px;
      gap: 10px;
      align-items: center;
      width: min(704px, calc(100vw - 48px));
      margin: 0;
      padding: 34px;
      background: #fff;
      border: 1px solid #dcdcde;
      border-radius: 6px;
      box-shadow: 0 14px 32px rgba(0,0,0,0.18);
      position: fixed;
      left: 50%;
      top: 160px;
      transform: translateX(-50%);
      z-index: 80;
    }
    .link-toolbox.is-open {
      display: grid;
    }
    .link-toolbox::before {
      content: "";
      position: absolute;
      top: -8px;
      left: var(--link-arrow-left, 50%);
      width: 16px;
      height: 16px;
      background: #fff;
      border-left: 1px solid #dcdcde;
      border-top: 1px solid #dcdcde;
      transform: translateX(-50%) rotate(45deg);
    }
    .link-text-field,
    .link-toolbox .btn-secondary {
      display: none;
    }
    .link-toolbox input[type="text"],
    .link-toolbox input[type="url"] {
      min-width: 0;
      height: 82px;
      padding: 0 24px;
      border: 2px solid #3858e9;
      border-radius: 3px;
      font: inherit;
      font-size: 1.75rem;
      color: #3c434a;
    }
    .link-toolbox input[type="text"]:focus,
    .link-toolbox input[type="url"]:focus {
      border-color: #3858e9;
      outline: none;
      box-shadow: none;
    }
    .link-follow-option {
      grid-column: 1 / -1;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      color: #3c434a;
      font-size: 0.86rem;
      font-weight: 800;
      user-select: none;
    }
    .link-follow-option input {
      width: 16px;
      height: 16px;
      accent-color: #3858e9;
    }
    .link-follow-option small {
      color: #757575;
      font-size: 0.78rem;
      font-weight: 700;
    }
    .link-toolbox .btn-primary {
      width: 44px;
      height: 44px;
      padding: 0;
      border: 0;
      background: transparent;
      color: #8c8f94;
      font-size: 2.2rem;
      line-height: 1;
      box-shadow: none;
    }
    .link-toolbox-status {
      grid-column: 1 / -1;
      min-height: 16px;
      color: var(--danger);
      font-size: 0.78rem;
      font-weight: 700;
    }
    .image-settings-panel {
      display: none;
      grid-template-columns: minmax(180px, 1fr) minmax(180px, 1fr) auto auto;
      gap: 8px;
      align-items: end;
      width: min(820px, calc(100vw - 48px));
      padding: 12px;
      background: #fff;
      border: 1px solid #dcdcde;
      border-radius: 6px;
      box-shadow: 0 12px 26px rgba(0,0,0,0.16);
      position: fixed;
      left: 50%;
      top: 220px;
      transform: translateX(-50%);
      z-index: 82;
    }
    .image-settings-panel.is-open {
      display: grid;
    }
    .image-settings-panel label {
      display: grid;
      gap: 4px;
      color: #3c434a;
      font-size: 0.76rem;
      font-weight: 800;
    }
    .image-settings-panel input {
      min-width: 0;
      height: 38px;
      padding: 0 10px;
      border: 1px solid #8c8f94;
      border-radius: 4px;
      font: inherit;
      font-size: 0.9rem;
    }
    .image-follow-option {
      grid-column: 1 / -1;
      display: inline-flex !important;
      grid-template-columns: none !important;
      align-items: center;
      gap: 8px;
      width: fit-content;
      user-select: none;
    }
    .image-follow-option input {
      width: 16px;
      height: 16px;
      min-width: 16px;
      padding: 0;
      accent-color: #3858e9;
    }
    .image-follow-option small {
      color: #757575;
      font-size: 0.76rem;
      font-weight: 700;
    }
    .editor-drop-marker {
      height: 0;
      margin: 0;
      border-top: 4px solid #3858e9;
      border-radius: 999px;
      box-shadow: 0 0 0 2px rgba(56, 88, 233, 0.16);
      pointer-events: none;
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
    .wysiwyg-editor .editor-block {
      position: relative;
      padding: 10px 12px;
      margin: 8px 0;
      border: 1px solid transparent;
      border-radius: 4px;
      cursor: grab;
    }
    .wysiwyg-editor blockquote.editor-block{
      padding: 30px 12px 10px 52px;
    }
    .wysiwyg-editor .editor-block:hover,
    .wysiwyg-editor .editor-block:focus-within {
      border-color: #dcdcde;
      background: #fbfbfb;
    }
    .wysiwyg-editor .editor-block.is-dragging {
      cursor: grabbing;
      opacity: 0.55;
    }
    .wysiwyg-editor h1.editor-block,
    .wysiwyg-editor h2.editor-block,
    .wysiwyg-editor h3.editor-block {
      line-height: 1.25;
      font-weight: 800;
    }
    .wysiwyg-editor p.editor-block {
      min-height: 1.7em;
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
      cursor: grab;
    }
    .wysiwyg-editor img:active {
      cursor: grabbing;
    }
    .wysiwyg-editor img.is-selected {
      outline: 3px solid #3858e9;
      outline-offset: 3px;
    }
    .wysiwyg-editor .editor-image-block {
      min-height: 24px;
      padding: 8px 12px;
    }
    .wysiwyg-editor .editor-image-block.is-dragging {
      opacity: 0.55;
    }
    .wysiwyg-editor .editor-faq-block,
    .markdown-preview .editor-faq-block {
      position: relative;
      margin: 18px 0;
      padding: 18px;
      border: 1px solid rgba(166, 47, 61, 0.2);
      border-radius: var(--radius-sm);
      background:
        linear-gradient(135deg, rgba(255, 248, 240, 0.96) 0%, rgba(255, 243, 244, 0.98) 100%),
        radial-gradient(circle at 100% 0%, rgba(243, 198, 76, 0.2), transparent 32%);
      box-shadow: 0 12px 28px rgba(91, 24, 36, 0.11);
      overflow: hidden;
    }
    .editor-faq-label {
      display: inline-flex;
      align-items: center;
      min-height: 24px;
      padding: 4px 10px;
      border-radius: 999px;
      background: var(--brand);
      color: #fff;
      font-size: 0.72rem;
      font-weight: 900;
      letter-spacing: 0.04em;
      text-transform: uppercase;
    }
    .editor-faq-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      margin-bottom: 12px;
    }
    .editor-faq-actions,
    .editor-faq-item-actions {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      flex-shrink: 0;
    }
    .editor-faq-add,
    .editor-faq-remove {
      border: 1px solid rgba(166, 47, 61, 0.24);
      border-radius: 6px;
      background: #fff;
      color: var(--brand-dark);
      font: inherit;
      font-size: 0.78rem;
      font-weight: 800;
      line-height: 1;
      padding: 7px 10px;
      cursor: pointer;
    }
    .editor-faq-add:hover,
    .editor-faq-remove:hover {
      border-color: var(--brand);
      background: var(--surface-soft);
    }
    .editor-faq-items {
      display: grid;
      gap: 12px;
    }
    .editor-faq-item {
      position: relative;
      padding: 16px;
      border: 1px solid rgba(166, 47, 61, 0.14);
      border-radius: 8px;
      background: rgba(255, 255, 255, 0.82);
      box-shadow: 0 6px 16px rgba(91, 24, 36, 0.06);
      z-index: 1;
    }
    .editor-faq-item-actions {
      justify-content: flex-end;
      margin-bottom: 8px;
    }
    .editor-faq-field {
      display: grid;
      gap: 6px;
      margin-top: 10px;
    }
    .editor-faq-field:first-of-type {
      margin-top: 0;
    }
    .editor-faq-field-label {
      color: var(--text-muted);
      font-size: 0.72rem;
      font-weight: 900;
      letter-spacing: 0.04em;
      text-transform: uppercase;
    }
    .editor-faq-question {
      margin: 0 0 8px;
      min-height: 28px;
      padding: 10px 12px;
      border: 1px solid rgba(166, 47, 61, 0.16);
      border-radius: 6px;
      background: #fff;
      color: var(--brand-dark);
      font-size: 1.05rem;
      font-weight: 900;
      line-height: 1.35;
    }
    .editor-faq-answer {
      margin: 0;
      min-height: 54px;
      padding: 10px 12px;
      border: 1px solid rgba(166, 47, 61, 0.16);
      border-radius: 6px;
      background: #fff;
      color: var(--text);
      line-height: 1.65;
    }
    .wysiwyg-editor blockquote,
    .markdown-preview blockquote {
      position: relative;
      margin: 18px 0;
      padding: 22px 24px 22px 64px;
      border: 1px solid rgba(166, 47, 61, 0.2);
      border-left: 7px solid var(--brand);
      border-radius: var(--radius-sm);
      background:
        linear-gradient(135deg, rgba(255, 255, 255, 0.92) 0%, rgba(255, 243, 244, 0.96) 100%),
        radial-gradient(circle at 100% 0%, rgba(243, 198, 76, 0.18), transparent 34%);
      color: var(--brand-dark);
      font-size: 1.02rem;
      font-weight: 700;
      line-height: 1.7;
      box-shadow: 0 12px 28px rgba(91, 24, 36, 0.11);
      overflow: hidden;
    }
    .wysiwyg-editor blockquote::before,
    .markdown-preview blockquote::before {
      content: "\"";
      position: absolute;
      top: 12px;
      left: 20px;
      color: rgba(166, 47, 61, 0.24);
      font-family: Georgia, serif;
      font-size: 3.6rem;
      line-height: 1;
      font-weight: 900;
    }
    .wysiwyg-editor blockquote::after,
    .markdown-preview blockquote::after {
      content: "";
      position: absolute;
      right: -34px;
      bottom: -40px;
      width: 120px;
      height: 120px;
      border-radius: 50%;
      background: rgba(243, 198, 76, 0.14);
      pointer-events: none;
    }
    .wysiwyg-editor blockquote p,
    .markdown-preview blockquote p {
      margin: 0;
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
    body.wp-admin-clone {
      background: #111;
      color: #1e1e1e;
      padding-bottom: 0;
    }
    body.wp-admin-clone .page-shell {
      max-width: none;
      min-height: 100vh;
      background: #f0f0f1;
      box-shadow: none;
    }
    .wp-admin-bar {
      height: 36px;
      background: #2b1b17;
      color: #f0e7e4;
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 0 16px;
      font-size: 0.8rem;
      font-weight: 700;
      border-bottom: 1px solid rgba(255,255,255,0.12);
    }
    .wp-admin-bar .wp-dot {
      width: 18px;
      height: 18px;
      display: inline-grid;
      place-items: center;
      background: #d02028;
      color: #fff;
      border-radius: 3px;
      font-size: 0.62rem;
      font-weight: 900;
    }
    .wp-editor-topbar {
      height: 58px;
      display: grid;
      grid-template-columns: minmax(260px, 1fr) minmax(260px, 520px) minmax(320px, 1fr);
      align-items: center;
      gap: 16px;
      padding: 0 18px;
      background: #fff;
      border-bottom: 1px solid #ddd;
      position: sticky;
      top: 0;
      z-index: 20;
    }
    .wp-toolbar-left,
    .wp-toolbar-right {
      display: flex;
      align-items: center;
      gap: 10px;
      min-width: 0;
    }
    .wp-toolbar-right {
      justify-content: flex-end;
    }
    .wp-icon-button {
      width: 36px;
      height: 36px;
      border: 0;
      border-radius: 3px;
      background: transparent;
      color: #1e1e1e;
      cursor: pointer;
      display: inline-grid;
      place-items: center;
      font-size: 1.15rem;
      line-height: 1;
      text-decoration: none;
    }
    .wp-icon-button:hover {
      background: #f0f0f1;
    }
    .wp-icon-button:disabled {
      color: #a7aaad;
      cursor: not-allowed;
      opacity: 0.62;
    }
    .wp-icon-button:disabled:hover {
      background: transparent;
    }
    .wp-plus {
      background: #3858e9;
      color: #fff;
      font-size: 1.4rem;
    }
    .wp-design-button {
      height: 36px;
      border: 0;
      border-radius: 3px;
      background: #3858e9;
      color: #fff;
      padding: 0 14px;
      font-weight: 800;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 8px;
    }
    .wp-document-title {
      height: 38px;
      display: flex;
      align-items: center;
      justify-content: center;
      background: #f0f0f1;
      border-radius: 3px;
      color: #1e1e1e;
      font-size: 0.92rem;
      font-weight: 600;
      min-width: 0;
    }
    .wp-save-state {
      color: #757575;
      font-size: 0.86rem;
      white-space: nowrap;
    }
    .seo-score-badge {
      border: 1px solid #f48fb1;
      background: #ffeaf3;
      color: #d6366f;
      border-radius: 3px;
      min-width: 86px;
      height: 36px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
      font-weight: 900;
    }
    .seo-score-badge strong {
      font-size: 1.3rem;
      line-height: 1;
    }
    .seo-score-badge.is-ok {
      border-color: #46b450;
      background: #edfaef;
      color: #008a20;
    }
    .seo-score-badge.is-warn {
      border-color: #f0b849;
      background: #fff8e5;
      color: #996800;
    }
    .wp-editor-layout {
      display: grid;
      grid-template-columns: minmax(0, 1fr) 340px;
      min-height: calc(100vh - 94px);
      background: #f0f0f1;
      transition: grid-template-columns 0.2s ease;
    }
    .wp-editor-layout.is-sidebar-collapsed {
      grid-template-columns: minmax(0, 1fr) 72px;
    }
    .wp-editor-main {
      min-width: 0;
      overflow: auto;
    }
    .wp-editor-notice {
      margin: 16px 20px 0;
    }
    .wp-canvas {
      min-height: 360px;
      padding: 76px 48px 54px;
      background: #760000;
      color: #fff;
      position: relative;
    }
    .wp-title-input {
      width: 100%;
      border: 0;
      background: transparent;
      color: #c8b400;
      font-size: clamp(2.2rem, 6vw, 4rem);
      line-height: 1.05;
      font-weight: 400;
      padding: 0;
      outline: 0;
      font-family: inherit;
    }
    .wp-title-input::placeholder {
      color: #c8b400;
      opacity: 1;
    }
    .wp-title-preview {
      min-height: 74px;
      color: #c8b400;
      font-size: clamp(2.2rem, 6vw, 4rem);
      line-height: 1.05;
      font-weight: 400;
      overflow-wrap: anywhere;
    }
    .wp-title-preview.is-empty {
      opacity: 0.86;
    }
    .wp-canvas-subline {
      color: rgba(255,255,255,0.72);
      margin-top: 26px;
      font-size: 1.08rem;
      font-weight: 600;
    }
    .wp-canvas .wysiwyg-toolbar,
    .wp-canvas .editor-block-inserter {
      margin-top: 24px;
      background: rgba(255,255,255,0.96);
    }
    .wp-canvas .editor-tabs-bar .wysiwyg-toolbar,
    .wp-canvas .editor-tabs-bar .editor-block-inserter {
      margin-top: 0;
      margin-bottom: 0;
      background: rgba(255,255,255,0.96);
    }
    .wp-canvas .editor-tabs-bar .editor-block-inserter {
      border-style: solid;
    }
    .wp-canvas .editor-shell {
      margin-top: 10px;
    }
    .wp-canvas .wysiwyg-editor,
    .wp-canvas #blog-content,
    .wp-canvas .markdown-preview {
      min-height: 280px;
      background: #fff;
      color: #1e1e1e;
    }
    .meta-box-panel {
      background: #fff;
      border-top: 1px solid #ddd;
      border-bottom: 1px solid #ddd;
      padding: 0 0 28px;
    }
    .meta-box-title {
      height: 38px;
      display: flex;
      align-items: center;
      padding: 0 20px;
      border-bottom: 1px solid #ddd;
      font-weight: 800;
      color: #1e1e1e;
    }
    .meta-box-help {
      padding: 12px 20px;
      color: #50575e;
      font-size: 0.92rem;
    }
    .meta-box-grid {
      display: grid;
      grid-template-columns: minmax(0, 1fr) 260px;
      gap: 18px;
      padding: 0 20px;
      align-items: start;
    }
    .meta-box-fieldset {
      border: 1px solid #dcdcde;
      padding: 18px;
      background: #fff;
      min-height: 250px;
    }
    .field-label-row {
      display: flex;
      align-items: baseline;
      justify-content: space-between;
      gap: 12px;
      margin-bottom: 6px;
    }
    .field-label-row label {
      margin-bottom: 0;
    }
    .char-counter {
      color: var(--text-muted);
      font-size: 0.75rem;
      font-weight: 800;
      white-space: nowrap;
    }
    .char-counter.warn {
      color: #996800;
    }
    .char-counter.over {
      color: var(--danger);
    }
    .yoast-bottom-panel {
      background: #fff;
      border-top: 1px solid #dcdcde;
      padding: 16px 20px 40px;
    }
    .yoast-sidebar {
      background: #fff;
      border-left: 1px solid #ddd;
      min-width: 0;
      width: 100%;
      height: calc(100vh - 94px);
      overflow: hidden;
      position: sticky;
      top: 58px;
      transition: width 0.2s ease, min-width 0.2s ease, max-width 0.2s ease;
    }
    .yoast-sidebar.is-collapsed {
      width: 72px;
      min-width: 72px;
      max-width: 72px;
    }
    .yoast-sidebar-header {
      height: 58px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0 18px;
      border-bottom: 1px solid #ddd;
      font-weight: 800;
      gap: 8px;
    }
    .yoast-sidebar.is-collapsed .yoast-sidebar-header {
      justify-content: center;
      padding: 0 8px;
    }
    .yoast-sidebar-title {
      min-width: 0;
      white-space: nowrap;
    }
    .yoast-sidebar.is-collapsed .yoast-sidebar-title {
      display: none;
    }
    .yoast-sidebar-toggle {
      width: 28px;
      height: 28px;
      border: 1px solid #dcdcde;
      border-radius: 50%;
      background: #f0f0f1;
      color: #1e1e1e;
      font-size: 1.2rem;
      line-height: 1;
      cursor: pointer;
      padding: 0;
      display: inline-grid;
      place-items: center;
      transition: transform 0.2s ease;
    }
    .yoast-sidebar-toggle:hover {
      background: #e5e5e5;
    }
    .yoast-sidebar-body {
      height: calc(100% - 58px);
      overflow: auto;
    }
    .yoast-sidebar.is-collapsed .yoast-sidebar-body {
      display: none;
    }
    .yoast-panel {
      border-bottom: 1px solid #ddd;
      padding: 18px;
    }
    .yoast-logo {
      color: #a4286a;
      font-size: 1.9rem;
      font-weight: 900;
      line-height: 1;
      margin-bottom: 14px;
    }
    .yoast-panel label {
      display: block;
      color: #344054;
      font-size: 0.86rem;
      font-weight: 800;
      margin-bottom: 8px;
    }
    .yoast-panel .form-control {
      border-color: #c3c4c7;
      border-radius: 4px;
    }
    .yoast-help {
      color: #667085;
      font-size: 0.84rem;
      line-height: 1.5;
      margin-top: 10px;
    }
    .seo-meter {
      display: grid;
      grid-template-columns: 72px 1fr;
      gap: 12px;
      align-items: center;
      margin-top: 12px;
    }
    .seo-meter-ring {
      width: 72px;
      height: 72px;
      border-radius: 50%;
      background: conic-gradient(#d63638 0deg, #ececec 0deg);
      display: grid;
      place-items: center;
      color: #1e1e1e;
      font-weight: 900;
      position: relative;
    }
    .seo-meter-ring::before {
      content: "";
      position: absolute;
      inset: 8px;
      background: #fff;
      border-radius: 50%;
    }
    .seo-meter-ring span {
      position: relative;
      z-index: 1;
    }
    .seo-meter-status {
      font-weight: 900;
      color: #d63638;
    }
    .seo-meter-status.is-ok {
      color: #008a20;
    }
    .seo-meter-status.is-warn {
      color: #996800;
    }
    .yoast-check-list {
      list-style: none;
      display: grid;
      gap: 10px;
      padding: 0;
      margin: 14px 0 0;
    }
    .yoast-check-list li {
      display: grid;
      grid-template-columns: 18px 1fr;
      gap: 8px;
      color: #344054;
      font-size: 0.84rem;
      line-height: 1.35;
    }
    .yoast-check-list .dot {
      width: 14px;
      height: 14px;
      margin-top: 2px;
      border-radius: 50%;
      background: #d63638;
    }
    .yoast-check-list .dot.ok {
      background: #00a32a;
    }
    .yoast-check-list .dot.warn {
      background: #dba617;
    }
    .yoast-accordion-title {
      display: flex;
      align-items: center;
      justify-content: space-between;
      font-weight: 900;
      color: #1e1e1e;
    }
    .yoast-analysis-icon {
      width: 18px;
      height: 18px;
      border-radius: 50%;
      display: inline-grid;
      place-items: center;
      color: #fff;
      font-size: 0.72rem;
      font-weight: 900;
      margin-right: 8px;
      background: #d63638;
    }
    .yoast-analysis-icon.ok {
      background: #00a32a;
    }
    @media (max-width: 1100px) {
      .wp-editor-layout {
        grid-template-columns: 1fr;
      }
      .yoast-sidebar {
        position: static;
        height: auto;
        border-left: 0;
        border-top: 1px solid #ddd;
      }
      .wp-editor-topbar {
        grid-template-columns: 1fr;
        height: auto;
        padding: 10px;
      }
      .wp-toolbar-right {
        justify-content: flex-start;
        flex-wrap: wrap;
      }
      .editor-tabs-bar {
        top: 0;
      }
      .editor-tabs-bar.is-floating {
        top: 0;
      }
    }
    @media (max-width: 760px) {
      .wp-canvas {
        padding: 44px 18px 36px;
      }
      .meta-box-grid {
        grid-template-columns: 1fr;
      }
      .wp-title-input {
        font-size: 2.3rem;
      }
      .link-toolbox {
        grid-template-columns: minmax(0, 1fr) 40px;
        padding: 18px;
      }
      .link-toolbox input[type="text"],
      .link-toolbox input[type="url"] {
        height: 58px;
        padding: 0 14px;
        font-size: 1rem;
      }
      .image-settings-panel {
        grid-template-columns: 1fr;
        align-items: stretch;
      }
    }
  </style>
</head>
<body class="wp-admin-clone">
  <div class="page-shell">

    <form id="create-blog-form" method="post">
      <input type="hidden" name="csrf_token" id="csrf-token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" id="editing-blog-id" name="id" value="">

      <header class="wp-editor-topbar">
        <div class="wp-toolbar-left">
          <a href="/admin/blogs.php" class="wp-icon-button" aria-label="Back to blogs">&#8592;</a>
          <button type="button" class="wp-icon-button" id="editor-undo-btn" aria-label="Undo" title="Undo" disabled>&#8592;</button>
          <button type="button" class="wp-icon-button" id="editor-redo-btn" aria-label="Redo" title="Redo" disabled>&#8594;</button>
        </div>
        <div class="wp-document-title"><span id="form-title-text">No title · Post</span></div>
        <div class="wp-toolbar-right">
          <button type="submit" id="save-draft-btn" class="btn btn-secondary btn-sm" data-save-status="draft">Save as Draft</button>
          <button type="submit" id="publish-blog-btn" class="btn btn-primary" data-save-status="published">Publish</button>
          <button type="button" id="view-blog-page-btn" class="btn btn-secondary btn-sm" style="display:none;">View Page</button>
        </div>
      </header>

      <div id="notice" class="notice wp-editor-notice" role="status"></div>

      <div class="wp-editor-layout" id="blog-form-wrapper">
        <main class="wp-editor-main">
          <section class="wp-canvas">
            <div class="wp-canvas-inner">

              <div class="editor-tabs-placeholder" id="editor-tabs-placeholder" aria-hidden="true"></div>
              <div class="editor-tabs-bar">
                <div class="editor-tabs-header">
                  <span id="content-word-counter" class="content-word-counter" aria-live="polite" style="color:rgba(255,255,255,0.78);">0 words</span>
                  <div class="editor-tabs-actions">
                    <div class="editor-tabs" aria-label="Markdown editor view">
                      <button type="button" class="editor-tab active" id="tab-write">WYSIWYG</button>
                      <button type="button" class="editor-tab" id="tab-markdown">Markdown</button>
                      <button type="button" class="editor-tab" id="tab-preview">Preview</button>
                      <button type="button" class="editor-tab" id="tab-split">Split View</button>
                    </div>
                    <button type="button" class="shortcut-helper-toggle" id="shortcut-helper-toggle" aria-expanded="false" aria-controls="shortcut-helper">Shortcuts</button>
                    <div class="shortcut-helper" id="shortcut-helper" role="dialog" aria-label="Editor shortcuts">
                      <div class="shortcut-helper-row"><span>Insert/edit link</span><kbd>Ctrl/⌘ K</kbd></div>
                      <div class="shortcut-helper-row"><span>Copy selected block</span><kbd>Ctrl/⌘ C</kbd></div>
                      <div class="shortcut-helper-row"><span>Cut selected block</span><kbd>Ctrl/⌘ X</kbd></div>
                      <div class="shortcut-helper-row"><span>Paste copied block</span><kbd>Ctrl/⌘ V</kbd></div>
                      <div class="shortcut-helper-row"><span>Delete selected block</span><kbd>Delete</kbd></div>
                      <div class="shortcut-helper-row"><span>Undo</span><kbd>Ctrl/⌘ Z</kbd></div>
                      <div class="shortcut-helper-row"><span>Redo</span><kbd>Ctrl/⌘ Shift Z</kbd></div>
                      <div class="shortcut-helper-row"><span>Move block up</span><kbd>Alt ↑</kbd></div>
                      <div class="shortcut-helper-row"><span>Move block down</span><kbd>Alt ↓</kbd></div>
                    </div>
                  </div>
                </div>
                <div class="editor-floating-tools">
                  <div class="wysiwyg-toolbar" id="editor-toolbar" aria-label="Article formatting toolbar">
                    <button type="button" class="wysiwyg-btn" data-command="h2" title="Heading">H2</button>
                    <button type="button" class="wysiwyg-btn" data-command="h3" title="Subheading">H3</button>
                    <button type="button" class="wysiwyg-btn" data-command="bold" title="Bold">B</button>
                    <button type="button" class="wysiwyg-btn" data-command="italic" title="Italic"><em>I</em></button>
                    <button type="button" class="wysiwyg-btn" data-command="ul" title="Bullet list">List</button>
                    <button type="button" class="wysiwyg-btn" data-command="ol" title="Numbered list">1.</button>
                    <button type="button" class="wysiwyg-btn" data-command="quote" title="Quote">Quote</button>
                    <button type="button" class="wysiwyg-btn" data-command="faq" title="FAQ block">FAQ</button>
                    <button type="button" class="wysiwyg-btn" data-command="link" title="Insert link">Link</button>
                    <button type="button" class="wysiwyg-btn" data-command="clear" title="Clear formatting">Clear</button>
                  </div>
                  <div class="editor-block-inserter" id="article-image-dropzone" role="button" tabindex="0" aria-controls="article-image-upload">
                    <div>
                      <strong>Add image block</strong>
                      <span>Drop an image here, paste into the editor, or click Add Image.</span>
                    </div>
                    <button type="button" class="btn btn-secondary btn-sm" id="article-image-button">Add Image</button>
                  </div>
                </div>
                <div id="article-image-status" class="editor-upload-status"></div>
              </div>
              <div id="link-toolbox" class="link-toolbox" aria-hidden="true">
                <input type="text" id="link-text-input" class="link-text-field" placeholder="Link text" aria-label="Link text" tabindex="-1">
                <input type="url" id="link-url-input" placeholder="Search or type URL" aria-label="Link URL">
                <button type="button" id="apply-link-btn" class="btn btn-primary btn-sm" aria-label="Apply link" title="Apply link">&#8592;</button>
                <button type="button" id="cancel-link-btn" class="btn btn-secondary btn-sm">Cancel</button>
                <label class="link-follow-option" for="link-nofollow-input">
                  <input type="checkbox" id="link-nofollow-input">
                  <span>Nofollow link</span>
                  <small>Unchecked is dofollow</small>
                </label>
                <div id="link-toolbox-status" class="link-toolbox-status" role="status"></div>
              </div>
              <div id="image-settings-panel" class="image-settings-panel" aria-hidden="true">
                <label for="image-alt-input">Alt Text
                  <input type="text" id="image-alt-input" maxlength="160" placeholder="Describe this image">
                </label>
                <label for="image-link-input">Image Link
                  <input type="url" id="image-link-input" placeholder="https:// or /page/">
                </label>
                <label class="image-follow-option" for="image-link-nofollow-input">
                  <input type="checkbox" id="image-link-nofollow-input">
                  <span>Nofollow link</span>
                  <small>Unchecked is dofollow</small>
                </label>
                <button type="button" id="apply-image-settings-btn" class="btn btn-primary btn-sm">Apply</button>
                <button type="button" id="remove-image-link-btn" class="btn btn-secondary btn-sm">Remove Link</button>
              </div>
              <input type="file" id="article-image-upload" accept="image/jpeg,image/png,image/webp" style="position:absolute; width:1px; height:1px; opacity:0; pointer-events:none;" tabindex="-1">
              <div id="editor-shell" class="editor-shell editor-mode-write">
                <textarea id="blog-content" name="content" maxlength="60000" aria-label="Markdown article content"></textarea>
                <div id="wysiwyg-editor" class="wysiwyg-editor" contenteditable="true" role="textbox" aria-multiline="true" data-placeholder="Write your article here. Use the toolbar for headings, links, lists, quotes, and images."></div>
                <div id="markdown-preview" class="markdown-preview">
                  <p style="color:var(--text-muted); font-style:italic;">Markdown preview will render here.</p>
                </div>
              </div>
            </div>
          </section>

          <section class="meta-box-panel">
            <div class="meta-box-title">Meta Boxes</div>
            <p class="meta-box-help">The code below will be inserted into the &lt;head&gt; section of this specific page/post.</p>
            <div class="meta-box-grid">
              <div class="meta-box-fieldset">
                <div class="form-group">
                  <div class="field-label-row">
                    <label for="blog-title">Article Title *</label>
                    <span id="title-char-counter" class="char-counter">0/160</span>
                  </div>
                  <input type="text" id="blog-title" name="title" class="form-control" maxlength="160" required>
                </div>
                <div class="form-group">
                  <div class="field-label-row">
                    <label for="blog-seo-title">SEO Title *</label>
                    <span id="seo-title-char-counter" class="char-counter">0/160</span>
                  </div>
                  <input type="text" id="blog-seo-title" name="seo_title" class="form-control" maxlength="160" required aria-describedby="seo-title-sync-note">
                  <span id="seo-title-sync-note" style="font-size:0.75rem; color:var(--text-muted); display:block; margin-top:4px;">Default: Article Title | <?= h($websiteTitle) ?>. Edit it here if you need a different search title.</span>
                </div>
                <div style="display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:16px;">
                  <div class="form-group">
                    <label for="blog-slug">SEO Slug *</label>
                    <input type="text" id="blog-slug" name="slug" class="form-control" maxlength="96" pattern="[a-z0-9-]+" required>
                    <span style="font-size:0.75rem; color:var(--text-muted); display:block; margin-top:4px;">Page URL: <code>/blog/<span id="slug-preview-text">gperya-article</span>/</code></span>
                  </div>
                  <div class="form-group">
                    <label for="blog-category">Category Tag *</label>
                    <select id="blog-category" name="category" class="form-control" required>
                      <?php foreach ($blogCategoryOptions as $category): ?>
                        <option value="<?= h($category['name']) ?>"><?= h($category['name']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="form-group">
                    <label for="blog-status-field">Blog Status *</label>
                    <select id="blog-status-field" name="status" class="form-control" required>
                      <option value="published">Published</option>
                      <option value="draft">Draft</option>
                    </select>
                  </div>
                </div>
                <div class="form-group">
                  <div class="field-label-row">
                    <label for="blog-excerpt">Meta Description *</label>
                    <span id="excerpt-char-counter" class="char-counter">0/170</span>
                  </div>
                  <textarea id="blog-excerpt" name="excerpt" class="form-control" maxlength="170" style="min-height:96px;" required></textarea>
                </div>
                <div class="form-group">
                  <label for="blog-author">Author Name</label>
                  <input type="text" id="blog-author" name="author" class="form-control" value="<?= h(BLOG_DEFAULT_AUTHOR) ?>" maxlength="80">
                </div>
              </div>
              <div class="meta-box-fieldset">
                <label for="blog-image-upload" style="display:block; font-weight:800; margin-bottom:10px;">Featured Image</label>
                <div id="image-dropzone" class="image-dropzone" role="button" tabindex="0" aria-controls="blog-image-upload" style="min-height:120px;">
                  <div class="image-dropzone-icon">+</div>
                  <strong>Drop image to upload</strong>
                  <span>JPEG, PNG, or WebP up to 3 MB.</span>
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
                <div style="width:100%; height:120px; border-radius:var(--radius-sm); border:1px solid var(--border); overflow:hidden; background:#0d0104; display:flex; align-items:center; justify-content:center; margin-top:12px;">
                  <img id="featured-image-preview" src="<?= h(BLOG_DEFAULT_IMAGE) ?>" alt="Featured image preview" style="width:100%; height:100%; object-fit:cover;">
                </div>
                <span id="image-path-badge" class="badge badge-yellow" style="font-size:0.72rem; margin-top:6px; display:inline-block; max-width:100%; overflow-wrap:anywhere;"><?= h(BLOG_DEFAULT_IMAGE) ?></span>
                <button type="submit" id="save-current-post-btn" class="btn btn-primary" style="width:100%; margin-top:14px;" data-save-current="true">Save Post</button>
              </div>
            </div>
          </section>

          <section class="yoast-bottom-panel">
            <div class="yoast-accordion-title">
              <span>SEO Rating</span>
              <span id="yoast-bottom-score">Needs improvement</span>
            </div>
          </section>
        </main>

        <aside class="yoast-sidebar" aria-label="SEO Rating">
          <div class="yoast-sidebar-header">
            <span class="yoast-sidebar-title">SEO Rating</span>
            <button type="button" class="yoast-sidebar-toggle" aria-label="Collapse SEO Rating sidebar" aria-expanded="true" title="Collapse SEO Rating">&times;</button>
          </div>
          <div class="yoast-sidebar-body">
            <div class="yoast-panel">
              <div class="yoast-logo">SEO</div>
              <p class="yoast-help" style="margin-top:0;">Optimize your content for discovery with SEO checks.</p>
            </div>
            <div class="yoast-panel">
              <label for="seo-focus-keyphrase">Focus keyphrase</label>
              <input type="text" id="seo-focus-keyphrase" name="focus_keyphrase" class="form-control" placeholder="Type here" maxlength="160">
              <p class="yoast-help">Use the main word or phrase you want your content found for across search.</p>
            </div>
            <div class="yoast-panel">
              <div class="yoast-accordion-title"><span><span id="seo-analysis-icon" class="yoast-analysis-icon">!</span>SEO analysis</span><span>&#8964;</span></div>
              <div class="seo-meter">
                <div id="seo-meter-ring" class="seo-meter-ring"><span id="seo-meter-score">0</span></div>
                <div>
                  <div id="seo-meter-status" class="seo-meter-status">Needs improvement</div>
                  <div class="yoast-help" id="seo-meter-help">Add a focus keyphrase and content to begin.</div>
                </div>
              </div>
              <ul id="seo-check-list" class="yoast-check-list"></ul>
            </div>
            <div class="yoast-panel">
              <div class="yoast-accordion-title"><span><span id="readability-analysis-icon" class="yoast-analysis-icon ok">!</span>Readability analysis</span><span>&#8964;</span></div>
              <ul id="readability-check-list" class="yoast-check-list"></ul>
            </div>
          </div>
        </aside>
      </div>
    </form>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', () => {
      let csrfToken = document.getElementById('csrf-token').value;
      const initialEditId = <?= json_encode($initialEditId, JSON_UNESCAPED_SLASHES) ?>;
      const websiteTitle = <?= json_encode($websiteTitle, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
      const form = document.getElementById('create-blog-form');
      const notice = document.getElementById('notice');
      const title = document.getElementById('blog-title');
      const seoTitle = document.getElementById('blog-seo-title');
      const titlePreview = document.getElementById('blog-title-preview');
      const titleCharCounter = document.getElementById('title-char-counter');
      const seoTitleCharCounter = document.getElementById('seo-title-char-counter');
      const excerpt = document.getElementById('blog-excerpt');
      const excerptCharCounter = document.getElementById('excerpt-char-counter');
      const blogStatus = document.getElementById('blog-status-field');
      const categoryField = document.getElementById('blog-category');
      const authorField = document.getElementById('blog-author');
      const slug = document.getElementById('blog-slug');
      const slugPreview = document.getElementById('slug-preview-text');
      const editingId = document.getElementById('editing-blog-id');
      const saveDraftBtn = document.getElementById('save-draft-btn');
      const publishBtn = document.getElementById('publish-blog-btn');
      const saveCurrentPostBtn = document.getElementById('save-current-post-btn');
      const viewBlogPageBtn = document.getElementById('view-blog-page-btn');
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
      const seoSidebar = document.querySelector('.yoast-sidebar');
      const seoSidebarToggle = document.querySelector('.yoast-sidebar-toggle');
      const seoSidebarTitle = document.querySelector('.yoast-sidebar-title');
      const editorLayout = document.querySelector('.wp-editor-layout');
      const articleImageUpload = document.getElementById('article-image-upload');
      const articleImageButton = document.getElementById('article-image-button');
      const articleImageDropzone = document.getElementById('article-image-dropzone');
      const articleImageStatus = document.getElementById('article-image-status');
      const linkToolbox = document.getElementById('link-toolbox');
      const linkTextInput = document.getElementById('link-text-input');
      const linkUrlInput = document.getElementById('link-url-input');
      const linkNofollowInput = document.getElementById('link-nofollow-input');
      const linkToolboxStatus = document.getElementById('link-toolbox-status');
      const applyLinkBtn = document.getElementById('apply-link-btn');
      const cancelLinkBtn = document.getElementById('cancel-link-btn');
      const imageSettingsPanel = document.getElementById('image-settings-panel');
      const imageAltInput = document.getElementById('image-alt-input');
      const imageLinkInput = document.getElementById('image-link-input');
      const imageLinkNofollowInput = document.getElementById('image-link-nofollow-input');
      const applyImageSettingsBtn = document.getElementById('apply-image-settings-btn');
      const removeImageLinkBtn = document.getElementById('remove-image-link-btn');
      const contentWordCounter = document.getElementById('content-word-counter');
      const editorTabsBar = document.querySelector('.editor-tabs-bar');
      const editorTabsPlaceholder = document.getElementById('editor-tabs-placeholder');
      const shortcutHelperToggle = document.getElementById('shortcut-helper-toggle');
      const shortcutHelper = document.getElementById('shortcut-helper');
      const tabWrite = document.getElementById('tab-write');
      const tabMarkdown = document.getElementById('tab-markdown');
      const tabPreview = document.getElementById('tab-preview');
      const tabSplit = document.getElementById('tab-split');
      const undoBtn = document.getElementById('editor-undo-btn');
      const redoBtn = document.getElementById('editor-redo-btn');
      const focusKeyphrase = document.getElementById('seo-focus-keyphrase');
      const keyphraseSynonyms = document.getElementById('seo-keyphrase-synonyms');
      const seoScoreBadge = document.getElementById('seo-score-badge');
      const seoMeterRing = document.getElementById('seo-meter-ring');
      const seoMeterScore = document.getElementById('seo-meter-score');
      const seoMeterStatus = document.getElementById('seo-meter-status');
      const seoMeterHelp = document.getElementById('seo-meter-help');
      const seoAnalysisIcon = document.getElementById('seo-analysis-icon');
      const readabilityAnalysisIcon = document.getElementById('readability-analysis-icon');
      const seoCheckList = document.getElementById('seo-check-list');
      const readabilityCheckList = document.getElementById('readability-check-list');
      const yoastBottomScore = document.getElementById('yoast-bottom-score');
      const saveState = document.querySelector('.wp-save-state');
      const hasLinkToolbox = linkToolbox && linkTextInput && linkUrlInput && linkNofollowInput && linkToolboxStatus && applyLinkBtn && cancelLinkBtn;
      let manualSlug = false;
      let manualSeoTitle = false;
      let savedEditorRange = null;
      let savedMarkdownSelection = null;
      let activeLinkElement = null;
      let activeImageElement = null;
      let draggedEditorBlock = null;
      let editorDropMarker = null;
      let pendingSaveStatus = '';
      let yoastLoaderPromise = null;
      let yoastAnalysisRequest = 0;
      let editorHistory = [];
      let editorHistoryIndex = -1;
      let editorHistoryTimer = 0;
      let isRestoringHistory = false;
      let editorBlockClipboardHtml = '';
      let editorTabsFloating = false;
      let editorTabsFrame = 0;
      const editorBlockClipboardPrefix = 'GPERYA_EDITOR_BLOCK::';
      const yoastModuleUrl = '/assets/vendor/yoastseo/yoastseo.bundle.js?v=3.6.0';
      const yoastResearcherUrl = '/assets/vendor/yoastseo/researcher.bundle.js?v=3.6.0';

      function setSeoSidebarCollapsed(isCollapsed) {
        if (!seoSidebar || !seoSidebarToggle || !editorLayout) {
          return;
        }

        seoSidebar.classList.toggle('is-collapsed', isCollapsed);
        editorLayout.classList.toggle('is-sidebar-collapsed', isCollapsed);
        seoSidebarToggle.setAttribute('aria-expanded', String(!isCollapsed));
        seoSidebarToggle.setAttribute('aria-label', isCollapsed ? 'Expand SEO Rating sidebar' : 'Collapse SEO Rating sidebar');
        seoSidebarToggle.title = isCollapsed ? 'Expand SEO Rating' : 'Collapse SEO Rating';
        seoSidebarToggle.textContent = isCollapsed ? '›' : '×';

        if (seoSidebarTitle) {
          seoSidebarTitle.hidden = isCollapsed;
        }
      }

      seoSidebarToggle?.addEventListener('click', () => {
        const isCollapsed = !seoSidebar.classList.contains('is-collapsed');
        setSeoSidebarCollapsed(isCollapsed);
      });

      function on(target, type, handler) {
        if (!target) return;
        target.addEventListener(type, handler);
      }

      function closeShortcutHelper() {
        if (!shortcutHelper || !shortcutHelperToggle) return;
        shortcutHelper.classList.remove('is-open');
        shortcutHelperToggle.setAttribute('aria-expanded', 'false');
      }

      function updateFloatingEditorTabs() {
        if (!editorTabsBar || !editorTabsPlaceholder) return;
        const topOffset = window.matchMedia('(max-width: 1100px)').matches ? 0 : 58;
        const canvasInner = editorTabsBar.parentElement;
        const canvasRect = canvasInner ? canvasInner.getBoundingClientRect() : editorTabsBar.getBoundingClientRect();
        const placeholderRect = editorTabsPlaceholder.getBoundingClientRect();
        const barHeight = editorTabsBar.offsetHeight || 56;
        const shouldFloat = placeholderRect.top <= topOffset && canvasRect.bottom > topOffset + barHeight + 12;

        editorTabsBar.style.setProperty('--editor-tabs-left', Math.max(0, canvasRect.left - 12) + 'px');
        editorTabsBar.style.setProperty('--editor-tabs-width', Math.max(280, canvasRect.width + 24) + 'px');
        editorTabsBar.style.setProperty('--editor-tabs-height', barHeight + 'px');
        editorTabsPlaceholder.style.setProperty('--editor-tabs-height', barHeight + 'px');
        if (shouldFloat !== editorTabsFloating) {
          editorTabsFloating = shouldFloat;
          editorTabsBar.classList.toggle('is-floating', shouldFloat);
          editorTabsPlaceholder.classList.toggle('is-active', shouldFloat);
        }
      }

      function scheduleFloatingEditorTabs() {
        if (editorTabsFrame) return;
        editorTabsFrame = window.requestAnimationFrame(() => {
          editorTabsFrame = 0;
          updateFloatingEditorTabs();
        });
      }

      function setText(target, value) {
        if (target) target.textContent = value;
      }

      function setHtml(target, value) {
        if (target) target.innerHTML = value;
      }

      function setClassName(target, value) {
        if (target) target.className = value;
      }

      function toggleClass(target, className, force) {
        if (target) target.classList.toggle(className, force);
      }

      function showNotice(message, type) {
        setText(notice, message);
        setClassName(notice, 'notice ' + type);
      }

      function updateCsrf(value) {
        if (!value) return;
        csrfToken = value;
        document.getElementById('csrf-token').value = value;
      }

      function currentBlogPublicUrl() {
        const currentSlug = slug ? slugify(slug.value || '') : '';
        return currentSlug ? `/blog/${encodeURIComponent(currentSlug)}/` : '';
      }

      function updateViewPageButton() {
        if (!viewBlogPageBtn) return;
        const status = blogStatus ? blogStatus.value : 'published';
        const publicUrl = currentBlogPublicUrl();
        const canView = status === 'published' && Boolean(publicUrl);
        viewBlogPageBtn.style.display = canView ? 'inline-flex' : 'none';
        viewBlogPageBtn.disabled = !canView;
        if (canView) {
          viewBlogPageBtn.dataset.href = publicUrl;
        } else {
          delete viewBlogPageBtn.dataset.href;
        }
      }

      function updateSaveState(status) {
        setText(saveState, status === 'draft' ? 'Draft' : 'Publish ready');
        updateViewPageButton();
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

      function plainTextFromMarkdown(value) {
        return String(value || '')
          .replace(/```[\s\S]*?```/g, ' ')
          .replace(/!\[[^\]]*\]\([^)]+\)/g, ' ')
          .replace(/\[([^\]]+)\]\([^)]+\)/g, '$1')
          .replace(/[#>*_`~|[\](){}-]/g, ' ')
          .replace(/\s+/g, ' ')
          .trim();
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

      function checkItem(label, state) {
        const dotClass = state === 'good' ? 'ok' : (state === 'warn' ? 'warn' : '');
        return `<li><span class="dot ${dotClass}"></span><span>${escapeHtml(label)}</span></li>`;
      }

      function scoreState(score) {
        if (score >= 80) return { label: 'Good', className: 'is-ok', color: '#00a32a' };
        if (score >= 55) return { label: 'OK', className: 'is-warn', color: '#dba617' };
        return { label: 'Needs improvement', className: '', color: '#d63638' };
      }

      function renderAnalysisResults(result) {
        const state = scoreState(result.seoScore);
        setClassName(seoScoreBadge, 'seo-score-badge ' + state.className);
        setText(seoScoreBadge ? seoScoreBadge.querySelector('span') : null, String(result.seoScore).padStart(2, '0') + '/100');
        setText(seoMeterScore, result.seoScore);
        if (seoMeterRing) {
          seoMeterRing.style.background = `conic-gradient(${state.color} ${result.seoScore * 3.6}deg, #ececec 0deg)`;
        }
        setText(seoMeterStatus, state.label);
        setClassName(seoMeterStatus, 'seo-meter-status ' + state.className);
        setText(seoMeterHelp, result.help);
        setText(yoastBottomScore, state.label + ' · ' + result.seoScore + '/100');
        setClassName(seoAnalysisIcon, 'yoast-analysis-icon ' + (result.seoScore >= 80 ? 'ok' : ''));
        setClassName(readabilityAnalysisIcon, 'yoast-analysis-icon ' + (result.readabilityScore >= 75 ? 'ok' : ''));
        setHtml(seoCheckList, result.seoChecks.map((check) => checkItem(check.label, check.state)).join(''));
        setHtml(readabilityCheckList, result.readabilityChecks.map((check) => checkItem(check.label, check.state)).join(''));
      }

      function normalizeYoastScore(score) {
        if (typeof score !== 'number' || !Number.isFinite(score)) return 0;
        return Math.max(0, Math.min(100, Math.round(score <= 10 ? score * 10 : score)));
      }

      function yoastCheckState(score) {
        if (score >= 8 || score >= 80) return 'good';
        if (score >= 5 || score >= 50) return 'warn';
        return 'bad';
      }

      function getYoastResultValue(result, methodName, propertyName, fallback) {
        if (!result) return fallback;
        if (typeof result[methodName] === 'function') return result[methodName]();
        return result[propertyName] ?? fallback;
      }

      function normalizeYoastChecks(results) {
        if (!Array.isArray(results)) return [];
        return results
          .map((result) => {
            const score = Number(getYoastResultValue(result, 'getScore', 'score', 0));
            const rawText = getYoastResultValue(result, 'getText', 'text', '');
            const label = String(rawText || '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
            return {
              label: label || 'Yoast assessment completed.',
              state: yoastCheckState(score),
            };
          })
          .filter((check) => check.label);
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

      async function analyzeWithYoast(fields, requestId) {
        const modules = await getYoastModules();
        if (requestId !== yoastAnalysisRequest) return;

        const Paper = modules.yoast.Paper;
        const SeoAssessor = modules.yoast.SeoAssessor;
        const ContentAssessor = modules.yoast.ContentAssessor;
        if (!Paper || !SeoAssessor || !ContentAssessor || !modules.EnglishResearcher) return;

        const paper = new Paper(fields.html, {
          keyword: fields.keyphrase,
          synonyms: fields.synonyms,
          description: fields.excerptText,
          slug: fields.slugText,
          seoTitle: fields.titleText,
          title: fields.titleText,
          textTitle: fields.titleText,
          titleWidth: measureSeoTitleWidth(fields.titleText),
          locale: 'en_US',
          permalink: window.location.origin + '/blog/' + (fields.slugText || 'gperya-article') + '/',
        });
        const researcher = new modules.EnglishResearcher(paper);
        const seoAssessor = new SeoAssessor(researcher);
        const contentAssessor = new ContentAssessor(researcher, { locale: 'en_US' });

        seoAssessor.assess(paper);
        contentAssessor.assess(paper);

        const yoastSeoChecks = normalizeYoastChecks(getYoastAssessorResults(seoAssessor));
        const yoastReadabilityChecks = normalizeYoastChecks(getYoastAssessorResults(contentAssessor));
        const seoScore = getYoastOverallScore(seoAssessor);
        const readabilityScore = getYoastOverallScore(contentAssessor);

        if (requestId !== yoastAnalysisRequest || (yoastSeoChecks.length === 0 && yoastReadabilityChecks.length === 0)) return;
        renderAnalysisResults({
          seoScore,
          readabilityScore,
          seoChecks: yoastSeoChecks,
          readabilityChecks: yoastReadabilityChecks,
          help: `YoastSEO.js local · ${fields.wordCount.toLocaleString()} words · ${fields.keyCount} keyphrase matches · ${fields.density.toFixed(1)}% density`,
        });
      }

      function analyzeSeo() {
        const keyphrase = focusKeyphrase ? focusKeyphrase.value.trim() : '';
        const synonyms = keyphraseSynonyms ? keyphraseSynonyms.value.trim() : '';
        const titleText = seoTitle && seoTitle.value.trim() ? seoTitle.value.trim() : (title ? title.value.trim() : '');
        const articleTitleText = title ? title.value.trim() : '';
        const slugText = slug ? slug.value.trim() : '';
        const excerptText = excerpt ? excerpt.value.trim() : '';
        const markdown = editorShell && editorShell.classList.contains('editor-mode-write') ? editorHtmlToMarkdown() : (blogContent ? blogContent.value : '');
        const html = parseMarkdown(markdown);
        const plainText = plainTextFromMarkdown(markdown);
        const wordCount = countWords(markdown);
        const keyCount = countPhrase([titleText, articleTitleText, excerptText, plainText].join(' '), keyphrase);
        const density = wordCount > 0 && keyphrase ? (countPhrase(plainText, keyphrase) / wordCount) * 100 : 0;
        const paragraphs = markdown.split(/\n\s*\n/).map((item) => plainTextFromMarkdown(item)).filter(Boolean);
        const sentences = plainText.split(/[.!?]+/).map((item) => item.trim()).filter(Boolean);
        const avgSentenceLength = sentences.length ? wordCount / sentences.length : 0;
        const headings = (markdown.match(/^#{2,3}\s+/gm) || []).length;
        const images = markdown.match(/!\[[^\]]*\]\([^)]+\)/g) || [];
        const links = markdown.match(/\[[^\]]+\]\((https?:\/\/|\/)[^)]+\)/g) || [];
        const firstParagraph = paragraphs[0] || '';
        const transitionWords = ['also', 'because', 'but', 'first', 'finally', 'for example', 'furthermore', 'however', 'instead', 'meanwhile', 'next', 'therefore', 'while'];
        const transitionCount = transitionWords.reduce((total, word) => total + countPhrase(plainText, word), 0);

        const seoChecks = [
          { label: keyphrase ? 'Focus keyphrase is set.' : 'Add a focus keyphrase.', ok: Boolean(keyphrase), points: 12 },
          { label: keyphrase && countPhrase(titleText, keyphrase) ? 'Keyphrase appears in the SEO title.' : 'Use the keyphrase in the SEO title.', ok: keyphrase && countPhrase(titleText, keyphrase), points: 12 },
          { label: keyphrase && countPhrase(slugText.replace(/-/g, ' '), keyphrase) ? 'Keyphrase appears in the slug.' : 'Use the keyphrase in the slug.', ok: keyphrase && countPhrase(slugText.replace(/-/g, ' '), keyphrase), points: 10 },
          { label: keyphrase && countPhrase(excerptText, keyphrase) ? 'Keyphrase appears in the meta description/excerpt.' : 'Use the keyphrase in the excerpt.', ok: keyphrase && countPhrase(excerptText, keyphrase), points: 10 },
          { label: keyphrase && countPhrase(firstParagraph, keyphrase) ? 'Keyphrase appears near the introduction.' : 'Mention the keyphrase early in the content.', ok: keyphrase && countPhrase(firstParagraph, keyphrase), points: 10 },
          { label: wordCount >= 300 ? 'Text length is at least 300 words.' : 'Write at least 300 words.', ok: wordCount >= 300, points: 12 },
          { label: density >= 0.5 && density <= 3 ? 'Keyphrase density looks natural.' : 'Aim for natural keyphrase density around 0.5% to 3%.', ok: density >= 0.5 && density <= 3, points: 10 },
          { label: headings > 0 ? 'Subheadings are present.' : 'Add H2/H3 subheadings.', ok: headings > 0, points: 8 },
          { label: images.length > 0 ? 'Images are present in the content.' : 'Add at least one content image.', ok: images.length > 0, points: 6 },
          { label: links.length > 0 ? 'Links are present.' : 'Add internal or outbound links.', ok: links.length > 0, points: 10 },
        ];

        const seoScore = seoChecks.reduce((total, check) => total + (check.ok ? check.points : 0), 0);
        const paragraphTooLong = paragraphs.some((paragraph) => countWords(paragraph) > 150);
        const readabilityChecks = [
          { label: avgSentenceLength > 0 && avgSentenceLength <= 20 ? 'Average sentence length is easy to read.' : 'Shorten sentences where possible.', ok: avgSentenceLength > 0 && avgSentenceLength <= 20 },
          { label: !paragraphTooLong ? 'Paragraph length looks comfortable.' : 'Break long paragraphs into smaller blocks.', ok: !paragraphTooLong },
          { label: headings >= Math.max(1, Math.floor(wordCount / 300)) ? 'Subheading distribution is helpful.' : 'Add subheadings every few hundred words.', ok: headings >= Math.max(1, Math.floor(wordCount / 300)) },
          { label: transitionCount >= Math.max(1, Math.floor(wordCount / 200)) ? 'Transition word use looks helpful.' : 'Add more transition words for flow.', ok: transitionCount >= Math.max(1, Math.floor(wordCount / 200)) },
        ];
        const readabilityScore = Math.round((readabilityChecks.filter((check) => check.ok).length / readabilityChecks.length) * 100);

        renderAnalysisResults({
          seoScore,
          readabilityScore,
          seoChecks: seoChecks.map((check) => ({ label: check.label, state: check.ok ? 'good' : 'bad' })),
          readabilityChecks: readabilityChecks.map((check) => ({ label: check.label, state: check.ok ? 'good' : 'warn' })),
          help: `${wordCount.toLocaleString()} words · ${keyCount} keyphrase matches · ${density.toFixed(1)}% density · loading YoastSEO.js`,
        });

        const requestId = ++yoastAnalysisRequest;
        analyzeWithYoast({
          keyphrase,
          synonyms,
          titleText,
          slugText,
          excerptText,
          html,
          wordCount,
          keyCount,
          density,
        }, requestId).catch(() => {
          if (requestId === yoastAnalysisRequest) {
            setText(seoMeterHelp, `${wordCount.toLocaleString()} words · ${keyCount} keyphrase matches · ${density.toFixed(1)}% density · YoastSEO.js unavailable`);
          }
        });
      }

      function updateWordCounter() {
        const words = countWords(blogContent ? blogContent.value : '');
        setText(contentWordCounter, `${words.toLocaleString()} ${words === 1 ? 'word' : 'words'}`);
        window.requestAnimationFrame(analyzeSeo);
      }

      function updateCharCounter(counter, current, max, warnAt) {
        setText(counter, `${current}/${max}`);
        toggleClass(counter, 'warn', current >= warnAt && current <= max);
        toggleClass(counter, 'over', current > max);
      }

      function updateTitleDisplay() {
        const value = title ? title.value.trim() : '';
        if (seoTitle && !manualSeoTitle) {
          seoTitle.value = value ? value + ' | ' + websiteTitle : '';
        }
        setText(titlePreview, value || 'Add title');
        toggleClass(titlePreview, 'is-empty', !value);
        setText(formTitle, value ? value + ' · Post' : 'No title · Post');
        updateCharCounter(titleCharCounter, title ? title.value.length : 0, 160, 140);
        updateSeoTitleCounter();
      }

      function updateSeoTitleCounter() {
        updateCharCounter(seoTitleCharCounter, seoTitle ? seoTitle.value.length : 0, 160, 140);
      }

      function updateExcerptCounter() {
        updateCharCounter(excerptCharCounter, excerpt ? excerpt.value.length : 0, 170, 140);
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

      function normalizeFaqItems(value) {
        const lines = String(value || '').split(/\n/);
        const items = [];
        let current = null;
        lines.forEach((line) => {
          if (/^Q:\s*/i.test(line)) {
            if (current) items.push(current);
            current = {
              question: line.replace(/^Q:\s*/i, '').trim(),
              answer: '',
            };
          } else if (/^A:\s*/i.test(line)) {
            if (!current) {
              current = { question: 'FAQ question', answer: '' };
            }
            current.answer = line.replace(/^A:\s*/i, '').trim();
          } else if (current && current.answer && line.trim()) {
            current.answer += ' ' + line.trim();
          }
        });
        if (current) items.push(current);
        return (items.length ? items : [{ question: 'FAQ question', answer: 'FAQ answer' }]).map((item) => ({
          question: item.question || 'FAQ question',
          answer: item.answer || 'FAQ answer',
        }));
      }

      function renderFaqItem(question, answer) {
        return `<div class="editor-faq-item"><div class="editor-faq-item-actions" contenteditable="false"><button type="button" class="editor-faq-remove" data-faq-remove>Remove</button></div><div class="editor-faq-field"><span class="editor-faq-field-label" contenteditable="false">Question</span><div class="editor-faq-question" contenteditable="true" role="textbox" aria-label="FAQ question">${escapeHtml(question)}</div></div><div class="editor-faq-field"><span class="editor-faq-field-label" contenteditable="false">Answer</span><div class="editor-faq-answer" contenteditable="true" role="textbox" aria-label="FAQ answer">${escapeHtml(answer)}</div></div></div>`;
      }

      function renderFaqBlock(items) {
        const normalizedItems = (Array.isArray(items) && items.length ? items : [{ question: 'FAQ question', answer: 'FAQ answer' }])
          .map((item) => ({
            question: item.question || 'FAQ question',
            answer: item.answer || 'FAQ answer',
          }));
        return `<section class="editor-block editor-faq-block" data-block-type="faq" draggable="true"><div class="editor-faq-header" contenteditable="false"><span class="editor-faq-label">FAQ Group</span><div class="editor-faq-actions"><button type="button" class="editor-faq-add" data-faq-add>Add FAQ</button></div></div><div class="editor-faq-items">${normalizedItems.map((item) => renderFaqItem(item.question, item.answer)).join('')}</div></section>`;
      }

      function parseMarkdown(markdown) {
        let html = escapeHtml(markdown || '');
        const faqBlocks = [];
        html = html.replace(/:::faq\n([\s\S]*?)\n:::/g, (match, content) => {
          const token = `@@FAQ_BLOCK_${faqBlocks.length}@@`;
          faqBlocks.push(renderFaqBlock(normalizeFaqItems(content)));
          return token;
        });
        html = html.replace(/```([\s\S]*?)```/g, '<pre><code>$1</code></pre>');
        html = html.replace(/^### (.*)$/gim, '<h3>$1</h3>');
        html = html.replace(/^## (.*)$/gim, '<h2>$1</h2>');
        html = html.replace(/^# (.*)$/gim, '<h1>$1</h1>');
        html = html.replace(/^&gt; (.*)$/gim, '<blockquote>$1</blockquote>');
        html = html.replace(/\[!\[([^\]]*)\]\((\/uploads\/blogs\/[a-zA-Z0-9._/-]+)\)\]\((https?:\/\/[^)\s]+|\/[^)\s]*)\)(\{nofollow\})?/g, (match, alt, src, href, nofollow) => `<a href="${href}" target="_blank" rel="noopener noreferrer${nofollow ? ' nofollow' : ''}"><img src="${src}" alt="${alt}" draggable="true"></a>`);
        html = html.replace(/!\[([^\]]*)\]\((\/uploads\/blogs\/[a-zA-Z0-9._/-]+)\)/g, '<img src="$2" alt="$1" draggable="true">');
        html = html.replace(/\[([^\]]+)\]\((https?:\/\/[^)\s]+|\/[^)\s]*)\)(\{nofollow\})?/g, (match, text, href, nofollow) => `<a href="${href}" target="_blank" rel="noopener noreferrer${nofollow ? ' nofollow' : ''}">${text}</a>`);
        html = html.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
        html = html.replace(/\*([^*]+)\*/g, '<em>$1</em>');
        html = html.replace(/`([^`]+)`/g, '<code>$1</code>');

        return html
          .split(/\n\s*\n/)
          .map((block) => {
            const trimmed = block.trim();
            if (!trimmed) return '';
            const faqMatch = trimmed.match(/^@@FAQ_BLOCK_(\d+)@@$/);
            if (faqMatch) return faqBlocks[Number(faqMatch[1])] || '';
            if (/^<h1/.test(trimmed) || /^<h2/.test(trimmed) || /^<h3/.test(trimmed)) return trimmed;
            if (/^<blockquote/.test(trimmed)) return trimmed.replace(/^<blockquote/, '<blockquote class="editor-block editor-quote-block"');
            if (/^<pre/.test(trimmed)) return trimmed;
            if (/^<a[^>]*><img/.test(trimmed)) return '<p class="editor-block editor-image-block">' + trimmed + '</p>';
            if (/^<img/.test(trimmed)) return '<p class="editor-block editor-image-block">' + trimmed + '</p>';
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

        if (node.classList && node.classList.contains('editor-faq-block')) {
          const items = Array.from(node.querySelectorAll('.editor-faq-item'));
          const faqMarkdown = items.map((item) => {
            const question = (item.querySelector('.editor-faq-question')?.textContent || 'FAQ question').trim();
            const answer = (item.querySelector('.editor-faq-answer')?.textContent || 'FAQ answer').trim();
            return `Q: ${question}\nA: ${answer}`;
          }).join('\n\n') || 'Q: FAQ question\nA: FAQ answer';
          return `:::faq\n${faqMarkdown}\n:::\n\n`;
        }

        if (tag === 'strong' || tag === 'b') return `**${children}**`;
        if (tag === 'em' || tag === 'i') return `*${children}*`;
        if (tag === 'code') return `\`${children}\``;
        if (tag === 'a') {
          const href = node.getAttribute('href') || '';
          return href ? `[${children}](${href})${linkMarkdownSuffix(linkHasNofollow(node))}` : children;
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
        if (tag === 'h1') return children.trim() ? `# ${children.trim()}\n\n` : '';
        if (tag === 'h2') return children.trim() ? `## ${children.trim()}\n\n` : '';
        if (tag === 'h3') return children.trim() ? `### ${children.trim()}\n\n` : '';
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

      function removeEmptyMarkdownHeadings(markdown) {
        const lines = String(markdown || '').split('\n');
        const normalized = [];

        lines.forEach((line) => {
          const trimmed = line.trim();
          if (/^#{1,6}\s*$/.test(trimmed)) return;

          if (/^#{1,6}\s+\S/.test(trimmed) && normalized.length && normalized[normalized.length - 1] !== '') {
            normalized.push('');
          }

          normalized.push(line);

          if (/^#{1,6}\s+\S/.test(trimmed)) {
            normalized.push('');
          }
        });

        return normalized
          .join('\n')
          .replace(/\n{3,}/g, '\n\n')
          .trim();
      }

      function syncMarkdownFromEditor() {
        blogContent.value = removeEmptyMarkdownHeadings(editorHtmlToMarkdown());
        updateWordCounter();
      }

      function prepareEditorBlocks() {
        if (!wysiwygEditor) return;
        wysiwygEditor.querySelectorAll('.editor-heading-block,.editor-paragraph-block,.editor-list-block,.editor-code-block').forEach((block) => {
          if (block.classList.contains('editor-image-block') || block.classList.contains('editor-quote-block') || block.classList.contains('editor-faq-block')) return;
          block.classList.remove('editor-block', 'editor-heading-block', 'editor-paragraph-block', 'editor-list-block', 'editor-code-block', 'is-dragging');
          block.removeAttribute('draggable');
          delete block.dataset.blockType;
        });
        wysiwygEditor.querySelectorAll('blockquote,section.editor-faq-block').forEach((block) => {
          if (block.closest('.editor-faq-block') && !block.classList.contains('editor-faq-block')) return;
          block.classList.add('editor-block');
          block.setAttribute('draggable', 'true');
          if (block.matches('blockquote')) block.classList.add('editor-quote-block');
          if (block.matches('section.editor-faq-block')) block.dataset.blockType = 'faq';
        });
        wysiwygEditor.querySelectorAll('img').forEach((image) => {
          image.draggable = true;
          const block = image.closest('p,div,figure') || image;
          block.classList.add('editor-block');
          block.classList.add('editor-image-block');
          block.setAttribute('draggable', 'true');
        });
      }

      function isVisiblyEmptyElement(element) {
        if (!element) return true;
        const text = (element.textContent || '').replace(/\u00a0/g, ' ').trim();
        if (text) return false;
        return !element.querySelector('img,iframe,video,audio,section.editor-faq-block');
      }

      function normalizeEmptyEditorHeadings() {
        if (!wysiwygEditor) return false;
        let changed = false;
        const selection = window.getSelection();
        const selectedElement = selection && selection.rangeCount > 0 ? closestElement(selection.getRangeAt(0).startContainer) : null;

        wysiwygEditor.querySelectorAll('h1,h2,h3,h4,h5,h6').forEach((heading) => {
          if (!isVisiblyEmptyElement(heading)) return;
          const paragraph = document.createElement('p');
          paragraph.innerHTML = '<br>';
          heading.replaceWith(paragraph);
          changed = true;

          if (selectedElement === heading || heading.contains(selectedElement)) {
            const range = document.createRange();
            range.selectNodeContents(paragraph);
            range.collapse(true);
            if (selection) {
              selection.removeAllRanges();
              selection.addRange(range);
              savedEditorRange = range.cloneRange();
            }
          }
        });

        return changed;
      }

      function setEditorMarkdown(markdown) {
        blogContent.value = removeEmptyMarkdownHeadings(markdown || '');
        wysiwygEditor.innerHTML = parseMarkdown(blogContent.value);
        prepareEditorBlocks();
        updateWordCounter();
      }

      function syncEditorFromMarkdown() {
        const normalizedMarkdown = removeEmptyMarkdownHeadings(blogContent.value);
        if (normalizedMarkdown !== blogContent.value.trim()) {
          blogContent.value = normalizedMarkdown;
        }
        wysiwygEditor.innerHTML = parseMarkdown(blogContent.value);
        prepareEditorBlocks();
        updateWordCounter();
      }

      function currentEditorMarkdown() {
        if (editorShell.classList.contains('editor-mode-write')) {
          syncMarkdownFromEditor();
        }
        return blogContent ? blogContent.value : '';
      }

      function updateHistoryButtons() {
        if (undoBtn) undoBtn.disabled = editorHistoryIndex <= 0;
        if (redoBtn) redoBtn.disabled = editorHistoryIndex < 0 || editorHistoryIndex >= editorHistory.length - 1;
      }

      function pushEditorHistory() {
        if (isRestoringHistory) return;
        const markdown = currentEditorMarkdown();
        if (editorHistory[editorHistoryIndex] === markdown) {
          updateHistoryButtons();
          return;
        }
        if (editorHistoryIndex < editorHistory.length - 1) {
          editorHistory = editorHistory.slice(0, editorHistoryIndex + 1);
        }
        editorHistory.push(markdown);
        if (editorHistory.length > 100) {
          editorHistory.shift();
        }
        editorHistoryIndex = editorHistory.length - 1;
        updateHistoryButtons();
      }

      function scheduleEditorHistory(immediate) {
        if (isRestoringHistory) return;
        window.clearTimeout(editorHistoryTimer);
        if (immediate) {
          pushEditorHistory();
          return;
        }
        editorHistoryTimer = window.setTimeout(pushEditorHistory, 320);
      }

      function resetEditorHistory(markdown) {
        window.clearTimeout(editorHistoryTimer);
        editorHistory = [typeof markdown === 'string' ? markdown : currentEditorMarkdown()];
        editorHistoryIndex = 0;
        updateHistoryButtons();
      }

      function restoreEditorHistory(step) {
        pushEditorHistory();
        const nextIndex = editorHistoryIndex + step;
        if (nextIndex < 0 || nextIndex >= editorHistory.length) {
          updateHistoryButtons();
          return;
        }
        editorHistoryIndex = nextIndex;
        isRestoringHistory = true;
        setEditorMarkdown(editorHistory[editorHistoryIndex]);
        if (editorShell.classList.contains('editor-mode-preview') || editorShell.classList.contains('editor-mode-split')) {
          renderMarkdownPreview();
        }
        isRestoringHistory = false;
        updateHistoryButtons();
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
        closeLinkToolbox();
        scheduleFloatingEditorTabs();
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
        if (!savedEditorRange) return;
        const selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(savedEditorRange.cloneRange());
        wysiwygEditor.focus({ preventScroll: true });
      }

      function currentEditorSelectionRange() {
        const selection = window.getSelection();
        if (!selection || selection.rangeCount === 0) return null;
        const range = selection.getRangeAt(0);
        return wysiwygEditor.contains(range.commonAncestorContainer) ? range : null;
      }

      function fallbackEditorRange() {
        const range = document.createRange();
        range.selectNodeContents(wysiwygEditor);
        range.collapse(false);
        return range;
      }

      function saveMarkdownSelection() {
        savedMarkdownSelection = {
          start: blogContent.selectionStart || 0,
          end: blogContent.selectionEnd || blogContent.selectionStart || 0,
          text: blogContent.value.slice(blogContent.selectionStart || 0, blogContent.selectionEnd || blogContent.selectionStart || 0)
        };
      }

      function normalizeLinkUrl(value) {
        const url = String(value || '').trim();
        if (!url) return '';
        if (/^https?:\/\//i.test(url) || /^\/(?!\/)/.test(url)) return url;
        return '';
      }

      function linkHasNofollow(anchor) {
        return (anchor?.getAttribute('rel') || '').split(/\s+/).includes('nofollow');
      }

      function linkMarkdownSuffix(isNofollow) {
        return isNofollow ? '{nofollow}' : '';
      }

      function selectedEditorText() {
        const selection = window.getSelection();
        if (!selection || selection.rangeCount === 0) return '';
        const range = selection.getRangeAt(0);
        if (!wysiwygEditor.contains(range.commonAncestorContainer)) return '';
        return selection.toString().trim();
      }

      function closestElement(node) {
        if (!node) return null;
        return node.nodeType === Node.ELEMENT_NODE ? node : node.parentElement;
      }

      function getActiveEditorLink() {
        const selection = window.getSelection();
        if (selection && selection.rangeCount > 0) {
          const range = selection.getRangeAt(0);
          const start = closestElement(range.startContainer);
          const end = closestElement(range.endContainer);
          const startLink = start ? start.closest('a') : null;
          const endLink = end ? end.closest('a') : null;
          const link = startLink || endLink;
          if (link && wysiwygEditor.contains(link)) return link;
        }
        if (savedEditorRange) {
          const savedStart = closestElement(savedEditorRange.startContainer);
          const savedLink = savedStart ? savedStart.closest('a') : null;
          if (savedLink && wysiwygEditor.contains(savedLink)) return savedLink;
        }
        return null;
      }

      function clearSelectedEditorImage() {
        if (!wysiwygEditor) return;
        wysiwygEditor.querySelectorAll('img.is-selected').forEach((image) => image.classList.remove('is-selected'));
        activeImageElement = null;
        closeImageSettingsPanel();
      }

      function applyAnchorAttributes(anchor, url, isNofollow = false) {
        anchor.href = url;
        const relParts = [];
        if (/^https?:\/\//i.test(url)) {
          anchor.target = '_blank';
          relParts.push('noopener', 'noreferrer');
        } else {
          anchor.removeAttribute('target');
        }
        if (isNofollow) relParts.push('nofollow');
        if (relParts.length) {
          anchor.rel = Array.from(new Set(relParts)).join(' ');
        } else {
          anchor.removeAttribute('rel');
        }
      }

      function selectionContainsImage(range) {
        if (!range) return false;
        const fragment = range.cloneContents();
        return Boolean(fragment.querySelector && fragment.querySelector('img'));
      }

      function selectEditorImage(image) {
        if (!image || !wysiwygEditor.contains(image)) return;
        clearSelectedEditorImage();
        activeImageElement = image;
        image.classList.add('is-selected');
        const selection = window.getSelection();
        const range = document.createRange();
        const wrapperLink = image.closest('a');
        if (wrapperLink && wysiwygEditor.contains(wrapperLink)) {
          range.selectNodeContents(wrapperLink);
          activeLinkElement = wrapperLink;
        } else {
          range.selectNode(image);
          activeLinkElement = null;
        }
        selection.removeAllRanges();
        selection.addRange(range);
        savedEditorRange = range.cloneRange();
        openImageSettingsPanel();
      }

      function activeImageLink() {
        if (!activeImageElement || !wysiwygEditor.contains(activeImageElement)) return null;
        const link = activeImageElement.closest('a');
        return link && wysiwygEditor.contains(link) ? link : null;
      }

      function openImageSettingsPanel() {
        if (!imageSettingsPanel || !activeImageElement || !wysiwygEditor.contains(activeImageElement)) return;
        if (imageAltInput) imageAltInput.value = activeImageElement.getAttribute('alt') || '';
        const link = activeImageLink();
        if (imageLinkInput) imageLinkInput.value = link ? (link.getAttribute('href') || '') : '';
        if (imageLinkNofollowInput) imageLinkNofollowInput.checked = link ? linkHasNofollow(link) : false;
        imageSettingsPanel.classList.add('is-open');
        imageSettingsPanel.setAttribute('aria-hidden', 'false');
        positionImageSettingsPanel();
      }

      function closeImageSettingsPanel() {
        if (!imageSettingsPanel) return;
        imageSettingsPanel.classList.remove('is-open');
        imageSettingsPanel.setAttribute('aria-hidden', 'true');
      }

      function positionImageSettingsPanel() {
        if (!imageSettingsPanel || !imageSettingsPanel.classList.contains('is-open') || !activeImageElement || !wysiwygEditor.contains(activeImageElement)) return;
        const rect = activeImageElement.getBoundingClientRect();
        const panelRect = imageSettingsPanel.getBoundingClientRect();
        const width = panelRect.width || Math.min(820, window.innerWidth - 48);
        const left = Math.max(24, Math.min(window.innerWidth - width - 24, rect.left + (rect.width / 2) - (width / 2)));
        const top = Math.max(84, Math.min(window.innerHeight - 110, rect.bottom + 12));
        imageSettingsPanel.style.left = left + 'px';
        imageSettingsPanel.style.top = top + 'px';
        imageSettingsPanel.style.transform = 'none';
      }

      function applyImageSettings() {
        if (!activeImageElement || !wysiwygEditor.contains(activeImageElement)) return;
        const alt = imageAltInput ? imageAltInput.value.trim() : '';
        activeImageElement.alt = alt || 'Article Image';
        const url = imageLinkInput ? normalizeLinkUrl(imageLinkInput.value) : '';
        if (imageLinkInput && imageLinkInput.value.trim() && !url) {
          showNotice('Image link must be a full http(s) URL or a site path that starts with /.', 'error');
          imageLinkInput.focus();
          return;
        }
        if (url) {
          linkSelectedEditorImage(url, activeImageElement.alt, imageLinkNofollowInput ? imageLinkNofollowInput.checked : false);
        }
        syncMarkdownFromEditor();
        scheduleEditorHistory(true);
        analyzeSeo();
        showNotice('Image settings updated.', 'ok');
        positionImageSettingsPanel();
      }

      function removeImageLink() {
        const link = activeImageLink();
        if (!link || !activeImageElement) return;
        link.parentNode.insertBefore(activeImageElement, link);
        if (!link.textContent.trim() && !link.querySelector('img')) {
          link.remove();
        }
        selectEditorImage(activeImageElement);
        syncMarkdownFromEditor();
        scheduleEditorHistory(true);
        analyzeSeo();
      }

      function linkSelectedEditorImage(url, text, isNofollow = false) {
        if (!activeImageElement || !wysiwygEditor.contains(activeImageElement)) return false;
        const image = activeImageElement;
        if (text) image.alt = text;
        const currentLink = image.closest('a');
        if (currentLink && wysiwygEditor.contains(currentLink)) {
          applyAnchorAttributes(currentLink, url, isNofollow);
          activeLinkElement = currentLink;
          syncMarkdownFromEditor();
          return true;
        }

        const anchor = document.createElement('a');
        applyAnchorAttributes(anchor, url, isNofollow);
        image.parentNode.insertBefore(anchor, image);
        anchor.appendChild(image);
        activeLinkElement = anchor;
        syncMarkdownFromEditor();
        return true;
      }

      function openLinkToolbox() {
        if (!hasLinkToolbox) {
          showNotice('Link toolbox is unavailable. Please refresh the editor assets.', 'error');
          return;
        }
        setText(linkToolboxStatus, '');
        if (!activeImageElement || !wysiwygEditor.contains(activeImageElement)) {
          activeLinkElement = null;
        }
        linkNofollowInput.checked = false;
        if (editorShell.classList.contains('editor-mode-markdown') || editorShell.classList.contains('editor-mode-split')) {
          saveMarkdownSelection();
          linkTextInput.value = (savedMarkdownSelection && savedMarkdownSelection.text.trim()) || '';
          linkUrlInput.value = 'https://';
        } else {
          saveEditorSelection();
          activeLinkElement = getActiveEditorLink();
          if (activeImageElement && wysiwygEditor.contains(activeImageElement)) {
            const linkedImageAnchor = activeImageElement.closest('a');
            if (linkedImageAnchor && wysiwygEditor.contains(linkedImageAnchor)) {
              activeLinkElement = linkedImageAnchor;
            }
            linkTextInput.value = activeImageElement.getAttribute('alt') || '';
            linkUrlInput.value = activeLinkElement ? (activeLinkElement.getAttribute('href') || 'https://') : 'https://';
            linkNofollowInput.checked = activeLinkElement ? linkHasNofollow(activeLinkElement) : false;
          } else if (activeLinkElement) {
            const linkedImage = activeLinkElement.querySelector('img');
            linkTextInput.value = linkedImage ? (linkedImage.getAttribute('alt') || '') : activeLinkElement.textContent.trim();
            linkUrlInput.value = activeLinkElement.getAttribute('href') || 'https://';
            linkNofollowInput.checked = linkHasNofollow(activeLinkElement);
          } else {
            linkTextInput.value = selectedEditorText();
            linkUrlInput.value = 'https://';
          }
        }
        linkToolbox.classList.add('is-open');
        linkToolbox.setAttribute('aria-hidden', 'false');
        positionLinkToolbox();
        window.setTimeout(() => {
          positionLinkToolbox();
          linkUrlInput.focus();
          linkUrlInput.select();
        }, 0);
      }

      function closeLinkToolbox() {
        if (!hasLinkToolbox) return;
        linkToolbox.classList.remove('is-open');
        linkToolbox.setAttribute('aria-hidden', 'true');
        setText(linkToolboxStatus, '');
        activeLinkElement = null;
        if (!activeImageElement || !wysiwygEditor.contains(activeImageElement)) {
          activeImageElement = null;
        }
      }

      function linkTargetRect() {
        if (activeImageElement && wysiwygEditor.contains(activeImageElement)) {
          return activeImageElement.getBoundingClientRect();
        }
        const selection = window.getSelection();
        if (selection && selection.rangeCount > 0) {
          const range = selection.getRangeAt(0);
          if (wysiwygEditor.contains(range.commonAncestorContainer)) {
            const rect = range.getBoundingClientRect();
            if (rect.width || rect.height) return rect;
          }
        }
        if (savedEditorRange) {
          const rect = savedEditorRange.getBoundingClientRect();
          if (rect.width || rect.height) return rect;
        }
        return wysiwygEditor.getBoundingClientRect();
      }

      function positionLinkToolbox() {
        if (!hasLinkToolbox) return;
        const rect = linkTargetRect();
        const toolboxRect = linkToolbox.getBoundingClientRect();
        const width = toolboxRect.width || Math.min(704, window.innerWidth - 48);
        const left = Math.max(24, Math.min(window.innerWidth - width - 24, rect.left + (rect.width / 2) - (width / 2)));
        const top = Math.max(84, Math.min(window.innerHeight - 120, rect.bottom + 12));
        const arrowLeft = Math.max(18, Math.min(width - 18, rect.left + (rect.width / 2) - left));
        linkToolbox.style.left = left + 'px';
        linkToolbox.style.top = top + 'px';
        linkToolbox.style.transform = 'none';
        linkToolbox.style.setProperty('--link-arrow-left', arrowLeft + 'px');
      }

      function currentEditorBlockFromSelection() {
        if (!wysiwygEditor || !editorShell.classList.contains('editor-mode-write')) return null;
        if (activeImageElement && wysiwygEditor.contains(activeImageElement)) {
          const imageBlock = activeImageElement.closest('.editor-block');
          if (imageBlock && wysiwygEditor.contains(imageBlock)) return imageBlock;
        }

        const selection = window.getSelection();
        if (selection && selection.rangeCount > 0) {
          const range = selection.getRangeAt(0);
          const element = closestElement(range.startContainer);
          const block = element ? element.closest('.editor-block') : null;
          if (block && wysiwygEditor.contains(block)) return block;
        }

        if (savedEditorRange) {
          const savedElement = closestElement(savedEditorRange.startContainer);
          const savedBlock = savedElement ? savedElement.closest('.editor-block') : null;
          if (savedBlock && wysiwygEditor.contains(savedBlock)) return savedBlock;
        }

        return null;
      }

      function focusEditorBlock(block) {
        if (!block || !wysiwygEditor.contains(block)) return;
        const range = document.createRange();
        range.selectNodeContents(block);
        range.collapse(false);
        const selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(range);
        savedEditorRange = range.cloneRange();
        block.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
      }

      function moveCurrentEditorBlock(direction) {
        const block = currentEditorBlockFromSelection();
        if (!block) return false;
        const sibling = direction < 0 ? block.previousElementSibling : block.nextElementSibling;
        if (!sibling || sibling === editorDropMarker) return false;

        if (direction < 0) {
          block.parentNode.insertBefore(block, sibling);
        } else {
          block.parentNode.insertBefore(block, sibling.nextSibling);
        }

        focusEditorBlock(block);
        syncMarkdownFromEditor();
        scheduleEditorHistory(true);
        analyzeSeo();
        return true;
      }

      function deleteCurrentEditorBlock() {
        const block = currentEditorBlockFromSelection();
        if (!block) return false;
        const nextFocus = block.nextElementSibling || block.previousElementSibling;
        block.remove();
        clearSelectedEditorImage();
        if (nextFocus && wysiwygEditor.contains(nextFocus)) {
          if (nextFocus.classList.contains('editor-block')) {
            focusEditorBlock(nextFocus);
          } else {
            const range = document.createRange();
            range.selectNodeContents(nextFocus);
            range.collapse(false);
            const selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);
            savedEditorRange = range.cloneRange();
          }
        } else {
          focusEditor();
          savedEditorRange = fallbackEditorRange();
        }
        syncMarkdownFromEditor();
        scheduleEditorHistory(true);
        analyzeSeo();
        showNotice('Block deleted.', 'ok');
        return true;
      }

      function cleanPastedEditorBlock(block) {
        block.classList.remove('is-dragging');
        block.querySelectorAll('.is-dragging').forEach((item) => item.classList.remove('is-dragging'));
        block.removeAttribute('id');
        return block;
      }

      function editorBlockFromHtml(html) {
        const template = document.createElement('template');
        template.innerHTML = String(html || '').trim();
        const markedBlock = template.content.querySelector('[data-gperya-editor-block="true"] > .editor-block');
        const block = markedBlock || template.content.querySelector('.editor-block');
        return block ? cleanPastedEditorBlock(block.cloneNode(true)) : null;
      }

      function topLevelEditorNodeFromRange(range) {
        if (!range || !wysiwygEditor) return null;
        if (range.startContainer === wysiwygEditor) {
          return wysiwygEditor.childNodes[range.startOffset] || wysiwygEditor.lastChild;
        }
        let element = closestElement(range.startContainer);
        while (element && element.parentNode !== wysiwygEditor) {
          element = element.parentElement;
        }
        return element && wysiwygEditor.contains(element) ? element : null;
      }

      function currentTopLevelEditorNode() {
        const selectionRange = currentEditorSelectionRange();
        if (selectionRange) {
          const topNode = topLevelEditorNodeFromRange(selectionRange);
          if (topNode) return topNode;
        }
        if (savedEditorRange && wysiwygEditor.contains(savedEditorRange.commonAncestorContainer)) {
          return topLevelEditorNodeFromRange(savedEditorRange);
        }
        return null;
      }

      function insertEditorBlock(block) {
        if (!block || !wysiwygEditor) return false;
        const currentBlock = currentEditorBlockFromSelection();
        if (currentBlock && wysiwygEditor.contains(currentBlock)) {
          currentBlock.parentNode.insertBefore(block, currentBlock.nextSibling);
        } else {
          const topLevelNode = currentTopLevelEditorNode();
          wysiwygEditor.insertBefore(block, topLevelNode ? topLevelNode.nextSibling : null);
        }
        prepareEditorBlocks();
        focusEditorBlock(block);
        syncMarkdownFromEditor();
        scheduleEditorHistory(true);
        analyzeSeo();
        return true;
      }

      function copyCurrentEditorBlock(event, shouldCut = false) {
        const block = currentEditorBlockFromSelection();
        if (!block) return false;
        editorBlockClipboardHtml = block.outerHTML;
        const markdown = nodeToMarkdown(block).trim();
        if (event.clipboardData) {
          event.clipboardData.setData('text/html', `<div data-gperya-editor-block="true">${editorBlockClipboardHtml}</div>`);
          event.clipboardData.setData('text/plain', editorBlockClipboardPrefix + markdown);
        }
        event.preventDefault();
        if (shouldCut) {
          const nextFocus = block.nextElementSibling || block.previousElementSibling;
          block.remove();
          if (nextFocus && nextFocus.classList.contains('editor-block')) {
            focusEditorBlock(nextFocus);
          } else {
            focusEditor();
          }
          syncMarkdownFromEditor();
          scheduleEditorHistory(true);
          analyzeSeo();
          showNotice('Block cut.', 'ok');
        } else {
          showNotice('Block copied.', 'ok');
        }
        return true;
      }

      function pasteCopiedEditorBlock(event) {
        const html = event.clipboardData ? event.clipboardData.getData('text/html') : '';
        const text = event.clipboardData ? event.clipboardData.getData('text/plain') : '';
        const hasBlockMarker = html.includes('data-gperya-editor-block="true"') || text.startsWith(editorBlockClipboardPrefix);
        if (!hasBlockMarker) return false;
        const block = editorBlockFromHtml(html || editorBlockClipboardHtml);
        if (!block) return false;
        event.preventDefault();
        return insertEditorBlock(block);
      }

      function convertedChildrenFromPaste(node) {
        const fragment = document.createDocumentFragment();
        Array.from(node.childNodes).forEach((child) => {
          const converted = convertPastedNode(child);
          if (converted) fragment.appendChild(converted);
        });
        return fragment;
      }

      function hasPastedBlockChildren(node) {
        return Array.from(node.children || []).some((child) => /^(address|article|aside|blockquote|div|h[1-6]|hr|ol|p|pre|section|table|ul)$/i.test(child.tagName));
      }

      function wrapPastedInlineFormatting(node, fragment) {
        const style = node.getAttribute('style') || '';
        const tag = node.tagName.toLowerCase();
        const isBold = tag === 'b' || tag === 'strong' || /font-weight\s*:\s*(bold|[6-9]00)/i.test(style);
        const isItalic = tag === 'i' || tag === 'em' || /font-style\s*:\s*italic/i.test(style);
        let wrapped = fragment;
        if (isItalic) {
          const em = document.createElement('em');
          em.appendChild(wrapped);
          wrapped = em;
        }
        if (isBold) {
          const strong = document.createElement('strong');
          strong.appendChild(wrapped);
          wrapped = strong;
        }
        return wrapped;
      }

      function convertPastedNode(node) {
        if (node.nodeType === Node.TEXT_NODE) {
          return document.createTextNode(node.textContent || '');
        }
        if (node.nodeType !== Node.ELEMENT_NODE) {
          return null;
        }

        const tag = node.tagName.toLowerCase();
        if (['script', 'style', 'meta', 'link', 'title', 'svg', 'canvas', 'iframe', 'object', 'embed'].includes(tag)) {
          return null;
        }

        if (tag === 'br') return document.createElement('br');
        if (/^h[1-6]$/.test(tag)) {
          const heading = document.createElement(['h1', 'h2', 'h3'].includes(tag) ? tag : 'h3');
          heading.appendChild(convertedChildrenFromPaste(node));
          return heading.textContent.trim() ? heading : null;
        }
        if (tag === 'blockquote') {
          const quote = document.createElement('blockquote');
          quote.className = 'editor-block editor-quote-block';
          quote.setAttribute('draggable', 'true');
          quote.appendChild(convertedChildrenFromPaste(node));
          return quote.textContent.trim() ? quote : null;
        }
        if (tag === 'ul' || tag === 'ol') {
          const list = document.createElement(tag);
          Array.from(node.children).forEach((child) => {
            if (child.tagName && child.tagName.toLowerCase() === 'li') {
              const item = convertPastedNode(child);
              if (item) list.appendChild(item);
            }
          });
          return list.children.length ? list : null;
        }
        if (tag === 'li') {
          const item = document.createElement('li');
          item.appendChild(convertedChildrenFromPaste(node));
          return item.textContent.trim() ? item : null;
        }
        if (tag === 'a') {
          const href = normalizeLinkUrl(node.getAttribute('href') || '');
          if (!href) return convertedChildrenFromPaste(node);
          const anchor = document.createElement('a');
          applyAnchorAttributes(anchor, href);
          anchor.appendChild(convertedChildrenFromPaste(node));
          return anchor.textContent.trim() || anchor.querySelector('img') ? anchor : null;
        }
        if (tag === 'img') {
          const src = node.getAttribute('src') || '';
          if (!src || /^data:/i.test(src)) return null;
          const image = document.createElement('img');
          image.src = src;
          image.alt = node.getAttribute('alt') || 'Article Image';
          image.draggable = true;
          return image;
        }
        if (tag === 'pre') {
          const pre = document.createElement('pre');
          const code = document.createElement('code');
          code.textContent = node.textContent || '';
          pre.appendChild(code);
          return pre.textContent.trim() ? pre : null;
        }
        if (tag === 'code') {
          const code = document.createElement('code');
          code.textContent = node.textContent || '';
          return code;
        }
        if (tag === 'p' || (tag === 'div' && !hasPastedBlockChildren(node))) {
          const paragraph = document.createElement('p');
          paragraph.appendChild(convertedChildrenFromPaste(node));
          return paragraph.textContent.trim() || paragraph.querySelector('img') ? paragraph : null;
        }
        if (tag === 'span' || tag === 'b' || tag === 'strong' || tag === 'i' || tag === 'em' || tag === 'u' || tag === 'font') {
          return wrapPastedInlineFormatting(node, convertedChildrenFromPaste(node));
        }
        if (tag === 'body' || tag === 'html' || tag === 'div' || tag === 'section' || tag === 'article') {
          return convertedChildrenFromPaste(node);
        }
        return convertedChildrenFromPaste(node);
      }

      function sanitizePastedDocumentHtml(html) {
        const template = document.createElement('template');
        template.innerHTML = String(html || '');
        const fragment = convertedChildrenFromPaste(template.content);
        const container = document.createElement('div');
        container.appendChild(fragment);
        return container.innerHTML.trim();
      }

      function pasteFormattedDocumentHtml(event) {
        const html = event.clipboardData ? event.clipboardData.getData('text/html') : '';
        if (!html || html.includes('data-gperya-editor-block="true"')) return false;
        const sanitized = sanitizePastedDocumentHtml(html);
        if (!sanitized) return false;
        event.preventDefault();
        const range = currentEditorSelectionRange() || (savedEditorRange ? savedEditorRange.cloneRange() : fallbackEditorRange());
        range.deleteContents();
        const template = document.createElement('template');
        template.innerHTML = sanitized;
        const insertedNodes = Array.from(template.content.childNodes);
        const containsBlockNodes = insertedNodes.some((node) => node.nodeType === Node.ELEMENT_NODE && /^(blockquote|h[1-6]|ol|p|pre|ul)$/i.test(node.tagName));
        if (containsBlockNodes) {
          const topLevelNode = topLevelEditorNodeFromRange(range);
          wysiwygEditor.insertBefore(template.content, topLevelNode ? topLevelNode.nextSibling : null);
        } else {
          range.insertNode(template.content);
        }
        const lastNode = insertedNodes[insertedNodes.length - 1];
        if (lastNode) {
          range.setStartAfter(lastNode);
          range.collapse(true);
          const selection = window.getSelection();
          selection.removeAllRanges();
          selection.addRange(range);
          savedEditorRange = range.cloneRange();
        }
        prepareEditorBlocks();
        syncMarkdownFromEditor();
        scheduleEditorHistory(true);
        analyzeSeo();
        return true;
      }

      function clipboardReadableText(event) {
        const text = event.clipboardData ? event.clipboardData.getData('text/plain') : '';
        if (text) return text;
        const html = event.clipboardData ? event.clipboardData.getData('text/html') : '';
        if (!html) return '';
        const template = document.createElement('template');
        template.innerHTML = html;
        return (template.content.textContent || '').replace(/\s+\n/g, '\n').replace(/\n\s+/g, '\n').trim();
      }

      function pasteIntoFaqField(event) {
        const field = event.target && event.target.closest ? event.target.closest('.editor-faq-question,.editor-faq-answer') : null;
        if (!field || !wysiwygEditor.contains(field)) return false;
        const text = clipboardReadableText(event);
        if (!text) return false;
        event.preventDefault();
        const range = currentEditorSelectionRange() || document.createRange();
        if (!field.contains(range.commonAncestorContainer)) {
          range.selectNodeContents(field);
          range.collapse(false);
        }
        range.deleteContents();
        range.insertNode(document.createTextNode(text));
        range.collapse(false);
        const selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(range);
        savedEditorRange = range.cloneRange();
        syncMarkdownFromEditor();
        scheduleEditorHistory(true);
        analyzeSeo();
        return true;
      }

      function selectFaqFieldText(event) {
        let field = event.target && event.target.closest ? event.target.closest('.editor-faq-question,.editor-faq-answer') : null;
        if (!field) {
          const selection = window.getSelection();
          if (selection && selection.rangeCount > 0) {
            const range = selection.getRangeAt(0);
            const element = closestElement(range.startContainer);
            field = element ? element.closest('.editor-faq-question,.editor-faq-answer') : null;
          }
        }
        if (!field || !wysiwygEditor.contains(field)) return false;
        event.preventDefault();
        event.stopPropagation();
        const range = document.createRange();
        range.selectNodeContents(field);
        const selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(range);
        savedEditorRange = range.cloneRange();
        return true;
      }

      function handleEditorShortcut(event) {
        if (editorShell.classList.contains('editor-mode-write') && (event.ctrlKey || event.metaKey) && !event.shiftKey && !event.altKey && (event.key || '').toLowerCase() === 'a') {
          if (selectFaqFieldText(event)) return;
        }

        if (editorShell.classList.contains('editor-mode-write') && !event.ctrlKey && !event.metaKey && !event.altKey && !event.shiftKey && (event.key === 'Delete' || event.key === 'Backspace')) {
          const faqField = event.target && event.target.closest ? event.target.closest('.editor-faq-question,.editor-faq-answer') : null;
          if (!faqField && deleteCurrentEditorBlock()) {
            event.preventDefault();
          }
          return;
        }

        if (editorShell.classList.contains('editor-mode-write') && event.altKey && !event.ctrlKey && !event.metaKey && !event.shiftKey && (event.key === 'ArrowUp' || event.key === 'ArrowDown')) {
          if (moveCurrentEditorBlock(event.key === 'ArrowUp' ? -1 : 1)) {
            event.preventDefault();
          }
          return;
        }

        if (!(event.ctrlKey || event.metaKey) || event.shiftKey || event.altKey || event.key.toLowerCase() !== 'k') {
          return;
        }
        if (hasLinkToolbox && linkToolbox.contains(event.target)) {
          return;
        }
        event.preventDefault();
        if (editorShell.classList.contains('editor-mode-write')) {
          saveEditorSelection();
        } else {
          saveMarkdownSelection();
        }
        openLinkToolbox();
      }

      function insertMarkdownLink(text, url, isNofollow = false) {
        const selection = savedMarkdownSelection || {
          start: blogContent.selectionStart || 0,
          end: blogContent.selectionEnd || blogContent.selectionStart || 0,
          text: ''
        };
        const linkText = text || selection.text || url;
        const markdown = `[${linkText.replace(/[\[\]]/g, '')}](${url})${linkMarkdownSuffix(isNofollow)}`;
        const before = blogContent.value.slice(0, selection.start);
        const after = blogContent.value.slice(selection.end);
        blogContent.value = before + markdown + after;
        const cursor = (before + markdown).length;
        blogContent.focus();
        blogContent.setSelectionRange(cursor, cursor);
        syncEditorFromMarkdown();
        if (editorShell.classList.contains('editor-mode-split')) {
          renderMarkdownPreview();
        }
      }

      function insertWysiwygLink(text, url, isNofollow = false) {
        if (linkSelectedEditorImage(url, text, isNofollow)) {
          clearSelectedEditorImage();
          return;
        }

        if (activeLinkElement && wysiwygEditor.contains(activeLinkElement)) {
          applyAnchorAttributes(activeLinkElement, url, isNofollow);
          const linkedImage = activeLinkElement.querySelector('img');
          if (linkedImage) {
            if (text) linkedImage.alt = text;
          } else if (text) {
            activeLinkElement.textContent = text;
          }
          syncMarkdownFromEditor();
          return;
        }

        restoreEditorSelection();
        const selection = window.getSelection();
        const editorRange = currentEditorSelectionRange() || (savedEditorRange ? savedEditorRange.cloneRange() : null);
        const hasSelection = editorRange && !editorRange.collapsed;
        if (hasSelection) {
          const range = editorRange;
          const anchor = document.createElement('a');
          applyAnchorAttributes(anchor, url, isNofollow);
          if (selectionContainsImage(range)) {
            const fragment = range.extractContents();
            const selectedImage = fragment.querySelector('img');
            if (selectedImage && text) selectedImage.alt = text;
            anchor.appendChild(fragment);
          } else {
            anchor.textContent = text || selection.toString() || url;
            range.deleteContents();
          }
          range.insertNode(anchor);
          range.setStartAfter(anchor);
          range.collapse(true);
          selection.removeAllRanges();
          selection.addRange(range);
        } else {
          const anchor = document.createElement('a');
          applyAnchorAttributes(anchor, url, isNofollow);
          anchor.textContent = text || url;
          const range = editorRange || fallbackEditorRange();
          range.deleteContents();
          range.insertNode(anchor);
          range.setStartAfter(anchor);
          range.collapse(true);
          const nextSelection = window.getSelection();
          nextSelection.removeAllRanges();
          nextSelection.addRange(range);
        }
        savedEditorRange = null;
        clearSelectedEditorImage();
        syncMarkdownFromEditor();
      }

      function applyLinkFromToolbox() {
        if (!hasLinkToolbox) return;
        const url = normalizeLinkUrl(linkUrlInput.value);
        if (!url) {
          setText(linkToolboxStatus, 'Use a full http(s) URL or a site path that starts with /.');
          linkUrlInput.focus();
          return;
        }
        const text = linkTextInput.value.trim();
        const isNofollow = linkNofollowInput.checked;
        if (editorShell.classList.contains('editor-mode-markdown') || editorShell.classList.contains('editor-mode-split')) {
          insertMarkdownLink(text, url, isNofollow);
        } else {
          insertWysiwygLink(text, url, isNofollow);
        }
        closeLinkToolbox();
        scheduleEditorHistory(true);
        analyzeSeo();
      }

      function setArticleImageStatus(message, type) {
        setText(articleImageStatus, message || '');
        if (articleImageStatus) {
          articleImageStatus.style.color = type === 'error' ? '#ffd7dc' : 'rgba(255,255,255,0.78)';
        }
        scheduleFloatingEditorTabs();
      }

      function getEditorBlockFromEvent(event) {
        const block = event.target && event.target.closest ? event.target.closest('.editor-block') : null;
        return block && wysiwygEditor.contains(block) ? block : null;
      }

      function ensureEditorDropMarker() {
        if (!editorDropMarker) {
          editorDropMarker = document.createElement('div');
          editorDropMarker.className = 'editor-drop-marker';
          editorDropMarker.setAttribute('aria-hidden', 'true');
        }
        return editorDropMarker;
      }

      function removeEditorDropMarker() {
        if (editorDropMarker && editorDropMarker.parentNode) {
          editorDropMarker.parentNode.removeChild(editorDropMarker);
        }
      }

      function markerReferenceFromEvent(event) {
        const targetBlock = getEditorBlockFromEvent(event);
        if (targetBlock && targetBlock !== draggedEditorBlock) {
          const rect = targetBlock.getBoundingClientRect();
          return {
            parent: targetBlock.parentNode,
            before: event.clientY > rect.top + rect.height / 2 ? targetBlock.nextSibling : targetBlock,
          };
        }

        const range = document.caretRangeFromPoint
          ? document.caretRangeFromPoint(event.clientX, event.clientY)
          : (document.caretPositionFromPoint ? document.caretPositionFromPoint(event.clientX, event.clientY) : null);
        const node = range && (range.startContainer || range.offsetNode);
        const element = closestElement(node);
        const block = element && wysiwygEditor.contains(element) ? element.closest('.editor-block') : null;
        if (block && block !== draggedEditorBlock && wysiwygEditor.contains(block)) {
          return { parent: block.parentNode, before: block.nextSibling };
        }
        let topLevelNode = element;
        while (topLevelNode && topLevelNode.parentNode !== wysiwygEditor) {
          topLevelNode = topLevelNode.parentElement;
        }
        if (topLevelNode && topLevelNode !== draggedEditorBlock && wysiwygEditor.contains(topLevelNode)) {
          const rect = topLevelNode.getBoundingClientRect();
          return {
            parent: wysiwygEditor,
            before: event.clientY > rect.top + rect.height / 2 ? topLevelNode.nextSibling : topLevelNode,
          };
        }

        return { parent: wysiwygEditor, before: null };
      }

      function updateEditorDropMarker(event) {
        if (!draggedEditorBlock || !wysiwygEditor.contains(draggedEditorBlock)) return;
        const marker = ensureEditorDropMarker();
        const reference = markerReferenceFromEvent(event);
        if (!reference.parent) return;
        if (reference.before === marker) return;
        reference.parent.insertBefore(marker, reference.before);
      }

      function moveDraggedEditorBlock(event) {
        if (!draggedEditorBlock || !wysiwygEditor.contains(draggedEditorBlock)) return false;
        event.preventDefault();
        const marker = editorDropMarker && editorDropMarker.parentNode ? editorDropMarker : null;
        if (marker) {
          marker.parentNode.insertBefore(draggedEditorBlock, marker);
          removeEditorDropMarker();
        } else {
          const reference = markerReferenceFromEvent(event);
          reference.parent.insertBefore(draggedEditorBlock, reference.before);
        }
        draggedEditorBlock.classList.remove('is-dragging');
        draggedEditorBlock = null;
        syncMarkdownFromEditor();
        scheduleEditorHistory(true);
        return true;
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
          scheduleEditorHistory(true);
          return;
        }
        restoreEditorSelection();
        const img = document.createElement('img');
        img.src = path;
        img.alt = altText || 'Article Image';
        img.draggable = true;
        const paragraph = document.createElement('p');
        paragraph.className = 'editor-image-block';
        paragraph.draggable = true;
        paragraph.appendChild(img);
        insertEditorBlock(paragraph);
        savedEditorRange = null;
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

        if (command === 'faq') {
          if (editorShell.classList.contains('editor-mode-markdown') || editorShell.classList.contains('editor-mode-split')) {
            const markdown = ':::faq\nQ: FAQ question\nA: FAQ answer\n\nQ: Another FAQ question\nA: Another FAQ answer\n:::';
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
            scheduleEditorHistory(true);
            return;
          }

          focusEditor();
          const wrapper = document.createElement('div');
          wrapper.innerHTML = renderFaqBlock([
            { question: 'FAQ question', answer: 'FAQ answer' },
            { question: 'Another FAQ question', answer: 'Another FAQ answer' },
          ]);
          const faqBlock = wrapper.firstElementChild;
          insertEditorBlock(faqBlock);
          return;
        }

        if (command === 'link') {
          openLinkToolbox();
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
        } else if (command === 'clear') {
          document.execCommand('removeFormat');
          document.execCommand('formatBlock', false, 'p');
        }

        prepareEditorBlocks();
        syncMarkdownFromEditor();
        if (!editorShell.classList.contains('editor-mode-write')) {
          renderMarkdownPreview();
        }
        scheduleEditorHistory(true);
      }

      function slugify(value) {
        return (value || '').toLowerCase().trim().replace(/[^a-z0-9-]+/g, '-').replace(/-+/g, '-').replace(/^-|-$/g, '').slice(0, 96);
      }

      function setImage(path) {
        const value = path || '/uploads/blogs/default-featured.svg';
        if (imageUrl) imageUrl.value = value;
        if (imagePreview) imagePreview.src = value;
        setText(imageBadge, value);
        if (imagePreset) {
          imagePreset.value = value;
        }
      }

      function setUploadStatus(message, type) {
        setText(imageUploadStatus, message || '');
        if (imageUploadStatus) {
          imageUploadStatus.style.color = type === 'error' ? 'var(--danger)' : 'var(--text-muted)';
        }
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

      on(title, 'input', () => {
        if (!manualSlug) {
          const generated = slugify(title.value);
          if (slug) slug.value = generated;
          setText(slugPreview, generated || 'gperya-article');
          updateViewPageButton();
        }
        updateTitleDisplay();
        analyzeSeo();
      });

      on(slug, 'input', () => {
        manualSlug = true;
        const clean = slugify(slug.value);
        slug.value = clean;
        setText(slugPreview, clean || 'gperya-article');
        updateViewPageButton();
        analyzeSeo();
      });

      on(seoTitle, 'input', () => {
        manualSeoTitle = true;
        updateSeoTitleCounter();
        analyzeSeo();
      });

      if (imagePreset) {
        on(imagePreset, 'change', () => {
          setImage(imagePreset.value);
          analyzeSeo();
        });
      }
      on(imageUrl, 'input', () => {
        if (imagePreview) imagePreview.src = imageUrl.value || '/uploads/blogs/default-featured.svg';
        setText(imageBadge, imageUrl.value || '/uploads/blogs/default-featured.svg');
        analyzeSeo();
      });
      on(focusKeyphrase, 'input', analyzeSeo);
      on(keyphraseSynonyms, 'input', analyzeSeo);
      on(blogStatus, 'change', () => {
        updateSaveState(blogStatus.value);
      });
      on(viewBlogPageBtn, 'click', () => {
        const href = viewBlogPageBtn.dataset.href || currentBlogPublicUrl();
        if (href) window.open(href, '_blank', 'noopener');
      });
      [saveDraftBtn, publishBtn].filter(Boolean).forEach((button) => {
        on(button, 'click', () => {
          pendingSaveStatus = button.dataset.saveStatus || '';
          blogStatus.value = pendingSaveStatus || blogStatus.value;
          updateSaveState(blogStatus.value);
        });
      });
      on(document.getElementById('blog-excerpt'), 'input', () => {
        updateExcerptCounter();
        analyzeSeo();
      });

      on(tabWrite, 'click', () => setEditorMode('write'));
      on(tabMarkdown, 'click', () => setEditorMode('markdown'));
      on(tabPreview, 'click', () => setEditorMode('preview'));
      on(tabSplit, 'click', () => setEditorMode('split'));
      on(shortcutHelperToggle, 'click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        const isOpen = shortcutHelper && shortcutHelper.classList.toggle('is-open');
        shortcutHelperToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      });
      on(document, 'click', (event) => {
        if (!shortcutHelper || !shortcutHelperToggle) return;
        if (shortcutHelper.contains(event.target) || shortcutHelperToggle.contains(event.target)) return;
        closeShortcutHelper();
      });
      on(document, 'keydown', (event) => {
        if (event.key === 'Escape') closeShortcutHelper();
      });
      on(window, 'scroll', scheduleFloatingEditorTabs);
      on(window, 'resize', scheduleFloatingEditorTabs);
      on(document.querySelector('.wp-editor-main'), 'scroll', scheduleFloatingEditorTabs);
      on(undoBtn, 'click', () => restoreEditorHistory(-1));
      on(redoBtn, 'click', () => restoreEditorHistory(1));
      on(blogContent, 'input', () => {
        syncEditorFromMarkdown();
        updateWordCounter();
        if (editorShell.classList.contains('editor-mode-split')) {
          renderMarkdownPreview();
        }
        scheduleEditorHistory(false);
      });
      on(wysiwygEditor, 'input', () => {
        normalizeEmptyEditorHeadings();
        prepareEditorBlocks();
        syncMarkdownFromEditor();
        updateWordCounter();
        if (!editorShell.classList.contains('editor-mode-write')) {
          renderMarkdownPreview();
        }
        scheduleEditorHistory(false);
      });
      on(wysiwygEditor, 'keyup', saveEditorSelection);
      on(wysiwygEditor, 'mouseup', saveEditorSelection);
      on(wysiwygEditor, 'focus', saveEditorSelection);
      if (wysiwygEditor) {
        wysiwygEditor.addEventListener('keydown', (event) => {
          if (editorShell.classList.contains('editor-mode-write') && (event.ctrlKey || event.metaKey) && !event.shiftKey && !event.altKey && (event.key || '').toLowerCase() === 'a') {
            selectFaqFieldText(event);
          }
        }, true);
      }
      on(wysiwygEditor, 'keydown', handleEditorShortcut);
      on(blogContent, 'keydown', handleEditorShortcut);
      on(wysiwygEditor, 'click', (event) => {
        const addFaqButton = event.target.closest ? event.target.closest('[data-faq-add]') : null;
        if (addFaqButton && wysiwygEditor.contains(addFaqButton)) {
          event.preventDefault();
          const faqBlock = addFaqButton.closest('.editor-faq-block');
          const faqItems = faqBlock?.querySelector('.editor-faq-items');
          if (faqItems) {
            faqItems.insertAdjacentHTML('beforeend', renderFaqItem('FAQ question', 'FAQ answer'));
            prepareEditorBlocks();
            syncMarkdownFromEditor();
            scheduleEditorHistory(true);
            analyzeSeo();
          }
          return;
        }

        const removeFaqButton = event.target.closest ? event.target.closest('[data-faq-remove]') : null;
        if (removeFaqButton && wysiwygEditor.contains(removeFaqButton)) {
          event.preventDefault();
          const faqBlock = removeFaqButton.closest('.editor-faq-block');
          const faqItem = removeFaqButton.closest('.editor-faq-item');
          const faqItems = faqBlock ? Array.from(faqBlock.querySelectorAll('.editor-faq-item')) : [];
          if (faqItem && faqItems.length > 1) {
            faqItem.remove();
          } else if (faqItem) {
            const question = faqItem.querySelector('.editor-faq-question');
            const answer = faqItem.querySelector('.editor-faq-answer');
            if (question) question.textContent = 'FAQ question';
            if (answer) answer.textContent = 'FAQ answer';
          }
          prepareEditorBlocks();
          syncMarkdownFromEditor();
          scheduleEditorHistory(true);
          analyzeSeo();
          return;
        }

        const image = event.target.closest ? event.target.closest('img') : null;
        if (image && wysiwygEditor.contains(image)) {
          event.preventDefault();
          selectEditorImage(image);
          return;
        }
        clearSelectedEditorImage();
        const link = event.target.closest ? event.target.closest('a') : null;
        if (link && wysiwygEditor.contains(link)) {
          activeLinkElement = link;
          const range = document.createRange();
          range.selectNodeContents(link);
          savedEditorRange = range.cloneRange();
        }
      });
      on(wysiwygEditor, 'dragstart', (event) => {
        const block = getEditorBlockFromEvent(event);
        if (!block) return;
        draggedEditorBlock = block;
        block.classList.add('is-dragging');
        if (event.dataTransfer) {
          event.dataTransfer.effectAllowed = 'move';
          event.dataTransfer.setData('text/plain', 'gperya-editor-block');
        }
      });
      on(wysiwygEditor, 'dragend', () => {
        if (draggedEditorBlock) {
          draggedEditorBlock.classList.remove('is-dragging');
        }
        draggedEditorBlock = null;
        removeEditorDropMarker();
      });
      on(wysiwygEditor, 'dragover', (event) => {
        if (draggedEditorBlock) {
          event.preventDefault();
          if (event.dataTransfer) event.dataTransfer.dropEffect = 'move';
          updateEditorDropMarker(event);
          return;
        }
        if (!event.dataTransfer || !event.dataTransfer.files.length) return;
        event.preventDefault();
        saveEditorSelection();
        wysiwygEditor.classList.add('is-dragover');
      });
      on(wysiwygEditor, 'dragleave', (event) => {
        if (draggedEditorBlock) return;
        if (!wysiwygEditor.contains(event.relatedTarget)) {
          wysiwygEditor.classList.remove('is-dragover');
        }
      });
      on(wysiwygEditor, 'drop', (event) => {
        if (moveDraggedEditorBlock(event)) return;
        if (!event.dataTransfer || !event.dataTransfer.files.length) return;
        event.preventDefault();
        wysiwygEditor.classList.remove('is-dragover');
        saveEditorSelection();
        uploadArticleImage(event.dataTransfer.files[0]);
      });
      on(wysiwygEditor, 'copy', (event) => {
        copyCurrentEditorBlock(event, false);
      });
      on(wysiwygEditor, 'cut', (event) => {
        copyCurrentEditorBlock(event, true);
      });
      on(wysiwygEditor, 'paste', (event) => {
        const items = event.clipboardData ? Array.from(event.clipboardData.items) : [];
        const imageItem = items.find((item) => item.kind === 'file' && item.type.startsWith('image/'));
        if (imageItem) {
          event.preventDefault();
          saveEditorSelection();
          uploadArticleImage(imageItem.getAsFile());
          return;
        }
        if (pasteIntoFaqField(event)) return;
        if (pasteCopiedEditorBlock(event)) return;
        pasteFormattedDocumentHtml(event);
      });
      on(editorToolbar, 'click', (event) => {
        const button = event.target.closest('[data-command]');
        if (!button) return;
        applyEditorCommand(button.dataset.command);
      });
      on(editorToolbar, 'mousedown', (event) => {
        if (event.target.closest('[data-command]')) {
          event.preventDefault();
        }
      });
      if (hasLinkToolbox) {
        on(applyLinkBtn, 'click', applyLinkFromToolbox);
        on(cancelLinkBtn, 'click', closeLinkToolbox);
        on(window, 'resize', positionLinkToolbox);
        on(window, 'scroll', positionLinkToolbox);
        on(window, 'resize', positionImageSettingsPanel);
        on(window, 'scroll', positionImageSettingsPanel);
        on(document, 'mousedown', (event) => {
          if (!linkToolbox.classList.contains('is-open')) return;
          if (linkToolbox.contains(event.target)) return;
          closeLinkToolbox();
        });
        on(linkToolbox, 'keydown', (event) => {
          if (event.key === 'Enter') {
            event.preventDefault();
            applyLinkFromToolbox();
          } else if (event.key === 'Escape') {
            event.preventDefault();
            closeLinkToolbox();
          }
        });
      }
      on(applyImageSettingsBtn, 'click', applyImageSettings);
      on(removeImageLinkBtn, 'click', removeImageLink);
      on(imageAltInput, 'keydown', (event) => {
        if (event.key === 'Enter') {
          event.preventDefault();
          applyImageSettings();
        }
      });
      on(imageLinkInput, 'keydown', (event) => {
        if (event.key === 'Enter') {
          event.preventDefault();
          applyImageSettings();
        }
      });
      on(imageLinkNofollowInput, 'keydown', (event) => {
        if (event.key === 'Enter') {
          event.preventDefault();
          applyImageSettings();
        }
      });
      on(document, 'mousedown', (event) => {
        if (!imageSettingsPanel || !imageSettingsPanel.classList.contains('is-open')) return;
        if (imageSettingsPanel.contains(event.target) || wysiwygEditor.contains(event.target)) return;
        clearSelectedEditorImage();
      });

      on(articleImageButton, 'click', () => {
        saveEditorSelection();
        articleImageUpload.click();
      });
      on(articleImageDropzone, 'click', (event) => {
        if (event.target.closest('button')) return;
        saveEditorSelection();
        articleImageUpload.click();
      });
      on(articleImageDropzone, 'keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          saveEditorSelection();
          articleImageUpload.click();
        }
      });
      on(articleImageDropzone, 'dragover', (event) => {
        event.preventDefault();
        saveEditorSelection();
        articleImageDropzone.classList.add('is-dragover');
      });
      on(articleImageDropzone, 'dragleave', (event) => {
        if (!articleImageDropzone.contains(event.relatedTarget)) {
          articleImageDropzone.classList.remove('is-dragover');
        }
      });
      on(articleImageDropzone, 'drop', (event) => {
        event.preventDefault();
        articleImageDropzone.classList.remove('is-dragover');
        saveEditorSelection();
        uploadArticleImage(event.dataTransfer.files[0]);
      });
      on(articleImageUpload, 'change', () => {
        uploadArticleImage(articleImageUpload.files[0]);
      });

      on(imageDropzone, 'click', () => imageUpload.click());
      on(imageDropzone, 'keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          imageUpload.click();
        }
      });
      on(imageDropzone, 'dragover', (event) => {
        event.preventDefault();
        imageDropzone.classList.add('is-dragover');
      });
      on(imageDropzone, 'dragleave', (event) => {
        if (!imageDropzone.contains(event.relatedTarget)) {
          imageDropzone.classList.remove('is-dragover');
        }
      });
      on(imageDropzone, 'drop', (event) => {
        event.preventDefault();
        imageDropzone.classList.remove('is-dragover');
        uploadFeaturedImage(event.dataTransfer.files[0]);
      });
      on(imageUpload, 'change', () => {
        uploadFeaturedImage(imageUpload.files[0]);
      });

      function resetForm() {
        form.reset();
        if (editingId) editingId.value = '';
        manualSlug = false;
        manualSeoTitle = false;
        setText(slugPreview, 'gperya-article');
        setImage('/uploads/blogs/default-featured.svg');
        setUploadStatus('');
        setArticleImageStatus('');
        if (blogStatus) blogStatus.value = 'published';
        if (focusKeyphrase) focusKeyphrase.value = '';
        if (keyphraseSynonyms) keyphraseSynonyms.value = '';
        updateSaveState(blogStatus ? blogStatus.value : 'published');
        setEditorMarkdown('');
        resetEditorHistory('');
        pendingSaveStatus = '';
        updateTitleDisplay();
        updateSeoTitleCounter();
        updateExcerptCounter();
        if (cancelBtn) cancelBtn.style.display = 'none';
        setEditorMode('write');
      }

      on(cancelBtn, 'click', resetForm);
      on(form, 'reset', () => {
        window.setTimeout(() => {
          if (editingId) editingId.value = '';
          manualSlug = false;
          manualSeoTitle = false;
          setText(slugPreview, 'gperya-article');
          setImage('/uploads/blogs/default-featured.svg');
          setUploadStatus('');
          setArticleImageStatus('');
          if (blogStatus) blogStatus.value = 'published';
          if (focusKeyphrase) focusKeyphrase.value = '';
          if (keyphraseSynonyms) keyphraseSynonyms.value = '';
          updateSaveState(blogStatus ? blogStatus.value : 'published');
          setEditorMarkdown('');
          resetEditorHistory('');
          pendingSaveStatus = '';
          updateTitleDisplay();
          updateSeoTitleCounter();
          updateExcerptCounter();
          if (cancelBtn) cancelBtn.style.display = 'none';
          setEditorMode('write');
        }, 0);
      });

      on(form, 'submit', async (event) => {
        event.preventDefault();
        if (editorShell.classList.contains('editor-mode-write')) {
          syncMarkdownFromEditor();
        }
        updateWordCounter();
        const saveButton = event.submitter && event.submitter.dataset ? event.submitter : null;
        const requestedStatus = saveButton && saveButton.dataset.saveStatus ? saveButton.dataset.saveStatus : ((blogStatus ? blogStatus.value : '') || 'published');
        if (blogStatus) blogStatus.value = requestedStatus === 'draft' ? 'draft' : 'published';
        const currentStatus = blogStatus ? blogStatus.value : (requestedStatus === 'draft' ? 'draft' : 'published');
        updateSaveState(currentStatus);
        const data = new FormData(form);
        data.set('slug', slugify(data.get('slug') || data.get('title') || ''));
        data.set('seo_title', data.get('seo_title') || '');
        data.set('status', currentStatus);
        data.set('focus_keyphrase', focusKeyphrase ? focusKeyphrase.value.trim() : '');
        data.set('csrf_token', csrfToken);
        const savedSlug = String(data.get('slug') || '').trim();
        const shouldOpenPublicPage = saveButton && saveButton.id === 'publish-blog-btn' && currentStatus === 'published' && savedSlug;
        const publicUrl = savedSlug ? '/blog/' + encodeURIComponent(savedSlug) + '/' : '';
        const publicWindow = shouldOpenPublicPage ? window.open('', '_blank', 'noopener') : null;

        try {
          const response = await fetch('/admin/blog-save.php', {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'fetch' },
            body: data
          });
          const result = await parseJsonResponse(response);
          updateCsrf(result.csrfToken);
          if (!response.ok || !result.ok) throw new Error((result.errors || [result.error || 'Blog post could not be saved.']).join(' '));
          if (result.blog) {
            if (editingId) editingId.value = result.blog.id || result.blog.slug || savedSlug;
            if (slug && result.blog.slug) slug.value = result.blog.slug;
            if (blogStatus) blogStatus.value = result.blog.status || currentStatus;
          }
          pendingSaveStatus = '';
          updateViewPageButton();
          showNotice(currentStatus === 'draft' ? 'Draft saved.' : 'Post published.', 'ok');
          if (publicWindow && publicUrl) {
            publicWindow.location.href = publicUrl;
          } else if (shouldOpenPublicPage && publicUrl) {
            window.open(publicUrl, '_blank', 'noopener');
          }
        } catch (error) {
          if (publicWindow) publicWindow.close();
          showNotice(error.message || 'Blog post could not be saved.', 'error');
        }
      });

      async function loadBlogForEditing(id) {
        const data = new FormData();
        data.append('csrf_token', csrfToken);
        data.append('id', id);
        const response = await fetch('/admin/blog-edit.php', {
          method: 'POST',
          headers: { 'Accept': 'application/json', 'X-Requested-With': 'fetch' },
          body: data
        });
        const result = await parseJsonResponse(response);
        updateCsrf(result.csrfToken);
        if (!response.ok || !result.ok) {
          throw new Error(result.error || 'Blog post could not be loaded.');
        }
        const blog = result.blog;
        if (editingId) editingId.value = blog.id || blog.slug || '';
        if (title) title.value = blog.title || '';
        if (seoTitle) seoTitle.value = blog.seoTitle || (blog.title ? blog.title + ' | ' + websiteTitle : '');
        manualSeoTitle = Boolean(seoTitle && seoTitle.value.trim());
        if (slug) slug.value = blog.slug || blog.id || '';
        setText(slugPreview, slug && slug.value ? slug.value : 'gperya-article');
        if (categoryField) categoryField.value = blog.category || 'Guides';
        if (blogStatus) blogStatus.value = blog.status || 'published';
        if (focusKeyphrase) focusKeyphrase.value = blog.focusKeyphrase || '';
        updateSaveState(blogStatus ? blogStatus.value : (blog.status || 'published'));
        if (authorField) authorField.value = blog.author || 'GperyaPH Editorial Team';
        if (excerpt) excerpt.value = blog.excerpt || '';
        updateTitleDisplay();
        updateSeoTitleCounter();
        updateExcerptCounter();
        setEditorMarkdown(blog.content || '');
        resetEditorHistory(blog.content || '');
        renderMarkdownPreview();
        setImage(blog.featuredImage || '/uploads/blogs/default-featured.svg');
        manualSlug = true;
        pendingSaveStatus = '';
        updateTitleDisplay();
        updateSeoTitleCounter();
        if (cancelBtn) cancelBtn.style.display = 'inline-block';
        showNotice('Blog post loaded for editing.', 'ok');
        analyzeSeo();
      }

      if (initialEditId) {
        loadBlogForEditing(initialEditId).catch((error) => showNotice(error.message || 'Blog post could not be loaded.', 'error'));
      }
      updateTitleDisplay();
      updateExcerptCounter();
      updateViewPageButton();
      resetEditorHistory(blogContent ? blogContent.value : '');
      scheduleFloatingEditorTabs();
      analyzeSeo();
    });
  </script>
</body>
</html>
