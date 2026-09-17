<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/index-checker.php';
try {
    $result=index_checker_run(index_checker_pdo(),index_checker_config());
    echo 'Index Checker: '.$result['state'].'; sitemap URLs: '.($result['sitemap_urls'] ?? 0).'; inspected: '.$result['processed'].".\n";
    exit(in_array($result['state'],['Complete','Already running','Quota deferred'],true) ? 0 : 1);
} catch (Throwable $error) {
    // Never echo remote responses, tokens or private credential contents.
    fwrite(STDERR,"Index Checker failed. Check sitemap availability, private server configuration and PHP error logs.\n"); exit(1);
}
