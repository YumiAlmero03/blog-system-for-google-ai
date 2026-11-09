<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/blog-storage.php';
require_once __DIR__ . '/includes/blog-wordpress-import.php';
require_once __DIR__ . '/includes/blog-taxonomy.php';

require_auth();
require_post();
require_valid_csrf();

header('Content-Type: application/json; charset=UTF-8');

try {
    $rawContent = '';
    $file = $_FILES['import_file'] ?? null;
    if (is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        if (($file['error'] ?? 0) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || (!is_uploaded_file($file['tmp_name']) && PHP_SAPI !== 'cli')) {
            throw new InvalidArgumentException('Upload failed. Please select the file again.');
        }
        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if ($ext !== 'json') {
            throw new InvalidArgumentException('Only JSON format is accepted. Please upload a .json file.');
        }
        if ((int) ($file['size'] ?? 0) <= 0 || (int) $file['size'] > 10 * 1024 * 1024) {
            throw new InvalidArgumentException('Select an import JSON file up to 10 MB.');
        }
        $rawContent = (string) file_get_contents($file['tmp_name']);
    } else {
        $rawInput = file_get_contents('php://input');
        if (trim($rawInput) !== '') {
            $rawContent = $rawInput;
        } elseif (isset($_POST['json_data']) && is_string($_POST['json_data'])) {
            $rawContent = $_POST['json_data'];
        } else {
            throw new InvalidArgumentException('Please select a JSON file to import.');
        }
    }

    if (!is_string($rawContent) || trim($rawContent) === '') {
        throw new InvalidArgumentException('The selected JSON file is empty.');
    }

    // Strip UTF-8 BOM if present
    $rawContent = preg_replace('/^\xEF\xBB\xBF/', '', $rawContent) ?? $rawContent;

    $json = json_decode($rawContent, true);
    if (!is_array($json) || array_is_list($json)) {
        throw new InvalidArgumentException('Invalid post JSON format: file must contain a JSON object.');
    }

    $title = trim((string) ($json['title'] ?? ''));
    if ($title === '') {
        throw new InvalidArgumentException('Invalid post JSON format: missing or empty "title".');
    }

    $contentHtml = isset($json['content_html']) && is_string($json['content_html']) ? trim($json['content_html']) : '';
    $contentMarkdown = isset($json['content']) && is_string($json['content']) ? trim($json['content']) : '';

    if ($contentHtml === '' && $contentMarkdown === '') {
        throw new InvalidArgumentException('Invalid post JSON format: missing "content_html" or "content".');
    }

    if (isset($json['schema_version']) && (!is_int($json['schema_version']) && !ctype_digit((string) $json['schema_version']))) {
        throw new InvalidArgumentException('Invalid post JSON format: invalid "schema_version".');
    }

    if (isset($json['tags']) && !is_array($json['tags'])) {
        throw new InvalidArgumentException('Invalid post JSON format: "tags" must be an array.');
    }

    // Convert HTML to Markdown if content_html is provided
    if ($contentHtml !== '') {
        $content = blog_wordpress_markdown($contentHtml);
    } else {
        $content = $contentMarkdown;
        if (stripos($content, '<table') !== false && stripos($content, '</table>') !== false) {
            $content = preg_replace_callback('/<table\b[^>]*>[\s\S]*?<\/table>/i', static function (array $match): string {
                return blog_wordpress_markdown($match[0]);
            }, $content) ?? $content;
        }
        if (stripos($content, '<details') !== false && stripos($content, '</details>') !== false) {
            $content = preg_replace_callback('/(?:<details\b[^>]*>[\s\S]*?<\/details>\s*)+/i', static function (array $match): string {
                return blog_wordpress_markdown($match[0]);
            }, $content) ?? $content;
        }
        $content = blog_convert_markdown_faqs($content);
    }

    $pdo = blogs_pdo();

    // Process Category
    $categoryName = trim((string) ($json['category'] ?? 'General'));
    if ($categoryName === '') {
        $categoryName = 'General';
    }
    blog_taxonomy_import_category($pdo, $categoryName);
    $allCategories = blog_categories_all();
    $categoryId = '';
    foreach ($allCategories as $cat) {
        if (strcasecmp($cat['name'], $categoryName) === 0 || strcasecmp($cat['id'], $categoryName) === 0 || strcasecmp($cat['slug'], $categoryName) === 0) {
            $categoryId = $cat['id'];
            $categoryName = $cat['name'];
            break;
        }
    }
    if ($categoryId === '') {
        $categoryId = normalize_slug($categoryName) ?: 'general';
    }

    // Process Tags
    $rawTags = is_array($json['tags'] ?? null) ? $json['tags'] : [];
    $tagIds = [];
    $resolvedTags = [];
    foreach ($rawTags as $tagItem) {
        $tagName = trim((string) $tagItem);
        if ($tagName === '') continue;
        $tagSlug = normalize_slug($tagName);
        if ($tagSlug === '') continue;

        $stmt = $pdo->prepare('SELECT id, name, slug FROM tags WHERE slug = ? OR lower(name) = lower(?)');
        $stmt->execute([$tagSlug, mb_substr($tagName, 0, 80)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $tagId = (string) $row['id'];
            $resolvedTags[] = $row;
        } else {
            $tagId = bin2hex(random_bytes(16));
            $cleanName = mb_substr($tagName, 0, 80);
            $ins = $pdo->prepare('INSERT INTO tags(id, name, slug, created_at, updated_at) VALUES (?, ?, ?, ?, ?)');
            $ins->execute([$tagId, $cleanName, $tagSlug, time(), time()]);
            $resolvedTags[] = ['id' => $tagId, 'name' => $cleanName, 'slug' => $tagSlug];
        }
        if (!in_array($tagId, $tagIds, true)) {
            $tagIds[] = $tagId;
        }
    }

    // Process Slug
    $rawSlug = isset($json['slug']) && is_string($json['slug']) ? trim($json['slug']) : '';
    $slug = normalize_slug($rawSlug !== '' ? $rawSlug : $title);
    if ($slug === '') {
        $slug = 'post-' . bin2hex(random_bytes(4));
    }

    // Process Status
    $rawStatus = strtolower(trim((string) ($json['status'] ?? 'draft')));
    $status = in_array($rawStatus, ['draft', 'published', 'scheduled'], true) ? $rawStatus : 'draft';

    // Meta Description / Excerpt
    $excerpt = trim((string) ($json['meta_description'] ?? $json['excerpt'] ?? ''));

    // Primary Keyword / Focus Keyphrase
    $focusKeyphrase = trim((string) ($json['primary_keyword'] ?? $json['focus_keyphrase'] ?? $json['focusKeyphrase'] ?? ''));

    // SEO Title
    $seoTitle = trim((string) ($json['seo_title'] ?? $json['seoTitle'] ?? ''));
    if ($seoTitle === '') {
        $seoTitle = $title . ' | ' . blog_website_title();
    }

    // Author
    $author = trim((string) ($json['author'] ?? $json['brand'] ?? ''));
    if ($author === '') {
        $author = BLOG_DEFAULT_AUTHOR;
    }

    // Featured Image
    $featuredImage = trim((string) ($json['featured_image'] ?? $json['featuredImage'] ?? ''));
    if ($featuredImage === '') {
        $featuredImage = BLOG_DEFAULT_IMAGE;
    }

    // Check if existing post with this slug or id exists
    $existing = blogs_find($slug);
    $postId = $existing['id'] ?? $slug;

    // Published / Scheduled dates
    $dateParser = static function (mixed $value): ?int {
        if ($value === null || $value === '') return null;
        if (is_int($value) && $value > 0) return $value;
        if (is_string($value) && !str_starts_with($value, '0000-')) {
            $ts = strtotime($value . (preg_match('/[Z+-]\d{2}/', $value) ? '' : ' UTC'));
            return $ts ?: (strtotime($value) ?: null);
        }
        return null;
    };
    $publishedAt = $dateParser($json['published_at'] ?? ($json['publishedAt'] ?? ($json['published_date'] ?? null)));
    $scheduledAt = $dateParser($json['scheduled_at'] ?? ($json['scheduledAt'] ?? null));

    $blogData = [
        'id' => $postId,
        'slug' => $slug,
        'title' => $title,
        'seoTitle' => $seoTitle,
        'category' => $categoryName,
        'categoryId' => $categoryId,
        'tagIds' => $tagIds,
        'author' => $author,
        'excerpt' => $excerpt,
        'content' => $content,
        'featuredImage' => $featuredImage,
        'status' => $status,
        'focusKeyphrase' => $focusKeyphrase,
        'publishedAt' => $publishedAt,
        'scheduledAt' => $scheduledAt,
    ];

    $saved = blogs_upsert($blogData);
    $loaded = blogs_find($saved['id']) ?? $saved;

    csrf_rotate();
    echo json_encode([
        'ok' => true,
        'blog' => $loaded,
        'allTags' => blog_tags_all(),
        'allCategories' => blog_categories_all(),
        'csrfToken' => csrf_token(),
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    http_response_code($error instanceof InvalidArgumentException ? 422 : 500);
    echo json_encode([
        'ok' => false,
        'error' => $error->getMessage() ?: 'Post import failed.',
        'csrfToken' => csrf_token(),
    ], JSON_UNESCAPED_SLASHES);
}
