<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/sports-api.php';
require_once __DIR__ . '/../../../includes/api-rate-limit.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    header('Allow: GET, OPTIONS');
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        http_response_code(405);
        header('Allow: GET, OPTIONS');
        echo json_encode(['ok' => false, 'error' => 'Use GET.'], JSON_THROW_ON_ERROR);
        exit;
    }

    if (api_rate_limit_exceeded(hash('sha256', 'sports-tree|' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown')), 120)) {
        http_response_code(429);
        header('Retry-After: 60');
        echo json_encode(['ok' => false, 'error' => 'Too many requests. Please try again shortly.'], JSON_THROW_ON_ERROR);
        exit;
    }

    $category = $_GET['category'] ?? ($_GET['category_id'] ?? ($_GET['category_slug'] ?? null));
    if ($category !== null && $category !== '') {
        if (!is_string($category) || strlen($category) > 100 || !preg_match('/^[A-Za-z0-9_-]+$/D', $category)) {
            throw new InvalidArgumentException('Invalid category parameter.');
        }
    } else {
        $category = null;
    }

    $sport = $_GET['sport'] ?? ($_GET['sport_key'] ?? null);
    if ($sport !== null && $sport !== '') {
        if (!is_string($sport) || strlen($sport) > 100 || !preg_match('/^[a-z0-9_]+$/D', $sport)) {
            throw new InvalidArgumentException('Invalid sport parameter.');
        }
    } else {
        $sport = null;
    }

    $options = [
        // Public listings always respect both registry switches.
        'only_enabled' => true,
        'only_enabled_layouts' => true,
        'only_active' => in_array(strtolower((string)($_GET['active'] ?? ($_GET['only_active'] ?? ''))), ['1', 'true', 'yes'], true),
        'category' => $category,
        'sport' => $sport,
        'include_empty_categories' => in_array(strtolower((string)($_GET['include_empty'] ?? '')), ['1', 'true', 'yes'], true),
    ];

    $tree = sports_api_tree($options);

    $view = strtolower((string)($_GET['view'] ?? ''));
    $layoutsOnly = in_array(strtolower((string)($_GET['layouts_only'] ?? '')), ['1', 'true', 'yes'], true) || $view === 'layouts';
    $treeOnly = in_array(strtolower((string)($_GET['tree_only'] ?? '')), ['1', 'true', 'yes'], true) || $view === 'categories' || $view === 'tree';

    if ($layoutsOnly) {
        $result = [
            'ok' => true,
            'layouts' => $tree['layouts'],
            'total_layouts' => $tree['total_layouts'],
        ];
    } elseif ($treeOnly) {
        $result = [
            'ok' => true,
            'categories' => $tree['categories'],
            'data' => $tree['data'],
            'total_categories' => $tree['total_categories'],
            'total_tournaments' => $tree['total_tournaments'],
        ];
    } else {
        $result = $tree;
    }

    $flags = JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE;
    if (!empty($_GET['pretty'])) {
        $flags |= JSON_PRETTY_PRINT;
    }

    echo json_encode($result, $flags);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Sports data temporarily unavailable. Please try again shortly.'], JSON_THROW_ON_ERROR);
}

