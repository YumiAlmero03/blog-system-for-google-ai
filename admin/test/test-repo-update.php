<?php
/**
 * Test Git repository update checking and update execution.
 *
 * Usage: php admin/test/test-repo-update.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/blog-storage.php';
require_once __DIR__ . '/../includes/repo-update.php';

function assert_repo_test(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $message);
    }
}

// 1. Basic Git environment checks
assert_repo_test(repo_is_git(), 'Current workspace must be recognized as a Git repository');
assert_repo_test(repo_git_binary() !== null, 'Git binary must be detected');

// 2. Status inspection
$status = repo_current_status(false);
assert_repo_test($status['ok'] === true, 'repo_current_status must return ok: true');
assert_repo_test($status['is_git'] === true, 'repo_current_status is_git must be true');
assert_repo_test(is_string($status['branch']) && $status['branch'] !== '', 'branch must be non-empty string');
assert_repo_test(is_array($status['head']) && !empty($status['head']['short']), 'head short hash must be populated');
assert_repo_test(is_string($status['remote']) && $status['remote'] !== '', 'remote must be detected');
assert_repo_test(is_string($status['remote_branch']) && $status['remote_branch'] !== '', 'remote_branch must be detected');
assert_repo_test(is_int($status['behind_count']), 'behind_count must be integer');
assert_repo_test(is_int($status['ahead_count']), 'ahead_count must be integer');
assert_repo_test(is_bool($status['has_update']), 'has_update must be boolean');
assert_repo_test(is_bool($status['has_uncommitted']), 'has_uncommitted must be boolean');

// 3. Force fetch check
$forced = repo_current_status(true);
assert_repo_test($forced['ok'] === true, 'Forced repo_current_status must return ok: true');
assert_repo_test(!empty($forced['checked_at']), 'checked_at timestamp must be present');

// 4. Test cache validity
$cached = repo_current_status(false);
assert_repo_test($cached['ok'] === true, 'Cached status must return ok: true');
assert_repo_test(!empty($cached['from_cache']), 'Subsequent call must return from_cache: true');

// 5. Test endpoint response from admin/settings.php
$root = repo_root_dir();
$cmd = sprintf(
    'php -r %s',
    escapeshellarg('
        $_SERVER["REQUEST_METHOD"] = "GET";
        $_GET["action"] = "check_repo_status";
        require "' . $root . '/admin/includes/auth.php";
        $hash = env_value("ADMIN_PASSWORD_HASH") ?? "";
        auth_mark_authenticated([
            "id" => "admin:env",
            "account_id" => 1,
            "role" => "super_user",
            "display_name" => "Admin",
            "auth_version" => 1,
            "credential_fingerprint" => hash("sha256", $hash),
        ]);
        $_COOKIE[AUTH_ACCESS_COOKIE] = jwt_sign([
            "aud" => "admin",
            "scope" => "admin:access",
            "sub" => "admin:env",
            "user_id" => "admin:env",
            "role" => "super_user",
            "ver" => 1,
            "sid" => $_SESSION["auth_binding"],
            "iat" => time(),
            "nbf" => time(),
            "exp" => time() + 3600,
        ], auth_jwt_secret());
        ob_start();
        require "' . $root . '/admin/settings.php";
        $out = ob_get_clean();
        echo $out;
    ')
);
$endpointOutput = shell_exec($cmd);
$json = json_decode((string) $endpointOutput, true);
assert_repo_test(is_array($json), 'Settings endpoint action=check_repo_status must output valid JSON');
assert_repo_test(($json['ok'] ?? false) === true, 'Endpoint JSON must indicate ok: true');
assert_repo_test(($json['is_git'] ?? false) === true, 'Endpoint JSON must indicate is_git: true');

echo "All repo update tests passed.\n";
