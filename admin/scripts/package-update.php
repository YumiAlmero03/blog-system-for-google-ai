<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__, 2));
function update_git(array $args): string {
    $process = proc_open(array_merge(['git'],$args), [1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start Git.');
    $out=stream_get_contents($pipes[1]); $error=stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process)!==0) throw new RuntimeException(trim($error));
    return $out;
}
function update_allowed(string $path): bool {
    if ($path==='' || str_contains($path,"\n") || str_contains($path,"\r") || str_starts_with($path,'/') || in_array('..',explode('/',$path),true)) return false;
    if (preg_match('~(^|/)(storage|uploads|chats|update-packages|node_modules|vendor|\.git|\.codex|\.agents)(/|$)~i',$path)) return false;
    if (preg_match('~(^|/)(\.env(?:\..*)?|.*\.(?:sqlite.*|db|zip|pem|key|log|sql|bak))$~i',$path)) return false;
    return true;
}
try {
    if (!class_exists('ZipArchive')) throw new RuntimeException('PHP ZipArchive is required.');
    if ($argc>2) throw new RuntimeException('Usage: sh admin/scripts/package-update.sh [deployed-commit-or-branch]');
    $base=trim(update_git(['rev-parse','--verify','--end-of-options',($argv[1] ?? 'HEAD').'^{commit}']));
    $paths=array_unique(array_merge(explode("\0",update_git(['diff','--no-renames','--name-only','-z',$base,'--'])),explode("\0",update_git(['ls-files','--others','--exclude-standard','-z']))));
    sort($paths); $files=[]; $deleted=[];
    foreach ($paths as $path) {
        if (!update_allowed($path)) continue;
        if (is_link($path)) throw new RuntimeException('Symlink must be reviewed separately: '.$path);
        if (is_file($path)) $files[]=$path;
        elseif (!file_exists($path)) $deleted[]=$path;
        else throw new RuntimeException('Unsupported update path: '.$path);
    }
    if (!$files && !$deleted) throw new RuntimeException('No deployable changes found.');
    if (!is_dir('admin/update-packages') && !mkdir('admin/update-packages',0700)) throw new RuntimeException('Cannot create package directory.');
    $prefix='admin/update-packages/site-update-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3));
    $zip=new ZipArchive();
    if ($zip->open($prefix.'.zip',ZipArchive::CREATE | ZipArchive::EXCL)!==true) throw new RuntimeException('Cannot create ZIP.');
    $manifest="Base commit: $base\nCreated UTC: ".gmdate('c')."\nSource: current working files, including non-ignored untracked files.\n\nSHA256  File\n";
    foreach ($files as $path) {
        $bytes=file_get_contents($path);
        if ($bytes===false || !$zip->addFromString($path,$bytes)) throw new RuntimeException('Cannot package '.$path);
        $manifest.=hash('sha256',$bytes).'  '.$path."\n";
    }
    if (!$zip->close()) throw new RuntimeException('Cannot finish ZIP.');
    if (file_put_contents($prefix.'-manifest.txt',$manifest)===false || file_put_contents($prefix.'-deletions.txt',implode("\n",$deleted).($deleted ? "\n" : ''))===false) throw new RuntimeException('Cannot write package reports.');
    echo "$prefix.zip\n$prefix-manifest.txt\n$prefix-deletions.txt\n".count($files).' files; '.count($deleted)." deletions.\n";
} catch (Throwable $error) { fwrite(STDERR,$error->getMessage()."\n"); exit(1); }
