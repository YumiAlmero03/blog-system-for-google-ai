<?php
/**
 * Tests TagMultiSelect integration for Quick Edit and Publish Blog.
 *
 * Verifies:
 * 1. blogs.php includes tag-multiselect.css and tag-multiselect.js, mounts TagMultiSelect in Quick Edit.
 * 2. blog-publish.php includes assets, mounts TagMultiSelect, and updates on import/load.
 * 3. Saving blog post with tags_present=1 and tag_ids[] attaches tags to the post in DB.
 * 4. Saving blog post with tags_present=1 and empty tag_ids removes tags from the post in DB.
 *
 * Run from www: php admin/test/test-tag-multiselect.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$dir = sys_get_temp_dir() . '/test-tags-' . bin2hex(random_bytes(6));
mkdir($dir, 0700);
putenv('APP_STORAGE_DIR=' . $dir);
putenv('ADMIN_USERNAME=super_user');
putenv('ADMIN_PASSWORD_HASH=' . password_hash('TestPass123!', PASSWORD_DEFAULT));
putenv('ADMIN_JWT_SECRET=' . str_repeat('test-jwt-secret-', 4));
ini_set('session.save_path', $dir);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/blog-storage.php';
require_once __DIR__ . '/../includes/blog-taxonomy.php';

function test_assert(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
}

// 1. Verify static markup & asset references in blogs.php and blog-publish.php
$blogsPhp = file_get_contents(__DIR__ . '/../blogs.php');
test_assert(str_contains($blogsPhp, '/admin/tag-multiselect.css'), 'blogs.php includes tag-multiselect.css');
test_assert(str_contains($blogsPhp, '/admin/tag-multiselect.js'), 'blogs.php includes tag-multiselect.js');
test_assert(str_contains($blogsPhp, 'quick-edit-tags-mount'), 'blogs.php has quick-edit-tags-mount container');
test_assert(str_contains($blogsPhp, 'new window.TagMultiSelect(mount'), 'blogs.php initializes TagMultiSelect for quick edit');

$publishPhp = file_get_contents(__DIR__ . '/../blog-publish.php');
test_assert(str_contains($publishPhp, '/admin/tag-multiselect.css'), 'blog-publish.php includes tag-multiselect.css');
test_assert(str_contains($publishPhp, '/admin/tag-multiselect.js'), 'blog-publish.php includes tag-multiselect.js');
test_assert(str_contains($publishPhp, 'id="blog-tags-multiselect"'), 'blog-publish.php has #blog-tags-multiselect container');
test_assert(str_contains($publishPhp, 'tagMultiSelectInstance = new window.TagMultiSelect'), 'blog-publish.php initializes TagMultiSelect');
test_assert(str_contains($publishPhp, 'tagMultiSelectInstance.setAvailableTags'), 'blog-publish.php updates tags in populateEditorFromBlog');
test_assert(str_contains($publishPhp, 'tagMultiSelectInstance.setSelected([])'), 'blog-publish.php resets tags in resetForm');

// 2. Verify backend taxonomy assignment via blogs_upsert
$pdo = blogs_pdo();
blog_taxonomy_import_category($pdo, 'General');
$categories = blog_categories_all();
$categoryId = $categories[0]['id'] ?? 'general';

// Create sample tags
$tag1Id = 'tag-promo';
$tag2Id = 'tag-tips';
$pdo->prepare('INSERT INTO tags (id, name, slug, created_at, updated_at) VALUES (?, ?, ?, ?, ?)')->execute([$tag1Id, 'Promotions', 'promotions', time(), time()]);
$pdo->prepare('INSERT INTO tags (id, name, slug, created_at, updated_at) VALUES (?, ?, ?, ?, ?)')->execute([$tag2Id, 'Tips & Tricks', 'tips-tricks', time(), time()]);

$postSlug = 'test-post-tags';
$postData = [
    'id' => $postSlug,
    'slug' => $postSlug,
    'title' => 'Test Post with Multi Select Tags',
    'content' => 'Sample content for testing multi-select tags.',
    'category' => 'General',
    'categoryId' => $categoryId,
    'author' => 'Editorial Team',
    'excerpt' => 'Test excerpt',
    'featuredImage' => '/uploads/blogs/default-featured.svg',
    'status' => 'published',
    'tagIds' => [$tag1Id, $tag2Id],
];

$saved = blogs_upsert($postData);
test_assert(!empty($saved['id']), 'Post saved successfully');

$loaded = blogs_find($postSlug);
test_assert($loaded !== null, 'Loaded post is not null');
test_assert(count($loaded['tags']) === 2, 'Loaded post has 2 tags');
test_assert(in_array($tag1Id, $loaded['tagIds'], true), 'Post has tag-promo');
test_assert(in_array($tag2Id, $loaded['tagIds'], true), 'Post has tag-tips');

// 3. Test updating with only 1 tag selected
$updateData = array_merge($loaded, [
    'title' => 'Updated Post with 1 Tag',
    'tagIds' => [$tag1Id],
]);
blogs_upsert($updateData);

$reloaded = blogs_find($postSlug);
test_assert(count($reloaded['tags']) === 1, 'Post now has 1 tag');
test_assert($reloaded['tagIds'] === [$tag1Id], 'Post only has tag-promo');

// 4. Test clearing all tags (tagIds = [])
$clearData = array_merge($reloaded, [
    'title' => 'Post with Cleared Tags',
    'tagIds' => [],
]);
blogs_upsert($clearData);

$cleared = blogs_find($postSlug);
test_assert(count($cleared['tags']) === 0, 'Post tags successfully cleared');
test_assert($cleared['tagIds'] === [], 'Post tagIds is empty array');

echo "All TagMultiSelect integration tests passed successfully.\n";
