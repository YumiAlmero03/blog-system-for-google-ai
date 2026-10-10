<?php
declare(strict_types=1);

/** Rankings count plays, not people. Missing games must not be reported as zero. */
function game_rankings_count(int $apiId): array
{
    $path = blog_storage_dir() . '/slotslaunch-rankings.json';
    $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
    $count = $data['counts'][(string) $apiId] ?? null;
    $syncedAt = $data['syncedAt'] ?? null;
    return ['count'=>is_int($count) && $count >= 0 ? $count : null,
        'metric'=>'plays', 'source'=>'slotslaunch', 'scope'=>'network',
        'syncedAt'=>is_int($syncedAt) ? $syncedAt : null,
        'stale'=>!is_int($syncedAt) || $syncedAt < time() - 86400];
}

function game_rankings_save(array $rows): int
{
    if (!array_is_list($rows)) throw new RuntimeException('Unexpected rankings response.');
    $counts = [];
    foreach ($rows as $row) {
        if (!is_array($row)) throw new RuntimeException('Invalid ranking record.');
        $id = filter_var($row['game_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        $count = filter_var($row['times_played'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]);
        if ($id === false || $count === false) throw new RuntimeException('Invalid ranking counts.');
        $counts[(string) $id] = $count;
    }
    $dir = blog_storage_dir();
    if (!is_dir($dir) && !mkdir($dir, 0700, true)) throw new RuntimeException('Cannot create rankings storage.');
    $tmp = tempnam($dir, 'rankings-');
    if ($tmp === false) throw new RuntimeException('Cannot create rankings snapshot.');
    try {
        $json = json_encode(['syncedAt'=>time(), 'counts'=>$counts], JSON_THROW_ON_ERROR);
        if (file_put_contents($tmp, $json, LOCK_EX) !== strlen($json)
            || !rename($tmp, $dir . '/slotslaunch-rankings.json')) throw new RuntimeException('Cannot save rankings snapshot.');
    } finally { if (is_file($tmp)) unlink($tmp); }
    return count($counts);
}
