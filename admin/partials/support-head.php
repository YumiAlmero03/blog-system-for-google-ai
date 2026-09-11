<!DOCTYPE html>
<html lang="en-PH">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h($title) ?> | Admin</title>
<link rel="stylesheet" href="/admin/style.css">
<style>
.support-container{max-width:1200px;margin:24px auto;padding:24px;background:var(--surface);border:1px solid var(--border-strong);border-radius:var(--radius-lg)}
.support-table{overflow-x:auto}.support-table table{width:100%;border-collapse:collapse}.support-table th,.support-table td{text-align:left;padding:12px;border-bottom:1px solid var(--border);vertical-align:top}
.support-text{white-space:pre-wrap;overflow-wrap:anywhere}.support-actions{display:flex;gap:8px;flex-wrap:wrap;margin:16px 0}.support-container textarea{width:100%;min-height:100px;padding:10px;border:1px solid var(--border-strong);border-radius:var(--radius-sm)}
.support-message{padding:12px;border-bottom:1px solid var(--border);background:var(--surface-soft);margin:8px 0}.support-error{color:var(--danger)}
@media(max-width:640px){.support-container{padding:12px;margin:12px}.support-table th,.support-table td{padding:8px}}
</style>
</head><body>
<?php require __DIR__ . '/admin-header.php'; ?>
<main class="support-container"><h1><?= h($title) ?></h1>
