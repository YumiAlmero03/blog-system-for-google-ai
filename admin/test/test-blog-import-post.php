<?php
/**
 * Tests importing blog posts from JSON format via blog-import-post.php.
 *
 * Checks validation, CSRF, authentication, HTML-to-Markdown conversion,
 * taxonomy (categories and tags creation), and idempotent updates.
 *
 * Run from www: php admin/test/test-blog-import-post.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

if (($argv[1] ?? '') === '--handler') {
    $request = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    ini_set('session.save_path', getenv('APP_STORAGE_DIR'));
    require_once __DIR__ . '/../includes/auth.php';
    $_SERVER['SCRIPT_NAME'] = '/admin/blog-import-post.php';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_ACCEPT'] = 'application/json';
    if (isset($request['role'])) {
        $user = auth_authenticate_credentials($request['role'], 'Import test password 123!');
        auth_mark_authenticated($user);
        $_COOKIE[AUTH_ACCESS_COOKIE] = jwt_sign([
            'aud' => 'admin',
            'scope' => 'admin:access',
            'sub' => $user['id'],
            'user_id' => $user['id'],
            'role' => $user['role'],
            'ver' => $user['auth_version'],
            'sid' => $_SESSION['auth_binding'],
            'iat' => time(),
            'nbf' => time(),
            'exp' => time() + 900,
        ], auth_jwt_secret());
    }
    $_POST = $request['post'] ?? [];
    if (isset($request['role'])) {
        $_POST['csrf_token'] = !empty($request['bad_csrf']) ? 'bad' : csrf_token();
    }
    if (isset($request['files']['import_file'])) {
        $tempFile = tempnam(sys_get_temp_dir(), 'test_upload_');
        file_put_contents($tempFile, $request['files']['import_file']['content']);
        $_FILES['import_file'] = [
            'name' => $request['files']['import_file']['name'],
            'type' => 'application/json',
            'tmp_name' => $tempFile,
            'error' => UPLOAD_ERR_OK,
            'size' => strlen($request['files']['import_file']['content']),
        ];
    }
    register_shutdown_function(static function (): void {
        fwrite(STDERR, 'STATUS:' . (http_response_code() ?: 200));
    });
    require __DIR__ . '/../blog-import-post.php';
    exit;
}

$dir = sys_get_temp_dir() . '/import-post-' . bin2hex(random_bytes(6));
mkdir($dir, 0700);
putenv('APP_STORAGE_DIR=' . $dir);
putenv('ADMIN_USERNAME=super_user');
putenv('ADMIN_PASSWORD_HASH=' . password_hash('Import test password 123!', PASSWORD_DEFAULT));
putenv('ADMIN_JWT_SECRET=' . str_repeat('import-test-', 5));
ini_set('session.save_path', $dir);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/blog-storage.php';
require_once __DIR__ . '/../includes/blog-taxonomy.php';

function import_check(bool $ok, string $label): void
{
    if (!$ok) throw new RuntimeException("Assertion failed: $label");
}

function import_handler(array $request): array
{
    $process = proc_open([PHP_BINARY, __FILE__, '--handler'], [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    fwrite($pipes[0], json_encode($request));
    fclose($pipes[0]);
    $body = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    import_check(proc_close($process) === 0, 'Handler failure: ' . $error);
    import_check(preg_match('/^STATUS:(\d+)$/', $error, $match) === 1, 'Unexpected handler warnings: ' . $error);
    return [(int) $match[1], json_decode($body, true), $body];
}

try {
    $pdo = blogs_pdo();
    blogs_schema($pdo);
    $_SESSION['user'] = auth_authenticate_credentials('super_user', 'Import test password 123!');

    // 1. Unauthenticated request must be denied
    [$status, $res] = import_handler([
        'post' => ['json_data' => '{}'],
    ]);
    import_check($status === 403, 'Unauthenticated request blocked (403)');

    // 2. Bad CSRF must be rejected
    [$status, $res] = import_handler([
        'role' => 'super_user',
        'bad_csrf' => true,
        'post' => ['json_data' => '{}'],
    ]);
    import_check($status === 403, 'Bad CSRF token blocked (403)');

    // 3. Non-JSON or invalid JSON format rejected
    [$status, $res] = import_handler([
        'role' => 'super_user',
        'post' => ['json_data' => 'not json at all'],
    ]);
    import_check($status === 422, 'Invalid JSON string rejected (422)');
    import_check(!empty($res['error']), 'Error message returned for invalid JSON');

    // 4. Missing title rejected
    [$status, $res] = import_handler([
        'role' => 'super_user',
        'post' => ['json_data' => json_encode(['content_html' => '<p>hello</p>'])],
    ]);
    import_check($status === 422, 'Missing title rejected (422)');

    // 5. Missing content rejected
    [$status, $res] = import_handler([
        'role' => 'super_user',
        'post' => ['json_data' => json_encode(['title' => 'Sample Post'])],
    ]);
    import_check($status === 422, 'Missing content rejected (422)');

    // 6. Non-json file extension rejected
    [$status, $res] = import_handler([
        'role' => 'super_user',
        'files' => [
            'import_file' => [
                'name' => 'archive.zip',
                'content' => 'dummy zip',
            ],
        ],
    ]);
    import_check($status === 422, 'Non-JSON file upload rejected (422)');

    // 7. Full import with user sample JSON file
    $userJson = json_encode([
        'schema_version' => 1,
        'generator' => 'blog',
        'status' => 'draft',
        'title' => 'The Strategic Importance of a bygame knight in Online Gaming',
        'content_html' => '<h2>Mastering the Art of Medieval Combat</h2><p>Many players enter the world of virtual battles without a clear plan. Check out the <a href="http://localhost:8082/" rel="nofollow noopener noreferrer" target="_blank">learning tools</a> offered by ByGame.</p>',
        'meta_description' => 'Want to dominate your opponents? Explore the strategic importance of the bygame knight.',
        'category' => 'General',
        'primary_keyword' => 'bygame knight',
        'brand' => 'Bygame',
        'language' => 'English',
        'slug' => 'the-strategic-importance-of-a-bygame-knight-in-online-gaming',
        'tags' => [
            'bygame knight',
            'online gaming',
            'gaming strategy',
            'tanking',
            'crowd control',
        ],
    ]);

    [$status, $res] = import_handler([
        'role' => 'super_user',
        'files' => [
            'import_file' => [
                'name' => 'the-strategic-importance-of-a-bygame-knight-in-online-gaming.json',
                'content' => $userJson,
            ],
        ],
    ]);

    import_check($status === 200, 'Import user JSON succeeded (200)');
    import_check($res['ok'] === true, 'Response ok is true');
    import_check(!empty($res['blog']), 'Response contains blog object');
    $blog = $res['blog'];

    import_check($blog['title'] === 'The Strategic Importance of a bygame knight in Online Gaming', 'Blog title matches');
    import_check($blog['slug'] === 'the-strategic-importance-of-a-bygame-knight-in-online-gaming', 'Blog slug matches');
    import_check($blog['status'] === 'draft', 'Blog status is draft');
    import_check($blog['excerpt'] === 'Want to dominate your opponents? Explore the strategic importance of the bygame knight.', 'Meta description mapped to excerpt');
    import_check($blog['focusKeyphrase'] === 'bygame knight', 'Primary keyword mapped to focusKeyphrase');
    import_check($blog['author'] === 'Bygame', 'Brand mapped to author');
    import_check(str_contains($blog['content'], '## Mastering the Art of Medieval Combat'), 'HTML h2 converted to Markdown ##');
    import_check(str_contains($blog['content'], '[learning tools](http://localhost:8082/){nofollow}'), 'HTML link converted to Markdown with nofollow');

    // Verify category General
    import_check($blog['category'] === 'General', 'Category label is General');
    import_check(!empty($blog['categoryId']), 'Category ID populated');

    // Verify tags created in DB and associated with post
    import_check(count($blog['tagIds']) === 5, 'All 5 tags assigned to post');
    import_check(count($blog['tags']) === 5, '5 tag objects populated');
    $tagNames = array_column($blog['tags'], 'name');
    import_check(in_array('bygame knight', $tagNames, true), 'Tag "bygame knight" exists');
    import_check(in_array('online gaming', $tagNames, true), 'Tag "online gaming" exists');

    // 8. Re-importing should update without duplicate slug error
    $updatedJson = json_encode([
        'schema_version' => 1,
        'generator' => 'blog',
        'status' => 'draft',
        'title' => 'The Strategic Importance of a bygame knight in Online Gaming (Updated)',
        'content_html' => '<h2>Updated Combat</h2><p>Updated body.</p>',
        'meta_description' => 'Updated meta.',
        'category' => 'General',
        'primary_keyword' => 'bygame knight updated',
        'brand' => 'Bygame',
        'language' => 'English',
        'slug' => 'the-strategic-importance-of-a-bygame-knight-in-online-gaming',
        'tags' => ['bygame knight', 'updated tag'],
    ]);

    [$status2, $res2] = import_handler([
        'role' => 'super_user',
        'files' => [
            'import_file' => [
                'name' => 'the-strategic-importance-of-a-bygame-knight-in-online-gaming.json',
                'content' => $updatedJson,
            ],
        ],
    ]);

    import_check($status2 === 200, 'Re-import succeeded (200)');
    import_check($res2['ok'] === true, 'Re-import ok is true');
    import_check($res2['blog']['title'] === 'The Strategic Importance of a bygame knight in Online Gaming (Updated)', 'Title updated');
    // 9. Test importing the actual file if present in Downloads
    $downloadsFile = '/Users/lanie/Downloads/the-strategic-importance-of-a-bygame-knight-in-online-gaming.json';
    if (file_exists($downloadsFile)) {
        $actualContent = file_get_contents($downloadsFile);
        [$status3, $res3] = import_handler([
            'role' => 'super_user',
            'files' => [
                'import_file' => [
                    'name' => basename($downloadsFile),
                    'content' => $actualContent,
                ],
            ],
        ]);
        import_check($status3 === 200, 'Import Downloads file succeeded (200)');
        import_check($res3['ok'] === true, 'Downloads import ok');
        import_check(count($res3['blog']['tagIds']) === 12, 'All 12 tags imported from Downloads file');
        import_check($res3['blog']['slug'] === 'the-strategic-importance-of-a-bygame-knight-in-online-gaming', 'Downloads file slug matched');
    }

    // 10. Test importing post with HTML table and FAQs
    $tableFaqJson = json_encode([
        'schema_version' => 1,
        'generator' => 'blog',
        'status' => 'draft',
        'title' => 'Table and FAQ Test Post',
        'content_html' => '<h2>Comparing Items</h2><table><thead><tr><th>Plan</th><th>Cost</th></tr></thead><tbody><tr><td>Basic</td><td>$10</td></tr><tr><td>Pro</td><td>$20</td></tr></tbody></table><h2>FAQs</h2><h3>How much is it?</h3><p>It starts at $10.</p><h3>Can I cancel?</h3><p>Yes, at any time.</p><h2>Conclusion</h2><p>Wrap up.</p>',
        'meta_description' => 'Table and FAQ description.',
        'category' => 'Informational',
        'primary_keyword' => 'test plan',
        'brand' => 'Bygame',
        'slug' => 'table-and-faq-test-post',
        'tags' => ['plans', 'pricing'],
    ]);

    [$statusTableFaq, $resTableFaq] = import_handler([
        'role' => 'super_user',
        'files' => [
            'import_file' => [
                'name' => 'table-and-faq-test-post.json',
                'content' => $tableFaqJson,
            ],
        ],
    ]);

    import_check($statusTableFaq === 200, 'Table and FAQ import succeeded (200)');
    import_check($resTableFaq['ok'] === true, 'Table and FAQ ok is true');
    $importedContent = $resTableFaq['blog']['content'];
    import_check(str_contains($importedContent, ':::table'), 'Imported content contains :::table block');
    import_check(str_contains($importedContent, 'headings: true'), 'Imported table has headings: true');
    import_check(str_contains($importedContent, '| Plan | Cost |'), 'Imported table header row present');
    import_check(str_contains($importedContent, '| Basic | $10 |'), 'Imported table body row present');
    import_check(str_contains($importedContent, ':::faq'), 'Imported content contains :::faq block');
    import_check(str_contains($importedContent, 'Q: How much is it?'), 'Imported FAQ question 1 present');
    import_check(str_contains($importedContent, 'A: It starts at $10.'), 'Imported FAQ answer 1 present');
    import_check(str_contains($importedContent, 'Q: Can I cancel?'), 'Imported FAQ question 2 present');
    import_check(str_contains($importedContent, 'A: Yes, at any time.'), 'Imported FAQ answer 2 present');

    // Verify public renderer handles the table and FAQ
    require_once __DIR__ . '/../includes/blog-renderer.php';
    $renderedPost = blog_render_content($importedContent);
    import_check(str_contains($renderedPost['content_html'], 'blog-table-block'), 'Rendered content has blog-table-block');
    import_check(str_contains($renderedPost['content_html'], 'blog-faq-block'), 'Rendered content has blog-faq-block');
    import_check(!empty($renderedPost['faq_schema']), 'Rendered content generates faq_schema');
    import_check(count($renderedPost['faq_schema']['mainEntity'] ?? []) === 2, 'faq_schema contains 2 questions');

    // 11. Test importing Markdown FAQ conversion fallback
    $mdFaqJson = json_encode([
        'schema_version' => 1,
        'generator' => 'blog',
        'status' => 'draft',
        'title' => 'Markdown FAQ Fallback Test',
        'content' => "## FAQs\n\n### Question One?\nAnswer one.\n\n### Question Two?\nAnswer two.\n\n## Next Header\nText.",
        'category' => 'General',
        'slug' => 'markdown-faq-fallback-test',
    ]);

    [$statusMdFaq, $resMdFaq] = import_handler([
        'role' => 'super_user',
        'files' => [
            'import_file' => [
                'name' => 'markdown-faq-fallback-test.json',
                'content' => $mdFaqJson,
            ],
        ],
    ]);

    import_check($statusMdFaq === 200, 'Markdown FAQ fallback import succeeded (200)');
    import_check(str_contains($resMdFaq['blog']['content'], ':::faq'), 'Markdown FAQ converted to :::faq');
    import_check(str_contains($resMdFaq['blog']['content'], 'Q: Question One?'), 'Markdown FAQ Q1 converted');
    import_check(str_contains($resMdFaq['blog']['content'], 'A: Answer one.'), 'Markdown FAQ A1 converted');

    // 12. Test importing user uploaded file if available
    $uploadedUserFile = '/Users/lanie/.gemini/antigravity/brain/58a93d11-49cf-4f26-99f2-4d91111564e6/.user_uploaded/media_1791806188820_d06929ed.json';
    if (file_exists($uploadedUserFile)) {
        $userJson = file_get_contents($uploadedUserFile);
        [$statusUser, $resUser] = import_handler([
            'role' => 'super_user',
            'files' => [
                'import_file' => [
                    'name' => basename($uploadedUserFile),
                    'content' => $userJson,
                ],
            ],
        ]);
        import_check($statusUser === 200, 'User uploaded file import succeeded (200)');
        import_check(str_contains($resUser['blog']['content'], ':::table'), 'User file table converted to :::table');
        import_check(str_contains($resUser['blog']['content'], ':::faq'), 'User file FAQs converted to :::faq');
        import_check(str_contains($resUser['blog']['content'], 'Q: Is correct score full time?'), 'User FAQ Q1 converted');
        import_check(str_contains($resUser['blog']['content'], 'Q: What happens if a match is postponed?'), 'User FAQ Q5 converted');
        $userRendered = blog_render_content($resUser['blog']['content']);
        import_check(count($userRendered['faq_schema']['mainEntity'] ?? []) === 5, 'User file has 5 schema FAQs');
    }

    echo "Blog post JSON import tests passed.\n";
} finally {
    // Cleanup temporary files
    foreach (scandir($dir) as $item) {
        if ($item === '.' || $item === '..') continue;
        $f = "$dir/$item";
        if (is_file($f)) unlink($f);
    }
    rmdir($dir);
}
