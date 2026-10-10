<?php
declare(strict_types=1);
if (($_GET['credits'] ?? '') === '1') { require __DIR__ . '/image-credits.php'; exit; }
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' https: data:; connect-src 'self'; object-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors *");
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Sports Odds</title>
  <link rel="stylesheet" href="/admin/plugin/odds-api/public/index-Iycxdcl7.css">
  <link rel="stylesheet" href="/admin/plugin/odds-api/public/tailwind.min.css">
  <link rel="stylesheet" href="/admin/plugin/odds-api/public/layout.css">
  <script type="module" src="/admin/plugin/odds-api/public/app.js"></script>
</head>
<body class="min-h-screen text-white antialiased p-3 sm:p-5">
  <main class="max-w-7xl mx-auto flex flex-col gap-4">
    <header id="iframe-header" class="flex items-center justify-between gap-4 pb-4 border-b border-white/10 w-full">
      <div class="iframe-header-titles flex items-center flex-wrap gap-3 min-w-0 flex-1">
        <h1 id="iframe-title" class="text-xl sm:text-2xl font-black text-white tracking-tight">Sports Odds</h1>
        <div id="iframe-title-section" class="flex items-center flex-wrap gap-3 min-w-0" hidden></div>
      </div>
      <button id="refresh" type="button" class="secondary-glass-button px-4 py-2 rounded-lg text-sm font-semibold flex items-center gap-2 cursor-pointer disabled:opacity-50">Refresh</button>
    </header>
    <div id="events" class="w-full"></div>
  </main>
</body>
</html>
