<?php
/**
 * Tests Play Now Tracker default period (today), hourly/daily/monthly graph bucketing,
 * ignored-IP filtering without unnecessary rewrites, 2,000-event performance,
 * and authenticated rendered HTML output.
 *
 * Run from www: php admin/test/test-playnow-tracker-chart.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$dir = sys_get_temp_dir() . '/playnow-chart-' . bin2hex(random_bytes(6));
mkdir($dir, 0700);
putenv('APP_STORAGE_DIR=' . $dir);
putenv('ADMIN_TIMEZONE=Asia/Manila');
putenv('ADMIN_USERNAME=super_user');
putenv('ADMIN_PASSWORD_HASH=' . password_hash('Visibility test password 123!', PASSWORD_DEFAULT));
putenv('ADMIN_JWT_SECRET=' . str_repeat('playnow-chart-secret-', 4));
ini_set('session.save_path', $dir);

require_once __DIR__ . '/../includes/blog-storage.php';
require_once __DIR__ . '/../includes/playnow-promos.php';
require_once __DIR__ . '/../includes/playnow-tracker-helpers.php';
require_once __DIR__ . '/../includes/auth.php';

function tc_check(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException('FAIL: ' . $message);
    }
}

function tc_remove(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) as $f) {
        if ($f === '.' || $f === '..') continue;
        $file = $path . '/' . $f;
        is_dir($file) ? tc_remove($file) : @unlink($file);
    }
    @rmdir($path);
}

function tc_handler(array $request): array
{
    $p = proc_open(
        [PHP_BINARY, __DIR__ . '/test-game-visibility.php', '--handler'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    fwrite($pipes[0], json_encode($request));
    fclose($pipes[0]);
    $body = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    tc_check(proc_close($p) === 0 && preg_match('/^STATUS:(\d+)$/m', $error, $m) === 1, 'Handler error: ' . $error);
    return [(int) $m[1], $body];
}

try {
    $tz = new DateTimeZone('Asia/Manila');
    $now = new DateTimeImmutable('2026-11-27 14:30:00', $tz); // Friday

    // 1. Default period resolution
    tc_check(tracker_resolve_period(null) === 'today', 'Omitted period must default to today');
    tc_check(tracker_resolve_period('') === 'today', 'Empty period must default to today');
    tc_check(tracker_resolve_period('invalid') === 'today', 'Invalid period must default to today');
    tc_check(tracker_resolve_period('all') === 'all', 'Explicit all period respected');

    // Verify Sunday week calculation (Nov 29, 2026 is Sunday -> Monday is Nov 23)
    $sunday = new DateTimeImmutable('2026-11-29 10:00:00', $tz);
    [$wkFrom, $wkTo] = tracker_period('this-week', $sunday);
    tc_check($wkFrom->format('Y-m-d') === '2026-11-23' && $wkTo->format('Y-m-d') === '2026-11-30', 'ISO week on Sunday starts Monday Nov 23');

    // 2. Build test events across today, yesterday, this-week, this-month, last-month
    $makeEvent = static function (string $localTime, string $ip = '203.0.113.10', string $page = '/slots') use ($tz): array {
        $dt = new DateTimeImmutable($localTime, $tz);
        return [
            'time' => $dt->setTimezone(new DateTimeZone('UTC'))->format('c'),
            'timestamp' => $dt->getTimestamp(),
            'ip' => $ip,
            'pagePath' => $page,
            'buttonText' => 'Play Now',
            'buttonKey' => 'Play Now',
            'targetUrl' => '/playnow',
            'location' => $page . ' | hero | Play Now',
        ];
    };

    $events = [
        $makeEvent('2026-10-15 11:00:00', '203.0.113.1', '/old'),
        $makeEvent('2026-11-05 09:15:00', '203.0.113.2', '/early'),
        $makeEvent('2026-11-24 16:00:00', '203.0.113.3', '/tue'),
        $makeEvent('2026-11-26 08:10:00', '203.0.113.4', '/yest'),
        $makeEvent('2026-11-26 08:45:00', '203.0.113.5', '/yest'),
        $makeEvent('2026-11-27 09:05:00', '203.0.113.6', '/today-a'),
        $makeEvent('2026-11-27 14:05:00', '203.0.113.7', '/today-b'),
        $makeEvent('2026-11-27 14:20:00', '203.0.113.8', '/today-b'),
        $makeEvent('2026-11-27 14:25:00', '10.0.0.99', '/ignored'), // Ignored CIDR
    ];

    blog_setting_set_multiline('ignored_engagement_ips', "10.0.0.0/24\n127.0.0.1");
    $isIgnored = playnow_tracker_ignored_matcher();

    $data = [
        'totalClicks' => 8,
        'updatedAt' => $events[7]['time'],
        'byPage' => ['/today-b' => 2, '/today-a' => 1, '/yest' => 2, '/tue' => 1, '/early' => 1, '/old' => 1],
        'byButton' => ['Play Now' => 8],
        'byIp' => ['203.0.113.7' => 1, '203.0.113.8' => 1, '10.0.0.99' => 1],
        'byTarget' => ['/playnow' => 8],
        'recent' => $events,
    ];

    // Today -> Hourly (24 buckets), excludes ignored IP 10.0.0.99
    $todayDash = tracker_build_dashboard($data, 'today', $isIgnored, $now);
    tc_check($todayDash['totalClicks'] === 3, 'Today totalClicks must be 3 (excluding ignored IP), got ' . $todayDash['totalClicks']);
    tc_check($todayDash['chart']['granularity'] === 'hour', 'Today granularity must be hour');
    tc_check(count($todayDash['chart']['buckets']) === 24, 'Today chart must have 24 hourly buckets');
    tc_check($todayDash['chart']['buckets'][9]['count'] === 1, 'Today 9 AM bucket must have 1 click');
    tc_check($todayDash['chart']['buckets'][14]['count'] === 2, 'Today 2 PM bucket must have 2 clicks');
    tc_check($todayDash['chart']['peakCount'] === 2, 'Today peak count must be 2');

    // Yesterday -> Hourly (24 buckets)
    $yestDash = tracker_build_dashboard($data, 'yesterday', $isIgnored, $now);
    tc_check($yestDash['totalClicks'] === 2 && $yestDash['chart']['granularity'] === 'hour', 'Yesterday must be hourly with 2 clicks');
    tc_check($yestDash['chart']['buckets'][8]['count'] === 2, 'Yesterday 8 AM bucket must have 2 clicks');

    // This week -> Daily (7 buckets: Mon Nov 23 .. Sun Nov 29)
    $weekDash = tracker_build_dashboard($data, 'this-week', $isIgnored, $now);
    tc_check($weekDash['totalClicks'] === 6 && $weekDash['chart']['granularity'] === 'day', 'This week must be daily with 6 clicks');
    tc_check(count($weekDash['chart']['buckets']) === 7, 'This week must have 7 daily buckets');
    tc_check($weekDash['chart']['buckets'][1]['count'] === 1, 'Tuesday bucket must have 1 click');
    tc_check($weekDash['chart']['buckets'][3]['count'] === 2, 'Thursday bucket must have 2 clicks');
    tc_check($weekDash['chart']['buckets'][4]['count'] === 3, 'Friday bucket must have 3 clicks');

    // This month -> Daily (30 buckets for November)
    $monthDash = tracker_build_dashboard($data, 'this-month', $isIgnored, $now);
    tc_check($monthDash['totalClicks'] === 7 && $monthDash['chart']['granularity'] === 'day', 'This month must be daily with 7 clicks');
    tc_check(count($monthDash['chart']['buckets']) === 30, 'November must have 30 daily buckets');

    // Last month -> Daily (31 buckets for October)
    $lastMonthDash = tracker_build_dashboard($data, 'last-month', $isIgnored, $now);
    tc_check($lastMonthDash['totalClicks'] === 1 && count($lastMonthDash['chart']['buckets']) === 31, 'October must have 31 daily buckets and 1 click');

    // All -> Daily (Oct 15 .. Nov 27 = 44 days <= 62)
    $allDash = tracker_build_dashboard($data, 'all', $isIgnored, $now);
    tc_check($allDash['chart']['granularity'] === 'day' && count($allDash['chart']['buckets']) === 44, 'All (44-day span) must be daily with 44 buckets');
    tc_check(!isset($allDash['byIp']['10.0.0.99']), 'Ignored IP stripped from all-time byIp');

    // All spanning > 62 days -> auto Monthly rollup
    $longEvents = [
        $makeEvent('2026-07-01 10:00:00', '203.0.113.1', '/jul'),
        $makeEvent('2026-11-27 10:00:00', '203.0.113.2', '/nov'),
    ];
    $longDash = tracker_build_dashboard(['totalClicks' => 2, 'recent' => $longEvents], 'all', $isIgnored, $now);
    tc_check($longDash['chart']['granularity'] === 'month' && count($longDash['chart']['buckets']) === 5, 'Span > 62 days must roll up to 5 monthly buckets');

    // 3. Zero-rewrite fast path in playnow_tracker_sync_ignored()
    $cleanPath = $dir . '/playnow-clicks.json';
    $cleanData = ['totalClicks' => 1, 'byIp' => ['203.0.113.10' => 1], 'recent' => [$makeEvent('2026-11-27 14:00:00', '203.0.113.10')]];
    file_put_contents($cleanPath, json_encode($cleanData));
    touch($cleanPath, 1700000000);
    clearstatcache(true, $cleanPath);
    $loaded = playnow_promo_data();
    $changed = playnow_tracker_sync_ignored($loaded, $isIgnored);
    clearstatcache(true, $cleanPath);
    tc_check($changed === false && filemtime($cleanPath) === 1700000000, 'Clean playnow-clicks.json must NOT be rewritten on tracker page load');

    // 4. Performance benchmark on 2,000 events (max stored log size)
    $perfEvents = [];
    $baseTs = $now->setTime(0, 0)->getTimestamp();
    for ($i = 0; $i < 2000; $i++) {
        $ts = $baseTs - ((2000 - $i) * 1200);
        $perfEvents[] = [
            'timestamp' => $ts,
            'time' => gmdate('c', $ts),
            'ip' => ($i % 10 === 0) ? '10.0.0.5' : ('203.0.113.' . ($i % 200 + 1)),
            'pagePath' => '/game/slot-' . ($i % 40),
            'buttonKey' => 'Play Now',
            'buttonText' => 'Play Now',
            'targetUrl' => '/playnow',
        ];
    }
    $perfData = ['totalClicks' => 2000, 'recent' => $perfEvents];
    $t0 = microtime(true);
    $perfToday = tracker_build_dashboard($perfData, 'today', $isIgnored, $now);
    $perfAll = tracker_build_dashboard($perfData, 'all', $isIgnored, $now);
    $elapsedMs = (microtime(true) - $t0) * 1000;
    tc_check($elapsedMs < 150.0, '2,000-event today+all build took too long: ' . round($elapsedMs, 2) . 'ms');
    tc_check($perfToday['chart']['granularity'] === 'hour' && $perfAll['chart']['totalClicks'] === 1800, '2,000-event build accurately filtered 200 ignored clicks');

    // 5. Authenticated HTML render test (default Today vs explicit This week)
    $todayEvent = [
        'time' => gmdate('c'),
        'timestamp' => time(),
        'ip' => '203.0.113.44',
        'pagePath' => '/slots/demo',
        'buttonText' => 'Play Now',
        'buttonKey' => 'Play Now',
        'targetUrl' => '/playnow',
        'location' => '/slots/demo | hero | Play Now',
    ];
    file_put_contents($cleanPath, json_encode([
        'totalClicks' => 1,
        'updatedAt' => $todayEvent['time'],
        'byPage' => ['/slots/demo' => 1],
        'byButton' => ['Play Now' => 1],
        'byIp' => ['203.0.113.44' => 1],
        'byTarget' => ['/playnow' => 1],
        'recent' => [$todayEvent],
    ]));

    [$stDefault, $htmlDefault] = tc_handler(['path' => '/admin/playnow-tracker.php', 'role' => 'super_user', 'method' => 'GET']);
    tc_check($stDefault === 200, 'Default playnow-tracker GET returned HTTP ' . $stDefault);
    tc_check(str_contains($htmlDefault, 'Showing: Today'), 'Default page must select Today');
    tc_check(str_contains($htmlDefault, 'class="btn btn-primary btn-sm" href="?period=today"'), 'Today button must be active by default');
    tc_check(str_contains($htmlDefault, 'Clicks per Hour') && str_contains($htmlDefault, 'id="tracker-chart-svg"'), 'Today page must render inline hourly SVG graph');

    [$stWeek, $htmlWeek] = tc_handler(['path' => '/admin/playnow-tracker.php', 'role' => 'super_user', 'method' => 'GET', 'get' => ['period' => 'this-week']]);
    tc_check($stWeek === 200 && str_contains($htmlWeek, 'Showing: This week') && str_contains($htmlWeek, 'Clicks per Day'), 'This week page must render daily SVG graph');

    [$stAll, $htmlAll] = tc_handler(['path' => '/admin/playnow-tracker.php', 'role' => 'super_user', 'method' => 'GET', 'get' => ['period' => 'all']]);
    tc_check($stAll === 200 && str_contains($htmlAll, 'Showing: All') && str_contains($htmlAll, 'Clicks per Day'), 'All page must render daily SVG graph');

    echo sprintf(
        "PASS: default today selection, hourly/daily/monthly chart bucketing, ISO week Sunday edge case, ignored-IP zero-rewrite fast path, 2,000-event benchmark (%.1f ms for today+all), and authenticated HTML rendering.\n",
        $elapsedMs
    );
} finally {
    session_write_close();
    tc_remove($dir);
}
