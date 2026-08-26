<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/blog-storage.php';

require_auth();

const SEO_V2_SITE_HOST = 'freeonlinegames.info';
const SEO_V2_RENDER_ROW_LIMIT = 500;

function seo_v2_get_int(string $key, int $default, int $min, int $max): int
{
    $source = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? $_POST : $_GET;
    if (!isset($source[$key]) || is_array($source[$key]) || !preg_match('/^\d+$/', (string) $source[$key])) {
        return $default;
    }

    return max($min, min($max, (int) $source[$key]));
}

function seo_v2_get_bool(string $key, bool $default = false): bool
{
    $source = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? $_POST : $_GET;
    if (!isset($source[$key]) || is_array($source[$key])) {
        return $default;
    }

    return in_array(strtolower(trim((string) $source[$key])), ['1', 'true', 'yes', 'on'], true);
}

function seo_v2_json(array $payload): void
{
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function seo_v2_normalize_path(string $path): string
{
    $path = '/' . ltrim($path, '/');
    $path = preg_replace('#/+#', '/', $path) ?? $path;
    $pathOnly = parse_url($path, PHP_URL_PATH);
    $path = is_string($pathOnly) && $pathOnly !== '' ? $pathOnly : '/';
    if ($path !== '/' && !str_contains(basename($path), '.')) {
        $path = rtrim($path, '/') . '/';
    }

    return $path;
}

function seo_v2_pages(int $maxPages, bool $includeGames): array
{
    $pages = [
        '/',
        '/slots/',
        '/arcade/',
        '/perya-games/',
        '/live-casino/',
        '/card-games/',
        '/fishing-games/',
        '/sports-betting/',
        '/app/',
        '/invite/',
        '/vip/',
        '/payments/',
        '/contact/',
        '/support/',
        '/blog/',
        '/blogs/',
    ];

    try {
        $pdo = blogs_pdo();
        $blogLimit = max(0, $maxPages - count($pages));
        if ($blogLimit > 0) {
            $stmt = $pdo->prepare('SELECT slug FROM blog_posts WHERE status = "published" AND slug <> "" ORDER BY updated_at DESC LIMIT :limit');
            $stmt->bindValue(':limit', min($blogLimit, 500), PDO::PARAM_INT);
            $stmt->execute();
            foreach ($stmt->fetchAll() as $row) {
                $slug = trim((string) ($row['slug'] ?? ''));
                if ($slug !== '') {
                    $pages[] = '/blogs/' . $slug . '/';
                }
            }
        }

        if ($includeGames && count($pages) < $maxPages) {
            $gameLimit = $maxPages - count($pages);
            $stmt = $pdo->prepare('SELECT slug FROM games WHERE published = 1 AND slug <> "" ORDER BY featured DESC, updated_at DESC LIMIT :limit');
            $stmt->bindValue(':limit', $gameLimit, PDO::PARAM_INT);
            $stmt->execute();
            foreach ($stmt->fetchAll() as $row) {
                $slug = trim((string) ($row['slug'] ?? ''));
                if ($slug !== '') {
                    $pages[] = '/games/' . $slug . '/';
                }
            }
        }
    } catch (Throwable $exception) {
        error_log('SEO checker v2 page list failed: ' . $exception->getMessage());
    }

    $pages = array_values(array_unique(array_map('seo_v2_normalize_path', $pages)));

    return array_slice($pages, 0, $maxPages);
}

function seo_v2_local_file(string $path): ?string
{
    $root = dirname(__DIR__);
    $path = trim(parse_url($path, PHP_URL_PATH) ?: '/', '/');
    $candidates = $path === ''
        ? [$root . '/index.html', $root . '/index.php']
        : [$root . '/' . $path . '/index.html', $root . '/' . $path . '/index.php', $root . '/' . $path];

    foreach ($candidates as $candidate) {
        $real = realpath($candidate);
        if ($real !== false && str_starts_with($real, $root) && is_file($real)) {
            return $real;
        }
    }

    return null;
}

function seo_v2_page_html(string $path): string
{
    $file = seo_v2_local_file($path);
    if ($file === null) {
        return '';
    }

    $html = @file_get_contents($file);
    return is_string($html) ? $html : '';
}

function seo_v2_link_status(string $urlPath): array
{
    static $cache = [];

    $path = seo_v2_normalize_path($urlPath);
    if (isset($cache[$path])) {
        return $cache[$path];
    }

    $root = dirname(__DIR__);
    $trimmed = trim($path, '/');
    $withoutSlash = '/' . trim($path, '/');

    if ($path !== '/' && $urlPath === $withoutSlash && is_dir($root . $withoutSlash)) {
        return $cache[$path] = ['status' => 301, 'message' => '301 directory redirect to trailing slash'];
    }

    if (seo_v2_local_file($path) !== null) {
        return $cache[$path] = ['status' => 200, 'message' => '200 OK'];
    }

    if (preg_match('#^/(games?|game)/([a-z0-9-]+)/$#', $path, $match)) {
        try {
            $stmt = blogs_pdo()->prepare('SELECT COUNT(*) FROM games WHERE slug = :slug AND published = 1');
            $stmt->execute([':slug' => $match[2]]);
            return $cache[$path] = ((int) $stmt->fetchColumn()) > 0
                ? ['status' => 200, 'message' => '200 dynamic game route']
                : ['status' => 404, 'message' => '404 game slug not found'];
        } catch (Throwable) {
            return $cache[$path] = ['status' => 0, 'message' => 'Unable to verify game route'];
        }
    }

    if (preg_match('#^/(blogs?|blog)/([a-z0-9-]+)/$#', $path, $match)) {
        try {
            $stmt = blogs_pdo()->prepare('SELECT COUNT(*) FROM blog_posts WHERE slug = :slug AND status = "published"');
            $stmt->execute([':slug' => $match[2]]);
            return $cache[$path] = ((int) $stmt->fetchColumn()) > 0
                ? ['status' => 200, 'message' => '200 dynamic blog route']
                : ['status' => 404, 'message' => '404 blog slug not found'];
        } catch (Throwable) {
            return $cache[$path] = ['status' => 0, 'message' => 'Unable to verify blog route'];
        }
    }

    if (preg_match('#^/bonus/[a-z0-9-]+/$#', $path)) {
        return $cache[$path] = ['status' => 302, 'message' => '302 bonus redirect route'];
    }

    return $cache[$path] = ['status' => 404, 'message' => '404 local file or route not found'];
}

function seo_v2_resolve_internal_link(string $href, string $pagePath): ?string
{
    $href = trim($href);
    if ($href === '' || str_starts_with($href, '#')) {
        return null;
    }

    $scheme = strtolower((string) parse_url($href, PHP_URL_SCHEME));
    if (in_array($scheme, ['mailto', 'tel', 'sms', 'javascript', 'data'], true)) {
        return null;
    }

    $host = parse_url($href, PHP_URL_HOST);
    if (is_string($host) && $host !== '' && strtolower($host) !== SEO_V2_SITE_HOST && strtolower($host) !== 'localhost') {
        return null;
    }

    $path = parse_url($href, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        return null;
    }

    if (!str_starts_with($path, '/')) {
        $base = rtrim(dirname($pagePath), '/');
        $path = ($base === '' ? '' : $base) . '/' . $path;
    }

    return seo_v2_normalize_path($path);
}

function seo_v2_text(string $html): string
{
    $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    $text = preg_replace('/\s+/', ' ', $text) ?? $text;

    return $text;
}

function seo_v2_schema_types(mixed $data): array
{
    $types = [];
    $stack = [$data];
    while ($stack !== []) {
        $item = array_pop($stack);
        if (!is_array($item)) {
            continue;
        }
        if (isset($item['@type'])) {
            $type = $item['@type'];
            if (is_array($type)) {
                foreach ($type as $typeItem) {
                    $types[] = (string) $typeItem;
                }
            } else {
                $types[] = (string) $type;
            }
        }
        foreach (['@graph', 'mainEntity', 'itemListElement'] as $key) {
            if (isset($item[$key])) {
                $stack[] = $item[$key];
            }
        }
        if (array_is_list($item)) {
            foreach ($item as $child) {
                $stack[] = $child;
            }
        }
    }

    $types = array_values(array_unique(array_filter($types)));
    sort($types, SORT_NATURAL);

    return $types;
}

function seo_v2_scan_page(string $pagePath): array
{
    $html = seo_v2_page_html($pagePath);
    $title = '';
    $description = '';
    $canonical = '';
    $links = [];
    $schemas = [];

    if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $match)) {
        $title = seo_v2_text($match[1]);
    }
    if (preg_match('/<meta\b(?=[^>]*\bname=["\']description["\'])([^>]*)>/i', $html, $match)
        && preg_match('/\bcontent=["\']([^"\']*)["\']/i', $match[1], $contentMatch)) {
        $description = html_entity_decode($contentMatch[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
    if (preg_match('/<link\b(?=[^>]*\brel=["\']canonical["\'])([^>]*)>/i', $html, $match)
        && preg_match('/\bhref=["\']([^"\']*)["\']/i', $match[1], $hrefMatch)) {
        $canonical = html_entity_decode($hrefMatch[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    if (preg_match_all('/<a\b([^>]*)>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $linkMatch) {
            if (!preg_match('/\bhref=["\']([^"\']+)["\']/i', $linkMatch[1], $hrefMatch)) {
                continue;
            }
            $href = html_entity_decode($hrefMatch[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $resolved = seo_v2_resolve_internal_link($href, $pagePath);
            if ($resolved === null) {
                continue;
            }
            $status = seo_v2_link_status($resolved);
            $links[] = [
                'anchorText' => substr(seo_v2_text($linkMatch[2]), 0, 180),
                'redirectLink' => $resolved,
                'linkPageLocation' => $pagePath,
                'status' => $status['status'],
                'errorMessage' => $status['message'],
            ];
        }
    }

    if (preg_match_all('/<script\b(?=[^>]*type=["\']application\/ld\+json["\'])(?:[^>]*)>(.*?)<\/script>/is', $html, $schemaMatches)) {
        foreach ($schemaMatches[1] as $index => $json) {
            $decoded = json_decode(html_entity_decode(trim($json), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), true);
            $schemas[] = [
                'page' => $pagePath,
                'schemaIndex' => $index + 1,
                'types' => $decoded === null ? [] : seo_v2_schema_types($decoded),
                'status' => $decoded === null ? 'Invalid JSON-LD' : 'Valid JSON-LD',
                'message' => $decoded === null ? (json_last_error_msg() ?: 'Unable to parse schema') : 'Schema parsed successfully',
            ];
        }
    }

    if ($schemas === []) {
        $schemas[] = [
            'page' => $pagePath,
            'schemaIndex' => 0,
            'types' => [],
            'status' => 'Missing',
            'message' => 'No JSON-LD schema found on this page',
        ];
    }

    return [
        'page' => [
            'path' => $pagePath,
            'status' => $html === '' ? 404 : 200,
            'title' => $title,
            'description' => $description,
            'canonical' => $canonical,
            'htmlBytes' => strlen($html),
            'linkCount' => count($links),
            'schemaCount' => count(array_filter($schemas, static fn (array $schema): bool => $schema['status'] !== 'Missing')),
        ],
        'links' => $links,
        'schemas' => $schemas,
    ];
}

$action = isset($_GET['action']) && is_string($_GET['action']) ? $_GET['action'] : '';
if ($action !== '') {
    if (!expects_json()) {
        header('X-Robots-Tag: noindex, nofollow');
    }

    $maxPages = seo_v2_get_int('max_pages', 250, 1, 5000);
    $includeGames = seo_v2_get_bool('include_games', false);
    $pages = seo_v2_pages($maxPages, $includeGames);

    if ($action === 'manifest') {
        seo_v2_json([
            'ok' => true,
            'totalPages' => count($pages),
            'renderRowLimit' => SEO_V2_RENDER_ROW_LIMIT,
        ]);
    }

    if ($action === 'scan') {
        $offset = seo_v2_get_int('offset', 0, 0, 1000000);
        $limit = seo_v2_get_int('limit', 10, 1, 50);
        $slice = array_slice($pages, $offset, $limit);
        $pageResults = [];
        $linkResults = [];
        $schemaResults = [];
        foreach ($slice as $page) {
            $result = seo_v2_scan_page($page);
            $pageResults[] = $result['page'];
            array_push($linkResults, ...$result['links']);
            array_push($schemaResults, ...$result['schemas']);
        }

        seo_v2_json([
            'ok' => true,
            'offset' => $offset,
            'limit' => $limit,
            'nextOffset' => $offset + count($slice),
            'done' => ($offset + count($slice)) >= count($pages),
            'totalPages' => count($pages),
            'pages' => $pageResults,
            'links' => $linkResults,
            'schemas' => $schemaResults,
        ]);
    }

    http_response_code(404);
    seo_v2_json(['ok' => false, 'error' => 'Unknown SEO checker action.']);
}
?>
<!DOCTYPE html>
<html lang="en-PH">
<head>
  <meta name="robots" content="noindex, nofollow">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Website SEO Checker V2 | Admin</title>
  <link rel="preload" href="/admin/style.css" as="style"><link rel="stylesheet" href="/admin/style.css">
  <link rel="icon" href="/assets/icons/favicon.ico">
  <style>
    body { background: #f6f7f9; }
    .seo-shell { max-width: 1280px; margin: 24px auto; padding: 20px; }
    .seo-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 18px; padding-bottom: 14px; border-bottom: 1px solid var(--border); }
    .seo-header h1 { margin-bottom: 4px; font-size: 1.5rem; line-height: 1.2; }
    .seo-header p { margin-bottom: 0; color: var(--text-muted); font-size: 0.9rem; }
    .seo-controls { display: grid; grid-template-columns: 160px 160px auto auto auto; gap: 10px; align-items: end; margin-bottom: 16px; }
    .seo-field label { display: block; margin-bottom: 5px; color: var(--text-muted); font-size: 0.75rem; font-weight: 800; text-transform: uppercase; }
    .seo-field input { width: 100%; min-height: 38px; padding: 8px 10px; border: 1px solid var(--border-strong); border-radius: 6px; }
    .seo-check { display: inline-flex; align-items: center; gap: 8px; min-height: 38px; font-weight: 800; color: #344054; }
    .seo-metrics { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 10px; margin-bottom: 16px; }
    .seo-metric { padding: 12px; background: #fafafa; border: 1px solid var(--border); border-radius: 8px; }
    .seo-metric strong { display: block; font-size: 1.35rem; line-height: 1.1; color: #111827; }
    .seo-metric span { display: block; margin-top: 5px; color: var(--text-muted); font-size: 0.76rem; font-weight: 800; }
    .seo-progress { height: 10px; margin-bottom: 18px; overflow: hidden; background: #eef2f6; border: 1px solid var(--border); border-radius: 999px; }
    .seo-progress span { display: block; width: 0%; height: 100%; background: var(--brand); transition: width 0.2s ease; }
    .seo-tabs { display: flex; gap: 8px; margin-bottom: 12px; flex-wrap: wrap; }
    .seo-tab { border: 1px solid var(--border-strong); background: #fff; color: #344054; border-radius: 6px; padding: 8px 12px; font-weight: 900; cursor: pointer; }
    .seo-tab.active { background: var(--brand); border-color: var(--brand); color: #fff; }
    .seo-panel { display: none; }
    .seo-panel.active { display: block; }
    .seo-table-wrap { overflow: auto; border: 1px solid var(--border); border-radius: 8px; background: #fff; }
    .seo-table { width: 100%; min-width: 860px; border-collapse: collapse; font-size: 0.84rem; }
    .seo-table th, .seo-table td { padding: 10px; border-bottom: 1px solid var(--border); text-align: left; vertical-align: top; }
    .seo-table th { position: sticky; top: 0; background: #f9fafb; color: #344054; font-size: 0.74rem; text-transform: uppercase; letter-spacing: 0; z-index: 1; }
    .seo-url { max-width: 320px; word-break: break-word; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.78rem; }
    .seo-pill { display: inline-flex; align-items: center; min-height: 22px; padding: 3px 7px; border-radius: 6px; font-size: 0.72rem; font-weight: 900; white-space: nowrap; }
    .seo-ok { background: #ecfdf3; color: #067647; }
    .seo-warn { background: #fffaeb; color: #b54708; }
    .seo-bad { background: #fef3f2; color: #b42318; }
    .seo-muted { color: var(--text-muted); font-size: 0.82rem; }
    .seo-note { margin-top: 8px; color: var(--text-muted); font-size: 0.82rem; }
    @media (max-width: 900px) {
      .seo-header, .seo-controls { grid-template-columns: 1fr; display: grid; }
      .seo-metrics { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
  </style>
</head>
<body>
<div class="page-shell">
  <?php require __DIR__ . '/partials/admin-header.php'; ?>

  <main class="admin-container seo-shell">
    <div class="seo-header">
      <div>
        <h1>Website SEO Checker V2</h1>
        <p>Batch scans page HTML for internal links, status messages, metadata, and JSON-LD schema without loading every result into the DOM.</p>
      </div>
      <a class="btn btn-secondary btn-sm" href="/admin/blogs.php">Back to Admin</a>
    </div>

    <section class="seo-controls" aria-label="SEO scan controls">
      <div class="seo-field">
        <label for="maxPages">Max pages</label>
        <input id="maxPages" type="number" min="1" max="5000" value="250">
      </div>
      <div class="seo-field">
        <label for="batchSize">Batch size</label>
        <input id="batchSize" type="number" min="1" max="50" value="10">
      </div>
      <label class="seo-check">
        <input id="includeGames" type="checkbox">
        Include game pages
      </label>
      <button id="startScan" class="btn btn-primary" type="button">Start V2 Scan</button>
      <button id="stopScan" class="btn btn-secondary" type="button" disabled>Stop</button>
      <button id="exportScan" class="btn btn-secondary" type="button" disabled>Export JSON</button>
    </section>

    <section class="seo-metrics" aria-label="SEO scan metrics">
      <div class="seo-metric"><strong id="metricPages">0</strong><span>Pages scanned</span></div>
      <div class="seo-metric"><strong id="metricLinks">0</strong><span>Internal links</span></div>
      <div class="seo-metric"><strong id="metricIssues">0</strong><span>Link issues</span></div>
      <div class="seo-metric"><strong id="metricSchemas">0</strong><span>Schema blocks</span></div>
      <div class="seo-metric"><strong id="metricMissingSchema">0</strong><span>Missing schema</span></div>
    </section>

    <div class="seo-progress" aria-label="Scan progress"><span id="scanProgress"></span></div>

    <nav class="seo-tabs" aria-label="SEO checker views">
      <button class="seo-tab active" type="button" data-tab="linksPanel">Internal Link List</button>
      <button class="seo-tab" type="button" data-tab="schemaPanel">Schema Per Page</button>
      <button class="seo-tab" type="button" data-tab="pagesPanel">Page Summary</button>
    </nav>

    <section id="linksPanel" class="seo-panel active">
      <div class="seo-table-wrap">
        <table class="seo-table">
          <thead>
            <tr>
              <th>Anchor Text</th>
              <th>Redirect Link</th>
              <th>Link Page Location</th>
              <th>Error Message</th>
            </tr>
          </thead>
          <tbody id="linksBody"></tbody>
        </table>
      </div>
      <p class="seo-note" id="linksNote">No scan data yet.</p>
    </section>

    <section id="schemaPanel" class="seo-panel">
      <div class="seo-table-wrap">
        <table class="seo-table">
          <thead>
            <tr>
              <th>Page</th>
              <th>Schema Types</th>
              <th>Status</th>
              <th>Message</th>
            </tr>
          </thead>
          <tbody id="schemaBody"></tbody>
        </table>
      </div>
      <p class="seo-note" id="schemaNote">No scan data yet.</p>
    </section>

    <section id="pagesPanel" class="seo-panel">
      <div class="seo-table-wrap">
        <table class="seo-table">
          <thead>
            <tr>
              <th>Page</th>
              <th>Status</th>
              <th>Title</th>
              <th>Description</th>
              <th>Canonical</th>
              <th>Size</th>
            </tr>
          </thead>
          <tbody id="pagesBody"></tbody>
        </table>
      </div>
      <p class="seo-note" id="pagesNote">No scan data yet.</p>
    </section>
  </main>
</div>

<script>
(() => {
  const rowLimit = <?= (int) SEO_V2_RENDER_ROW_LIMIT ?>;
  const state = { running: false, total: 0, offset: 0, pages: [], links: [], schemas: [] };
  const $ = (id) => document.getElementById(id);
  const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[char]));
  const statusClass = (status) => Number(status) === 200 ? 'seo-ok' : (Number(status) >= 300 && Number(status) < 400 ? 'seo-warn' : 'seo-bad');

  function params(offset = 0) {
    const query = new URLSearchParams({
      max_pages: $('maxPages').value || '250',
      include_games: $('includeGames').checked ? '1' : '0',
      offset: String(offset),
      limit: $('batchSize').value || '10'
    });
    return query.toString();
  }

  function setRunning(running) {
    state.running = running;
    $('startScan').disabled = running;
    $('stopScan').disabled = !running;
    $('exportScan').disabled = state.pages.length === 0;
  }

  function updateMetrics() {
    const issues = state.links.filter((link) => Number(link.status) !== 200).length;
    const missingSchema = state.schemas.filter((schema) => schema.status === 'Missing' || schema.status === 'Invalid JSON-LD').length;
    $('metricPages').textContent = state.pages.length.toLocaleString();
    $('metricLinks').textContent = state.links.length.toLocaleString();
    $('metricIssues').textContent = issues.toLocaleString();
    $('metricSchemas').textContent = state.schemas.filter((schema) => schema.status !== 'Missing').length.toLocaleString();
    $('metricMissingSchema').textContent = missingSchema.toLocaleString();
    $('scanProgress').style.width = state.total ? `${Math.min(100, Math.round((state.offset / state.total) * 100))}%` : '0%';
  }

  function renderRows() {
    $('linksBody').innerHTML = state.links.slice(0, rowLimit).map((link) => `
      <tr>
        <td>${esc(link.anchorText || '(empty anchor)')}</td>
        <td class="seo-url">${esc(link.redirectLink)}</td>
        <td class="seo-url">${esc(link.linkPageLocation)}</td>
        <td><span class="seo-pill ${statusClass(link.status)}">${esc(link.errorMessage)}</span></td>
      </tr>
    `).join('');
    $('schemaBody').innerHTML = state.schemas.slice(0, rowLimit).map((schema) => `
      <tr>
        <td class="seo-url">${esc(schema.page)}</td>
        <td>${esc((schema.types || []).join(', ') || 'None')}</td>
        <td><span class="seo-pill ${schema.status === 'Valid JSON-LD' ? 'seo-ok' : 'seo-bad'}">${esc(schema.status)}</span></td>
        <td>${esc(schema.message)}</td>
      </tr>
    `).join('');
    $('pagesBody').innerHTML = state.pages.slice(0, rowLimit).map((page) => `
      <tr>
        <td class="seo-url">${esc(page.path)}</td>
        <td><span class="seo-pill ${statusClass(page.status)}">${esc(page.status)}</span></td>
        <td>${esc(page.title || '(missing title)')}</td>
        <td>${esc(page.description || '(missing description)')}</td>
        <td class="seo-url">${esc(page.canonical || '(missing canonical)')}</td>
        <td>${Number(page.htmlBytes || 0).toLocaleString()} bytes</td>
      </tr>
    `).join('');
    $('linksNote').textContent = state.links.length > rowLimit ? `Showing first ${rowLimit.toLocaleString()} of ${state.links.length.toLocaleString()} links. Export JSON for the full list.` : `${state.links.length.toLocaleString()} links found.`;
    $('schemaNote').textContent = state.schemas.length > rowLimit ? `Showing first ${rowLimit.toLocaleString()} of ${state.schemas.length.toLocaleString()} schema rows. Export JSON for the full list.` : `${state.schemas.length.toLocaleString()} schema rows found.`;
    $('pagesNote').textContent = state.pages.length > rowLimit ? `Showing first ${rowLimit.toLocaleString()} of ${state.pages.length.toLocaleString()} pages. Export JSON for the full list.` : `${state.pages.length.toLocaleString()} pages scanned.`;
  }

  async function scanBatch() {
    if (!state.running) return;
    const response = await fetch(`/admin/seo-checker-v2.php?action=scan&${params(state.offset)}`, {
      headers: { Accept: 'application/json', 'X-Requested-With': 'fetch' },
      cache: 'no-store'
    });
    const data = await response.json();
    if (!data.ok) throw new Error(data.error || 'Scan failed');
    state.total = data.totalPages || state.total;
    state.offset = data.nextOffset || state.offset;
    state.pages.push(...(data.pages || []));
    state.links.push(...(data.links || []));
    state.schemas.push(...(data.schemas || []));
    updateMetrics();
    renderRows();
    if (data.done) {
      setRunning(false);
      return;
    }
    window.setTimeout(scanBatch, 80);
  }

  $('startScan').addEventListener('click', async () => {
    state.total = 0;
    state.offset = 0;
    state.pages = [];
    state.links = [];
    state.schemas = [];
    setRunning(true);
    updateMetrics();
    renderRows();
    try {
      const manifestResponse = await fetch(`/admin/seo-checker-v2.php?action=manifest&${params(0)}`, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'fetch' },
        cache: 'no-store'
      });
      const manifest = await manifestResponse.json();
      state.total = manifest.totalPages || 0;
      updateMetrics();
      await scanBatch();
    } catch (error) {
      setRunning(false);
      alert(error.message || 'Unable to run SEO scan.');
    }
  });

  $('stopScan').addEventListener('click', () => setRunning(false));
  $('exportScan').addEventListener('click', () => {
    const blob = new Blob([JSON.stringify({
      exportedAt: new Date().toISOString(),
      pages: state.pages,
      internalLinks: state.links,
      schemas: state.schemas
    }, null, 2)], { type: 'application/json' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'website-seo-checker-v2.json';
    link.click();
    URL.revokeObjectURL(link.href);
  });

  document.querySelectorAll('.seo-tab').forEach((button) => {
    button.addEventListener('click', () => {
      document.querySelectorAll('.seo-tab').forEach((tab) => tab.classList.remove('active'));
      document.querySelectorAll('.seo-panel').forEach((panel) => panel.classList.remove('active'));
      button.classList.add('active');
      $(button.dataset.tab).classList.add('active');
    });
  });
})();
</script>
</body>
</html>
