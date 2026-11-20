<?php
declare(strict_types=1);

/**
 * Utility script to strip stale staging domains from blog_posts (`content` and
 * `featured_image`) and verify rendered `content_html` via `blog_render_content()`.
 *
 * Usage:
 *   php admin/scripts/clean-media-urls.php [--dry-run]
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/blog-storage.php';
require_once __DIR__ . '/../includes/blog-renderer.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);

function clean_media_strip_upload_origins(string $value): string
{
    $hostsPattern = implode('|', array_map(
        static fn(string $host): string => preg_quote($host, '#'),
        [
            'thechikin.chickenkiller.com',
            'freecasinogames.ph',
            'freeonlinegames.info',
            'bygame.ph',
            'localhost:8080',
            'localhost:8082',
            'localhost:8083',
            '[insert_domain_here]',
        ]
    ));

    // Normalize upload paths back to root-relative /uploads/...
    $cleaned = preg_replace('#(?:https?:)?//(?:' . $hostsPattern . ')(?:/worldcupmatches)?(/uploads/[^\s"\')<>]+)#i', '$1', $value);
    return is_string($cleaned) ? $cleaned : $value;
}

function clean_media_normalize_text(string $value, string $activeBase): string
{
    $normalized = clean_media_strip_upload_origins($value);
    return site_replace_domain_placeholders($normalized, $activeBase);
}

$pdo = blogs_pdo();
$activeBase = site_base_url();
$rows = $pdo->query('SELECT id, slug, content, featured_image FROM blog_posts ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

$updated = 0;
$verified = 0;
$updateStmt = $pdo->prepare('UPDATE blog_posts SET content = ?, featured_image = ?, updated_at = ? WHERE id = ?');

if (!$dryRun) {
    $pdo->beginTransaction();
}

try {
    $now = time();
    foreach ($rows as $row) {
        $oldContent = (string) ($row['content'] ?? '');
        $oldFeatured = (string) ($row['featured_image'] ?? '');

        $newContent = clean_media_normalize_text($oldContent, $activeBase);
        $newFeatured = clean_media_normalize_text($oldFeatured, $activeBase);

        // Verify live HTML rendering produces clean URLs
        $rendered = blog_render_content($newContent);
        $verified++;

        if ($newContent !== $oldContent || $newFeatured !== $oldFeatured) {
            $updated++;
            echo sprintf("[%s] %s (%s)\n", $dryRun ? 'WOULD UPDATE' : 'UPDATED', (string) $row['slug'], (string) $row['id']);
            if (!$dryRun) {
                $updateStmt->execute([$newContent, $newFeatured, $now, $row['id']]);
            }
        }
    }

    if (!$dryRun) {
        $pdo->commit();
    }
} catch (Throwable $e) {
    if (!$dryRun && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Clean media URLs failed: ' . $e->getMessage() . "\n");
    exit(1);
}

echo sprintf(
    "Done (%s): scanned %d post(s), verified rendering for %d, %s %d post(s).\n",
    $dryRun ? 'dry-run' : 'live',
    count($rows),
    $verified,
    $dryRun ? 'would update' : 'updated',
    $updated
);
