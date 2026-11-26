<?php
declare(strict_types=1);

require_once __DIR__ . '/blog-storage.php';

function repo_root_dir(): string
{
    return dirname(__DIR__, 2);
}

function repo_git_binary(): ?string
{
    static $binary = null;
    if ($binary !== null) {
        return $binary !== '' ? $binary : null;
    }

    $candidates = ['/usr/bin/git', '/usr/local/bin/git', '/opt/homebrew/bin/git'];
    foreach ($candidates as $candidate) {
        if (is_executable($candidate)) {
            $binary = $candidate;
            return $binary;
        }
    }

    $check = @shell_exec('command -v git 2>/dev/null');
    $path = is_string($check) ? trim($check) : '';
    $binary = ($path !== '' && is_executable($path)) ? $path : '';
    return $binary !== '' ? $binary : null;
}

function repo_is_git(): bool
{
    $root = repo_root_dir();
    if (!is_dir($root . '/.git')) {
        return false;
    }
    return repo_git_binary() !== null;
}

function repo_git_run(array $args, int $timeoutSeconds = 15): array
{
    $git = repo_git_binary();
    if ($git === null) {
        return ['ok' => false, 'code' => 127, 'stdout' => '', 'stderr' => 'git executable not found.'];
    }

    $cmd = array_merge([$git], $args);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $cwd = repo_root_dir();
    $process = @proc_open($cmd, $descriptors, $pipes, $cwd);
    if (!is_resource($process)) {
        return ['ok' => false, 'code' => -1, 'stdout' => '', 'stderr' => 'Could not start git process.'];
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], true);
    stream_set_blocking($pipes[2], true);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);

    return [
        'ok' => ($code === 0),
        'code' => $code,
        'stdout' => is_string($stdout) ? trim($stdout) : '',
        'stderr' => is_string($stderr) ? trim($stderr) : '',
    ];
}

function repo_cache_path(): string
{
    return blog_storage_dir() . '/repo-update-status.json';
}

function repo_current_status(bool $forceFetch = false): array
{
    if (!repo_is_git()) {
        return [
            'ok' => true,
            'is_git' => false,
            'error' => 'Git repository not found (.git directory is missing).',
        ];
    }

    $cacheFile = repo_cache_path();
    if (!$forceFetch && is_file($cacheFile) && is_readable($cacheFile)) {
        $age = time() - (int) filemtime($cacheFile);
        if ($age < 60) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached) && !empty($cached['is_git'])) {
                $cached['from_cache'] = true;
                $cached['cache_age'] = $age;
                return $cached;
            }
        }
    }

    // Branch
    $branchRes = repo_git_run(['branch', '--show-current']);
    $branch = ($branchRes['ok'] && $branchRes['stdout'] !== '') ? $branchRes['stdout'] : 'v2';

    // HEAD commit info
    $headRes = repo_git_run(['log', '-1', '--format=%H%x1f%h%x1f%cd%x1f%s']);
    $headHash = '';
    $headShort = '';
    $headDate = '';
    $headMessage = '';
    if ($headRes['ok'] && $headRes['stdout'] !== '') {
        $parts = explode("\x1f", $headRes['stdout']);
        $headHash = $parts[0] ?? '';
        $headShort = $parts[1] ?? '';
        $headDate = $parts[2] ?? '';
        $headMessage = $parts[3] ?? '';
    }

    // Tracking upstream
    $upstreamRes = repo_git_run(['rev-parse', '--abbrev-ref', '--symbolic-full-name', '@{u}']);
    if ($upstreamRes['ok'] && $upstreamRes['stdout'] !== '') {
        $upstream = $upstreamRes['stdout'];
        $upstreamParts = explode('/', $upstream, 2);
        $remote = $upstreamParts[0] ?? 'origin';
        $remoteBranch = $upstreamParts[1] ?? $branch;
    } else {
        $remote = 'origin';
        $remoteBranch = $branch;
        $upstream = "$remote/$remoteBranch";
    }

    // Remote URL
    $urlRes = repo_git_run(['config', '--get', "remote.{$remote}.url"]);
    $remoteUrl = $urlRes['stdout'] ?? '';

    // Uncommitted tracked code changes
    $statusRes = repo_git_run(['status', '--porcelain', '--untracked-files=no', '--', '.', ':!admin/storage/**', ':!admin/uploads/**']);
    $uncommittedLines = array_values(array_filter(explode("\n", $statusRes['stdout'] ?? '')));
    $hasUncommitted = !empty($uncommittedLines);

    // Fetch from remote
    $fetchError = null;
    $fetchRes = repo_git_run(['fetch', $remote, $remoteBranch], 15);
    if (!$fetchRes['ok']) {
        $fetchError = $fetchRes['stderr'] !== '' ? $fetchRes['stderr'] : 'Failed to fetch from remote.';
    }

    // Compare commits
    $behindRes = repo_git_run(['rev-list', '--count', "HEAD..{$upstream}"]);
    $behindCount = ($behindRes['ok'] && ctype_digit(trim($behindRes['stdout']))) ? (int) trim($behindRes['stdout']) : 0;

    $aheadRes = repo_git_run(['rev-list', '--count', "{$upstream}..HEAD"]);
    $aheadCount = ($aheadRes['ok'] && ctype_digit(trim($aheadRes['stdout']))) ? (int) trim($aheadRes['stdout']) : 0;

    $hasUpdate = $behindCount > 0;
    $newCommits = [];
    if ($hasUpdate) {
        $logRes = repo_git_run(['log', '-n', '10', '--format=%H%x1f%h%x1f%an%x1f%cr%x1f%s', "HEAD..{$upstream}"]);
        if ($logRes['ok'] && $logRes['stdout'] !== '') {
            $lines = explode("\n", $logRes['stdout']);
            foreach ($lines as $line) {
                if (trim($line) === '') continue;
                $cParts = explode("\x1f", $line);
                $newCommits[] = [
                    'hash' => $cParts[0] ?? '',
                    'short' => $cParts[1] ?? '',
                    'author' => $cParts[2] ?? '',
                    'time' => $cParts[3] ?? '',
                    'message' => $cParts[4] ?? '',
                ];
            }
        }
    }

    $result = [
        'ok' => true,
        'is_git' => true,
        'branch' => $branch,
        'head' => [
            'hash' => $headHash,
            'short' => $headShort,
            'date' => $headDate,
            'message' => $headMessage,
        ],
        'remote' => $remote,
        'remote_branch' => $remoteBranch,
        'remote_url' => $remoteUrl,
        'upstream' => $upstream,
        'has_update' => $hasUpdate,
        'behind_count' => $behindCount,
        'ahead_count' => $aheadCount,
        'new_commits' => $newCommits,
        'has_uncommitted' => $hasUncommitted,
        'uncommitted_files' => $uncommittedLines,
        'fetch_error' => $fetchError,
        'checked_at' => date('Y-m-d H:i:s'),
        'from_cache' => false,
    ];

    $storageDir = dirname($cacheFile);
    if (!is_dir($storageDir)) {
        @mkdir($storageDir, 0700, true);
    }
    @file_put_contents($cacheFile, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    return $result;
}

function repo_run_update(): array
{
    if (!repo_is_git()) {
        return [
            'ok' => false,
            'error' => 'Cannot update: Git repository not detected on this server.',
        ];
    }

    // Check for uncommitted changes
    $status = repo_current_status(false);
    if (!empty($status['has_uncommitted'])) {
        $files = implode(', ', array_slice($status['uncommitted_files'] ?? [], 0, 5));
        return [
            'ok' => false,
            'error' => 'Cannot update: Local tracked code changes detected (' . $files . '). Commit or discard them first to prevent conflicts.',
        ];
    }

    $root = repo_root_dir();
    $script = $root . '/admin/update.sh';

    if (is_file($script)) {
        $bash = is_executable('/bin/bash') ? '/bin/bash' : 'bash';
        $cmd = [$bash, $script];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($cmd, $descriptors, $pipes, $root);
        if (!is_resource($process)) {
            return [
                'ok' => false,
                'error' => 'Could not start the update process.',
            ];
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        $combined = (is_string($stdout) ? $stdout : '') . "\n" . (is_string($stderr) ? $stderr : '');
        preg_match('/Backup:\s*([^\r\n]+)/', $combined, $backupMatches);
        $backupPath = isset($backupMatches[1]) ? trim($backupMatches[1]) : null;

        // Clear cached status
        $cacheFile = repo_cache_path();
        if (is_file($cacheFile)) {
            @unlink($cacheFile);
        }

        if ($code === 0) {
            return [
                'ok' => true,
                'output' => is_string($stdout) ? trim($stdout) : '',
                'backup' => $backupPath,
                'message' => 'Pulled latest updates from repository successfully.',
            ];
        }

        $errorMsg = is_string($stderr) && trim($stderr) !== '' ? trim($stderr) : (is_string($stdout) ? trim($stdout) : 'Update script exited with code ' . $code);
        return [
            'ok' => false,
            'error' => $errorMsg,
            'output' => is_string($stdout) ? trim($stdout) : '',
            'backup' => $backupPath,
        ];
    }

    // Fallback: direct git pull if update.sh is absent
    $remote = $status['remote'] ?? 'origin';
    $remoteBranch = $status['remote_branch'] ?? 'v2';
    $pullRes = repo_git_run(['pull', '--ff-only', $remote, $remoteBranch], 30);

    $cacheFile = repo_cache_path();
    if (is_file($cacheFile)) {
        @unlink($cacheFile);
    }

    if ($pullRes['ok']) {
        return [
            'ok' => true,
            'output' => $pullRes['stdout'],
            'message' => 'Git pull succeeded.',
        ];
    }

    return [
        'ok' => false,
        'error' => $pullRes['stderr'] !== '' ? $pullRes['stderr'] : $pullRes['stdout'],
    ];
}
