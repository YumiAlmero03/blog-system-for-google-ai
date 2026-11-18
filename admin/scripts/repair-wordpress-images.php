<?php
declare(strict_types=1);

/**
 * Repairs images on posts that were imported from a WordPress export.
 *
 *  - Downloads each post's featured image (from the export's _thumbnail_id) and sets it.
 *  - Fixes linked-image Markdown that earlier imports mangled: [!alt(src)](href) -> [![alt](src)](href).
 *  - Downloads remote images used inside post content and points them at local /uploads/blogs/ copies.
 *
 * Only image fields are touched (featured image, image Markdown); text edits are left alone, and a
 * featured image that is already set is never replaced. Safe to re-run: files are content-addressed.
 *
 * Usage:
 *   php admin/scripts/repair-wordpress-images.php --xml=/path/to/export.xml [--post=slug] [--dry-run]
 *   php admin/scripts/repair-wordpress-images.php [--post=slug] [--dry-run]   (content images only)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/blog-import-batches.php';

$options = getopt('', ['xml:', 'post:', 'dry-run', 'help']);
if (isset($options['help'])) {
    echo "Usage: php admin/scripts/repair-wordpress-images.php [--xml=/path/export.xml] [--post=slug] [--dry-run]\n";
    echo "  --xml      WordPress export; needed to restore featured images (content images work without it)\n";
    echo "  --post     Limit to one post slug\n";
    echo "  --dry-run  Report what would change without downloading or saving anything\n";
    exit(0);
}

$dryRun = isset($options['dry-run']);
$onlySlug = isset($options['post']) && is_string($options['post']) ? normalize_slug($options['post']) : '';

try {
    $featuredBySlug = [];
    $slugFilter = null;
    if (isset($options['xml'])) {
        $xmlPath = (string) $options['xml'];
        $xml = is_file($xmlPath) ? file_get_contents($xmlPath) : false;
        if ($xml === false) throw new InvalidArgumentException('Cannot read XML file: ' . $xmlPath);
        $manifest = blog_wordpress_manifest($xml, true);
        $slugFilter = [];
        foreach ($manifest['blogs'] as $record) {
            $slugFilter[$record['slug']] = true;
            if (($record['featuredImage'] ?? '') !== '') $featuredBySlug[$record['slug']] = (string) $record['featuredImage'];
        }
        unset($xml, $manifest);
        echo 'Export: ' . count($slugFilter) . " posts, " . count($featuredBySlug) . " with a featured image.\n";
    }

    $pdo = blogs_pdo();
    $query = 'SELECT id, slug, featured_image, content FROM blog_posts' . ($onlySlug !== '' ? ' WHERE slug = :slug' : '') . ' ORDER BY slug';
    $stmt = $pdo->prepare($query);
    $stmt->execute($onlySlug !== '' ? [':slug' => $onlySlug] : []);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $localize = $dryRun
        ? static fn (string $url): string => '/uploads/blogs/(dry-run)'
        : static fn (string $url): string => blog_import_localize_remote_image($url);

    $work = [];
    foreach ($rows as $row) {
        if ($slugFilter !== null && !isset($slugFilter[$row['slug']])) continue;
        $content = (string) $row['content'];
        $repair = blog_repair_image_markdown($content, $localize);
        $featured = (string) $row['featured_image'];
        $featuredNote = '';
        $featuredIsDefault = $featured === '' || $featured === BLOG_DEFAULT_IMAGE;
        if ($featuredIsDefault && isset($featuredBySlug[$row['slug']])) {
            try {
                $featured = $localize($featuredBySlug[$row['slug']]);
                $featuredNote = 'featured set';
            } catch (Throwable $error) {
                $repair['failures'][] = $featuredBySlug[$row['slug']] . ' (' . $error->getMessage() . ')';
                $featuredNote = 'featured FAILED';
            }
        }
        $work[] = ['row' => $row, 'repair' => $repair, 'featured' => $featured, 'featuredNote' => $featuredNote,
            'changed' => $repair['content'] !== $content || $featured !== (string) $row['featured_image']];
    }

    $changed = array_filter($work, static fn (array $item): bool => $item['changed']);
    if (!$dryRun && $changed) {
        $backup = blogs_db_path() . '.pre-image-repair-' . date('YmdHis');
        try { $pdo->exec("VACUUM INTO '" . str_replace("'", "''", $backup) . "'"); }
        catch (Throwable) { if (!@copy(blogs_db_path(), $backup)) throw new RuntimeException('Could not back up the database; nothing was changed.'); }
        echo "Database backup: $backup\n";
        $update = $pdo->prepare('UPDATE blog_posts SET content = :content, featured_image = :featured, updated_at = :now WHERE id = :id');
        $pdo->beginTransaction();
        try {
            foreach ($changed as $item) {
                $update->execute([':content' => $item['repair']['content'], ':featured' => $item['featured'], ':now' => time(), ':id' => $item['row']['id']]);
            }
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
        require_once __DIR__ . '/../includes/indexing-queue.php';
        indexing_content_changed($pdo, 'blog');
        blog_clear_api_cache();
    }

    $failures = 0;
    foreach ($work as $item) {
        $repair = $item['repair'];
        $failures += count($repair['failures']);
        printf("%-62s %s linked-fixed=%d images-localized=%d %s\n", substr($item['row']['slug'], 0, 62),
            $item['changed'] ? ($dryRun ? 'WOULD UPDATE' : 'UPDATED     ') : 'unchanged   ',
            $repair['fixed'], $repair['localized'], $item['featuredNote']);
        foreach ($repair['failures'] as $failure) echo "    ! $failure\n";
    }
    printf("\n%s: %d of %d posts %s.%s\n", $dryRun ? 'Dry run' : 'Done', count($changed), count($work),
        $dryRun ? 'would change' : 'updated', $failures ? " $failures image(s) failed; re-run to retry." : '');
    exit($failures > 0 ? 1 : 0);
} catch (Throwable $error) {
    fwrite(STDERR, 'Repair failed: ' . $error->getMessage() . "\n");
    exit(1);
}
