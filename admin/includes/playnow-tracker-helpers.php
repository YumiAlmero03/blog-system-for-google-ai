<?php
declare(strict_types=1);

require_once __DIR__ . '/admin-date.php';
require_once __DIR__ . '/playnow-promos.php';

function tracker_periods(): array
{
    return [
        'today' => 'Today',
        'yesterday' => 'Yesterday',
        'this-week' => 'This week',
        'this-month' => 'This month',
        'last-month' => 'Last month',
        'all' => 'All',
    ];
}

function tracker_resolve_period(mixed $raw): string
{
    $period = is_string($raw) ? trim($raw) : '';
    $periods = tracker_periods();
    return isset($periods[$period]) ? $period : 'today';
}

function tracker_period(string $period, ?DateTimeImmutable $now = null): array
{
    $tz = $now instanceof DateTimeImmutable ? $now->getTimezone() : admin_display_timezone();
    $now ??= new DateTimeImmutable('now', $tz);
    $todayStart = $now->setTime(0, 0);

    return match ($period) {
        'today' => [$todayStart, $todayStart->modify('+1 day')],
        'yesterday' => [$todayStart->modify('-1 day'), $todayStart],
        'this-week' => (static function () use ($todayStart): array {
            $daysSinceMonday = (int) $todayStart->format('N') - 1;
            $monday = $daysSinceMonday > 0 ? $todayStart->modify('-' . $daysSinceMonday . ' days') : $todayStart;
            return [$monday, $monday->modify('+7 days')];
        })(),
        'this-month' => [$todayStart->modify('first day of this month'), $todayStart->modify('first day of next month')],
        'last-month' => [$todayStart->modify('first day of last month'), $todayStart->modify('first day of this month')],
        default => [null, null],
    };
}

function tracker_event_timestamp(array $event): ?int
{
    if (isset($event['timestamp']) && is_numeric($event['timestamp'])) {
        return (int) $event['timestamp'];
    }
    $time = $event['time'] ?? null;
    if (is_int($time) || (is_string($time) && preg_match('/^\d+$/', $time) === 1)) {
        return (int) $time;
    }
    if (is_string($time) && $time !== '') {
        $parsed = strtotime($time);
        if ($parsed !== false) {
            return $parsed;
        }
    }
    return null;
}

function tracker_top(array $items): array
{
    arsort($items);
    return $items;
}

/**
 * Builds bucket slots for the selected period and computes the chart series,
 * filtered event list, and breakdown aggregates in a single pass.
 */
function tracker_build_dashboard(array $data, string $period, ?callable $isIgnored = null, ?DateTimeImmutable $now = null): array
{
    $period = tracker_resolve_period($period);
    $periods = tracker_periods();
    $tz = $now instanceof DateTimeImmutable ? $now->getTimezone() : admin_display_timezone();
    $now ??= new DateTimeImmutable('now', $tz);
    $isIgnored ??= playnow_tracker_ignored_matcher();

    [$from, $to] = tracker_period($period, $now);
    $fromTs = $from instanceof DateTimeImmutable ? $from->getTimestamp() : null;
    $toTs = $to instanceof DateTimeImmutable ? $to->getTimestamp() : null;

    $rawRecent = isset($data['recent']) && is_array($data['recent']) ? $data['recent'] : [];
    $filteredEvents = [];
    $filteredTimestamps = [];
    $byPage = [];
    $byButton = [];
    $byIp = [];
    $byTarget = [];

    // Single reverse pass (newest first) using fast integer range checks + memoized IP filter.
    for ($i = count($rawRecent) - 1; $i >= 0; $i--) {
        $event = $rawRecent[$i];
        if (!is_array($event)) {
            continue;
        }
        $ts = tracker_event_timestamp($event);
        if ($period !== 'all') {
            if ($ts === null || $fromTs === null || $toTs === null || $ts < $fromTs || $ts >= $toTs) {
                continue;
            }
        }
        $ip = (string) ($event['ip'] ?? '');
        if ($ip !== '' && $isIgnored($ip)) {
            continue;
        }

        $filteredEvents[] = $event;
        $filteredTimestamps[] = $ts;

        if ($period !== 'all') {
            $page = (string) ($event['pagePath'] ?? ($event['pageUrl'] ?? ''));
            $button = (string) ($event['buttonKey'] ?? ($event['buttonText'] ?? ''));
            $target = (string) ($event['targetUrl'] ?? '');
            $byPage[$page] = ($byPage[$page] ?? 0) + 1;
            $byButton[$button] = ($byButton[$button] ?? 0) + 1;
            $byIp[$ip] = ($byIp[$ip] ?? 0) + 1;
            $byTarget[$target] = ($byTarget[$target] ?? 0) + 1;
        }
    }

    if ($period === 'all') {
        $totalClicks = (int) ($data['totalClicks'] ?? 0);
        $byPage = is_array($data['byPage'] ?? null) ? $data['byPage'] : [];
        $byButton = is_array($data['byButton'] ?? null) ? $data['byButton'] : [];
        $byIp = is_array($data['byIp'] ?? null) ? $data['byIp'] : [];
        $byTarget = is_array($data['byTarget'] ?? null) ? $data['byTarget'] : [];
        foreach (array_keys($byIp) as $ipKey) {
            if ($isIgnored((string) $ipKey)) {
                unset($byIp[$ipKey]);
            }
        }
    } else {
        $totalClicks = count($filteredEvents);
    }

    $chart = tracker_build_chart_series($period, $from, $to, $filteredTimestamps, $tz, $now);

    return [
        'period' => $period,
        'periodLabel' => $periods[$period],
        'totalClicks' => $totalClicks,
        'updatedAt' => (string) ($data['updatedAt'] ?? ''),
        'byPage' => $byPage,
        'byButton' => $byButton,
        'byIp' => $byIp,
        'byTarget' => $byTarget,
        'recent' => $filteredEvents,
        'chart' => $chart,
    ];
}

/**
 * Generates contiguous hourly, daily, or monthly chart buckets based on the period selector.
 *
 * - today / yesterday -> Hourly (24 buckets: 12 AM - 11 PM)
 * - this-week -> Daily (7 buckets: Mon - Sun)
 * - this-month / last-month -> Daily (28 - 31 buckets)
 * - all -> Daily (contiguous span up to 62 days), auto-grouped to Monthly if span > 62 days
 */
function tracker_build_chart_series(
    string $period,
    ?DateTimeImmutable $from,
    ?DateTimeImmutable $to,
    array $timestamps,
    DateTimeZone $tz,
    DateTimeImmutable $now
): array {
    $buckets = [];
    $granularity = ($period === 'today' || $period === 'yesterday') ? 'hour' : 'day';
    $periods = tracker_periods();

    if ($granularity === 'hour' && $from instanceof DateTimeImmutable && $to instanceof DateTimeImmutable) {
        $cursor = $from;
        $bucketStarts = [];
        $index = 0;
        while ($cursor < $to) {
            $next = $cursor->modify('+1 hour');
            $hourNum = (int) $cursor->format('G');
            $short = $hourNum === 0 ? '12a' : ($hourNum < 12 ? $hourNum . 'a' : ($hourNum === 12 ? '12p' : ($hourNum - 12) . 'p'));
            $full = $cursor->format('M j, Y') . ' · ' . $cursor->format('g:00 A') . ' – ' . $cursor->format('g:59 A');
            $buckets[$index] = [
                'key' => $cursor->format('Y-m-d H'),
                'label' => $short,
                'fullLabel' => $full,
                'count' => 0,
            ];
            $bucketStarts[$index] = $cursor->getTimestamp();
            $cursor = $next;
            $index++;
        }

        $bucketCount = count($buckets);
        $startTs = $from->getTimestamp();
        $endTs = $to->getTimestamp();
        foreach ($timestamps as $ts) {
            if ($ts === null || $ts < $startTs || $ts >= $endTs || $bucketCount === 0) {
                continue;
            }
            $guess = intdiv($ts - $startTs, 3600);
            if ($guess < 0) $guess = 0;
            if ($guess >= $bucketCount) $guess = $bucketCount - 1;
            // Adjust for any DST offset edge case
            while ($guess > 0 && $ts < $bucketStarts[$guess]) {
                $guess--;
            }
            while ($guess + 1 < $bucketCount && $ts >= $bucketStarts[$guess + 1]) {
                $guess++;
            }
            $buckets[$guess]['count']++;
        }
    } elseif ($period !== 'all' && $from instanceof DateTimeImmutable && $to instanceof DateTimeImmutable) {
        $cursor = $from;
        $bucketStarts = [];
        $index = 0;
        $isWeek = $period === 'this-week';
        while ($cursor < $to) {
            $next = $cursor->modify('+1 day');
            $buckets[$index] = [
                'key' => $cursor->format('Y-m-d'),
                'label' => $isWeek ? $cursor->format('D j') : $cursor->format('M j'),
                'fullLabel' => $cursor->format('D, M j, Y'),
                'count' => 0,
            ];
            $bucketStarts[$index] = $cursor->getTimestamp();
            $cursor = $next;
            $index++;
        }

        $bucketCount = count($buckets);
        $startTs = $from->getTimestamp();
        $endTs = $to->getTimestamp();
        foreach ($timestamps as $ts) {
            if ($ts === null || $ts < $startTs || $ts >= $endTs || $bucketCount === 0) {
                continue;
            }
            $guess = intdiv($ts - $startTs, 86400);
            if ($guess < 0) $guess = 0;
            if ($guess >= $bucketCount) $guess = $bucketCount - 1;
            while ($guess > 0 && $ts < $bucketStarts[$guess]) {
                $guess--;
            }
            while ($guess + 1 < $bucketCount && $ts >= $bucketStarts[$guess + 1]) {
                $guess++;
            }
            $buckets[$guess]['count']++;
        }
    } else {
        // 'all' period: span from earliest to latest recorded event
        $validTs = array_values(array_filter($timestamps, static fn($v): bool => $v !== null));
        if (!$validTs) {
            // Show empty last 7 days baseline
            $endDay = $now->setTime(0, 0)->modify('+1 day');
            $startDay = $endDay->modify('-7 days');
            $cursor = $startDay;
            while ($cursor < $endDay) {
                $buckets[] = [
                    'key' => $cursor->format('Y-m-d'),
                    'label' => $cursor->format('M j'),
                    'fullLabel' => $cursor->format('D, M j, Y'),
                    'count' => 0,
                ];
                $cursor = $cursor->modify('+1 day');
            }
        } else {
            $minTs = min($validTs);
            $maxTs = max($validTs);
            $minDay = (new DateTimeImmutable('@' . $minTs))->setTimezone($tz)->setTime(0, 0);
            $maxDay = (new DateTimeImmutable('@' . $maxTs))->setTimezone($tz)->setTime(0, 0);
            $spanDays = (int) $minDay->diff($maxDay)->days + 1;

            if ($spanDays <= 62) {
                $granularity = 'day';
                $keyToIdx = [];
                $cursor = $minDay;
                $endDay = $maxDay->modify('+1 day');
                $idx = 0;
                while ($cursor < $endDay) {
                    $key = $cursor->format('Y-m-d');
                    $buckets[$idx] = [
                        'key' => $key,
                        'label' => $cursor->format('M j'),
                        'fullLabel' => $cursor->format('D, M j, Y'),
                        'count' => 0,
                    ];
                    $keyToIdx[$key] = $idx;
                    $cursor = $cursor->modify('+1 day');
                    $idx++;
                }
                foreach ($validTs as $ts) {
                    $key = (new DateTimeImmutable('@' . $ts))->setTimezone($tz)->format('Y-m-d');
                    if (isset($keyToIdx[$key])) {
                        $buckets[$keyToIdx[$key]]['count']++;
                    }
                }
            } else {
                $granularity = 'month';
                $keyToIdx = [];
                $cursor = $minDay->modify('first day of this month');
                $endMonth = $maxDay->modify('first day of next month');
                $idx = 0;
                while ($cursor < $endMonth) {
                    $key = $cursor->format('Y-m');
                    $buckets[$idx] = [
                        'key' => $key,
                        'label' => $cursor->format('M Y'),
                        'fullLabel' => $cursor->format('F Y'),
                        'count' => 0,
                    ];
                    $keyToIdx[$key] = $idx;
                    $cursor = $cursor->modify('first day of next month');
                    $idx++;
                }
                foreach ($validTs as $ts) {
                    $key = (new DateTimeImmutable('@' . $ts))->setTimezone($tz)->format('Y-m');
                    if (isset($keyToIdx[$key])) {
                        $buckets[$keyToIdx[$key]]['count']++;
                    }
                }
            }
        }
    }

    $maxCount = 0;
    $totalChartClicks = 0;
    $peakLabel = '';
    $peakCount = 0;
    foreach ($buckets as $bucket) {
        $c = $bucket['count'];
        $totalChartClicks += $c;
        if ($c > $maxCount) {
            $maxCount = $c;
            $peakCount = $c;
            $peakLabel = $bucket['fullLabel'];
        }
    }

    $title = match ($granularity) {
        'hour' => 'Clicks per Hour',
        'month' => 'Clicks per Month',
        default => 'Clicks per Day',
    };
    $granularityLabel = match ($granularity) {
        'hour' => 'Hourly',
        'month' => 'Monthly',
        default => 'Daily',
    };

    return [
        'granularity' => $granularity,
        'granularityLabel' => $granularityLabel,
        'title' => $title,
        'subtitle' => ($periods[$period] ?? 'Today') . ' · ' . $granularityLabel . ' (' . $tz->getName() . ')',
        'buckets' => $buckets,
        'maxCount' => $maxCount,
        'totalClicks' => $totalChartClicks,
        'peakLabel' => $peakLabel,
        'peakCount' => $peakCount,
    ];
}

function tracker_chart_y_ticks(int $maxCount): array
{
    if ($maxCount <= 4) {
        return [4, 3, 2, 1, 0];
    }
    $rawStep = $maxCount / 4;
    $magnitude = 10 ** floor(log10($rawStep));
    $normalized = $rawStep / $magnitude;
    $niceNormalized = $normalized <= 1 ? 1 : ($normalized <= 2 ? 2 : ($normalized <= 5 ? 5 : 10));
    $step = (int) max(1, round($niceNormalized * $magnitude));
    $top = $step * 4;
    while ($top < $maxCount) {
        $top += $step;
    }
    return [$top, (int) round($top * 0.75), (int) round($top * 0.5), (int) round($top * 0.25), 0];
}
