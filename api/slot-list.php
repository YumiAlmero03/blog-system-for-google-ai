<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/blog-storage.php';
require_once __DIR__ . '/../includes/game-public.php';

const SLOT_LIST_CACHE_TTL = 60;


function slot_list_cache_dir(): string
{
    return blog_storage_dir() . '/api-cache';
}

function slot_list_param(string $key): ?string
{
    $source = ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' ? $_GET : $_POST;
    if (!isset($source[$key]) || is_array($source[$key])) {
        return null;
    }

    return trim((string) $source[$key]);
}

function slot_list_string(string $key, int $maxLength = 120): string
{
    $value = slot_list_param($key);
    if ($value === null) {
        return '';
    }

    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    return substr($value, 0, $maxLength);
}

function slot_list_string_list(string $key, int $maxItems = 20, int $maxLength = 120): array
{
    $source = ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' ? $_GET : $_POST;
    if (!isset($source[$key])) {
        return [];
    }

    $values = is_array($source[$key]) ? $source[$key] : [$source[$key]];
    $clean = [];
    foreach ($values as $value) {
        if (!is_scalar($value)) {
            continue;
        }

        foreach (explode(',', (string) $value) as $part) {
            $part = normalize_slug(substr(trim($part), 0, $maxLength));
            if ($part !== '') {
                $clean[] = $part;
            }
            if (count($clean) >= $maxItems) {
                break 2;
            }
        }
    }

    return array_values(array_unique($clean));
}

function slot_list_type_filter(): array
{
    $types = slot_list_string_list('type');
    foreach (slot_list_string_list('types') as $type) {
        $types[] = $type;
    }

    return array_values(array_unique($types));
}

function slot_list_positive_int(string $key, int $default, int $min, int $max): int
{
    $value = slot_list_param($key);
    if ($value === null || !ctype_digit($value)) {
        return $default;
    }

    return max($min, min($max, (int) $value));
}

function slot_list_bool_filter(string $key): ?int
{
    $value = slot_list_param($key);
    if ($value === '1' || strtolower((string) $value) === 'true') {
        return 1;
    }
    if ($value === '0' || strtolower((string) $value) === 'false') {
        return 0;
    }

    return null;
}

function slot_list_sort(string $value): string
{
    return match ($value) {
        'name_asc' => 'name ASC, id DESC',
        'rtp_desc' => 'rtp DESC, updated_at DESC, id DESC',
        'release_desc' => 'release DESC, updated_at DESC, id DESC',
        'updated_desc' => 'updated_at DESC, id DESC',
        default => 'featured DESC, updated_at DESC, id DESC',
    };
}

function slot_list_cache_key(array $filters): string
{
    $dbMtime = is_file(blogs_db_path()) ? (int) @filemtime(blogs_db_path()) : 0;
    $filters['endpoint'] = 'slot-list-v4';
    $filters['dbMtime'] = $dbMtime;

    return hash('sha256', json_encode($filters, JSON_UNESCAPED_SLASHES));
}

function slot_list_cache_path(string $key): string
{
    return slot_list_cache_dir() . '/' . $key . '.json';
}

function slot_list_cache_read(string $key): ?string
{
    $path = slot_list_cache_path($key);
    if (!is_file($path) || (time() - (int) @filemtime($path)) > SLOT_LIST_CACHE_TTL) {
        return null;
    }

    $cached = @file_get_contents($path);
    if (!is_string($cached) || $cached === '') {
        return null;
    }

    return $cached;
}

function slot_list_cache_write(string $key, string $payload): void
{
    $dir = slot_list_cache_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return;
    }

    $denyFile = $dir . '/.htaccess';
    if (!is_file($denyFile)) {
        @file_put_contents($denyFile, "Require all denied\n", LOCK_EX);
    }

    @file_put_contents(slot_list_cache_path($key), $payload, LOCK_EX);
}

if (!in_array(($_SERVER['REQUEST_METHOD'] ?? ''), ['GET', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
    header('X-Robots-Tag: noindex, nofollow');
    exit;
}

header('X-Robots-Tag: noindex, nofollow');
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: public, max-age=' . SLOT_LIST_CACHE_TTL);

$count = slot_list_positive_int('count', 24, 1, 100);
$page = slot_list_positive_int('page', 1, 1, 1000000);
$search = slot_list_string('search');
$provider = slot_list_string('provider');
$types = slot_list_type_filter();
$featured = slot_list_bool_filter('featured');
$progressive = slot_list_bool_filter('progressive');
$megaways = slot_list_bool_filter('megaways') === 1 ? 1 : null;
$upcoming = slot_list_bool_filter('upcoming');
$published = slot_list_bool_filter('published') ?? 1;
$sort = slot_list_string('sort', 32);
$offset = ($page - 1) * $count;

try {
    $cacheKey = slot_list_cache_key([
        'count' => $count,
        'page' => $page,
        'search' => $search,
        'provider' => $provider,
        'types' => $types,
        'featured' => $featured,
        'progressive' => $progressive,
        'megaways' => $megaways,
        'upcoming' => $upcoming,
        'published' => $published,
        'sort' => $sort,
    ]);
    $cachedPayload = slot_list_cache_read($cacheKey);
    if ($cachedPayload !== null) {
        header('X-API-Cache: HIT');
        echo $cachedPayload;
        exit;
    }

    $where = [
        'published = :published',
        game_public_eligibility_sql(),
    ];
    $params = [':published' => $published];

    if ($search !== '') {
        $where[] = '(name LIKE :search OR slug LIKE :search OR provider LIKE :search OR type LIKE :search)';
        $params[':search'] = '%' . $search . '%';
    }
    if ($provider !== '') {
        $where[] = 'provider_slug = :provider';
        $params[':provider'] = normalize_slug($provider);
    }
    if ($types !== []) {
        $typePlaceholders = [];
        foreach ($types as $index => $typeValue) {
            $placeholder = ':type_' . $index;
            $typePlaceholders[] = $placeholder;
            $params[$placeholder] = $typeValue;
        }
        $where[] = 'type_slug IN (' . implode(', ', $typePlaceholders) . ')';
    }
    if ($featured !== null) {
        $where[] = 'featured = :featured';
        $params[':featured'] = $featured;
    }
    if ($progressive !== null) {
        $where[] = 'progressive = :progressive';
        $params[':progressive'] = $progressive;
    }
    if ($megaways !== null) {
        $where[] = 'megaways = :megaways';
        $params[':megaways'] = $megaways;
    }
    if ($upcoming !== null) {
        $where[] = 'upcoming = :upcoming';
        $params[':upcoming'] = $upcoming;
    }

    $whereSql = 'WHERE ' . implode(' AND ', $where);
    $orderSql = slot_list_sort($sort);
    $pdo = blogs_pdo();
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM games {$whereSql}");
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $countStmt->execute();
    $total = (int) $countStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($total / $count));

    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $count;
    }

    $stmt = $pdo->prepare(
        "SELECT id, api_id, name, slug, url, thumb, short_description, long_description, provider, provider_slug, type, type_slug,
                themes, rtp, volatility, featured, progressive, published, upcoming, release, min_bet,
                max_bet, max_win_per_spin, paylines, updated_at
         FROM games
         {$whereSql}
         ORDER BY {$orderSql}
         LIMIT :limit OFFSET :offset"
    );
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $count, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $payload = json_encode([
        'ok' => true,
        'slots' => array_map('slot_list_item', $stmt->fetchAll()),
        'pagination' => [
            'total' => $total,
            'count' => $count,
            'page' => $page,
            'totalPages' => $totalPages,
        ],
        'filters' => [
            'search' => $search,
            'provider' => $provider,
            'types' => $types,
            'featured' => $featured,
            'progressive' => $progressive,
            'megaways' => $megaways,
            'upcoming' => $upcoming,
            'published' => $published,
            'sort' => $sort,
        ],
    ], JSON_UNESCAPED_SLASHES);
    if (!is_string($payload)) {
        throw new RuntimeException('Slot response encoding failed.');
    }

    slot_list_cache_write($cacheKey, $payload);
    header('X-API-Cache: MISS');
    echo $payload;
} catch (Throwable $exception) {
    error_log('Slot list storage error: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Slot list is unavailable.'], JSON_UNESCAPED_SLASHES);
}
