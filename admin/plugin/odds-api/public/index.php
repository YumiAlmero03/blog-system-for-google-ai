<?php
declare(strict_types=1);
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors *");
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Sports Odds</title><link rel="stylesheet" href="/admin/plugin/odds-api/public/style.css"><link rel="stylesheet" href="/admin/plugin/odds-api/public/layout.css"><script type="module" src="/admin/plugin/odds-api/public/app.js"></script></head>
<body><main>
<header id="iframe-header"><div><p id="iframe-title">Sports Odds</p><div id="iframe-title-section" hidden></div></div><button id="refresh" type="button">Refresh</button></header>
<p id="status" role="status" aria-live="polite">Loading…</p>
<div id="events"></div>
</main></body></html>
