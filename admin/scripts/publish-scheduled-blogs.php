<?php
declare(strict_types=1);

/**
 * CLI / Cron script to publish due scheduled blog posts, clear the API cache,
 * and notify the indexing queue.
 *
 * Usage:
 *   php admin/scripts/publish-scheduled-blogs.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/blog-storage.php';

try {
    $now = time();
    $pdo = blogs_pdo();
    blogs_publish_due_scheduled($pdo, $now);
    $publishedCount = (int) ($GLOBALS['blogs_published_scheduled_count'] ?? 0);

    $upcomingStmt = $pdo->prepare(
        'SELECT slug, scheduled_at
         FROM blog_posts
         WHERE status = "scheduled"
           AND scheduled_at IS NOT NULL
           AND scheduled_at > :now
         ORDER BY scheduled_at ASC'
    );
    $upcomingStmt->execute([':now' => $now]);
    $upcoming = $upcomingStmt->fetchAll(PDO::FETCH_ASSOC);

    $tz = blog_display_timezone();
    $nowFormatted = (new DateTimeImmutable('@' . $now))->setTimezone($tz)->format('Y-m-d H:i:s T');

    echo sprintf(
        "Blog scheduler [%s]: published %d due post(s), %d upcoming scheduled post(s).\n",
        $nowFormatted,
        $publishedCount,
        count($upcoming)
    );

    foreach ($upcoming as $row) {
        $when = (new DateTimeImmutable('@' . (int) $row['scheduled_at']))->setTimezone($tz)->format('Y-m-d H:i:s T');
        echo sprintf("  - %s (scheduled for %s)\n", (string) $row['slug'], $when);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Blog scheduler error: ' . $e->getMessage() . "\n");
    exit(1);
}
