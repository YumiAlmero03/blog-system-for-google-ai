<?php
declare(strict_types=1);
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors *");
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Sports Odds</title><link rel="stylesheet" href="/odds-api/style.css"><script type="module" src="/odds-api/app.js"></script></head>
<body><main>
<header><div><h1 id="iframe-title">Sports Odds</h1><p>Compare the latest bookmaker prices</p></div><button id="refresh" type="button">Refresh</button></header>
<div class="filters"><label for="sport">Sport / competition</label><select id="sport" disabled><option>Loading sports…</option></select></div>
<p id="status" role="status" aria-live="polite">Loading sports…</p>
<div id="events"></div>
<footer>Decimal odds · Prices can change. Times are shown in your local timezone.</footer>
</main></body></html>
