<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    echo "Not found.\n";
    exit(1);
}

require_once __DIR__ . '/../includes/blog-storage.php';

function cli_option(string $name): ?string
{
    global $argv;

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === $name) {
            return '1';
        }

        if (str_starts_with($arg, $name . '=')) {
            return substr($arg, strlen($name) + 1);
        }
    }

    return null;
}

function cli_int_option(string $name, int $default, int $min, int $max): int
{
    $value = cli_option($name);
    if ($value === null || !preg_match('/^\d+$/', $value)) {
        return $default;
    }

    return max($min, min($max, (int) $value));
}

function ollama_generate_url(): string
{
    $explicitUrl = env_value('OLLAMA_GENERATE_URL') ?: cli_option('--ollama-url');
    if (is_string($explicitUrl) && trim($explicitUrl) !== '') {
        return trim($explicitUrl);
    }

    return ollama_base_url() . '/api/generate';
}

function ollama_model(): string
{
    $model = cli_option('--model') ?: env_value('OLLAMA_MODEL') ?: ollama_default_model();
    return trim((string) $model);
}

function ollama_base_url(): string
{
    $host = env_value('OLLAMA_HOST');
    if (!is_string($host) || trim($host) === '') {
        $host = 'http://127.0.0.1:11434';
    }

    return rtrim(trim($host), '/');
}

function ollama_installed_models(): array
{
    $url = ollama_base_url() . '/api/tags';
    $raw = false;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch !== false) {
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 10,
            ]);
            $raw = curl_exec($ch);
        }
    } else {
        $raw = @file_get_contents($url);
    }

    if (!is_string($raw) || $raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || !isset($decoded['models']) || !is_array($decoded['models'])) {
        return [];
    }

    $models = [];
    foreach ($decoded['models'] as $model) {
        if (is_array($model) && isset($model['name']) && is_string($model['name'])) {
            $models[] = $model['name'];
        }
    }

    return $models;
}

function ollama_default_model(): string
{
    $models = ollama_installed_models();
    foreach (['qwen3.5:9b', 'qwen3:8b', 'qwen3.6:latest', 'gemma4:31b-cloud', 'tinyllama:latest'] as $preferred) {
        if (in_array($preferred, $models, true)) {
            return $preferred;
        }
    }

    return $models[0] ?? 'qwen3.5:9b';
}

function random_rtp(): float
{
    return mt_rand(7000, 9900) / 100;
}

function random_volatility(): string
{
    $values = ['low', 'medium', 'high'];
    return $values[array_rand($values)];
}

function clamp_short_description(string $value, int $max = 159): string
{
    $value = trim(preg_replace('/\s+/', ' ', strip_tags($value)) ?? '');
    if (strlen($value) <= $max) {
        return $value;
    }

    return rtrim(substr($value, 0, $max - 1), " \t\n\r\0\x0B.,;:") . '.';
}

function game_theme_text(array $game): string
{
    $themes = json_decode((string) ($game['themes'] ?? '[]'), true);
    if (!is_array($themes) || $themes === []) {
        return '';
    }

    return implode(', ', array_slice(array_map('strval', $themes), 0, 5));
}

function game_context(array $game, float $rtp, string $volatility): string
{
    $themes = game_theme_text($game);

    return implode("\n", array_filter([
        'Name: ' . (string) $game['name'],
        'Provider: ' . ((string) ($game['provider'] ?? '') ?: 'Unknown'),
        'Type: ' . ((string) ($game['type'] ?? '') ?: 'Slot'),
        $themes !== '' ? 'Themes: ' . $themes : '',
        'RTP: ' . number_format($rtp, 2) . '%',
        'Volatility: ' . $volatility,
        ((string) ($game['reels'] ?? '')) !== '' ? 'Reels: ' . (string) $game['reels'] : '',
        ((string) ($game['paylines'] ?? '')) !== '' ? 'Paylines: ' . (string) $game['paylines'] : '',
    ]));
}

function fallback_generated_copy(array $game, float $rtp, string $volatility): array
{
    $name = trim((string) ($game['name'] ?? 'This game')) ?: 'This game';
    $provider = trim((string) ($game['provider'] ?? '')) ?: 'the provider';
    $type = trim((string) ($game['type'] ?? '')) ?: 'slot';
    $themes = game_theme_text($game);
    $themeSentence = $themes !== ''
        ? 'Its theme profile includes ' . $themes . ', giving the round structure a clear visual direction.'
        : 'Its presentation is built around clear reels, readable symbols, and a straightforward play flow.';
    $reels = trim((string) ($game['reels'] ?? ''));
    $paylines = trim((string) ($game['paylines'] ?? ''));
    $mechanics = [];
    if ($reels !== '') {
        $mechanics[] = $reels . ' reels';
    }
    if ($paylines !== '') {
        $mechanics[] = $paylines . ' paylines';
    }
    $mechanicSentence = $mechanics !== []
        ? 'The saved game data lists ' . implode(' and ', $mechanics) . ', which helps players understand the basic layout before opening the demo.'
        : 'The layout should be reviewed in demo mode first, since reel count, paylines, and feature triggers can vary by title.';

    $short = clamp_short_description($name . ' is a ' . $type . ' from ' . $provider . ' with ' . number_format($rtp, 2) . '% RTP and ' . strtolower($volatility) . ' volatility.');

    $paragraphs = [
        'Overview & Game Mechanics',
        $name . ' is an informational game listing for players who want to understand the basic feel of the title before opening it in demo mode. The game is categorized as a ' . $type . ' and is associated with ' . $provider . '. While the exact symbol set and bonus behavior should always be confirmed inside the live game client, the available data gives enough context to outline how players can approach the experience responsibly. ' . $themeSentence,
        'From a mechanics perspective, the first thing to check is how the base game presents its rounds. Most modern casino games use a repeating spin or instant-win cycle where the player chooses a stake, starts a round, and waits for the result animation to resolve. ' . $mechanicSentence . ' A demo session is useful because it lets players inspect controls, bet increments, audio settings, autoplay options, and feature screens without using real funds.',
        'The estimated RTP for this listing is ' . number_format($rtp, 2) . '%. RTP, or return to player, is a long-term mathematical indicator rather than a short-session prediction. A title with this RTP can still produce uneven outcomes over a small number of rounds. That is why RTP should be read together with volatility. This game is marked as ' . strtolower($volatility) . ' volatility, which describes how payouts may be distributed. Lower volatility usually points toward steadier but smaller results, while higher volatility can mean longer quiet stretches mixed with larger feature potential.',
        'The practical way to evaluate ' . $name . ' is to start with the free demo. In demo mode, players can watch how frequently special symbols appear, how quickly rounds resolve, and whether the feature pacing feels comfortable. The goal is not to predict a win, but to understand the rhythm of play. If the game includes bonus rounds, multipliers, respins, free spins, or collection mechanics, those features should be treated as entertainment elements rather than guaranteed value.',
        'Bankroll pacing matters even when a title looks simple. A sensible approach is to choose a stake that allows many rounds instead of placing a large amount into only a few spins. This gives a clearer picture of the game flow and reduces the pressure of short-term variance. Players should also check whether quick spin or autoplay options are enabled, because those settings can make a balance move faster than expected.',
        'On mobile, readability and control spacing are especially important. Before playing for real, users should confirm that the buttons are easy to tap, the paytable can be opened clearly, and the game frame fits the screen without hiding important controls. A smooth mobile demo is a good sign that the title will be easier to understand during longer sessions.',
        'Overall, ' . $name . ' should be approached as a game to inspect first and play carefully if moving beyond demo mode. Review the paytable, understand the stake controls, note the volatility level, and avoid treating any single round as representative of the long-term math. The best use of this page is to preview the mechanics, compare the title with other games, and decide whether its pacing matches your preferred style of casino entertainment.',
    ];

    return [
        'short_description' => $short,
        'long_description' => implode("\n\n", $paragraphs),
    ];
}

function build_prompt(array $game, float $rtp, string $volatility): string
{
    return "Do not use thinking mode. Return the final answer only as valid JSON.\n"
        . "Create original informational casino game copy from the facts below.\n\n"
        . game_context($game, $rtp, $volatility)
        . "\n\nReturn only valid JSON with exactly these keys:\n"
        . "- short_description: under 160 characters, one sentence, no hype claims.\n"
        . "- long_description: 650 to 800 words, written under the topic \"Overview & Game Mechanics\". Explain likely gameplay flow, symbols/features in general terms, RTP, volatility, demo play, bankroll pacing, and mobile experience. Do not promise winnings. Do not mention that you are an AI. Do not invent official license details.\n";
}

function ollama_generate(string $url, string $model, string $prompt, int $timeout): array
{
    $payload = json_encode([
        'model' => $model,
        'prompt' => $prompt,
        'stream' => false,
        'format' => 'json',
        'think' => false,
        'options' => [
            'temperature' => 0.75,
            'top_p' => 0.9,
            'num_predict' => 1400,
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if (!is_string($payload)) {
        throw new RuntimeException('Could not encode Ollama request.');
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Could not initialize cURL.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeout,
        ]);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);

        if (!is_string($raw) || $raw === '') {
            throw new RuntimeException('Ollama returned an empty response.' . ($error !== '' ? ' cURL error: ' . $error : ''));
        }
        if ($status >= 400) {
            $models = ollama_installed_models();
            $suffix = $status === 404 && $models !== []
                ? ' Installed models: ' . implode(', ', $models) . '. Use --model=' . $models[0] . ' or set OLLAMA_MODEL.'
                : '';
            throw new RuntimeException('Ollama returned HTTP ' . $status . ': ' . substr($raw, 0, 300) . $suffix);
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $payload,
                'timeout' => $timeout,
            ],
        ]);
        $raw = @file_get_contents($url, false, $context);
        if (!is_string($raw) || $raw === '') {
            throw new RuntimeException('Ollama returned an empty response.');
        }
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || !isset($decoded['response']) || !is_string($decoded['response'])) {
        throw new RuntimeException('Ollama returned unexpected JSON: ' . substr($raw, 0, 300));
    }

    $content = trim($decoded['response']);
    if ($content === '' && isset($decoded['thinking']) && is_string($decoded['thinking'])) {
        throw new RuntimeException('Ollama finished thinking but returned an empty final response. Thinking chars: ' . strlen($decoded['thinking']));
    }

    $result = decode_generated_json($content);
    if (!is_array($result)) {
        throw new RuntimeException('Ollama response was not valid content JSON: ' . substr($content, 0, 300));
    }

    return $result;
}

function decode_generated_json(string $content): ?array
{
    $content = trim($content);
    if ($content === '') {
        return null;
    }

    $decoded = json_decode($content, true);
    if (is_array($decoded)) {
        return $decoded;
    }

    if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/is', $content, $matches) === 1) {
        $decoded = json_decode($matches[1], true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    $start = strpos($content, '{');
    $end = strrpos($content, '}');
    if ($start !== false && $end !== false && $end > $start) {
        $decoded = json_decode(substr($content, $start, $end - $start + 1), true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    return null;
}

function ollama_generate_with_retries(string $url, string $model, string $prompt, int $timeout, int $retries): array
{
    $attempts = max(1, $retries + 1);
    $lastException = null;

    for ($attempt = 1; $attempt <= $attempts; $attempt++) {
        try {
            return ollama_generate($url, $model, $prompt, $timeout);
        } catch (Throwable $exception) {
            $lastException = $exception;
            if ($attempt < $attempts) {
                fwrite(STDERR, 'Ollama attempt ' . $attempt . ' failed: ' . $exception->getMessage() . "\n");
                sleep(min(5, $attempt));
            }
        }
    }

    throw $lastException instanceof Throwable ? $lastException : new RuntimeException('Ollama generation failed.');
}

function normalize_long_description(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    if (!str_starts_with(strtolower($value), 'overview & game mechanics')) {
        $value = "Overview & Game Mechanics\n\n" . $value;
    }

    return $value;
}

$limit = cli_int_option('--limit', 25, 1, 500);
$offset = cli_int_option('--offset', 0, 0, 10000000);
$timeout = cli_int_option('--timeout', 420, 30, 3600);
$retries = cli_int_option('--retries', 1, 0, 5);
$overwrite = cli_option('--overwrite') !== null;
$dryRun = cli_option('--dry-run') !== null;
$skipErrors = cli_option('--skip-errors') !== null;
$fallbackOnly = cli_option('--fallback-only') !== null;
$slug = cli_option('--slug');
$model = ollama_model();
$url = ollama_generate_url();

$where = [];
$params = [];
if (!$overwrite) {
    $where[] = '(rtp IS NULL OR volatility = "" OR short_description = "" OR long_description = "")';
}
if (is_string($slug) && trim($slug) !== '') {
    $where[] = 'slug = :slug';
    $params[':slug'] = normalize_slug($slug);
}
$whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

$pdo = blogs_pdo();
$stmt = $pdo->prepare(
    "SELECT id, name, slug, provider, type, themes, reels, paylines, rtp, volatility, short_description, long_description
     FROM games
     {$whereSql}
     ORDER BY updated_at DESC, id DESC
     LIMIT :limit OFFSET :offset"
);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value, PDO::PARAM_STR);
}
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$games = $stmt->fetchAll();

echo 'Using Ollama model ' . $model . ' at ' . $url . "\n";
echo 'Timeout ' . $timeout . 's, retries ' . $retries . ($skipErrors ? ', skip errors on' : '') . ($fallbackOnly ? ', fallback only on' : '') . ".\n";
echo 'Found ' . count($games) . " game(s) to enrich.\n";

$update = $pdo->prepare(
    'UPDATE games
     SET rtp = :rtp,
         volatility = :volatility,
         short_description = :short_description,
         long_description = :long_description,
         updated_at = :updated_at
     WHERE id = :id'
);

$updated = 0;
foreach ($games as $game) {
    $rtp = $overwrite || $game['rtp'] === null || $game['rtp'] === '' ? random_rtp() : (float) $game['rtp'];
    $volatility = $overwrite || trim((string) $game['volatility']) === '' ? random_volatility() : trim((string) $game['volatility']);

    echo 'Generating: ' . $game['slug'] . "\n";
    $generated = null;
    if (!$fallbackOnly) {
        try {
            $generated = ollama_generate_with_retries($url, $model, build_prompt($game, $rtp, $volatility), $timeout, $retries);
        } catch (Throwable $exception) {
            fwrite(STDERR, 'Using fallback for ' . $game['slug'] . ': ' . $exception->getMessage() . "\n");
        }
    }

    if (!is_array($generated)) {
        $generated = fallback_generated_copy($game, $rtp, $volatility);
    }

    $short = $overwrite || trim((string) $game['short_description']) === ''
        ? clamp_short_description((string) ($generated['short_description'] ?? ''))
        : (string) $game['short_description'];
    $long = $overwrite || trim((string) $game['long_description']) === ''
        ? normalize_long_description((string) ($generated['long_description'] ?? ''))
        : (string) $game['long_description'];

    if ($short === '' || $long === '') {
        throw new RuntimeException('Ollama did not generate required descriptions for ' . $game['slug'] . '.');
    }

    if ($dryRun) {
        echo 'Dry run: RTP ' . number_format($rtp, 2) . ', volatility ' . $volatility . ', short ' . strlen($short) . " chars.\n";
        continue;
    }

    $update->execute([
        ':rtp' => $rtp,
        ':volatility' => $volatility,
        ':short_description' => $short,
        ':long_description' => $long,
        ':updated_at' => time(),
        ':id' => (int) $game['id'],
    ]);
    $updated++;
}

echo 'Updated ' . $updated . " game(s).\n";
