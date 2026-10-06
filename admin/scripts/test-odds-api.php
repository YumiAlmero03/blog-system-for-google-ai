<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$dir = sys_get_temp_dir() . '/odds-api-test-' . bin2hex(random_bytes(6)); mkdir($dir, 0700);
putenv('APP_STORAGE_DIR=' . $dir);
// Simulate an existing installation so the old market selection must migrate.
$legacy = new PDO('sqlite:' . $dir . '/odds-api.sqlite');
$legacy->exec("CREATE TABLE settings (id INTEGER PRIMARY KEY, enabled INTEGER NOT NULL DEFAULT 0, api_key TEXT NOT NULL DEFAULT '', regions TEXT NOT NULL DEFAULT 'us', markets TEXT NOT NULL DEFAULT 'h2h', bookmakers TEXT NOT NULL DEFAULT '')");
$legacy->exec("INSERT INTO settings(id,markets) VALUES(1,'h2h,spreads')");
$legacy = null;
require_once __DIR__ . '/../plugin/odds-api/includes/service.php';
require_once __DIR__ . '/../includes/admin-permissions.php';
function odds_check(bool $ok, string $label): void { if (!$ok) throw new RuntimeException($label); }
$calls = [];
$transport = static function(string $path, array $params) use (&$calls): array {
    $calls[] = [$path,$params];
    if ($path === 'sports/') return ['data'=>[
        ['key'=>'basketball_nba','title'=>'NBA','group'=>'Basketball','active'=>true,'has_outrights'=>false],
        ['key'=>'inactive','title'=>'Inactive','active'=>false],
    ]];
    return ['data'=>[['id'=>'event-1','sport_key'=>'basketball_nba','sport_title'=>'NBA','commence_time'=>'2026-10-06T12:00:00Z','home_team'=>'Home','away_team'=>'Away','secret'=>'must-not-leak',
        'bookmakers'=>[['key'=>'sample','title'=>'Sample','last_update'=>'2026-10-05T12:00:00Z','markets'=>[['key'=>'spreads','outcomes'=>[['name'=>'Home','price'=>1.95,'point'=>0],['name'=>'Away','price'=>1.9,'point'=>-1.5]]]]]]]]];
};
$settings = ['enabled'=>'1','apiKey'=>'test-secret-key','regions'=>'us, uk','markets'=>'h2h,spreads','bookmakers'=>'sample'];
try {
    odds_check(odds_api_result('sports','',$transport)['enabled']===false && count($calls)===0,'Disabled makes no provider calls');
    try { odds_api_save(array_replace($settings,['apiKey'=>''])); throw new RuntimeException('Enabled without key'); } catch (InvalidArgumentException $e) {}
    odds_check(odds_api_iframe()['markets']==='h2h,spreads','Existing markets migrate to iframe settings');
    odds_api_save($settings);
    $config = odds_api_config();
    odds_check($config['regions']==='us,uk','Normalize regions');
    foreach (['regions'=>'invalid','bookmakers'=>'x&apiKey=bad','enabled'=>'yes','apiKey'=>['bad']] as $field=>$value) {
        try { odds_api_save(array_replace($settings,[$field=>$value])); throw new RuntimeException('Accepted invalid ' . $field); } catch (InvalidArgumentException $e) {}
        odds_check(odds_api_config()===$config,'Failed validation is atomic');
    }
    $iframe = odds_api_iframe();
    foreach (['markets'=>'unsupported','title'=>'','blocks'=>['event_fields'=>[['field'=>'unknown','label'=>'Unknown','role'=>'data']],'outcome_fields'=>[]]] as $field=>$value) {
        try { odds_api_save_iframe(array_replace($iframe,[$field=>$value])); throw new RuntimeException('Accepted invalid iframe field'); } catch (InvalidArgumentException $e) {}
        odds_check(odds_api_iframe()===$iframe,'Iframe validation is atomic');
    }
    $sports = odds_api_result('sports','',$transport);
    odds_check(count($sports['data'])===1 && $sports['data'][0]['key']==='basketball_nba','Active sports only');
    $odds = odds_api_result('odds','basketball_nba',$transport);
    odds_check($odds['data'][0]['sport']['title']==='NBA' && $odds['data'][0]['sport']['group']==='Basketball','Sports title and group joined to event');
    odds_check(count($calls)===2 && $calls[1][0]==='sports/basketball_nba/odds/','Correct upstream endpoint');
    odds_check($calls[1][1]===['apiKey'=>'test-secret-key','regions'=>'us,uk','markets'=>'h2h,spreads','oddsFormat'=>'decimal','dateFormat'=>'iso','bookmakers'=>'sample'],'Saved params sent server-side');
    odds_check(!str_contains(json_encode($odds),'test-secret-key') && !str_contains(json_encode($odds),'must-not-leak'),'Only public fields');
    odds_check($odds['data'][0]['bookmakers'][0]['markets'][0]['outcomes'][0]['point']===0.0,'Zero line preserved');
    odds_api_result('odds','basketball_nba',$transport);
    odds_check(count($calls)===2,'Cache shared across reads');
    $iframe['blocks']['event_fields'] = array_reverse($iframe['blocks']['event_fields']);
    $iframe['blocks']['outcome_fields'] = array_reverse($iframe['blocks']['outcome_fields']);
    $iframe['event_fields'] = array_reverse($iframe['event_fields']);
    $iframe['outcome_fields'] = array_reverse($iframe['outcome_fields']);
    $iframe['title'] = 'My odds display';
    odds_api_save_iframe($iframe);
    $ordered = odds_api_result('odds','basketball_nba',$transport);
    odds_check($ordered['layout']['event_fields']===$iframe['event_fields'] && $ordered['layout']['outcome_fields']===$iframe['outcome_fields'] && $ordered['layout']['title']==='My odds display','Saved display order and title reach public iframe');
    odds_check(count($calls)===2,'Layout-only edits preserve cached odds');
    $custom = $iframe;
    $custom['id'] = 0; $custom['title'] = 'Compact card';
    $custom['blocks'] = [
        'event_fields'=>[['field'=>'home_team','label'=>'Host','role'=>'title'],['field'=>'sport.description','label'=>'About this league','role'=>'description']],
        'outcome_fields'=>[['field'=>'outcome.price','label'=>'Current price','role'=>'title']],
    ];
    $customId = odds_api_save_iframe($custom);
    odds_check($customId>1,'Create a separate iframe');
    $compact = odds_api_result('odds','basketball_nba',$transport,$customId);
    odds_check($compact['layout']['event_fields']===['home_team','sport.description'] && $compact['layout']['outcome_fields']===['outcome.price'],'Removed fields stay out of layout');
    odds_check($compact['layout']['blocks']['event_fields'][0]['role']==='title' && $compact['layout']['blocks']['event_fields'][1]['role']==='description','Title and description assignments persist');
    odds_check($compact['layout']['labels']['event_fields']['home_team']==='Host' && $compact['layout']['labels']['outcome_fields']['outcome.price']==='Current price','Renamed data labels persist');
    odds_check(count($calls)===2,'Identical requests share cache across iframe layouts');
    odds_check(count(odds_api_iframe()['event_fields'])===12,'Editing a new iframe leaves default layout intact');
    foreach (['empty','duplicate','role','label'] as $invalid) {
        $bad = $custom; $bad['id']=$customId;
        if ($invalid==='empty') $bad['blocks']=['event_fields'=>[],'outcome_fields'=>[]];
        if ($invalid==='duplicate') $bad['blocks']['event_fields'][]=$bad['blocks']['event_fields'][0];
        if ($invalid==='role') $bad['blocks']['event_fields'][0]['role']='script';
        if ($invalid==='label') $bad['blocks']['event_fields'][0]['label']=str_repeat('a',121);
        try { odds_api_save_iframe($bad); throw new RuntimeException('Accepted invalid ' . $invalid); } catch (InvalidArgumentException $e) {}
    }
    odds_check(odds_api_iframe($customId)['blocks']===$custom['blocks'],'Rejected blocks preserve previous layout');
    try { odds_api_result('sports','',$transport,999999); throw new RuntimeException('Unknown iframe accepted'); } catch (OutOfBoundsException $e) {}
    odds_check(count($calls)===2,'Unknown iframe makes no provider request');
    foreach (['../../secret','inactive','unknown'] as $sport) {
        try { odds_api_result('odds',$sport,$transport); throw new RuntimeException('Invalid sport accepted'); } catch (InvalidArgumentException $e) {}
    }
    odds_check(count($calls)===2,'Invalid sport never consumes odds request');
    $ttl = odds_api_pdo()->query('SELECT expires_at-fetched_at AS ttl FROM cache ORDER BY ttl')->fetchAll(PDO::FETCH_COLUMN);
    odds_check(array_map('intval',$ttl)===[300,3600],'Cache lifetimes');
    odds_api_save(array_replace($settings,['apiKey'=>'','bookmakers'=>'']));
    odds_check(odds_api_config()['api_key']==='test-secret-key','Blank retains saved key');
    odds_api_result('odds','basketball_nba',$transport);
    odds_check(count($calls)===4 && !isset($calls[3][1]['bookmakers']),'Settings invalidate cache; omit empty bookmakers');
    odds_api_save(array_replace($settings,['enabled'=>'0','apiKey'=>'']));
    odds_check(odds_api_result('odds','basketball_nba',$transport)['data']===[] && count($calls)===4,'Off prevents odds fetches');
    odds_api_save($settings);
    odds_api_save_iframe(array_replace($iframe,['markets'=>'totals']));
    odds_check(odds_api_config()['markets']==='totals','Markets controlled by iframe settings');
    odds_api_save(array_replace($settings,['markets'=>'outrights']));
    odds_check(odds_api_config()['markets']==='totals','General settings cannot override iframe markets');
    odds_api_result('odds','basketball_nba',$transport);
    odds_check(end($calls)[1]['markets']==='totals','Changed iframe markets reach provider');
    $custom['id']=$customId; $custom['markets']='h2h'; odds_api_save_iframe($custom);
    odds_api_result('odds','basketball_nba',$transport,$customId);
    odds_check(end($calls)[1]['markets']==='h2h' && odds_api_iframe()['markets']==='totals','Each iframe uses its own markets');
    odds_api_save_iframe(['id'=>$customId,'title'=>'Legacy client','markets'=>'h2h','event_fields'=>[],'outcome_fields'=>['outcome.price']]);
    odds_check(odds_api_iframe($customId)['event_fields']===[],'Legacy ordered arrays support removable fields');
    odds_api_save($settings);
    $failedCalls = 0;
    $failure = static function() use (&$failedCalls) { $failedCalls++; throw new RuntimeException('https://provider/?apiKey=test-secret-key'); };
    for ($i=0;$i<2;$i++) {
        try { odds_api_result('sports','',$failure); throw new LogicException('Failure accepted'); }
        catch (RuntimeException $e) { odds_check(!str_contains($e->getMessage(),'test-secret-key'),'Sanitized provider failure'); }
    }
    odds_check($failedCalls===1,'Error cooldown');
    odds_api_save(array_replace($settings,['enabled'=>'0','clear_api_key'=>'1']));
    odds_check(odds_api_config()['api_key']==='','Clear saved key');
    odds_check(!file_exists($dir.'/blogs.sqlite') && !file_exists($dir.'/games.sqlite'),'Blog/game databases untouched');
    odds_check(file_exists($dir.'/sports-livescore.sqlite') && (int)sports_livescore_pdo()->query('SELECT COUNT(*) FROM iframes')->fetchColumn()===2,'Layouts live in sports SQLite iframes table');
    odds_check(odds_api_pdo()->query('SELECT markets FROM iframe_settings WHERE id=1')->fetchColumn()==='h2h,spreads','Legacy iframe source preserved during migration');
    odds_check(!str_contains(file_get_contents($dir.'/sports-livescore.sqlite'),'test-secret-key'),'Credentials stay out of sports layout storage');
    $_SERVER['SCRIPT_NAME']='/admin/plugin/odds-api/index.php'; $_SESSION['user']['role']='editor';
    odds_check(auth_route_capability()==='admin' && !auth_can('admin'),'Editors cannot manage odds');
    echo "PASS: settings validation, key retention/removal, disabled behavior, exact provider parameters, active sports, public fields, cache TTL/invalidation, error cooldown, independent storage permissions, sports-table migration, named iframes, removable blocks, roles and custom labels.\n";
} finally {
    foreach (glob($dir.'/*') ?: [] as $file) unlink($file);
    rmdir($dir);
}
