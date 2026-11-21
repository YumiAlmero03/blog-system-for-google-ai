<?php
/**
 * Test ignored IP filtering and pruning for Play Now tracker and promo links.
 *
 * Usage: php admin/test/test-playnow-ignored-ips.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$tempDir = sys_get_temp_dir() . '/test-playnow-ignored-' . bin2hex(random_bytes(6));
if (!mkdir($tempDir, 0700, true)) {
    throw new RuntimeException('Failed to create temp directory.');
}

register_shutdown_function(static function () use ($tempDir): void {
    $cleanup = static function ($dir, $self) {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $self($path, $self) : @unlink($path);
        }
        @rmdir($dir);
    };
    $cleanup($tempDir, $cleanup);
});

putenv('APP_STORAGE_DIR=' . $tempDir);

require_once __DIR__ . '/../includes/blog-storage.php';
require_once __DIR__ . '/../includes/playnow-promos.php';

function assert_test(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $message);
    }
}

// 1. Verify fresh storage is lazy when an ignored IP sends a click
blog_setting_set_multiline('ignored_engagement_ips', "127.0.0.1\n10.0.0.0/24");
$clicksFile = $tempDir . '/playnow-clicks.json';
assert_test(!file_exists($clicksFile), 'playnow-clicks.json must not exist initially');

// Simulate POST from ignored IP (127.0.0.1)
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
unset($_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_REAL_IP'], $_SERVER['HTTP_X_FORWARDED_FOR']);

ob_start();
// Run playnow-click logic in-process or via require
$payload = [
    'pageUrl' => 'https://example.com/slots',
    'pagePath' => '/slots',
    'buttonText' => 'Play Now',
];
// Test via executing api/playnow-click.php
$cmd = sprintf(
    'APP_STORAGE_DIR=%s php -r %s',
    escapeshellarg($tempDir),
    escapeshellarg('
        $_SERVER["REQUEST_METHOD"] = "POST";
        $_SERVER["REMOTE_ADDR"] = "127.0.0.1";
        $_POST = ["pageUrl" => "https://example.com/slots", "pagePath" => "/slots", "buttonText" => "Play Now"];
        require "' . dirname(__DIR__, 2) . '/api/playnow-click.php";
    ')
);
$output = shell_exec($cmd);
$res = json_decode((string) $output, true);
assert_test(is_array($res) && ($res['ok'] ?? false) === true, 'Ignored IP click should return ok: true');
assert_test(($res['ignored'] ?? false) === true, 'Ignored IP click should return ignored: true');
assert_test(!file_exists($clicksFile), 'playnow-clicks.json must remain lazy and not be created by ignored IP');

// 2. Normal IP records click
$cmdNormal = sprintf(
    'APP_STORAGE_DIR=%s php -r %s',
    escapeshellarg($tempDir),
    escapeshellarg('
        $_SERVER["REQUEST_METHOD"] = "POST";
        $_SERVER["REMOTE_ADDR"] = "203.0.113.5";
        $_POST = ["pageUrl" => "https://example.com/slots", "pagePath" => "/slots", "buttonText" => "Play Now"];
        require "' . dirname(__DIR__, 2) . '/api/playnow-click.php";
    ')
);
$outputNormal = shell_exec($cmdNormal);
$resNormal = json_decode((string) $outputNormal, true);
assert_test(is_array($resNormal) && ($resNormal['ok'] ?? false) === true, 'Normal IP click should succeed');
assert_test(!isset($resNormal['ignored']), 'Normal IP click should not be flagged ignored');
assert_test(($resNormal['totalClicks'] ?? 0) === 1, 'Normal IP click increments totalClicks to 1');
assert_test(file_exists($clicksFile), 'playnow-clicks.json created after valid click');

$data = json_decode((string) file_get_contents($clicksFile), true);
assert_test(($data['totalClicks'] ?? 0) === 1, 'Stored totalClicks is 1');
assert_test(isset($data['byIp']['203.0.113.5']), 'byIp contains normal IP');
assert_test(count($data['recent']) === 1, 'recent contains 1 event');

// 3. Ignored IP click when file already exists
$cmdIgnoredAfter = sprintf(
    'APP_STORAGE_DIR=%s php -r %s',
    escapeshellarg($tempDir),
    escapeshellarg('
        $_SERVER["REQUEST_METHOD"] = "POST";
        $_SERVER["REMOTE_ADDR"] = "10.0.0.42"; // Matches 10.0.0.0/24
        $_POST = ["pageUrl" => "https://example.com/slots", "pagePath" => "/slots", "buttonText" => "Play Now"];
        require "' . dirname(__DIR__, 2) . '/api/playnow-click.php";
    ')
);
$outputIgnoredAfter = shell_exec($cmdIgnoredAfter);
$resIgnoredAfter = json_decode((string) $outputIgnoredAfter, true);
assert_test(($resIgnoredAfter['ignored'] ?? false) === true, 'CIDR matched IP click should return ignored: true');
assert_test(($resIgnoredAfter['totalClicks'] ?? 0) === 1, 'Ignored IP click does not increment totalClicks');

$dataAfter = json_decode((string) file_get_contents($clicksFile), true);
assert_test(($dataAfter['totalClicks'] ?? 0) === 1, 'Stored totalClicks remains 1');
assert_test(!isset($dataAfter['byIp']['10.0.0.42']), 'Ignored IP not in byIp');
assert_test(count($dataAfter['recent']) === 1, 'recent still has 1 event');

// 4. Promo code clicks with ignored IP
$promo = playnow_promo_create('/playnow', 'Test Promo', 'Desc', '', 'testcode123');
assert_test($promo['code'] === 'testcode123', 'Promo created');

// Click from normal IP
$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
$clickedPromo = playnow_promo_record_click('testcode123');
assert_test(($clickedPromo['clicks'] ?? 0) === 1, 'Normal promo click increments promo clicks');

$dataPromo = json_decode((string) file_get_contents($clicksFile), true);
assert_test(($dataPromo['totalClicks'] ?? 0) === 2, 'totalClicks is now 2');

// Click from ignored IP (CIDR 10.0.0.50)
$_SERVER['REMOTE_ADDR'] = '10.0.0.50';
$ignoredPromoClick = playnow_promo_record_click('testcode123');
assert_test(($ignoredPromoClick['clicks'] ?? 0) === 1, 'Ignored promo click does not increment promo clicks');

$dataPromo2 = json_decode((string) file_get_contents($clicksFile), true);
assert_test(($dataPromo2['totalClicks'] ?? 0) === 2, 'totalClicks remains 2 after ignored promo click');

// 5. Test Pruning of historical clicks when an IP is subsequently added to Ignored IPs
// First, add clicks from a new IP 198.51.100.22
$cmdHist = sprintf(
    'APP_STORAGE_DIR=%s php -r %s',
    escapeshellarg($tempDir),
    escapeshellarg('
        $_SERVER["REQUEST_METHOD"] = "POST";
        $_SERVER["REMOTE_ADDR"] = "198.51.100.22";
        $_POST = ["pageUrl" => "https://example.com/slots", "pagePath" => "/slots", "buttonText" => "Play Now"];
        require "' . dirname(__DIR__, 2) . '/api/playnow-click.php";
    ')
);
shell_exec($cmdHist);

$dataPrePrune = json_decode((string) file_get_contents($clicksFile), true);
assert_test(($dataPrePrune['totalClicks'] ?? 0) === 3, 'Pre-prune totalClicks is 3');
assert_test(isset($dataPrePrune['byIp']['198.51.100.22']), 'Pre-prune byIp contains 198.51.100.22');

// Now add 198.51.100.22 to ignored IPs
blog_setting_set_multiline('ignored_engagement_ips', "127.0.0.1\n10.0.0.0/24\n198.51.100.22");
$pruned = playnow_tracker_sync_ignored();
assert_test($pruned === true, 'playnow_tracker_sync_ignored should prune data and return true');

$dataPostPrune = json_decode((string) file_get_contents($clicksFile), true);
assert_test(($dataPostPrune['totalClicks'] ?? 0) === 2, 'Post-prune totalClicks decremented back to 2');
assert_test(!isset($dataPostPrune['byIp']['198.51.100.22']), '198.51.100.22 removed from byIp');
$recentIps = array_column($dataPostPrune['recent'], 'ip');
assert_test(!in_array('198.51.100.22', $recentIps, true), '198.51.100.22 removed from recent events');

echo "All playnow ignored IP tests passed.\n";
