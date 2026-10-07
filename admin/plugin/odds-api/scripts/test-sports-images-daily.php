<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
$dir=sys_get_temp_dir().'/sports-daily-test-'.bin2hex(random_bytes(5));mkdir($dir,0700);putenv('APP_STORAGE_DIR='.$dir);
require_once __DIR__.'/../includes/sports-images-daily.php';
$n=0;
function check_daily(bool $ok,string $message): void { global $n;if(!$ok)throw new RuntimeException($message);$n++; }
$log=static function(array $event): void {};
$result=static fn(int $count,int $remaining,bool $stopped=false)=>['processed'=>$count,'enriched'=>$count,'sportsdataverse'=>$count,'wikimedia'=>0,'unmatched'=>0,'image_failures'=>0,'remaining'=>$remaining,'stopped'=>$stopped,'errors'=>[]];
try {
    $calls=[];
    $batch=static function($id,$opts)use(&$calls,$result){$calls[]=$opts;return $result($opts['limit'],100);};
    $r=sports_images_daily(['max_items'=>7,'batch_size'=>3,'pause_ms'=>0],$batch,$log);
    check_daily($r['processed']===7 && $r['batches']===3 && $r['status']==='item_limit','Stops at max-items');
    check_daily(array_column($calls,'limit')===[3,3,1],'Final batch respects remaining budget');
    check_daily(count(array_unique(array_column($calls,'checked_since')))===1,'One attempt cutoff prevents cycling failed items');
    $r=sports_images_daily(['pause_ms'=>0],static fn()=>$result(2,0),$log);
    check_daily($r['status']==='complete' && $r['batches']===1,'Stops when queue is exhausted');
    $r=sports_images_daily(['pause_ms'=>0],static fn()=>$result(0,8,true),$log);
    check_daily($r['status']==='rate_limited' && $r['batches']===1,'Stops immediately on 429');
    $cooldownFile=$dir.'/sports-images-cooldown.json';
    $saved=json_decode(file_get_contents($cooldownFile),true);
    check_daily($r['wait_seconds']===1800 && $saved['retry_at']>=time()+1799,'429 stores a 30-minute cooldown and reports retry time');
    $r=sports_images_daily([],static function(){throw new RuntimeException('No requests allowed during cooldown');},$log);
    check_daily($r['status']==='cooldown' && $r['batches']===0,'Immediate rerun skips all provider work');
    check_daily(json_decode(file_get_contents($cooldownFile),true)===$saved,'Rerunning does not extend the cooldown');
    file_put_contents($cooldownFile,json_encode(['retry_at'=>time()-1]));
    $r=sports_images_daily([],static fn()=>$result(1,0),$log);
    check_daily($r['status']==='complete','Expired cooldown allows enrichment again');

    $r=sports_images_daily(['pause_ms'=>0],static fn()=>$result(0,8),$log);
    check_daily($r['status']==='no_progress','Does not busy-loop on unchanged queue');
    $lock=fopen($dir.'/sports-images-daily.lock','c');flock($lock,LOCK_EX);
    $r=sports_images_daily([],static function(){throw new RuntimeException('Must not run while locked');},$log);
    check_daily($r['status']==='already_running','Concurrent job skipped');flock($lock,LOCK_UN);fclose($lock);
    $bad=false;try{sports_images_daily(['batch_size'=>26],null,$log);}catch(InvalidArgumentException){$bad=true;}
    check_daily($bad,'Invalid batch size rejected');
    $r=sports_images_daily(['max_seconds'=>1,'pause_ms'=>0],static function()use($result){usleep(1100000);return $result(1,8);},$log);
    check_daily($r['status']==='time_limit' && $r['batches']===1,'Time budget checked between batches');
    ob_start();sports_images_daily([],static fn()=>$result(1,0));ob_end_clean();
    check_daily(str_contains(file_get_contents($dir.'/sports-images-daily.log'),'"event":"finish"'),'Persistent log contains summary');
    $pdo=sports_images_db();
    $fixtures=[];
    foreach ([[0,1,1],[1,0,1],[1,1,1],[0,0,1],[1,1,0]] as $i=>[$categoryOn,$tournamentOn,$active]) {
        $cat=sports_category_save(['name'=>'Category '.$i,'slug'=>'category-'.$i,'enabled'=>$categoryOn]);
        $t=tournament_save(['sports_category_id'=>$cat['id'],'title'=>'Tournament '.$i,'odds_sport_key'=>'basketball_case'.$i,'enabled'=>$tournamentOn,'active'=>$active]);
        $pdo->prepare('INSERT INTO participants(sports_category_id,odds_sport_key,participant_id,name,slug,image_key,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)')
            ->execute([$cat['id'],$t['odds_sport_key'],'test-'.$i,'Team '.$i,'team-'.$i,'team-'.$i,time(),time()]);
        $pid=(int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO participant_tournaments(participant_id,tournament_id,created_at) VALUES(?,?,?)')->execute([$pid,$t['id'],time()]);
        $fixtures[]=[$t['id'],$pid];
    }
    $realBatch=static fn($id,$opts)=>sports_images_enrich($id,$opts+['manifest'=>[],'transport'=>static fn()=>'{"search":[]}']);
    $r=sports_images_daily(['pause_ms'=>0],$realBatch,$log);
    check_daily($r['processed']===2,'Only the enabled category + enabled active tournament and its participant are attempted');
    foreach($fixtures as $i=>[$tid,$pid]) {
        $q=$pdo->prepare("SELECT COUNT(*) FROM sports_image_assets WHERE (entity_type='tournament' AND entity_id=?) OR (entity_type='participant' AND entity_id=?)");$q->execute([$tid,$pid]);
        check_daily((int)$q->fetchColumn()===($i===2?2:0),'Eligibility combination '.$i.' includes/excludes both tournament and participant');
    }
    $r=sports_images_daily(['tournament_id'=>(int)$fixtures[0][0],'pause_ms'=>0],$realBatch,$log);
    check_daily($r['processed']===0,'Explicit tournament flag cannot bypass a disabled category');
    echo "PASS: {$n} daily image job checks\n";
} finally {foreach(glob($dir.'/*') as $file)unlink($file);rmdir($dir);}
