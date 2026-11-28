<?php
/**
 * Validates OpenRouter integration across chat AI and both PHP/Python game enrichment scripts.
 *
 * Spawns a local mock OpenRouter HTTP server (/api/v1/chat/completions) in an isolated temp
 * storage directory — zero external network requests.
 *
 * Run from www: php admin/test/test-openrouter-enrichment.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$checks = 0;
function or_check(bool $ok, string $label): void
{
    global $checks;
    if (!$ok) {
        throw new RuntimeException('FAIL: ' . $label);
    }
    $checks++;
}

$dir = sys_get_temp_dir() . '/openrouter-test-' . bin2hex(random_bytes(6));
mkdir($dir, 0700, true);
$storageDir = $dir . '/storage';
$chatDir = $dir . '/chats';
$requestsLog = $dir . '/requests.jsonl';
$modeFile = $dir . '/mode.txt';
$routerFile = $dir . '/mock-openrouter.php';

file_put_contents($modeFile, 'ok');
file_put_contents($routerFile, <<<'PHP'
<?php
declare(strict_types=1);

$logFile = getenv('MOCK_REQUESTS_LOG') ?: '';
$modeFile = getenv('MOCK_MODE_FILE') ?: '';
$mode = is_file($modeFile) ? trim((string) file_get_contents($modeFile)) : 'ok';

$rawBody = (string) file_get_contents('php://input');
$headers = [];
foreach ($_SERVER as $key => $val) {
    if (str_starts_with($key, 'HTTP_')) {
        $name = strtolower(str_replace('_', '-', substr($key, 5)));
        $headers[$name] = (string) $val;
    }
}
if (isset($_SERVER['CONTENT_TYPE'])) {
    $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
}

$entry = [
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'uri' => $_SERVER['REQUEST_URI'] ?? '',
    'headers' => $headers,
    'body' => json_decode($rawBody, true),
];
if ($logFile !== '') {
    file_put_contents($logFile, json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
}

header('Content-Type: application/json');

if ($mode === '429') {
    http_response_code(429);
    echo json_encode(['error' => ['message' => 'Rate limit exceeded', 'code' => 429]]);
    exit;
}

if ($mode === 'chat_blocks') {
    echo json_encode([
        'choices' => [
            [
                'message' => [
                    'role' => 'assistant',
                    'content' => [
                        ['type' => 'text', 'text' => 'Hello from '],
                        ['type' => 'text', 'text' => 'OpenRouter support!'],
                    ],
                ],
            ],
        ],
    ]);
    exit;
}

$contentJson = json_encode([
    'short_description' => 'Test Slot is a 5-reel video slot from Pragmatic Test featuring demo play and clear paytable rules.',
    'long_description' => '<p>Test Slot from Pragmatic Test is an informational slot guide with straightforward 5-reel mechanics.</p><h2>Rules and Winning Conditions</h2><p>Check the in-game paytable for symbol combinations, stake controls, RTP, and volatility details before playing.</p>',
], JSON_UNESCAPED_SLASHES);

echo json_encode([
    'id' => 'gen-mock-1',
    'choices' => [
        [
            'message' => [
                'role' => 'assistant',
                'content' => $contentJson,
            ],
        ],
    ],
]);
PHP);

$sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($sock === false) {
    throw new RuntimeException("Could not allocate local port: $errstr ($errno)");
}
$addr = stream_socket_get_name($sock, false);
fclose($sock);
$port = (int) substr((string) $addr, strrpos((string) $addr, ':') + 1);
$mockUrl = "http://127.0.0.1:{$port}/api/v1/chat/completions";

$serverEnv = array_merge($_ENV, [
    'MOCK_REQUESTS_LOG' => $requestsLog,
    'MOCK_MODE_FILE' => $modeFile,
]);
$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['file', $dir . '/server.out', 'a'],
    2 => ['file', $dir . '/server.err', 'a'],
];
$serverProc = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", $routerFile], $descriptors, $pipes, $dir, $serverEnv);
if (!is_resource($serverProc)) {
    throw new RuntimeException('Could not start mock OpenRouter HTTP server.');
}
fclose($pipes[0]);

$ready = false;
for ($i = 0; $i < 30; $i++) {
    $probe = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.1);
    if (is_resource($probe)) {
        fclose($probe);
        $ready = true;
        break;
    }
    usleep(100000);
}

function read_logged_requests(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    return array_values(array_filter(array_map(static fn(string $l) => json_decode($l, true), $lines), 'is_array'));
}

function run_cmd(array $cmd, array $env, string $cwd): array
{
    $desc = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($cmd, $desc, $p, $cwd, $env);
    if (!is_resource($proc)) {
        throw new RuntimeException('Failed to spawn command: ' . implode(' ', $cmd));
    }
    fclose($p[0]);
    $stdout = (string) stream_get_contents($p[1]);
    $stderr = (string) stream_get_contents($p[2]);
    fclose($p[1]);
    fclose($p[2]);
    $code = proc_close($proc);
    return ['code' => $code, 'stdout' => $stdout, 'stderr' => $stderr];
}

try {
    or_check($ready, 'Mock OpenRouter server started');

    putenv('APP_STORAGE_DIR=' . $storageDir);
    putenv('CHAT_STORAGE_DIR=' . $chatDir);
    putenv('SITE_BASE_URL=https://bybet.example');
    putenv('OPENROUTER_API_URL=' . $mockUrl);
    putenv('OPENROUTER_API_KEY=');
    putenv('OPENROUTER_MODEL=');
    putenv('CHAT_AI_MODEL=');
    putenv('OPENROUTER_WEB_SEARCH=false');

    require_once __DIR__ . '/../includes/chat-ai.php';

    // 1. Missing API key check on chat_openrouter_reply
    $missingKeyThrown = false;
    try {
        chat_openrouter_reply([['sender' => 'user', 'text' => 'Need help']], []);
    } catch (RuntimeException $e) {
        $missingKeyThrown = ($e->getMessage() === 'Missing OpenRouter API key.');
    }
    or_check($missingKeyThrown, 'chat_openrouter_reply rejects missing OPENROUTER_API_KEY');

    // 2. Live mock request on chat_openrouter_reply (multimodal array content blocks + default model)
    putenv('OPENROUTER_API_KEY=sk-or-test-key-123');
    file_put_contents($modeFile, 'chat_blocks');
    @unlink($requestsLog);

    $reply = chat_openrouter_reply([['sender' => 'user', 'text' => 'Hello support']], []);
    or_check($reply === 'Hello from OpenRouter support!', 'chat_openrouter_reply extracts multimodal text content blocks');

    $reqs = read_logged_requests($requestsLog);
    or_check(count($reqs) === 1, 'chat_openrouter_reply sent 1 HTTP request');
    or_check(($reqs[0]['uri'] ?? '') === '/api/v1/chat/completions', 'chat_openrouter_reply hits /api/v1/chat/completions');
    or_check(($reqs[0]['headers']['authorization'] ?? '') === 'Bearer sk-or-test-key-123', 'chat_openrouter_reply sends Bearer token');
    or_check(($reqs[0]['body']['model'] ?? '') === 'nvidia/nemotron-3.5-super-vl:free', 'chat_openrouter_reply defaults to nvidia/nemotron-3.5-super-vl:free');
    or_check(($reqs[0]['body']['reasoning'] ?? null) === ['effort' => 'low', 'exclude' => true], 'chat_openrouter_reply excludes reasoning tokens');

    // 3. Seed isolated games database for PHP & Python enrichment scripts
    $pdo = blogs_pdo();
    $insertGame = $pdo->prepare(
        'INSERT INTO games (api_id, name, slug, provider, provider_slug, type, type_slug, themes, reels, paylines, published, done_processing, is_viewable, created_at, updated_at)
         VALUES (:api_id, :name, :slug, :provider, :provider_slug, :type, :type_slug, :themes, :reels, :paylines, 1, 1, 1, :now, :now)'
    );
    $now = time();
    foreach ([
        [1001, 'PHP Enrich Slot', 'php-slot-test'],
        [1002, 'Python Enrich Slot', 'py-slot-test'],
        [1003, 'Python Rate Limit Slot', 'py-rate-limit-test'],
    ] as [$apiId, $name, $slug]) {
        $insertGame->execute([
            ':api_id' => $apiId,
            ':name' => $name,
            ':slug' => $slug,
            ':provider' => 'Pragmatic Test',
            ':provider_slug' => 'pragmatic-test',
            ':type' => 'Slot',
            ':type_slug' => 'slot',
            ':themes' => '["Adventure"]',
            ':reels' => '5',
            ':paylines' => '20',
            ':now' => $now,
        ]);
    }
    $insertGame = null;
    $pdo = null;

    $rootDir = dirname(__DIR__, 2);
    $subEnv = array_merge($_ENV, [
        'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
        'APP_STORAGE_DIR' => $storageDir,
        'CHAT_STORAGE_DIR' => $chatDir,
        'SITE_BASE_URL' => 'https://bybet.example',
        'OPENROUTER_API_KEY' => 'sk-or-test-key-123',
        'OPENROUTER_API_URL' => $mockUrl,
        'OPENROUTER_MODEL' => '',
        'OPENROUTER_WEB_SEARCH' => 'false',
    ]);

    // 4. Test PHP enrich-game-descriptions.php
    file_put_contents($modeFile, 'ok');
    @unlink($requestsLog);
    $phpRes = run_cmd([
        PHP_BINARY,
        'admin/plugin/games/scripts/enrich-game-descriptions.php',
        '--slug=php-slot-test',
        '--api-url=' . $mockUrl,
        '--retries=0',
    ], $subEnv, $rootDir);

    or_check($phpRes['code'] === 0, 'PHP enrich script exited 0: ' . $phpRes['stderr']);
    or_check(str_contains($phpRes['stdout'], 'Using OpenRouter model nvidia/nemotron-3.5-super-vl:free'), 'PHP enrich script logs default OpenRouter model');
    or_check(str_contains($phpRes['stdout'], 'Updated 1 game(s).'), 'PHP enrich script updated 1 game');

    $reqs = read_logged_requests($requestsLog);
    or_check(count($reqs) === 1, 'PHP enrich script sent 1 request');
    or_check(($reqs[0]['body']['model'] ?? '') === 'nvidia/nemotron-3.5-super-vl:free', 'PHP enrich request model is nvidia/nemotron-3.5-super-vl:free');
    or_check(($reqs[0]['body']['response_format']['type'] ?? '') === 'json_object', 'PHP enrich request uses json_object format');

    $checkPdo = new PDO('sqlite:' . $storageDir . '/games.sqlite');
    $stmt = $checkPdo->prepare('SELECT short_description, long_description, rtp, volatility FROM games WHERE slug = ?');
    $stmt->execute(['php-slot-test']);
    $phpRow = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    $stmt = null;
    $checkPdo = null;
    or_check(is_array($phpRow) && str_contains((string) $phpRow['short_description'], 'Test Slot'), 'PHP enrich saved short_description');
    or_check(str_starts_with(strtolower((string) $phpRow['long_description']), 'overview & game mechanics'), 'PHP enrich normalized long_description');
    or_check($phpRow['rtp'] !== null && $phpRow['volatility'] !== '', 'PHP enrich populated missing RTP and volatility');

    // 5. Test Python enrich-game-descriptions.py (normal success)
    @unlink($requestsLog);
    $pyRes = run_cmd([
        'python3',
        'admin/plugin/games/scripts/enrich-game-descriptions.py',
        '--slug=py-slot-test',
        '--api-url=' . $mockUrl,
        '--retries=0',
    ], $subEnv, $rootDir);

    or_check($pyRes['code'] === 0, 'Python enrich script exited 0: ' . $pyRes['stderr']);
    or_check(str_contains($pyRes['stdout'], 'Using OpenRouter model nvidia/nemotron-3.5-super-vl:free'), 'Python enrich script logs default OpenRouter model');
    or_check(str_contains($pyRes['stdout'], 'Updated 1 game(s).'), 'Python enrich script updated 1 game');

    $reqs = read_logged_requests($requestsLog);
    or_check(count($reqs) === 1, 'Python enrich script sent 1 request');
    or_check(($reqs[0]['headers']['authorization'] ?? '') === 'Bearer sk-or-test-key-123', 'Python enrich sends Bearer header');
    or_check(($reqs[0]['headers']['user-agent'] ?? '') === 'Site-Game-Enricher/1.0', 'Python enrich sets explicit User-Agent header');
    or_check(($reqs[0]['body']['model'] ?? '') === 'nvidia/nemotron-3.5-super-vl:free', 'Python enrich uses nvidia/nemotron-3.5-super-vl:free');
    or_check(($reqs[0]['body']['response_format']['type'] ?? '') === 'json_object', 'Python enrich uses json_object format');
    or_check(!isset($reqs[0]['body']['plugins']), 'Python enrich leaves paid web search plugin off by default');

    $checkPdo = new PDO('sqlite:' . $storageDir . '/games.sqlite');
    $stmt = $checkPdo->prepare('SELECT short_description, long_description, rewrite_status FROM games WHERE slug = ?');
    $stmt->execute(['py-slot-test']);
    $pyRow = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    $stmt = null;
    $checkPdo = null;
    or_check(is_array($pyRow) && ($pyRow['rewrite_status'] ?? '') === 'done', 'Python enrich marked rewrite_status=done');
    or_check(str_contains((string) $pyRow['short_description'], 'Test Slot'), 'Python enrich saved short_description');

    // 6. Test Python enrich-game-descriptions.py immediate stop on HTTP 429 (no retries)
    file_put_contents($modeFile, '429');
    @unlink($requestsLog);
    $py429 = run_cmd([
        'python3',
        'admin/plugin/games/scripts/enrich-game-descriptions.py',
        '--slug=py-rate-limit-test',
        '--api-url=' . $mockUrl,
        '--retries=2',
    ], $subEnv, $rootDir);

    or_check($py429['code'] === 1, 'Python enrich exits with code 1 on HTTP 429');
    or_check(str_contains($py429['stderr'], 'HTTP 429'), 'Python enrich logs HTTP 429 fatal error');
    $reqs429 = read_logged_requests($requestsLog);
    or_check(count($reqs429) === 1, 'Python enrich aborts immediately on HTTP 429 without retrying');

    $checkPdo = new PDO('sqlite:' . $storageDir . '/games.sqlite');
    $stmt = $checkPdo->prepare('SELECT rewrite_status FROM games WHERE slug = ?');
    $stmt->execute(['py-rate-limit-test']);
    $row429 = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    $stmt = null;
    $checkPdo = null;
    or_check(is_array($row429) && ($row429['rewrite_status'] ?? '') === 'failed', 'Python enrich marks rate-limited game as failed');

    echo "PASS: {$checks} OpenRouter chat + PHP/Python game enrichment checks passed.\n";
} finally {
    if (is_resource($serverProc)) {
        proc_terminate($serverProc);
        proc_close($serverProc);
    }
    if (is_dir($dir)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
