<?php
/**
 * Checks that SITE_BASE_URL controls URLs and replaces placeholders/legacy domains while
 * preserving unrelated links.
 *
 * Changes SITE_BASE_URL only in the test process and checks plain URLs and HTML. Literal legacy
 * domains are test inputs, not deployment settings; this script does not edit .env.
 *
 * Run from www: php admin/test/test-site-base-url.php
 * A successful run prints a pass message; failed assertions exit with an error.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/env.php';
function base_check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
// Legacy domains below are test inputs, not deployment settings.
// Verify they resolve against different SITE_BASE_URL values.
foreach (['https://first.example', 'https://second.example/subdir', 'http://freecasinogames.ph', 'https://bygame.ph', 'https://bygame.ph/subdir'] as $base) {
    putenv('SITE_BASE_URL=' . $base . '/');
    base_check(site_base_url() === $base, 'SITE_BASE_URL is authoritative');
    base_check(public_url('/blog/post/') === $base . '/blog/post/', 'Relative URLs follow configuration');
    foreach (['[insert_domain_here]', 'https://[insert_domain_here]', 'http://[insert_domain_here]', '//[insert_domain_here]', 'https://bygame.ph'] as $placeholder) {
        base_check(public_url($placeholder . '/blog/post/') === $base . '/blog/post/', 'Placeholder URL resolves');
        base_check(str_contains(public_html_absolute_urls('<a href="' . $placeholder . '/blog/post/">Post</a>'), 'href="' . $base . '/blog/post/"'), 'HTML placeholder resolves');
    }
    base_check(public_url('https://external.example/article') === 'https://external.example/article', 'External URL preserved');
    base_check(public_url('http://freeonlinegames.info/blog/') === $base . '/blog/', 'Legacy domain follows configuration');
}
base_check(public_url('[insert_domain_here]/post', 'https://override.example') === 'https://override.example/post', 'Explicit base is honored');
echo "Site base URL tests passed.\n";
