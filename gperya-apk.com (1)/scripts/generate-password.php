<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    echo "Not found.\n";
    exit(1);
}

$password = null;

if (isset($argv[1])) {
    if ($argv[1] === '--stdin') {
        $password = rtrim((string) stream_get_contents(STDIN), "\r\n");
    } else {
        fwrite(STDERR, "Usage: php scripts/generate-password.php [--stdin]\n");
        exit(1);
    }
} else {
    fwrite(STDOUT, 'Password: ');
    if (stripos(PHP_OS_FAMILY, 'Windows') === false) {
        system('stty -echo');
    }
    $password = rtrim((string) fgets(STDIN), "\r\n");
    if (stripos(PHP_OS_FAMILY, 'Windows') === false) {
        system('stty echo');
    }
    fwrite(STDOUT, "\n");
}

if ($password === '' || strlen($password) > 512) {
    fwrite(STDERR, "Password must be non-empty and at most 512 bytes.\n");
    exit(1);
}

$algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
fwrite(STDOUT, password_hash($password, $algo) . "\n");
