<?php
declare(strict_types=1);

require_once __DIR__ . '/env.php';

const BLOG_CATEGORIES = ['Guides', 'Troubleshooting', 'Casino', 'Promotions', 'Community'];
const BLOG_STATUSES = ['published', 'draft'];
const BLOG_DEFAULT_AUTHOR = 'GperyaPH Editorial Team';
const BLOG_DEFAULT_IMAGE = '/uploads/blogs/default-featured.svg';
const BLOG_DEFAULT_SITE_TITLE = 'GperyaPH';

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
            seo_title TEXT NOT NULL DEFAULT "",
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
    $hasSeoTitle = false;
    foreach ($columns as $column) {
        if (($column['name'] ?? null) === 'status') {
            $hasStatus = true;
        }
        if (($column['name'] ?? null) === 'focus_keyphrase') {
            $hasFocusKeyphrase = true;
        }
        if (($column['name'] ?? null) === 'seo_title') {
            $hasSeoTitle = true;
        }
    }
    if (!$hasStatus) {
        $pdo->exec('ALTER TABLE blog_posts ADD COLUMN status TEXT NOT NULL DEFAULT "published"');
    }
    if (!$hasFocusKeyphrase) {
        $pdo->exec('ALTER TABLE blog_posts ADD COLUMN focus_keyphrase TEXT NOT NULL DEFAULT ""');
    }
    if (!$hasSeoTitle) {
        $pdo->exec('ALTER TABLE blog_posts ADD COLUMN seo_title TEXT NOT NULL DEFAULT ""');
        $pdo->exec('UPDATE blog_posts SET seo_title = title WHERE seo_title = ""');
    }
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_blog_posts_updated_at ON blog_posts(updated_at DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_blog_posts_status_updated_at ON blog_posts(status, updated_at DESC)');
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS blog_categories (
            id TEXT PRIMARY KEY,
            name TEXT NOT NULL UNIQUE,
            description TEXT NOT NULL DEFAULT "",
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_blog_categories_sort_order ON blog_categories(sort_order ASC, name ASC)');
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS app_settings (
            setting_key TEXT PRIMARY KEY,
            setting_value TEXT NOT NULL,
            updated_at INTEGER NOT NULL
        )'
    );
    blog_settings_seed($pdo);
    blog_categories_seed($pdo);
}

function blog_settings_seed(PDO $pdo): void
{
    $stmt = $pdo->prepare(
        'INSERT OR IGNORE INTO app_settings (setting_key, setting_value, updated_at)
         VALUES (:setting_key, :setting_value, :updated_at)'
    );
    $stmt->execute([
        ':setting_key' => 'website_title',
        ':setting_value' => BLOG_DEFAULT_SITE_TITLE,
        ':updated_at' => time(),
    ]);
}

function blog_categories_seed(PDO $pdo): void
{
    $now = time();
    $stmt = $pdo->prepare(
        'INSERT OR IGNORE INTO blog_categories (id, name, description, sort_order, created_at, updated_at)
         VALUES (:id, :name, "", :sort_order, :created_at, :updated_at)'
    );

    foreach (BLOG_CATEGORIES as $index => $category) {
        $stmt->execute([
            ':id' => normalize_slug($category),
            ':name' => $category,
            ':sort_order' => ($index + 1) * 10,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }

    $existingStmt = $pdo->query('SELECT DISTINCT category FROM blog_posts WHERE TRIM(category) <> ""');
    foreach ($existingStmt->fetchAll(PDO::FETCH_COLUMN) as $category) {
        $name = normalize_blog_category_name((string) $category);
        if ($name === null) {
            continue;
        }
        $stmt->execute([
            ':id' => normalize_slug($name),
            ':name' => $name,
            ':sort_order' => 1000,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }
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
        'seoTitle' => normalize_seo_title($blog['seoTitle'] ?? ($blog['seo_title'] ?? $title)),
        'category' => normalize_blog_category_name($blog['category'] ?? null) ?? blog_default_category(),
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
        'seoTitle' => normalize_seo_title($row['seo_title'] ?? $row['title']),
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

function normalize_seo_title(mixed $value): string
{
    if (!is_string($value)) {
        return '';
    }

    $value = preg_replace('/\s+/', ' ', trim($value)) ?? '';
    return substr($value, 0, 160);
}

function blog_setting_get(string $key, string $default = ''): string
{
    $stmt = blogs_pdo()->prepare('SELECT setting_value FROM app_settings WHERE setting_key = :setting_key LIMIT 1');
    $stmt->execute([':setting_key' => $key]);
    $value = $stmt->fetchColumn();

    return is_string($value) && $value !== '' ? $value : $default;
}

function blog_setting_set(string $key, string $value, int $maxLength = 255): void
{
    $key = strtolower(trim($key));
    $key = preg_replace('/[^a-z0-9_:-]+/', '_', $key) ?? '';
    $key = trim($key, '_');
    if ($key === '') {
        throw new InvalidArgumentException('Setting key is invalid.');
    }

    $value = preg_replace('/\s+/', ' ', trim($value)) ?? '';
    $value = substr($value, 0, $maxLength);

    $stmt = blogs_pdo()->prepare(
        'INSERT INTO app_settings (setting_key, setting_value, updated_at)
         VALUES (:setting_key, :setting_value, :updated_at)
         ON CONFLICT(setting_key) DO UPDATE SET
            setting_value = excluded.setting_value,
            updated_at = excluded.updated_at'
    );
    $stmt->execute([
        ':setting_key' => $key,
        ':setting_value' => $value,
        ':updated_at' => time(),
    ]);

    blog_clear_api_cache();
}

function blog_website_title(): string
{
    return blog_setting_get('website_title', BLOG_DEFAULT_SITE_TITLE);
}

function normalize_blog_category_name(mixed $value): ?string
{
    if (!is_string($value)) {
        return null;
    }

    $value = preg_replace('/\s+/', ' ', trim($value)) ?? '';
    if ($value === '' || strlen($value) > 50) {
        return null;
    }

    return $value;
}

function blog_default_category(): string
{
    $categories = blog_categories_all();
    $first = $categories[0]['name'] ?? null;
    return is_string($first) && $first !== '' ? $first : BLOG_CATEGORIES[0];
}

function blog_categories_all(bool $includeCounts = false): array
{
    $pdo = blogs_pdo();
    $sql = $includeCounts
        ? 'SELECT c.*, COUNT(p.id) AS post_count
           FROM blog_categories c
           LEFT JOIN blog_posts p ON p.category = c.name
           GROUP BY c.id
           ORDER BY c.sort_order ASC, c.name ASC'
        : 'SELECT *, 0 AS post_count FROM blog_categories ORDER BY sort_order ASC, name ASC';
    $rows = $pdo->query($sql)->fetchAll();

    return array_map(static function (array $row): array {
        return [
            'id' => (string) ($row['id'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'sortOrder' => (int) ($row['sort_order'] ?? 0),
            'postCount' => (int) ($row['post_count'] ?? 0),
            'createdAt' => (int) ($row['created_at'] ?? 0),
            'updatedAt' => (int) ($row['updated_at'] ?? 0),
        ];
    }, $rows);
}

function blog_category_names(): array
{
    return array_map(static fn (array $category): string => $category['name'], blog_categories_all());
}

function blog_categories_page(): array
{
    return blog_categories_all(true);
}

function blog_category_find(string $id): ?array
{
    $id = normalize_slug($id);
    if ($id === '') {
        return null;
    }

    $stmt = blogs_pdo()->prepare(
        'SELECT c.*, COUNT(p.id) AS post_count
         FROM blog_categories c
         LEFT JOIN blog_posts p ON p.category = c.name
         WHERE c.id = :id
         GROUP BY c.id
         LIMIT 1'
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    if (!is_array($row)) {
        return null;
    }

    return [
        'id' => (string) ($row['id'] ?? ''),
        'name' => (string) ($row['name'] ?? ''),
        'description' => (string) ($row['description'] ?? ''),
        'sortOrder' => (int) ($row['sort_order'] ?? 0),
        'postCount' => (int) ($row['post_count'] ?? 0),
        'createdAt' => (int) ($row['created_at'] ?? 0),
        'updatedAt' => (int) ($row['updated_at'] ?? 0),
    ];
}

function blog_category_save(array $input): array
{
    $name = normalize_blog_category_name($input['name'] ?? null);
    if ($name === null) {
        return ['ok' => false, 'errors' => ['Category name is required and must be 50 characters or less.']];
    }

    $existingId = isset($input['id']) && is_string($input['id']) ? normalize_slug($input['id']) : '';
    $id = normalize_slug($name);
    if ($id === '') {
        return ['ok' => false, 'errors' => ['Category slug is invalid.']];
    }

    $description = isset($input['description']) && is_string($input['description'])
        ? substr(trim($input['description']), 0, 180)
        : '';
    $sortOrder = isset($input['sort_order']) && is_numeric($input['sort_order'])
        ? max(0, min(100000, (int) $input['sort_order']))
        : 0;

    $pdo = blogs_pdo();
    $duplicateStmt = $pdo->prepare('SELECT id FROM blog_categories WHERE (id = :id OR lower(name) = lower(:name)) AND id <> :existing_id LIMIT 1');
    $duplicateStmt->execute([
        ':id' => $id,
        ':name' => $name,
        ':existing_id' => $existingId,
    ]);
    if ($duplicateStmt->fetchColumn()) {
        return ['ok' => false, 'errors' => ['A category with this name already exists.']];
    }

    $existing = $existingId !== '' ? blog_category_find($existingId) : null;
    $now = time();
    $pdo->beginTransaction();
    try {
        if ($existing) {
            $stmt = $pdo->prepare(
                'UPDATE blog_categories
                 SET id = :id, name = :name, description = :description, sort_order = :sort_order, updated_at = :updated_at
                 WHERE id = :existing_id'
            );
            $stmt->execute([
                ':id' => $id,
                ':name' => $name,
                ':description' => $description,
                ':sort_order' => $sortOrder,
                ':updated_at' => $now,
                ':existing_id' => $existingId,
            ]);

            if ($existing['name'] !== $name) {
                $postsStmt = $pdo->prepare('UPDATE blog_posts SET category = :new_name, updated_at = :updated_at WHERE category = :old_name');
                $postsStmt->execute([
                    ':new_name' => $name,
                    ':old_name' => $existing['name'],
                    ':updated_at' => $now,
                ]);
            }
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO blog_categories (id, name, description, sort_order, created_at, updated_at)
                 VALUES (:id, :name, :description, :sort_order, :created_at, :updated_at)'
            );
            $stmt->execute([
                ':id' => $id,
                ':name' => $name,
                ':description' => $description,
                ':sort_order' => $sortOrder,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    blog_clear_api_cache();
    return ['ok' => true, 'category' => blog_category_find($id)];
}

function blog_category_delete(string $id): array
{
    $category = blog_category_find($id);
    if (!$category) {
        return ['ok' => false, 'error' => 'Category not found.'];
    }
    if ($category['postCount'] > 0) {
        return ['ok' => false, 'error' => 'Move or edit posts in this category before deleting it.'];
    }
    if (count(blog_categories_all()) <= 1) {
        return ['ok' => false, 'error' => 'At least one category is required.'];
    }

    $stmt = blogs_pdo()->prepare('DELETE FROM blog_categories WHERE id = :id');
    $stmt->execute([':id' => normalize_slug($id)]);
    blog_clear_api_cache();

    return ['ok' => true, 'deleted' => $stmt->rowCount() > 0];
}

function blog_clear_api_cache(): void
{
    $dir = blog_storage_dir() . '/api-cache';
    if (!is_dir($dir)) {
        return;
    }

    foreach (glob($dir . '/*.json') ?: [] as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
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

    foreach (blog_category_names() as $allowedCategory) {
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
            id, slug, title, seo_title, category, author, excerpt, content, featured_image, status, focus_keyphrase, date_label, created_at, updated_at
        ) VALUES (
            :id, :slug, :title, :seo_title, :category, :author, :excerpt, :content, :featured_image, :status, :focus_keyphrase, :date_label, :created_at, :updated_at
        )
        ON CONFLICT(id) DO UPDATE SET
            slug = excluded.slug,
            title = excluded.title,
            seo_title = excluded.seo_title,
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
        ':seo_title' => normalize_seo_title($blog['seoTitle'] ?? ($blog['seo_title'] ?? $blog['title'])),
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
    $blog['seoTitle'] = normalize_seo_title($blog['seoTitle'] ?? ($blog['seo_title'] ?? ($existing['seoTitle'] ?? $blog['title'])));
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
    $seoTitle = request_string('seo_title', 160);
    $slug = request_string('slug', 110);
    $category = request_string('category', 50);
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
    if ($seoTitle === null) {
        $errors[] = 'SEO title is required.';
    }
    if ($slug === '' || strlen($slug) > 96) {
        $errors[] = 'Slug is invalid.';
    }
    if ($category === null || !in_array($category, blog_category_names(), true)) {
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
            'seoTitle' => normalize_seo_title($seoTitle),
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
