<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$dir=sys_get_temp_dir().'/sports-api-test-'.bin2hex(random_bytes(6));mkdir($dir,0700);putenv('APP_STORAGE_DIR='.$dir);
require_once __DIR__ . '/../plugin/odds-api/includes/sports-api.php';
require_once __DIR__ . '/../includes/admin-permissions.php';
$count=0;
function check(bool $ok,string $message):void{global $count;if(!$ok)throw new RuntimeException($message);$count++;}
function rejects(callable $fn,string $message):void{try{$fn();}catch(InvalidArgumentException $e){check(true,$message);return;}throw new RuntimeException($message);}
function field(string $id,string $path,string $role='data'):array{return ['id'=>$id,'type'=>'field','path'=>$path,'label'=>$id,'role'=>$role,'visible'=>true,'format'=>'text','fallback'=>''];}
function loop(string $id,string $path,array $children):array{return ['id'=>$id,'type'=>'loop','path'=>$path,'label'=>$id,'visible'=>true,'columns'=>3,'children'=>$children];}
try{
    $legacy=odds_api_iframe();$migrated=sports_api_get(1);
    check($legacy['id']===$migrated['id']&&$legacy['title']===$migrated['title']&&$legacy['markets']===$migrated['markets'],'Migration preserves identity and settings');
    check(count($migrated['layout_config'])===count($legacy['blocks']['event_fields'])+1,'Migration preserves fields and wraps outcomes');
    sports_api_validate_layout($migrated['layout_config']);check(true,'Migrated layout validates');
    $data=json_decode('{"data":[{"title":"Game","secret":"private","bookmakers":[{"title":"1xBet","markets":[{"key":"h2h","outcomes":[{"name":"Home","price":1.5},{"name":"Away","price":2.85}]},{"key":"spreads","outcomes":[{"name":"Home","price":1.98,"point":0},{"name":"Away","price":1.94,"point":5.5}]}]}]}]}');
    $root=sports_api_root($data,'$.data');$paths=array_column(sports_api_discover($root),null,'path');
    foreach(['$.bookmakers[]','$.bookmakers[].markets[]','$.bookmakers[].markets[].outcomes[]'] as $path)check($paths[$path]['kind']==='array','Discover array '.$path);
    check(isset($paths['$.bookmakers[].markets[].outcomes[].point']),'Union discovers optional later point');
    $out='$.bookmakers[].markets[].outcomes[]';
    $layout=[field('heading','$.title','title'),loop('books','$.bookmakers[]',[field('book','$.bookmakers[].title'),loop('markets','$.bookmakers[].markets[]',[field('market','$.bookmakers[].markets[].key','description'),loop('outcomes',$out,[field('name',$out.'.name'),field('price',$out.'.price'),field('point',$out.'.point')])])])];
    $layout=sports_api_validate_layout($layout);$project=sports_api_project($root,$layout,3);$markets=$project['records'][0]['blocks'][1]['items'][0]['blocks'][1]['items'];
    check(count($markets)===2,'Nested markets repeat');check(count($markets[0]['blocks'][1]['items'][0]['blocks'])===2,'Missing h2h point skipped');check($markets[1]['blocks'][1]['items'][0]['blocks'][2]['value']===0,'Zero point retained');
    $input=['id'=>0,'name'=>'Generic layout','api_url'=>'https://example.com/feed','enabled'=>'1','root_path'=>'$.data','columns_per_row'=>3,'layout_config'=>$layout];$id=sports_api_save($input);check($id>1,'New layout independent');
    foreach(range(1,4) as $columns){$input['id']=$id;$input['columns_per_row']=$columns;sports_api_save($input);check(sports_api_get($id)['columns_per_row']===$columns,'Columns persisted '.$columns);}
    $calls=0;$transport=static function($url)use(&$calls,$data){$calls++;return $data;};$result=sports_api_output($id,'',$transport);sports_api_output($id,'',$transport);
    check($calls===1,'Cache prevents repeated remote reads');check(!str_contains(json_encode($result),'private')&&!isset($result['api_url']),'Projection excludes unused response and API URL');
    $input['layout_config']=array_reverse($layout);sports_api_save($input);check(sports_api_get($id)['layout_config'][0]['id']==='books','Order persists');
    $input['enabled']='0';sports_api_save($input);check(!sports_api_output($id,'',$transport)['enabled']&&$calls===1,'Disabled never fetches');
    $generic=json_decode('{"items":[{"name":"A","active":false,"score":0},{"name":"B"}],"odd.key":"ok"}');
    $genericLayout=[field('name','$.name','title'),field('active','$.active'),field('zero','$.score'),array_replace(field('fallback','$.missing'),['fallback'=>'Not supplied'])];
    $p=sports_api_project(sports_api_root($generic,'$.items'),$genericLayout,2);check($p['records'][0]['blocks'][1]['value']===false&&$p['records'][0]['blocks'][2]['value']===0,'Generic false/zero');check($p['records'][1]['blocks'][1]['value']==='Not supplied','Explicit fallback');
    check(sports_api_root($generic,'$["odd.key"]')==='ok','Quoted key path');
    foreach(['http://127.0.0.1/','http://[::1]/','http://169.254.169.254/','http://100.64.0.1/','http://10.0.0.1/','http://localhost/','http://2130706433/','ftp://example.com/','https://user:pass@example.com/','https://example.com:8080/','https://example.com/?key={apiKey}'] as $url)rejects(fn()=>sports_api_valid_url($url),'Reject unsafe URL');
    check($migrated['sport']==='basketball_nba','Default sport for migrated layout is basketball_nba');
    $availSports=sports_api_available_sports();check(count($availSports)>=10&&in_array('basketball_nba',array_column($availSports,'key'),true),'Available sports catalogue returns valid list');
    rejects(fn()=>sports_api_save(array_replace($input,['api_url'=>'https://api.the-odds-api.com/v4/sports/{sport}/odds/?apiKey={apiKey}','sport'=>''])),'Missing sport rejected when URL has {sport}');
    rejects(fn()=>sports_api_save(array_replace($input,['api_url'=>'https://api.the-odds-api.com/v4/sports/{sport}/odds/?apiKey={apiKey}','sport'=>'bad sport!'])),'Invalid sport key rejected');
    $inputWithSport=array_replace($input,['api_url'=>'https://api.the-odds-api.com/v4/sports/{sport}/odds/?apiKey={apiKey}','sport'=>'soccer_epl']);
    $sportLayoutId=sports_api_save($inputWithSport);check(sports_api_get($sportLayoutId)['sport']==='soccer_epl','Sport persisted in layout settings');
    rejects(fn()=>sports_api_validate_layout([field('bad',$out.'.name')]),'Missing loop parent rejected');rejects(fn()=>sports_api_save(array_replace($input,['columns_per_row'=>5])),'Invalid columns');rejects(fn()=>sports_api_validate_layout('{invalid'),'Malformed layout JSON');
    $failures=0;for($i=0;$i<2;$i++){try{sports_api_fetch('https://example.com/fail',function()use(&$failures){$failures++;throw new RuntimeException('secret upstream error');});throw new LogicException('Did not fail');}catch(RuntimeException $e){check($e->getMessage()==='API temporarily unavailable.','Sanitized error');}}check($failures===1,'Failure cache');
    $dupId=sports_api_duplicate($sportLayoutId);$dup=sports_api_get($dupId);$orig=sports_api_get($sportLayoutId);
    check($dupId!==$sportLayoutId&&$dup['title']===$orig['title'].' (copy)'&&!$dup['enabled']&&$dup['sport']===$orig['sport']&&$dup['layout_config']===$orig['layout_config']&&$dup['api_url']===$orig['api_url']&&$dup['columns_per_row']===$orig['columns_per_row'],'Duplicate copies settings, starts off, original unchanged');
    sports_api_delete($dupId);try{sports_api_get($dupId);throw new LogicException('Not deleted');}catch(OutOfBoundsException $e){check(true,'Delete removes the layout');}
    check(sports_api_get($sportLayoutId)['title']===$orig['title'],'Delete leaves other layouts intact');
    rejects(fn()=>sports_api_delete(1),'Default layout cannot be deleted');
    try{sports_api_delete(999999);throw new LogicException('Unknown deleted');}catch(OutOfBoundsException $e){check(true,'Deleting unknown layout fails');}
    $_SERVER['SCRIPT_NAME']='/admin/plugin/odds-api/sports/index.php';check(auth_route_capability()==='admin','Settings route requires admin');
    echo "PASS: $count sports API checks\n";
}finally{foreach(glob($dir.'/*') as $file)if(is_file($file))unlink($file);rmdir($dir);}
