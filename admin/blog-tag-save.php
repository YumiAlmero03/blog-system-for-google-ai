<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/blog-storage.php';

require_auth();
require_post();
require_valid_csrf();

header('Content-Type: application/json; charset=UTF-8');

$action = request_string('action', 20) ?? 'save';

try {
    if ($action === 'delete') {
        $id = request_string('id', 96);
        if ($id === null) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Tag id is required.', 'csrfToken' => csrf_token()], JSON_UNESCAPED_SLASHES);
            exit;
        }

        $result = blog_taxonomy_delete('tag', $id);
        if (!$result['ok']) {
            http_response_code(422);
            echo json_encode($result + ['csrfToken' => csrf_token()], JSON_UNESCAPED_SLASHES);
            exit;
        }

        csrf_rotate();
        echo json_encode($result + ['csrfToken' => csrf_token()], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $result = blog_taxonomy_save('tag', [
        'id' => request_string('id', 96) ?? '',
        'name' => request_string('name', 50) ?? '',
        'slug' => $_POST['slug'] ?? '',
    ]);

    if (!$result['ok']) {
        http_response_code(422);
        echo json_encode($result + ['csrfToken' => csrf_token()], JSON_UNESCAPED_SLASHES);
        exit;
    }

    csrf_rotate();
    echo json_encode($result + ['csrfToken' => csrf_token()], JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    error_log('Blog tag save error: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Blog tags are unavailable.', 'csrfToken' => csrf_token()], JSON_UNESCAPED_SLASHES);
}
