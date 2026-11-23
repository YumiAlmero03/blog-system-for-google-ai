<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../includes/blog-storage.php';
require_once __DIR__ . '/../../../includes/slotslaunch-client.php';

final class GamePhCheckException extends RuntimeException {}

/** One signed catalogue request for at most 100 saved games; no iframe/play requests. */
function games_ph_fetch(array $apiIds, ?callable $transport = null): array
{
    $key = trim((string) (env_value('SLOTSLAUNCH_API_KEY') ?: env_value('SLOTSLAUNCH_API_TOKEN') ?: env_value('SLOTSLAUNCH_TOKEN') ?: env_value('GAMES_API_TOKEN')));
    $secret = trim((string) env_value('SLOTSLAUNCH_API_SECRET'));
    $origin = trim((string) (env_value('SLOTSLAUNCH_ORIGIN') ?: env_value('SLOTSLAUNCH_SITE_DOMAIN') ?: env_value('SITE_BASE_URL')));
    if ($key === '' || $secret === '' || $origin === '') {
        throw new GamePhCheckException('Configure the SlotsLaunch API key, secret and registered site domain in admin/.env before rechecking.');
    }
    if (!str_contains($origin, '://')) $origin = 'https://' . $origin;
    $parts = parse_url($origin);
    if (!$parts || !filter_var($origin, FILTER_VALIDATE_URL) || !in_array($parts['scheme'] ?? '', ['http','https'], true)
        || isset($parts['user']) || isset($parts['pass']) || preg_match('/[\r\n]/', $origin)) {
        throw new GamePhCheckException('Configure a valid registered SlotsLaunch origin/domain in admin/.env.');
    }
    $host = preg_replace('/^www\./i', '', (string) $parts['host']);
    $origin = $parts['scheme'] . '://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '');
    $client = new \SlotsLaunch\Client($key, $secret, $host);
    $headers = ['Accept: application/json', 'Origin: ' . $origin];
    // SlotsLaunch signs the path without its query string.
    foreach ($client->apiHeaders('GET', '/api/games') as $name => $value) $headers[] = $name . ': ' . $value;
    $query = ['token'=>$key, 'per_page'=>100, 'page'=>1, 'order_by'=>'id', 'order'=>'asc', 'id'=>array_values($apiIds)];
    $url = 'https://slotslaunch.com/api/games?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    if ($transport) {
        $response = $transport($url, $headers);
    } else {
        if (!function_exists('curl_init')) throw new GamePhCheckException('PHP cURL is required to recheck availability.');
        $body = '';
        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_HTTPHEADER=>$headers, CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>20, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION=>static function ($curl, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 8388608) return 0;
                $body .= $chunk;
                return strlen($chunk);
            }]);
        $ok = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        unset($curl);
        if ($status === 429) throw new GamePhCheckException('SlotsLaunch rate limit reached. Wait a minute before rechecking again.');
        if ($ok === false || $status !== 200) throw new GamePhCheckException('SlotsLaunch availability check failed (HTTP ' . $status . '). Existing availability was kept.');
        try { $response = json_decode($body, true, 512, JSON_THROW_ON_ERROR); }
        catch (JsonException) { throw new GamePhCheckException('SlotsLaunch returned invalid data. Existing availability was kept.'); }
    }
    if (!is_array($response) || !isset($response['data']) || !is_array($response['data']) || !array_is_list($response['data'])
        || isset($response['error']) || (int) ($response['meta']['last_page'] ?? 1) > 1) {
        throw new GamePhCheckException('SlotsLaunch returned an unexpected game list. Existing availability was kept.');
    }
    return $response['data'];
}

/** Cursor is the local ID, so changing restrictions or updated_at cannot skip games. */
function games_ph_recheck(PDO $pdo, int $afterId = 0, ?int $maxId = null, ?int $slotId = null, ?callable $transport = null): array
{
    $maxId ??= (int) $pdo->query('SELECT COALESCE(MAX(id),0) FROM games')->fetchColumn();
    $stmt = $slotId !== null
        ? $pdo->prepare('SELECT id,api_id,restrictions,published FROM games WHERE id=?')
        : $pdo->prepare('SELECT id,api_id,restrictions,published FROM games WHERE id>? AND id<=? ORDER BY id LIMIT 100');
    $stmt->execute($slotId !== null ? [$slotId] : [$afterId,$maxId]);
    $games = $stmt->fetchAll();
    if ($slotId !== null && !$games) throw new InvalidArgumentException('Game not found.');
    $total = (int) $pdo->query('SELECT COUNT(*) FROM games WHERE id<=' . $maxId)->fetchColumn();
    $result = ['processed'=>count($games), 'checked'=>0, 'changed'=>0, 'available'=>0, 'restricted'=>0, 'unpublished'=>0, 'unknown'=>0,
        'total'=>$slotId !== null ? count($games) : $total, 'max_id'=>$maxId, 'next_after_id'=>$afterId, 'done'=>true];
    if (!$games) return $result;
    $byApiId = [];
    foreach ($games as $game) if ((int)$game['api_id'] > 0) $byApiId[(int)$game['api_id']] = $game;
    $remote = $byApiId ? games_ph_fetch(array_keys($byApiId), $transport) : [];
    $updates = [];
    foreach ($remote as $item) {
        if (!is_array($item) || !is_scalar($item['id'] ?? null) || !ctype_digit((string)$item['id'])) continue;
        $apiId = (int)$item['id'];
        if (!isset($byApiId[$apiId])) continue;
        if (isset($updates[$apiId])) throw new GamePhCheckException('SlotsLaunch returned duplicate game records. Existing availability was kept.');
        if (!array_key_exists('restrictions', $item) || !in_array($item['published'] ?? null, [true,false,1,0,'1','0'], true)) continue;
        $restrictions = $item['restrictions'];
        if (is_string($restrictions)) $restrictions = str_starts_with(trim($restrictions), '[') ? json_decode($restrictions, true) : explode(',', $restrictions);
        elseif ($restrictions === null) $restrictions = [];
        if (!is_array($restrictions) || !array_is_list($restrictions)) continue;
        $valid = true; $tokens = [];
        foreach ($restrictions as $country) {
            if (!is_string($country)) { $valid = false; break; }
            foreach (explode(',', $country) as $token) {
                $token = strtoupper(trim($token));
                if ($token !== '') $tokens[] = $token;
            }
        }
        if (!$valid) continue;
        $tokens = array_values(array_unique($tokens)); sort($tokens);
        $updates[$apiId] = ['restrictions'=>json_encode($tokens, JSON_THROW_ON_ERROR), 'published'=>(int)(bool)$item['published']];
    }
    if ($updates) {
        require_once __DIR__ . '/../../../includes/indexing-queue.php';
        indexing_content_changed($pdo, 'game');
    }
    $pdo->beginTransaction();
    try {
        $update = $pdo->prepare('UPDATE games SET restrictions=?,published=?,updated_at=? WHERE id=? AND (restrictions<>? OR published<>?)');
        // Use the same exact PH/PHILIPPINES rules as public listings.
        $phAllowed = $pdo->prepare('SELECT ' . games_ph_allowed_sql('restrictions') . ' FROM (SELECT ? AS restrictions)');
        foreach ($updates as $apiId=>$values) {
            $old = $byApiId[$apiId];
            $phAllowed->execute([$values['restrictions']]);
            $allowed = (bool)$phAllowed->fetchColumn();
            $result[$allowed ? ($values['published'] ? 'available' : 'unpublished') : 'restricted']++;
            $update->execute([$values['restrictions'],$values['published'],time(),$old['id'],$values['restrictions'],$values['published']]);
            $result['changed'] += $update->rowCount();
            $result['checked']++;
        }
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
    $result['unknown'] = count($games) - $result['checked'];
    $result['next_after_id'] = (int)end($games)['id'];
    $stmt = $pdo->prepare('SELECT 1 FROM games WHERE id>? AND id<=? LIMIT 1');
    $stmt->execute([$result['next_after_id'],$maxId]);
    $result['done'] = $slotId !== null || !$stmt->fetchColumn();
    return $result;
}
