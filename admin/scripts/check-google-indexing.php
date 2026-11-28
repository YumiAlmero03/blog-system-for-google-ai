<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/index-checker.php';
require_once __DIR__ . '/../includes/indexnow.php';
$failed=false;
try {
    $pdo=index_checker_pdo();
    indexing_reconcile($pdo,'blog'); indexing_reconcile($pdo,'game');
} catch (Throwable $error) {
    fwrite(STDERR,"Indexing queue reconciliation failed. Check private server logs and database availability.\n"); exit(1);
}
try { indexing_google_sitemap_job($pdo); }
catch (Throwable $error) { $failed=true; error_log('Google sitemap submission deferred; IndexNow remains independent.'); }
try {
    indexing_google_events($pdo);
    $result=index_checker_run($pdo,index_checker_config());
    echo 'Index Checker: '.$result['state'].'; sitemap URLs: '.($result['sitemap_urls'] ?? 0).'; inspected: '.$result['processed'].".\n";
    if (!in_array($result['state'],['Complete','Already running','Quota deferred'],true)) $failed=true;
} catch (Throwable $error) {
    $failed=true; fwrite(STDERR,"Google inspection failed. Check private configuration and server logs.\n");
}
try { echo 'IndexNow: '.indexnow_process_queue($pdo)." URL notification(s) accepted.\n"; }
catch (Throwable $error) { $failed=true; error_log('IndexNow worker failed; queued notifications retained.'); }
exit($failed?1:0);
