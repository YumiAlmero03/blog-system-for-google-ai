<?php
declare(strict_types=1);

require_once __DIR__ . '/env.php';

const BLOG_CATEGORIES = ['Guides', 'Troubleshooting', 'Casino', 'Promotions', 'Community'];
const BLOG_STATUSES = ['published', 'draft'];
const BLOG_DEFAULT_AUTHOR = 'GperyaPH Editorial Team';
const BLOG_DEFAULT_IMAGE = '/uploads/blogs/default-featured.svg';

function blog_storage_dir(): string
{
    $storageDir = env_value('APP_STORAGE_DIR');
    if (!is_string($storageDir) || $storageDir === '' || $storageDir === '/absolute/path/outside/public/storage') {
        $storageDir = dirname(__DIR__) . '/storage';
    }

    return rtrim($storageDir, '/\\');
}

function blogs_json_path(): string
{
    return blog_storage_dir() . '/blogs.min.json';
}

function blogs_db_path(): string
{
    return blog_storage_dir() . '/blogs.sqlite';
}

function ensure_blog_storage_dir(): void
{
    $dir = blog_storage_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Blog storage directory unavailable.');
    }
}

function blogs_pdo(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    ensure_blog_storage_dir();

    $pdo = new PDO('sqlite:' . blogs_db_path(), null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');

    blogs_schema($pdo);
    blogs_migrate_json($pdo);

    return $pdo;
}

function blogs_schema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS blog_posts (
            id TEXT PRIMARY KEY,
            slug TEXT NOT NULL UNIQUE,
            title TEXT NOT NULL,
            category TEXT NOT NULL,
            author TEXT NOT NULL,
            excerpt TEXT NOT NULL,
            content TEXT NOT NULL,
            featured_image TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT "published",
            focus_keyphrase TEXT NOT NULL DEFAULT "",
            date_label TEXT NOT NULL,
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL
        )'
    );
    $columns = $pdo->query('PRAGMA table_info(blog_posts)')->fetchAll();
    $hasStatus = false;
    $hasFocusKeyphrase = false;
    foreach ($columns as $column) {
        if (($column['name'] ?? null) === 'status') {
            $hasStatus = true;
        }
        if (($column['name'] ?? null) === 'focus_keyphrase') {
            $hasFocusKeyphrase = true;
        }
    }
    if (!$hasStatus) {
        $pdo->exec('ALTER TABLE blog_posts ADD COLUMN status TEXT NOT NULL DEFAULT "published"');
    }
    if (!$hasFocusKeyphrase) {
        $pdo->exec('ALTER TABLE blog_posts ADD COLUMN focus_keyphrase TEXT NOT NULL DEFAULT ""');
    }
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_blog_posts_updated_at ON blog_posts(updated_at DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_blog_posts_status_updated_at ON blog_posts(status, updated_at DESC)');
}

function blogs_migrate_json(PDO $pdo): void
{
    $marker = blog_storage_dir() . '/blogs-json-migrated';
    if (is_file($marker)) {
        return;
    }

    $jsonPath = blogs_json_path();
    if (!is_file($jsonPath) || !is_readable($jsonPath)) {
        @file_put_contents($marker, (string) time());
        return;
    }

    $raw = file_get_contents($jsonPath);
    $blogs = json_decode($raw !== false ? $raw : '', true);
    if (!is_array($blogs)) {
        @file_put_contents($marker, (string) time());
        return;
    }

    $pdo->beginTransaction();
    try {
        foreach ($blogs as $blog) {
            if (!is_array($blog)) {
                continue;
            }
            $normalized = blog_normalize_existing($blog);
            if ($normalized === null) {
                continue;
            }
            blogs_upsert_with_pdo($pdo, $normalized);
            blog_ensure_post_route($normalized['slug']);
        }
        $pdo->commit();
        @file_put_contents($marker, (string) time());
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}

function blog_normalize_existing(array $blog): ?array
{
    $title = isset($blog['title']) && is_string($blog['title']) ? trim($blog['title']) : '';
    $slug = isset($blog['slug']) && is_string($blog['slug']) ? normalize_slug($blog['slug']) : '';
    if ($slug === '' && isset($blog['id']) && is_string($blog['id'])) {
        $slug = normalize_slug($blog['id']);
    }
    if ($slug === '' && $title !== '') {
        $slug = normalize_slug($title);
    }
    if ($title === '' || $slug === '') {
        return null;
    }

    $now = time();
    return [
        'id' => $slug,
        'slug' => $slug,
        'title' => substr($title, 0, 160),
        'category' => isset($blog['category']) && in_array($blog['category'], BLOG_CATEGORIES, true) ? $blog['category'] : 'Guides',
        'author' => isset($blog['author']) && is_string($blog['author']) && trim($blog['author']) !== '' ? substr(trim($blog['author']), 0, 80) : BLOG_DEFAULT_AUTHOR,
        'excerpt' => isset($blog['excerpt']) && is_string($blog['excerpt']) ? substr(trim($blog['excerpt']), 0, 360) : '',
        'content' => isset($blog['content']) && is_string($blog['content']) ? substr(trim($blog['content']), 0, 60000) : '',
        'featuredImage' => isset($blog['featuredImage']) && is_string($blog['featuredImage']) ? normalize_featured_image($blog['featuredImage']) : BLOG_DEFAULT_IMAGE,
        'status' => normalize_blog_status($blog['status'] ?? null),
        'focusKeyphrase' => normalize_focus_keyphrase($blog['focusKeyphrase'] ?? ($blog['focus_keyphrase'] ?? '')),
        'date' => isset($blog['date']) && is_string($blog['date']) && trim($blog['date']) !== '' ? substr(trim($blog['date']), 0, 32) : date('M j, Y', $now),
        'createdAt' => isset($blog['createdAt']) && is_int($blog['createdAt']) ? $blog['createdAt'] : (isset($blog['timestamp']) && is_int($blog['timestamp']) ? (int) floor($blog['timestamp'] / 1000) : $now),
        'updatedAt' => isset($blog['updatedAt']) && is_int($blog['updatedAt']) ? $blog['updatedAt'] : $now,
    ];
}

function blog_row_to_array(array $row): array
{
    return [
        'id' => $row['id'],
        'slug' => $row['slug'],
        'title' => $row['title'],
        'category' => $row['category'],
        'author' => $row['author'],
        'excerpt' => $row['excerpt'],
        'content' => $row['content'],
        'featuredImage' => $row['featured_image'],
        'status' => normalize_blog_status($row['status'] ?? null),
        'focusKeyphrase' => normalize_focus_keyphrase($row['focus_keyphrase'] ?? ''),
        'date' => $row['date_label'],
        'createdAt' => (int) $row['created_at'],
        'updatedAt' => (int) $row['updated_at'],
    ];
}

function normalize_blog_status(mixed $status): string
{
    if (!is_string($status)) {
        return 'published';
    }

    $status = strtolower(trim($status));
    return in_array($status, BLOG_STATUSES, true) ? $status : 'published';
}

function normalize_focus_keyphrase(mixed $value): string
{
    if (!is_string($value)) {
        return '';
    }

    $value = preg_replace('/\s+/', ' ', trim($value)) ?? '';
    return substr($value, 0, 160);
}

function normalize_blog_category_filter(?string $category): ?string
{
    if (!is_string($category)) {
        return null;
    }

    $category = trim($category);
    if ($category === '') {
        return null;
    }

    foreach (BLOG_CATEGORIES as $allowedCategory) {
        if (strcasecmp($allowedCategory, $category) === 0) {
            return $allowedCategory;
        }
    }

    return null;
}

function request_blog_category_filter(): ?string
{
    if (!isset($_POST['category']) || is_array($_POST['category'])) {
        return null;
    }

    return normalize_blog_category_filter((string) $_POST['category']);
}

function blog_like_term(string $value): string
{
    return '%' . strtr($value, [
        '\\' => '\\\\',
        '%' => '\\%',
        '_' => '\\_',
    ]) . '%';
}

function blogs_page(int $count, int $page, ?string $category = null, ?string $status = null, ?string $search = null): array
{
    $count = max(1, min(100, $count));
    $page = max(1, $page);
    $offset = ($page - 1) * $count;
    $pdo = blogs_pdo();
    $category = normalize_blog_category_filter($category);
    $status = $status === null ? null : normalize_blog_status($status);
    $search = is_string($search) ? trim($search) : '';

    $where = [];
    $params = [];
    if ($category !== null) {
        $where[] = 'category = :category';
        $params[':category'] = $category;
    }
    if ($status !== null) {
        $where[] = 'status = :status';
        $params[':status'] = $status;
    }
    if ($search !== '') {
        $where[] = '(title LIKE :search ESCAPE \'\\\' OR slug LIKE :search ESCAPE \'\\\' OR category LIKE :search ESCAPE \'\\\' OR author LIKE :search ESCAPE \'\\\' OR excerpt LIKE :search ESCAPE \'\\\' OR content LIKE :search ESCAPE \'\\\' OR focus_keyphrase LIKE :search ESCAPE \'\\\' OR status LIKE :search ESCAPE \'\\\')';
        $params[':search'] = blog_like_term($search);
    }
    $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';

    $totalStmt = $pdo->prepare('SELECT COUNT(*) FROM blog_posts' . $whereSql);
    $totalStmt->execute($params);
    $total = (int) $totalStmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT * FROM blog_posts' . $whereSql . ' ORDER BY updated_at DESC, created_at DESC LIMIT :limit OFFSET :offset');
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $count, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    return [
        'items' => array_map('blog_row_to_array', $stmt->fetchAll()),
        'total' => $total,
        'count' => $count,
        'page' => $page,
        'category' => $category,
        'search' => $search,
        'totalPages' => max(1, (int) ceil($total / $count)),
    ];
}

function blogs_all(): array
{
    return blogs_page(100, 1)['items'];
}

function blogs_find(string $id): ?array
{
    $id = normalize_slug($id);
    $stmt = blogs_pdo()->prepare('SELECT * FROM blog_posts WHERE id = :id OR slug = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    return is_array($row) ? blog_row_to_array($row) : null;
}

function blog_ensure_post_route(string $slug): void
{
    $slug = normalize_slug($slug);
    if ($slug === '') {
        return;
    }

    $blogRoot = dirname(__DIR__) . '/blog';
    $routeDir = $blogRoot . '/' . $slug;
    $indexPath = $routeDir . '/index.php';

    if (is_file($routeDir . '/index.html') || is_file($indexPath)) {
        return;
    }

    if (!is_dir($routeDir) && !@mkdir($routeDir, 0755, true) && !is_dir($routeDir)) {
        error_log('Blog route directory could not be created for slug: ' . $slug);
        return;
    }

    $contents = "<?php\n"
        . "declare(strict_types=1);\n\n"
        . '$_GET[\'slug\'] = ' . var_export($slug, true) . ";\n"
        . "require __DIR__ . '/../view.php';\n";

    if (@file_put_contents($indexPath, $contents, LOCK_EX) === false) {
        error_log('Blog route file could not be created for slug: ' . $slug);
    }
}

function blog_remove_post_route(string $slug): void
{
    $slug = normalize_slug($slug);
    if ($slug === '') {
        return;
    }

    $routeDir = dirname(__DIR__) . '/blog/' . $slug;
    $indexPath = $routeDir . '/index.php';

    if (is_file($indexPath)) {
        @unlink($indexPath);
    }

    if (is_dir($routeDir)) {
        @rmdir($routeDir);
    }
}

function blogs_upsert_with_pdo(PDO $pdo, array $blog): array
{
    $stmt = $pdo->prepare(
        'INSERT INTO blog_posts (
            id, slug, title, category, author, excerpt, content, featured_image, status, focus_keyphrase, date_label, created_at, updated_at
        ) VALUES (
            :id, :slug, :title, :category, :author, :excerpt, :content, :featured_image, :status, :focus_keyphrase, :date_label, :created_at, :updated_at
        )
        ON CONFLICT(id) DO UPDATE SET
            slug = excluded.slug,
            title = excluded.title,
            category = excluded.category,
            author = excluded.author,
            excerpt = excluded.excerpt,
            content = excluded.content,
            featured_image = excluded.featured_image,
            status = excluded.status,
            focus_keyphrase = excluded.focus_keyphrase,
            date_label = excluded.date_label,
            updated_at = excluded.updated_at'
    );
    $stmt->execute([
        ':id' => $blog['id'],
        ':slug' => $blog['slug'],
        ':title' => $blog['title'],
        ':category' => $blog['category'],
        ':author' => $blog['author'],
        ':excerpt' => $blog['excerpt'],
        ':content' => $blog['content'],
        ':featured_image' => $blog['featuredImage'],
        ':status' => normalize_blog_status($blog['status'] ?? null),
        ':focus_keyphrase' => normalize_focus_keyphrase($blog['focusKeyphrase'] ?? ($blog['focus_keyphrase'] ?? '')),
        ':date_label' => $blog['date'],
        ':created_at' => $blog['createdAt'],
        ':updated_at' => $blog['updatedAt'],
    ]);

    return $blog;
}

function blogs_upsert(array $blog, bool $manageTransaction = true): array
{
    $pdo = blogs_pdo();
    $now = time();
    $existing = blogs_find($blog['id']);
    $oldSlug = $existing['slug'] ?? null;

    $blog['id'] = $existing['id'] ?? $blog['slug'];
    $blog['date'] = $existing['date'] ?? date('M j, Y', $now);
    $blog['status'] = normalize_blog_status($blog['status'] ?? ($existing['status'] ?? null));
    $blog['focusKeyphrase'] = array_key_exists('focusKeyphrase', $blog) || array_key_exists('focus_keyphrase', $blog)
        ? normalize_focus_keyphrase($blog['focusKeyphrase'] ?? ($blog['focus_keyphrase'] ?? ''))
        : normalize_focus_keyphrase($existing['focusKeyphrase'] ?? '');
    $blog['createdAt'] = $existing['createdAt'] ?? $now;
    $blog['updatedAt'] = $now;

    if ($manageTransaction) {
        $pdo->beginTransaction();
    }

    try {
        blogs_upsert_with_pdo($pdo, $blog);

        if ($manageTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $exception) {
        if ($manageTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    if (is_string($oldSlug) && $oldSlug !== $blog['slug']) {
        blog_remove_post_route($oldSlug);
    }
    if ($blog['status'] === 'published') {
        blog_ensure_post_route($blog['slug']);
    } else {
        blog_remove_post_route($blog['slug']);
    }

    return $blog;
}

function blogs_delete(string $id): bool
{
    $post = blogs_find($id);
    $stmt = blogs_pdo()->prepare('DELETE FROM blog_posts WHERE id = :id OR slug = :id');
    $stmt->execute([':id' => normalize_slug($id)]);
    $deleted = $stmt->rowCount() > 0;

    if ($deleted && is_array($post)) {
        blog_remove_post_route($post['slug']);
    }

    return $deleted;
}

function blogs_sync_routes(): int
{
    $count = 0;
    foreach (blogs_all() as $post) {
        blog_ensure_post_route($post['slug']);
        $count++;
    }

    return $count;
}

function normalize_slug(string $value): string
{
    $slug = strtolower(trim($value));
    $slug = preg_replace('/[^a-z0-9-]+/', '-', $slug) ?? '';
    $slug = trim(preg_replace('/-+/', '-', $slug) ?? '', '-');
    return substr($slug, 0, 96);
}

function normalize_featured_image(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return BLOG_DEFAULT_IMAGE;
    }

    if (str_starts_with($value, '/uploads/blogs/') && !str_contains($value, '..') && strlen($value) <= 180) {
        return $value;
    }

    return BLOG_DEFAULT_IMAGE;
}

function request_positive_int(string $key, int $default, int $min, int $max): int
{
    if (!isset($_POST[$key]) || is_array($_POST[$key])) {
        return $default;
    }

    $value = trim((string) $_POST[$key]);
    if (!ctype_digit($value)) {
        return $default;
    }

    return max($min, min($max, (int) $value));
}

function blog_payload_from_post(): array
{
    $title = request_string('title', 160);
    $slug = request_string('slug', 110);
    $category = request_string('category', 32);
    $author = request_string('author', 80) ?? BLOG_DEFAULT_AUTHOR;
    $excerpt = request_string('excerpt', 360);
    $content = request_string('content', 60000);
    $featuredImage = request_string('featured_image', 180) ?? BLOG_DEFAULT_IMAGE;
    $status = normalize_blog_status($_POST['status'] ?? null);
    $focusKeyphrase = request_string('focus_keyphrase', 160) ?? '';
    $existingId = request_string('id', 110);

    $slug = $slug !== null ? normalize_slug($slug) : '';
    if ($slug === '' && $title !== null) {
        $slug = normalize_slug($title);
    }

    $errors = [];
    if ($title === null) {
        $errors[] = 'Title is required.';
    }
    if ($slug === '' || strlen($slug) > 96) {
        $errors[] = 'Slug is invalid.';
    }
    if ($category === null || !in_array($category, BLOG_CATEGORIES, true)) {
        $errors[] = 'Category is invalid.';
    }
    if ($excerpt === null) {
        $errors[] = 'Excerpt is required.';
    }
    if ($content === null) {
        $errors[] = 'Content is required.';
    }

    if ($errors !== []) {
        return ['ok' => false, 'errors' => $errors];
    }

    return [
        'ok' => true,
        'blog' => [
            'id' => $existingId !== null ? normalize_slug($existingId) : $slug,
            'slug' => $slug,
            'title' => $title,
            'category' => $category,
            'author' => $author,
            'excerpt' => $excerpt,
            'content' => $content,
            'featuredImage' => normalize_featured_image($featuredImage),
            'status' => $status,
            'focusKeyphrase' => normalize_focus_keyphrase($focusKeyphrase),
        ],
    ];
}
