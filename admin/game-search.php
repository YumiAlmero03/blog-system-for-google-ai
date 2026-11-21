<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/blog-storage.php';
require_once __DIR__ . '/plugin/games/includes/game-public.php';
require_once __DIR__ . '/plugin/games/includes/game-visibility.php';
require_once __DIR__ . '/plugin/games/includes/game-restrictions.php';

require_auth();

header('Content-Type: application/json; charset=UTF-8');
header('X-Robots-Tag: noindex, nofollow');

$slug = isset($_GET['slug']) && is_string($_GET['slug']) ? normalize_slug(trim($_GET['slug'])) : '';
$search = isset($_GET['search']) ? trim((string) $_GET['search']) : (isset($_GET['query']) ? trim((string) $_GET['query']) : '');
$limit = isset($_GET['limit']) ? max(1, min(20, (int) $_GET['limit'])) : 8;

try {
    $pdo = blogs_pdo();
    $allowedPh = games_ph_allowed_sql('games.restrictions');
    $visibility = game_visibility_sql('games');

    if ($slug !== '') {
        $stmt = $pdo->prepare("SELECT * FROM games WHERE slug = :slug AND published = 1 AND ($allowedPh) AND ($visibility) ORDER BY updated_at DESC, id DESC LIMIT 1");
        $stmt->execute([':slug' => $slug]);
        $row = $stmt->fetch();
        if (!$row) {
            echo json_encode(['ok' => false, 'error' => 'Game not found.', 'slots' => [], 'game' => null], JSON_UNESCAPED_SLASHES);
            exit;
        }

        $item = slot_list_item($row);
        echo json_encode(['ok' => true, 'slots' => [$item], 'game' => $item], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($search === '' || mb_strlen($search) < 2) {
        echo json_encode(['ok' => true, 'slots' => [], 'game' => null], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $exact = mb_strtolower($search);
    $starts = $exact . '%';
    $term = '%' . $search . '%';

    $stmt = $pdo->prepare("SELECT * FROM games WHERE ($allowedPh) AND ($visibility) AND published = 1 AND (name LIKE :term OR slug LIKE :term OR provider LIKE :term) ORDER BY CASE WHEN lower(name) = :exact THEN 0 WHEN lower(name) LIKE :starts THEN 1 WHEN lower(slug) LIKE :starts THEN 2 ELSE 3 END, updated_at DESC, id DESC LIMIT :limit");
    $stmt->bindValue(':term', $term, PDO::PARAM_STR);
    $stmt->bindValue(':exact', $exact, PDO::PARAM_STR);
    $stmt->bindValue(':starts', $starts, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll();
    $slots = [];
    foreach ($rows as $row) {
        $slots[] = slot_list_item($row);
    }

    echo json_encode(['ok' => true, 'slots' => $slots, 'game' => $slots[0] ?? null], JSON_UNESCAPED_SLASHES);
} catch (\Throwable $error) {
    error_log('Admin game search failed: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Search failed.'], JSON_UNESCAPED_SLASHES);
}
