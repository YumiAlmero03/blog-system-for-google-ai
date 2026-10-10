<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../includes/env.php';
require_once __DIR__ . '/../livescore/includes/storage.php';

function odds_api_fields(): array
{
    return [
        'event_fields'=>[
            'home_team'=>'Home team', 'away_team'=>'Away team', 'sport_title'=>'Sport title',
            'sport.title'=>'Title', 'sport.group'=>'Group', 'commence_time'=>'Start time',
            'id'=>'Event ID', 'sport_key'=>'Sport key', 'sport.key'=>'Sports list key',
            'sport.description'=>'Description', 'sport.active'=>'Active', 'sport.has_outrights'=>'Has outrights',
        ],
        'outcome_fields'=>[
            'bookmaker.title'=>'Bookmaker', 'bookmaker.key'=>'Bookmaker key', 'bookmaker.last_update'=>'Bookmaker updated',
            'market.key'=>'Market', 'market.last_update'=>'Market updated',
            'outcome.name'=>'Outcome', 'outcome.price'=>'Odds', 'outcome.point'=>'Line',
        ],
    ];
}

function odds_api_directory(): string
{
    $path = env_value('APP_STORAGE_DIR');
    return rtrim(!$path || $path === '/absolute/path/outside/public/storage' ? __DIR__ . '/../../../storage' : $path, '/\\');
}

function odds_api_pdo(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) return $pdo;
    $dir = odds_api_directory();
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('Odds storage unavailable.');
    $path = $dir . '/odds-api.sqlite';
    $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    chmod($path, 0600);
    $pdo->exec('PRAGMA busy_timeout=5000');
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (id INTEGER PRIMARY KEY CHECK(id=1), enabled INTEGER NOT NULL DEFAULT 0, api_key TEXT NOT NULL DEFAULT '', regions TEXT NOT NULL DEFAULT 'us', markets TEXT NOT NULL DEFAULT 'h2h', bookmakers TEXT NOT NULL DEFAULT '')");
    $pdo->exec('INSERT OR IGNORE INTO settings(id) VALUES(1)');
    $pdo->exec("CREATE TABLE IF NOT EXISTS iframe_settings (id INTEGER PRIMARY KEY CHECK(id=1), title TEXT NOT NULL, markets TEXT NOT NULL, event_fields TEXT NOT NULL, outcome_fields TEXT NOT NULL)");
    $fields = odds_api_fields();
    // Preserve the previously configured market when introducing the iframe editor.
    $pdo->prepare('INSERT OR IGNORE INTO iframe_settings(id,title,markets,event_fields,outcome_fields) SELECT 1,?,markets,?,? FROM settings WHERE id=1')->execute([
        'Sports Odds', json_encode(array_keys($fields['event_fields'])), json_encode(array_keys($fields['outcome_fields'])),
    ]);
    $pdo->exec('CREATE TABLE IF NOT EXISTS cache (cache_key TEXT PRIMARY KEY, payload TEXT NOT NULL, fetched_at INTEGER NOT NULL, expires_at INTEGER NOT NULL)');
    return $pdo;
}

function odds_api_config(int $iframeId = 1): array
{
    $config = odds_api_pdo()->query('SELECT id,enabled,api_key,regions,bookmakers FROM settings WHERE id=1')->fetch();
    $config['markets'] = odds_api_iframe($iframeId)['markets'];
    $config['iframe_id'] = $iframeId;
    return $config;
}

function odds_api_iframe_pdo(): PDO
{
    static $ready = false;
    $pdo = sports_livescore_pdo();
    if ($ready) return $pdo;
    $pdo->exec('CREATE TABLE IF NOT EXISTS iframes (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, markets TEXT NOT NULL, blocks TEXT NOT NULL, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL)');
    // Import the existing embed once; /odds-api/ continues to use iframe 1.
    if (!$pdo->query('SELECT 1 FROM iframes WHERE id=1')->fetchColumn()) {
        $old = odds_api_pdo()->query('SELECT * FROM iframe_settings WHERE id=1')->fetch();
        $blocks = [];
        foreach (odds_api_fields() as $group=>$fields) {
            $blocks[$group] = [];
            foreach (json_decode($old[$group], true, 32, JSON_THROW_ON_ERROR) as $key) {
                if (isset($fields[$key])) $blocks[$group][] = ['field'=>$key,'label'=>$fields[$key],'role'=>'data'];
            }
        }
        $pdo->prepare('INSERT OR IGNORE INTO iframes(id,title,markets,blocks,created_at,updated_at) VALUES(1,?,?,?,?,?)')->execute([$old['title'],$old['markets'],json_encode($blocks, JSON_THROW_ON_ERROR),time(),time()]);
    }
    $ready = true;
    return $pdo;
}

function odds_api_layout(array $row): array
{
    $row['id'] = (int)$row['id'];
    $row['blocks'] = json_decode($row['blocks'], true, 32, JSON_THROW_ON_ERROR);
    $row['labels'] = odds_api_fields();
    foreach ($row['blocks'] as $group=>$blocks) {
        $row[$group] = array_column($blocks, 'field');
        foreach ($blocks as $block) $row['labels'][$group][$block['field']] = $block['label'];
    }
    return $row;
}

function odds_api_iframe(int $id = 1): array
{
    $stmt = odds_api_iframe_pdo()->prepare('SELECT * FROM iframes WHERE id=?'); $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) throw new OutOfBoundsException('Iframe not found.');
    return odds_api_layout($row);
}

function odds_api_default_iframe(): array
{
    $blocks = [];
    foreach (odds_api_fields() as $group=>$fields) {
        $blocks[$group] = [];
        foreach ($fields as $field=>$label) $blocks[$group][] = ['field'=>$field,'label'=>$label,'role'=>'data'];
    }
    return odds_api_layout(['id'=>0,'title'=>'Sports Odds','markets'=>'h2h','blocks'=>json_encode($blocks),'created_at'=>0,'updated_at'=>0]);
}

function odds_api_save_iframe(array $input): int
{
    $title = $input['title'] ?? '';
    if (!is_string($title) || trim($title)==='' || strlen($title)>200 || preg_match('/[\x00-\x1f\x7f]/', $title)) throw new InvalidArgumentException('Enter an iframe title up to 200 bytes.');
    $markets = odds_api_csv($input['markets'] ?? '', 'markets', ['h2h','spreads','totals','outrights']);
    $id = $input['id'] ?? 1;
    if ((!is_string($id) && !is_int($id)) || !preg_match('/^(0|[1-9][0-9]{0,9})$/D', (string)$id)) throw new InvalidArgumentException('Invalid iframe ID.');
    $id = (int)$id;
    if ($id > 0) odds_api_iframe($id);
    $incoming = $input['blocks'] ?? null;
    if (is_string($incoming)) {
        if (strlen($incoming)>20000) throw new InvalidArgumentException('Iframe layout is too large.');
        try { $incoming = json_decode($incoming, true, 32, JSON_THROW_ON_ERROR); }
        catch (Throwable $e) { throw new InvalidArgumentException('Invalid blocks.'); }
    }
    // Accept older clients posting ordered arrays while the editor uses blocks.
    if ($incoming === null) {
        $incoming = [];
        foreach (odds_api_fields() as $group=>$fields) {
            $incoming[$group] = [];
            if (!is_array($input[$group] ?? null)) throw new InvalidArgumentException('Invalid display fields.');
            foreach ($input[$group] as $field) {
                if (!is_string($field)) throw new InvalidArgumentException('Invalid display field.');
                $incoming[$group][] = ['field'=>$field,'label'=>$fields[$field] ?? '', 'role'=>'data'];
            }
        }
    }
    if (!is_array($incoming)) throw new InvalidArgumentException('Invalid blocks.');
    $normalized = []; $count = 0;
    foreach (odds_api_fields() as $group=>$fields) {
        $order = $incoming[$group] ?? null;
        if (!is_array($order) || !array_is_list($order) || count($order)>count($fields)) throw new InvalidArgumentException('Invalid display blocks.');
        $normalized[$group] = [];
        $seen = [];
        foreach ($order as $block) {
            if (!is_array($block)) throw new InvalidArgumentException('Invalid block.');
            $key = $block['field'] ?? null; $label = $block['label'] ?? null; $role = $block['role'] ?? null;
            if (!is_string($key) || !isset($fields[$key]) || isset($seen[$key])) throw new InvalidArgumentException('Invalid display field order.');
            if (!is_string($label) || trim($label)==='' || strlen($label)>120 || preg_match('/[\x00-\x1f\x7f]/', $label)) throw new InvalidArgumentException('Enter a block label up to 120 bytes.');
            if (!in_array($role, ['data','title','description'], true)) throw new InvalidArgumentException('Invalid block role.');
            $seen[$key] = true;
            $normalized[$group][] = ['field'=>$key,'label'=>trim($label),'role'=>$role]; $count++;
        }
    }
    if (!$count) throw new InvalidArgumentException('Add at least one data block.');
    $pdo = odds_api_iframe_pdo();
    if ($id === 0) {
        $pdo->prepare('INSERT INTO iframes(title,markets,blocks,created_at,updated_at) VALUES(?,?,?,?,?)')->execute([trim($title),$markets,json_encode($normalized, JSON_THROW_ON_ERROR),time(),time()]);
        return (int)$pdo->lastInsertId();
    }
    $pdo->prepare('UPDATE iframes SET title=?,markets=?,blocks=?,updated_at=? WHERE id=?')->execute([trim($title),$markets,json_encode($normalized, JSON_THROW_ON_ERROR),time(),$id]);
    return $id;
}

function odds_api_csv(mixed $value, string $name, ?array $allowed = null, bool $optional = false): string
{
    if (!is_string($value) || strlen($value)>1000) throw new InvalidArgumentException('Invalid ' . $name . '.');
    $parts = array_values(array_unique(array_filter(array_map('trim', explode(',', strtolower($value))), static fn($s)=>$s!=='')));
    if (!$parts && !$optional) throw new InvalidArgumentException($name . ' is required.');
    foreach ($parts as $part) {
        if (!preg_match('/^[a-z0-9_]+$/D', $part) || ($allowed !== null && !in_array($part, $allowed, true))) throw new InvalidArgumentException('Invalid ' . $name . '.');
    }
    return implode(',', $parts);
}

function odds_api_save(array $input): void
{
    $old = odds_api_config();
    $key = $input['apiKey'] ?? '';
    if (!is_string($key) || strlen($key)>256 || ($key!=='' && !preg_match('/^[A-Za-z0-9_-]+$/D', $key))) throw new InvalidArgumentException('Invalid API key.');
    $key = !empty($input['clear_api_key']) ? '' : ($key !== '' ? $key : $old['api_key']);
    $enabled = $input['enabled'] ?? '0';
    if (!in_array($enabled, ['0','1'], true)) throw new InvalidArgumentException('Invalid enabled setting.');
    if ($enabled === '1' && $key === '') throw new InvalidArgumentException('Enter an API key before enabling Odds API.');
    $regions = odds_api_csv($input['regions'] ?? '', 'regions', ['us','us2','uk','au','eu']);
    $bookmakers = odds_api_csv($input['bookmakers'] ?? '', 'bookmakers', null, true);
    $pdo = odds_api_pdo();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE settings SET enabled=?,api_key=?,regions=?,bookmakers=? WHERE id=1')->execute([(int)$enabled,$key,$regions,$bookmakers]);
        $pdo->exec('DELETE FROM cache');
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
}

function odds_api_http(string $path, array $query): array
{
    $body = ''; $quota = [];
    $curl = curl_init('https://api.the-odds-api.com/v4/' . $path . '?' . http_build_query($query));
    curl_setopt_array($curl, [CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>15, CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS, CURLOPT_HTTPHEADER=>['Accept: application/json'],
        CURLOPT_WRITEFUNCTION=>static function($ch, string $chunk) use (&$body): int {
            if (strlen($body)+strlen($chunk)>4*1024*1024) return 0;
            $body .= $chunk; return strlen($chunk);
        },
        CURLOPT_HEADERFUNCTION=>static function($ch, string $header) use (&$quota): int {
            if (preg_match('/^x-requests-(remaining|used|last):\s*(\d+)/i', $header, $m)) $quota[strtolower($m[1])] = (int)$m[2];
            return strlen($header);
        },
    ]);
    $ok = curl_exec($curl); $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE); curl_close($curl);
    // Never expose a provider response, request URL, or cURL error containing credentials.
    if ($ok === false || $status !== 200) throw new RuntimeException('Odds provider unavailable.');
    try { $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR); }
    catch (Throwable $e) { throw new RuntimeException('Invalid odds response.'); }
    if (!is_array($data) || !array_is_list($data)) throw new RuntimeException('Invalid odds response.');
    return ['data'=>$data, 'quota'=>$quota];
}

/** Allowlist provider fields before storing or returning public data. */
function odds_api_public_data(string $type, array $data): array
{
    $result = [];
    foreach ($data as $item) {
        if (!is_array($item)) continue;
        if ($type === 'sports') {
            if (!is_string($item['key'] ?? null) || !preg_match('/^[a-z0-9_]+$/D', $item['key']) || empty($item['active'])) continue;
            $result[] = ['key'=>$item['key'], 'title'=>(string)($item['title'] ?? $item['key']), 'group'=>(string)($item['group'] ?? ''), 'description'=>(string)($item['description'] ?? ''), 'active'=>true, 'has_outrights'=>!empty($item['has_outrights'])];
            continue;
        }
        $event = [];
        foreach (['id','sport_key','sport_title','commence_time','home_team','away_team'] as $field) $event[$field] = is_string($item[$field] ?? null) ? $item[$field] : '';
        $event['bookmakers'] = [];
        foreach (($item['bookmakers'] ?? []) as $book) {
            $markets = [];
            foreach (($book['markets'] ?? []) as $market) {
                $outcomes = [];
                foreach (($market['outcomes'] ?? []) as $outcome) {
                    if (!is_string($outcome['name'] ?? null) || !is_numeric($outcome['price'] ?? null)) continue;
                    $outcomes[] = ['name'=>$outcome['name'], 'price'=>(float)$outcome['price'], 'point'=>is_numeric($outcome['point'] ?? null) ? (float)$outcome['point'] : null];
                }
                $markets[] = ['key'=>(string)($market['key'] ?? ''), 'last_update'=>(string)($market['last_update'] ?? ''), 'outcomes'=>$outcomes];
            }
            $event['bookmakers'][] = ['key'=>(string)($book['key'] ?? ''), 'title'=>(string)($book['title'] ?? ''), 'last_update'=>(string)($book['last_update'] ?? ''), 'markets'=>$markets];
        }
        $result[] = $event;
    }
    return $result;
}

function odds_api_cached(string $type, string $sport, array $config, ?callable $transport = null): array
{
    $pdo = odds_api_pdo();
    $cacheConfig = $config; unset($cacheConfig['iframe_id']);
    if ($type === 'sports') $cacheConfig = ['api_key'=>$config['api_key']];
    $cacheKey = hash('sha256', json_encode(['fields-v3',$type,$sport,$cacheConfig]));
    $lock = fopen(odds_api_directory() . '/odds-api.lock', 'c');
    if ($lock === false) throw new RuntimeException('Odds cache unavailable.');
    try {
        if (!flock($lock, LOCK_EX)) throw new RuntimeException('Odds cache unavailable.');
        $query = $pdo->prepare('SELECT * FROM cache WHERE cache_key=?'); $query->execute([$cacheKey]); $cached = $query->fetch();
        if ($cached && (int)$cached['expires_at']>time()) {
            $payload = json_decode($cached['payload'], true, 64, JSON_THROW_ON_ERROR);
            if (isset($payload['error'])) throw new RuntimeException('Odds provider unavailable. Please try again shortly.');
            return $payload;
        }
        // Settings can change while waiting for another request to finish.
        if (odds_api_config((int)$config['iframe_id']) !== $config) throw new RuntimeException('Odds settings changed. Please reload.');
        $params = ['apiKey'=>$config['api_key']];
        $path = 'sports/';
        if ($type === 'odds') {
            $path .= rawurlencode($sport) . '/odds/';
            $params += ['regions'=>$config['regions'],'markets'=>$config['markets'],'oddsFormat'=>'decimal','dateFormat'=>'iso'];
            if ($config['bookmakers'] !== '') $params['bookmakers'] = $config['bookmakers'];
        }
        try {
            $response = ($transport ?? 'odds_api_http')($path, $params);
            if (!is_array($response['data'] ?? null) || !array_is_list($response['data'])) throw new RuntimeException();
            $payload = ['data'=>odds_api_public_data($type, $response['data']), 'updatedAt'=>gmdate('c')];
            $ttl = $type === 'sports' ? 3600 : 300;
        } catch (Throwable $e) { $payload = ['error'=>true]; $ttl = 60; }
        $pdo->prepare('INSERT OR REPLACE INTO cache(cache_key,payload,fetched_at,expires_at) VALUES(?,?,?,?)')->execute([$cacheKey,json_encode($payload, JSON_THROW_ON_ERROR),time(),time()+$ttl]);
        if (isset($payload['error'])) throw new RuntimeException('Odds provider unavailable. Please try again shortly.');
        return $payload;
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}

function odds_api_result(string $action, string $sport = '', ?callable $transport = null, int $iframeId = 1): array
{
    if (!in_array($action, ['sports','odds'], true)) throw new InvalidArgumentException('Choose sports or odds.');
    if ($action === 'sports') $sport = '';
    if ($action === 'odds' && !preg_match('/^[a-z0-9_]{1,100}$/D', $sport)) throw new InvalidArgumentException('A valid sport key is required.');
    $config = odds_api_config($iframeId);
    $layout = odds_api_iframe($iframeId);
    if (!(int)$config['enabled']) return ['ok'=>true,'enabled'=>false,'data'=>[],'updatedAt'=>null,'layout'=>$layout];
    if ($config['api_key'] === '') throw new RuntimeException('Odds API is not configured.');
    if ($action === 'odds') {
        $sports = odds_api_cached('sports', '', $config, $transport);
        if (!in_array($sport, array_column($sports['data'], 'key'), true)) throw new InvalidArgumentException('Sport is not available.');
    }
    $result = odds_api_cached($action, $sport, $config, $transport);
    if ($action === 'odds') {
        $sportMap = array_column($sports['data'], null, 'key');
        foreach ($result['data'] as &$event) $event['sport'] = $sportMap[$event['sport_key']] ?? null;
        unset($event);
    }
    if (odds_api_config($iframeId) !== $config) throw new RuntimeException('Odds settings changed. Please reload.');
    return ['ok'=>true,'enabled'=>true,'layout'=>$layout] + $result;
}
