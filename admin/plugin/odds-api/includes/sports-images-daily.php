<?php
declare(strict_types=1);
require_once __DIR__ . '/sports-images.php';

/** Run bounded, resumable batches. Dependencies are injectable for offline regression checks. */
function sports_images_daily(array $options = [], ?callable $batch = null, ?callable $logger = null, ?callable $wait = null): array
{
    $maxImages = (int)($options['max_images'] ?? 100);
    $maxItems = (int)($options['max_items'] ?? 1000);
    $maxSeconds = (int)($options['max_seconds'] ?? 1800);
    $batchSize = (int)($options['batch_size'] ?? 25);
    $pauseMs = (int)($options['pause_ms'] ?? 1000);
    if ($maxImages < 1 || $maxImages > 100000 || $maxItems < 1 || $maxItems > 100000 || $maxSeconds < 1 || $maxSeconds > 86400 || $batchSize < 1 || $batchSize > 25 || $pauseMs < 0 || $pauseMs > 5000) {
        throw new InvalidArgumentException('Invalid limits: max-items 1–100000, max-seconds 1–86400, batch-size 1–25, pause-ms 0–5000.');
    }
    $storage = blog_storage_dir();
    if (!is_dir($storage) && !mkdir($storage,0700,true) && !is_dir($storage)) throw new RuntimeException('Storage directory unavailable.');
    $lock = fopen($storage.'/sports-images-daily.lock','c');
    if (!$lock) throw new RuntimeException('Cannot open daily image job lock.');
    $summary = ['status'=>'running','batches'=>0,'processed'=>0,'enriched'=>0,'sportsdataverse'=>0,'wikimedia'=>0,'unmatched'=>0,'image_failures'=>0,'remaining'=>null,'skipped_existing'=>0,'skipped_manual'=>0,'deferred'=>0];
    $log = $logger ?? static function(array $event) use ($storage): void {
        $line = gmdate('c').' '.json_encode($event,JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE).PHP_EOL;
        echo $line;
        $file=$storage.'/sports-images-daily.log';
        clearstatcache(true,$file);
        if (is_file($file) && filesize($file)>10485760) rename($file,$file.'.1');
        if (file_put_contents($file,$line,FILE_APPEND|LOCK_EX) === false) throw new RuntimeException('Cannot write daily image log.');
        chmod($file,0600);
    };
    if (!flock($lock,LOCK_EX|LOCK_NB)) {
        fclose($lock);
        // No shared log rotation while another process holds the job lock.
        $summary['status']='already_running';
        if ($logger) $logger($summary); else echo "Another daily sports image job is already running; skipped.\n";
        return $summary;
    }
    $wait ??= static function(int $seconds) use ($log): void {
        while ($seconds > 0) {
            $chunk = min(30,$seconds);
            $left = sleep($chunk);
            $seconds -= $chunk-$left;
            if ($seconds > 0 && $seconds % 300 === 0) $log(['event'=>'waiting','wait_seconds'=>$seconds]);
        }
    };
    try {
        $cooldownFile = $storage.'/sports-images-cooldown.json';
        $cooldown = is_file($cooldownFile) ? json_decode((string)file_get_contents($cooldownFile), true) : [];
        $retryAt = (int)($cooldown['retry_at'] ?? 0);
        if ($retryAt > time()) {
            $summary += ['retry_at'=>gmdate('c',$retryAt),'wait_seconds'=>$retryAt-time()];
            $summary['status']='waiting';
            $log(['event'=>'cooldown','message'=>'Image enrichment is paused after HTTP 429; this process will resume automatically.']+$summary);
            $wait($retryAt-time());
            $summary['status']='running';
            unset($summary['retry_at'],$summary['wait_seconds']);
        }
        $rateLimited = static function() use ($cooldownFile, &$summary): void {
            $retryAt = time()+1800;
            if (file_put_contents($cooldownFile,json_encode(['retry_at'=>$retryAt]),LOCK_EX) === false) {
                throw new RuntimeException('Cannot save the image provider cooldown.');
            }
            chmod($cooldownFile,0600);
            $summary['status']='rate_limited';
            $summary['retry_at']=gmdate('c',$retryAt);
            $summary['wait_seconds']=1800;
        };
        $started=time();
        $deadline=microtime(true)+$maxSeconds;
        $id=$options['tournament_id'] ?? null;
        $batchOptions=['checked_since'=>$started,'active_only'=>true];
        $log(['event'=>'start','tournament_id'=>$id,'max_images'=>$maxImages,'max_items'=>$maxItems,'max_seconds'=>$maxSeconds]);
        $waitForProvider = static function() use ($rateLimited, $wait, $log, &$summary, &$deadline): void {
            $rateLimited();
            $log(['event'=>'waiting','message'=>'HTTP 429: waiting 30 minutes, then retrying automatically.']+$summary);
            $waitingStarted=microtime(true);
            $wait(1800);
            $deadline+=microtime(true)-$waitingStarted;
            $summary['status']='running';
            unset($summary['retry_at'],$summary['wait_seconds']);
        };
        if ($batch === null) {
            while (true) {
                try { $batchOptions['manifest']=sports_images_manifest(); break; }
                catch (SportsImagesRateLimit $e) { $waitForProvider(); }
                catch (Throwable $e) {
                    $batchOptions['manifest']=[];
                    $log(['event'=>'source_warning','message'=>'SportsDataverse unavailable; using Wikidata/Commons. '.$e->getMessage()]);
                    break;
                }
            }
            $batch='sports_images_enrich';
        }
        while ($summary['enriched'] < $maxImages && $summary['processed'] < $maxItems && microtime(true)<$deadline) {
            $batchOptions['limit']=min($batchSize,$maxItems-$summary['processed'],$maxImages-$summary['enriched']);
            $batchOptions['deadline']=$deadline;
            $result=$batch($id,$batchOptions);
            $summary['batches']++;
            foreach (['processed','enriched','sportsdataverse','wikimedia','unmatched','image_failures'] as $key) $summary[$key]+=(int)($result[$key] ?? 0);
            $summary['remaining']=(int)($result['remaining'] ?? 0);
            foreach (['skipped_existing','skipped_manual','deferred'] as $key) $summary[$key]=(int)($result[$key] ?? 0);
            $log(['event'=>'batch','number'=>$summary['batches'],'processed'=>$result['processed'],'enriched'=>$result['enriched'],
                'unmatched'=>$result['unmatched'],'image_failures'=>$result['image_failures'],'remaining'=>$summary['remaining'],'skipped_existing'=>$summary['skipped_existing'],'skipped_manual'=>$summary['skipped_manual'],'deferred'=>$summary['deferred'],'errors'=>$result['errors'] ?? []]);
            if (!empty($result['stopped'])) { $waitForProvider();continue; }
            if ($summary['enriched'] >= $maxImages) { $summary['status']='image_target';break; }
            if ($summary['remaining']===0) { $summary['status']='complete';break; }
            if ((int)$result['processed']===0) { $summary['status']=microtime(true)>=$deadline?'time_limit':'no_progress';break; }
            if ($summary['processed']<$maxItems && microtime(true)<$deadline) usleep((int)(min($pauseMs/1000,max(0,$deadline-microtime(true)))*1000000));
        }
        if ($summary['status']==='running') $summary['status']=$summary['processed']>=$maxItems?'item_limit':'time_limit';
        $log(['event'=>'finish','elapsed_seconds'=>time()-$started]+$summary);
        return $summary;
    } finally { flock($lock,LOCK_UN);fclose($lock); }
}
