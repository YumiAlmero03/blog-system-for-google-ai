<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/blog-storage.php';
require_once __DIR__ . '/../includes/blog-renderer.php';

require_auth();
require_post();
require_valid_csrf();

header('Content-Type: application/json; charset=UTF-8');

$payload = blog_payload_from_post();
if (!$payload['ok']) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'errors' => $payload['errors']], JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    blog_validate_button_blocks($payload['blog']['content']);
    if (($_SESSION['user']['role'] ?? '') === 'editor') {
        $existing = blogs_find($payload['blog']['id']);
        preg_match_all('/^:::custom-code[ \t]*\n[\s\S]*?^:::[ \t]*$/m', str_replace(["\r\n","\r"],"\n",$existing['content'] ?? ''), $oldCode);
        preg_match_all('/^:::custom-code[ \t]*\n[\s\S]*?^:::[ \t]*$/m', str_replace(["\r\n","\r"],"\n",$payload['blog']['content']), $newCode);
        if ($oldCode[0] !== $newCode[0]) auth_deny(403,'Custom code changes require an administrator.');
        // Demo/button URLs also appear in the admin editor preview.
        preg_match_all('/^\s*url:\s*(.+)$/mi',$payload['blog']['content'],$blockUrls);
        foreach ($blockUrls[1] as $url) {
            $url = html_entity_decode(trim($url),ENT_QUOTES | ENT_HTML5,'UTF-8');
            if (!preg_match('~^(?:https?://[^\s<>]+|/(?!/)[^\s<>]*)$~i',$url)) auth_deny(403,'Use an HTTP(S) or site-relative block URL.');
        }
    }
    $duplicateErrors = blog_duplicate_validation_errors($payload['blog']);
    if ($duplicateErrors !== []) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'errors' => $duplicateErrors, 'csrfToken' => csrf_token()], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $saved = blogs_upsert($payload['blog']);
} catch (InvalidArgumentException $exception) {
    http_response_code(422);
    echo json_encode(['ok'=>false,'error'=>$exception->getMessage(),'csrfToken'=>csrf_token()]);
    exit;
} catch (Throwable $exception) {
    error_log('Blog storage error: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Blog storage is unavailable.', 'csrfToken' => csrf_token()], JSON_UNESCAPED_SLASHES);
    exit;
}

csrf_rotate();
echo json_encode(['ok' => true, 'blog' => $saved, 'csrfToken' => csrf_token()], JSON_UNESCAPED_SLASHES);
