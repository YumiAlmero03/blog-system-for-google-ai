<?php
declare(strict_types=1);
require_once __DIR__ . '/blog-storage.php';
require_once __DIR__ . '/image-validation.php';
require_once __DIR__ . '/blog-wordpress-import.php';

const BLOG_IMPORT_MAX_BYTES = 100 * 1024 * 1024;
const BLOG_IMPORT_MAX_IMAGE_BYTES = 3 * 1024 * 1024;
const BLOG_IMPORT_BATCH_POSTS = 10;
const BLOG_IMPORT_BATCH_IMAGES = 3;
const BLOG_IMPORT_BATCH_SECONDS = 3.0;

function blog_import_restore_image(ZipArchive $zip, string $name, array $manifest, array &$imageMap, string $jobId): void
{
    $uploadDirAdmin = dirname(__DIR__) . '/uploads/blogs';
    $uploadDirWeb = dirname(__DIR__, 2) . '/uploads/blogs';
    if (!is_dir($uploadDirAdmin)) @mkdir($uploadDirAdmin, 0755, true);
    if (!is_dir($uploadDirWeb)) @mkdir($uploadDirWeb, 0755, true);
    $entryStat = $zip->statName($name);
    if (!is_array($entryStat) || (int) ($entryStat['size'] ?? 0) <= 0 || (int) $entryStat['size'] > BLOG_IMPORT_MAX_IMAGE_BYTES) {
        throw new RuntimeException('An image entry is too large or invalid.');
    }
    $basename = basename($name);
    if ($basename === '' || $basename !== preg_replace('/[^A-Za-z0-9._ -]/', '_', $basename) || substr_count($basename, '.') !== 1 || !preg_match('/\.(jpe?g|png|webp|avif)$/i', $basename)) {
        error_log('Unsupported blog import image: ' . $name);
        throw new RuntimeException('Unsupported image entry in ZIP.');
    }
    $data = $zip->getFromName($name);
    if (!is_string($data) || strlen($data) === 0 || strlen($data) > BLOG_IMPORT_MAX_IMAGE_BYTES) {
        throw new RuntimeException('An image entry is too large or invalid.');
    }
    $temporaryImage = tempnam(sys_get_temp_dir(), 'blog-import-image-');
    if ($temporaryImage === false || file_put_contents($temporaryImage, $data) === false) {
        throw new RuntimeException('An image could not be prepared.');
    }
    $extension = strtolower(pathinfo($basename, PATHINFO_EXTENSION));
    $validation = validate_blog_image_file($temporaryImage, $extension);
    if (($validation['ok'] ?? false) !== true) {
        $reason = (string) ($validation['reason'] ?? 'unknown validation failure');
        @unlink($temporaryImage);
        throw new RuntimeException('An image failed validation: ' . basename($name) . ' (' . $reason . ').');
    }
    $destinationName = substr(hash('sha256', $jobId . ':' . $name), 0, 32) . '.' . $extension;
    $destinationAdmin = $uploadDirAdmin . '/' . $destinationName;
    $destinationWeb = $uploadDirWeb . '/' . $destinationName;
    $restored = false;
    if (@copy($temporaryImage, $destinationAdmin)) {
        @chmod($destinationAdmin, 0644);
        $restored = true;
    }
    if (@copy($temporaryImage, $destinationWeb)) {
        @chmod($destinationWeb, 0644);
        $restored = true;
    }
    @unlink($temporaryImage);
    if (!$restored) {
        throw new RuntimeException('An image could not be restored.');
    }
    @chmod($destinationAdmin, 0644);
    @chmod($destinationWeb, 0644);
    $imageMap['/' . ltrim((string) (($manifest['images'][$name] ?? '') ?: '/uploads/blogs/' . $basename), '/')] = '/uploads/blogs/' . $destinationName;
    $imageMap['/uploads/blogs/' . $basename] = '/uploads/blogs/' . $destinationName;
}

function blog_import_record(PDO $pdo, mixed $record, bool $isWordPress, array $imageMap): string
{
    if ($isWordPress && is_array($record) && isset($record['wordpressHtml'])) {
        if (!is_string($record['wordpressHtml']) || strlen($record['wordpressHtml']) > 1024 * 1024) return 'failed';
        $record['content'] = blog_wordpress_markdown($record['wordpressHtml']);
        unset($record['wordpressHtml']);
    }
    if (!is_array($record)) {
        return 'failed';
    }
    $normalized = blog_normalize_existing($record);
    $slug = is_string($record['slug'] ?? null) ? normalize_slug($record['slug']) : '';
    if ($normalized === null || $slug === '') {
        return 'failed';
    }
    $originalContent = $record['content'] ?? null;
    if (!is_string($originalContent) || strlen($originalContent) > 60000) {
        return 'failed';
    }
    $normalized['content'] = preg_replace_callback('#/uploads/blogs/[A-Za-z0-9._/-]+#', static fn (array $match): string => $imageMap[$match[0]] ?? $match[0], $originalContent) ?? $originalContent;
    $normalized['featuredImage'] = $imageMap[$normalized['featuredImage']] ?? $normalized['featuredImage'];
    $existingStmt = $pdo->prepare('SELECT id FROM blog_posts WHERE slug = :slug LIMIT 1');
    $existingStmt->execute([':slug' => $slug]);
    $existingId = $existingStmt->fetchColumn();
    if ($existingId !== false && (string) $existingId !== (string) ($record['id'] ?? $slug)) {
        return 'skipped';
    }
    $normalized['id'] = $existingId !== false ? (string) $existingId : $slug;
    blog_taxonomy_import_category($pdo, $normalized['category']);
    if ($isWordPress) {
        $normalized['tagIds'] = [];
        foreach ($record['wordpressTags'] ?? [] as $tagName) {
            $tagSlug = normalize_slug($tagName);
            if ($tagSlug === '') continue;
            $tagStmt = $pdo->prepare('SELECT id FROM tags WHERE slug = ? OR name = ? COLLATE NOCASE');
            $tagStmt->execute([$tagSlug, mb_substr($tagName, 0, 80)]);
            $tagId = $tagStmt->fetchColumn();
            if ($tagId === false) {
                $tagId = bin2hex(random_bytes(16));
                $tagStmt = $pdo->prepare('INSERT INTO tags(id,name,slug,created_at,updated_at) VALUES(?,?,?,?,?)');
                $tagStmt->execute([$tagId, mb_substr($tagName, 0, 80), $tagSlug, time(), time()]);
            }
            $normalized['tagIds'][] = (string) $tagId;
        }
    }
    blogs_upsert_with_pdo($pdo, $normalized);
    return $existingId !== false ? 'updated' : 'imported';
}

function blog_import_jobs_init(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS blog_import_jobs (id TEXT PRIMARY KEY, owner TEXT NOT NULL, state TEXT NOT NULL, expires_at INTEGER NOT NULL)');
    $stmt = $pdo->query('SELECT id,state FROM blog_import_jobs WHERE expires_at < ' . time() . ' LIMIT 10');
    foreach ($stmt->fetchAll() as $row) {
        $state = json_decode($row['state'], true);
        if (is_array($state)) blog_import_cleanup($state);
        $delete = $pdo->prepare('DELETE FROM blog_import_jobs WHERE id=?');
        $delete->execute([$row['id']]);
    }
}

function blog_import_cleanup(array $state): void
{
    foreach (['posts.jsonl', 'source.zip'] as $file) @unlink($state['directory'] . '/' . $file);
    @rmdir($state['directory']);
}

function blog_import_start(PDO $pdo, string $source, bool $isWordPress, string $owner): array
{
    blog_import_jobs_init($pdo);
    $id = bin2hex(random_bytes(24));
    $directory = sys_get_temp_dir() . '/cms-blog-import-' . $id;
    if (!mkdir($directory, 0700)) throw new RuntimeException('Could not prepare import storage.');
    $zip = null;
    $output = null;
    try {
        $images = [];
        if ($isWordPress) {
            $xml = file_get_contents($source);
            if ($xml === false) throw new RuntimeException('Could not read XML upload.');
            $manifest = blog_wordpress_manifest($xml, true);
            unset($xml);
        } else {
            if (!class_exists('ZipArchive')) throw new RuntimeException('ZIP import is unavailable.');
            if (!copy($source, $directory . '/source.zip')) throw new RuntimeException('Could not stage ZIP upload.');
            $zip = new ZipArchive();
            if ($zip->open($directory . '/source.zip') !== true) throw new InvalidArgumentException('Invalid blog export ZIP.');
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                $name = is_array($stat) ? (string) ($stat['name'] ?? '') : '';
                if ($name === '' || str_starts_with($name, '/') || str_contains($name, '\\') || preg_match('#(^|/)\.\.?(/|$)#', $name)) throw new InvalidArgumentException('Unsafe ZIP path detected.');
                if (str_ends_with($name, '/')) continue;
                if ($name === 'blogs.json') {
                    if ((int) $stat['size'] > BLOG_IMPORT_MAX_BYTES) throw new InvalidArgumentException('blogs.json is too large.');
                } elseif (str_starts_with($name, 'images/')) {
                    if ((int) $stat['size'] <= 0 || (int) $stat['size'] > BLOG_IMPORT_MAX_IMAGE_BYTES) throw new InvalidArgumentException('An image entry is too large or invalid.');
                    $images[] = $name;
                } else {
                    throw new InvalidArgumentException('ZIP structure is not a supported blog export.');
                }
            }
            $json = $zip->getFromName('blogs.json');
            $manifest = is_string($json) ? json_decode($json, true) : null;
            unset($json);
            if (!is_array($manifest) || ($manifest['format'] ?? '') !== 'blogs-export-v1' || !is_array($manifest['blogs'] ?? null)) throw new InvalidArgumentException('blogs.json is invalid or unsupported.');
            $zip->close();
            $zip = null;
        }
        $output = fopen($directory . '/posts.jsonl', 'xb');
        if ($output === false) throw new RuntimeException('Could not stage posts.');
        foreach ($manifest['blogs'] as $record) {
            $line = json_encode($record, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
            if (fwrite($output, $line) !== strlen($line)) throw new RuntimeException('Could not stage posts.');
        }
        fclose($output);
        $output = null;
        $state = [
            'jobId' => $id, 'directory' => $directory, 'wordpress' => $isWordPress,
            'images' => $images, 'imageSources' => $manifest['images'] ?? [], 'imageMap' => [],
            'imageCursor' => 0, 'postCursor' => 0, 'postOffset' => 0, 'revision' => 0,
            'total' => count($manifest['blogs']), 'done' => false,
            'counts' => ['imported' => 0, 'updated' => 0, 'skipped' => (int) ($manifest['skipped'] ?? 0), 'failed' => 0, 'imagesRestored' => 0],
        ];
        $stmt = $pdo->prepare('INSERT INTO blog_import_jobs(id,owner,state,expires_at) VALUES(?,?,?,?)');
        $stmt->execute([$id, $owner, json_encode($state, JSON_THROW_ON_ERROR), time() + 86400]);
        return blog_import_progress($state);
    } catch (Throwable $error) {
        if (is_resource($output)) fclose($output);
        if ($zip instanceof ZipArchive) $zip->close();
        blog_import_cleanup(['directory' => $directory]);
        throw $error;
    }
}

function blog_import_progress(array $state): array
{
    return [
        'ok' => true, 'jobId' => $state['jobId'], 'revision' => $state['revision'], 'done' => $state['done'],
        'phase' => $state['imageCursor'] < count($state['images']) ? 'images' : 'posts',
        'processed' => $state['postCursor'], 'total' => $state['total'],
        'imagesProcessed' => $state['imageCursor'], 'imagesTotal' => count($state['images']),
        'counts' => $state['counts'],
        'message' => $state['wordpress'] ? 'XML does not include image files. Upload featured images separately; inline images keep their original URLs. Private, password-protected, and pending posts become drafts.' : '',
    ];
}

function blog_import_batch(PDO $pdo, string $id, string $owner, int $revision): array
{
    $started = microtime(true);
    $zip = null;
    $input = null;
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT state FROM blog_import_jobs WHERE id=? AND owner=? AND expires_at>=?');
        $stmt->execute([$id, $owner, time()]);
        $json = $stmt->fetchColumn();
        if ($json === false) throw new InvalidArgumentException('Import expired or is unavailable. Please select the file again.');
        $state = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        // A retried request returns its committed progress without repeating the batch.
        if ($state['done'] || $revision !== $state['revision']) {
            $pdo->commit();
            return blog_import_progress($state);
        }
        if ($state['imageCursor'] < count($state['images'])) {
            $zip = new ZipArchive();
            if ($zip->open($state['directory'] . '/source.zip') !== true) throw new RuntimeException('Staged ZIP is unavailable. Please select the file again.');
            for ($i = 0; $i < BLOG_IMPORT_BATCH_IMAGES && $state['imageCursor'] < count($state['images']); $i++) {
                blog_import_restore_image($zip, $state['images'][$state['imageCursor']], ['images' => $state['imageSources']], $state['imageMap'], $state['jobId']);
                $state['imageCursor']++;
                $state['counts']['imagesRestored']++;
                if (microtime(true) - $started >= BLOG_IMPORT_BATCH_SECONDS) break;
            }
            $zip->close();
            $zip = null;
        } else {
            $input = fopen($state['directory'] . '/posts.jsonl', 'rb');
            if ($input === false || fseek($input, $state['postOffset']) !== 0) throw new RuntimeException('Staged posts are unavailable. Please select the file again.');
            for ($i = 0; $i < BLOG_IMPORT_BATCH_POSTS && $state['postCursor'] < $state['total']; $i++) {
                $line = fgets($input);
                if ($line === false) throw new RuntimeException('Could not read staged post.');
                $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                // A bad record does not roll back other posts in this batch.
                $pdo->exec('SAVEPOINT blog_import_record');
                try {
                    $outcome = blog_import_record($pdo, $record, $state['wordpress'], $state['imageMap']);
                } catch (InvalidArgumentException $error) {
                    $pdo->exec('ROLLBACK TO blog_import_record');
                    $outcome = 'failed';
                }
                $pdo->exec('RELEASE blog_import_record');
                $state['counts'][$outcome]++;
                $state['postCursor']++;
                $state['postOffset'] = ftell($input);
                if (microtime(true) - $started >= BLOG_IMPORT_BATCH_SECONDS) break;
            }
            fclose($input);
            $input = null;
        }
        $state['done'] = $state['postCursor'] >= $state['total'] && $state['imageCursor'] >= count($state['images']);
        $state['revision']++;
        $stmt = $pdo->prepare('UPDATE blog_import_jobs SET state=?,expires_at=? WHERE id=? AND owner=?');
        $stmt->execute([json_encode($state, JSON_THROW_ON_ERROR), time() + 86400, $id, $owner]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    } finally {
        if ($zip instanceof ZipArchive) $zip->close();
        if (is_resource($input)) fclose($input);
    }
    blog_clear_api_cache();
    if ($state['done']) blog_import_cleanup($state);
    return blog_import_progress($state);
}
