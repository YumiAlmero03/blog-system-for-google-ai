<?php
/**
 * Checks bounded ZIP imports, image restoration, progress, retries, and duplicate prevention.
 *
 * Builds ZIP fixtures and imports them into a temporary database; creates and removes test upload
 * images. Requires SQLite, ZIP, and GD. An optional XML path tests the three-post WordPress
 * sample.
 *
 * Run from www: php admin/test/test-blog-import-batches.php
 * A successful run prints a pass message; failed assertions exit with an error.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$directory = sys_get_temp_dir() . '/test-blog-batches-' . bin2hex(random_bytes(6));
mkdir($directory, 0700);
putenv('APP_STORAGE_DIR=' . $directory);
putenv('SITE_BASE_URL=https://example.test');
require_once __DIR__ . '/../includes/blog-import-batches.php';
function batch_check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function batch_remove(string $path): void {
    foreach (scandir($path) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $child = $path . '/' . $name;
        is_dir($child) ? batch_remove($child) : unlink($child);
    }
    rmdir($path);
}
$pdo = null;
try {
    $pdo = blogs_pdo();
    $posts = [];
    for ($i = 0; $i < 25; $i++) $posts[] = ['id' => 'batch-' . $i, 'slug' => 'batch-' . $i, 'title' => 'Batch ' . $i, 'content' => 'Content', 'status' => 'draft', 'category' => 'Guides'];
    $posts[] = ['slug' => 'invalid'];
    $zip = new ZipArchive();
    $zip->open($directory . '/posts.zip', ZipArchive::CREATE);
    $zip->addFromString('blogs.json', json_encode(['format' => 'blogs-export-v1', 'blogs' => $posts]));
    $zip->close();
    $progress = blog_import_start($pdo, $directory . '/posts.zip', false, 'owner');
    batch_check(!$progress['done'] && $progress['processed'] === 0, 'Upload stages without importing posts');
    try { blog_import_batch($pdo, $progress['jobId'], 'different-owner', 0); throw new RuntimeException('Cross-session access accepted'); }
    catch (InvalidArgumentException $e) {}
    $first = blog_import_batch($pdo, $progress['jobId'], 'owner', 0);
    batch_check($first['processed'] > 0 && $first['processed'] <= 10 && !$first['done'], 'First batch is bounded');
    $retry = blog_import_batch($pdo, $progress['jobId'], 'owner', 0);
    batch_check($retry === $first, 'Retry returns committed progress without duplicate writes');
    $progress = $first;
    while (!$progress['done']) {
        $next = blog_import_batch($pdo, $progress['jobId'], 'owner', $progress['revision']);
        batch_check($next['processed'] - $progress['processed'] <= 10, 'Every post batch is bounded');
        $progress = $next;
    }
    batch_check($progress['counts']['imported'] === 25 && $progress['counts']['failed'] === 1, 'Cumulative counts include invalid post');
    batch_check((int) $pdo->query("SELECT COUNT(*) FROM blog_posts WHERE id LIKE 'batch-%'")->fetchColumn() === 25, 'All posts persisted');
    batch_check(blog_import_batch($pdo, $progress['jobId'], 'owner', $progress['revision']) === $progress, 'Finished jobs can be safely retried');
    $state = json_decode($pdo->query("SELECT state FROM blog_import_jobs WHERE id='" . $progress['jobId'] . "'")->fetchColumn(), true);
    batch_check(!is_dir($state['directory']), 'Finished staging files cleaned up');
    $repeat = blog_import_start($pdo, $directory . '/posts.zip', false, 'owner');
    while (!$repeat['done']) $repeat = blog_import_batch($pdo, $repeat['jobId'], 'owner', $repeat['revision']);
    batch_check($repeat['counts']['updated'] === 25 && $repeat['counts']['imported'] === 0, 'Reupload updates instead of duplicating');
    $imageZip = new ZipArchive();
    $imageZip->open($directory . '/images.zip', ZipArchive::CREATE);
    $imageZip->addFromString('blogs.json', json_encode(['format' => 'blogs-export-v1', 'blogs' => [['id' => 'image-post', 'slug' => 'image-post', 'title' => 'Images', 'content' => '![test](/uploads/blogs/image-0.png)', 'featuredImage' => '/uploads/blogs/image-0.png', 'status' => 'draft']]]));
    $fixture = imagecreatetruecolor(16, 16);
    imagepng($fixture, $directory . '/fixture.png');
    for ($i = 0; $i < 4; $i++) $imageZip->addFile($directory . '/fixture.png', 'images/image-' . $i . '.png');
    $imageZip->close();
    $images = blog_import_start($pdo, $directory . '/images.zip', false, 'owner');
    $images = blog_import_batch($pdo, $images['jobId'], 'owner', 0);
    batch_check($images['imagesProcessed'] > 0 && $images['imagesProcessed'] <= 3 && $images['processed'] === 0, 'Images use separate bounded batches');
    while (!$images['done']) $images = blog_import_batch($pdo, $images['jobId'], 'owner', $images['revision']);
    batch_check($images['counts']['imagesRestored'] === 4 && $images['counts']['imported'] === 1, 'All ZIP images and post restored');
    $imagePost = $pdo->query("SELECT content,featured_image FROM blog_posts WHERE id='image-post'")->fetch();
    batch_check(str_contains($imagePost['content'], $imagePost['featured_image']) && $imagePost['featured_image'] !== '/uploads/blogs/image-0.png', 'Post uses restored image mapping');
    if (isset($argv[1])) {
        $wp = blog_import_start($pdo, $argv[1], true, 'owner');
        while (!$wp['done']) $wp = blog_import_batch($pdo, $wp['jobId'], 'owner', $wp['revision']);
        batch_check($wp['counts']['imported'] === 3 && $wp['counts']['failed'] === 0, 'Provided WordPress file imports through batches');
        batch_check((int) $pdo->query('SELECT COUNT(*) FROM blog_post_tags')->fetchColumn() > 0, 'WordPress tags persisted');
    }
    echo "Batch import tests passed.\n";
} finally {
    if ($pdo instanceof PDO) {
        foreach ($pdo->query('SELECT state FROM blog_import_jobs')->fetchAll() as $row) {
            $state = json_decode($row['state'], true);
            foreach (array_unique($state['imageMap']) as $path) {
                @unlink(dirname(__DIR__) . $path);
                @unlink(dirname(__DIR__, 2) . $path);
            }
            blog_import_cleanup($state);
        }
    }
    batch_remove($directory);
}
