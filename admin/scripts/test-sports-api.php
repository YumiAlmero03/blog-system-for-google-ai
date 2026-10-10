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
    check(isset($migrated['bookmakers'])&&$migrated['bookmakers']==='','Migrated layout has bookmakers string');
    $inputWithBooks=array_replace($input,['id'=>0,'api_url'=>'https://api.the-odds-api.com/v4/sports/{sport}/odds/?apiKey={apiKey}&bookmakers={bookmakers}','sport'=>'soccer_epl','bookmakers'=>'onexbet, draftkings']);
    $bookLayoutId=sports_api_save($inputWithBooks);
    check(sports_api_get($bookLayoutId)['bookmakers']==='onexbet,draftkings','Bookmakers normalized and persisted in layout settings');
    rejects(fn()=>sports_api_save(array_replace($inputWithBooks,['bookmakers'=>'bad;bookmaker'])),'Invalid bookmaker token rejected');
    odds_api_pdo()->exec("UPDATE settings SET api_key='test-key' WHERE id=1");
    $urlWithBooks=sports_api_url(sports_api_get($bookLayoutId),$inputWithBooks['api_url'],'soccer_epl');
    check(str_contains($urlWithBooks,'bookmakers=onexbet%2Cdraftkings')&&str_contains($urlWithBooks,'apiKey=test-key'),'sports_api_url interpolates layout bookmakers and apiKey');
    $inputPlain=array_replace($input,['id'=>0,'api_url'=>'https://api.the-odds-api.com/v4/sports/{sport}/odds/?apiKey={apiKey}&bookmakers={bookmakers}','sport'=>'soccer_epl','bookmakers'=>'']);
    $plainLayoutId=sports_api_save($inputPlain);
    $urlWithoutBooks=sports_api_url(sports_api_get($plainLayoutId),$inputPlain['api_url'],'soccer_epl');
    check(!str_contains($urlWithoutBooks,'bookmakers='),'sports_api_url removes dangling bookmakers param when empty');
    $dupBookId=sports_api_duplicate($bookLayoutId);$dupBook=sports_api_get($dupBookId);
    check($dupBook['bookmakers']==='onexbet,draftkings','Duplicate copies layout bookmakers');
    sports_api_delete($dupBookId);
    sports_api_delete($plainLayoutId);
    sports_api_pdo()->prepare('UPDATE iframes SET updated_at=? WHERE id=?')->execute(['2026-10-08 07:52:12',$bookLayoutId]);
    $layoutsList=sports_api_layouts_get_all('soccer_epl');
    $foundBookLayout=array_values(array_filter($layoutsList,fn($l)=>$l['id']===$bookLayoutId))[0]??null;
    check($foundBookLayout!==null&&$foundBookLayout['bookmakers']==='onexbet,draftkings','sports_api_layouts_get_all includes bookmakers');
    check($foundBookLayout['updated_at']===strtotime('2026-10-08 07:52:12 UTC'),'Legacy datetime update timestamps retain full date/time');
    check(sports_api_layout_timestamp('1791445932')===1791445932 && sports_api_layout_timestamp(null)===0,'Numeric and missing timestamps remain compatible');
    $tree=sports_api_tree();
    check(isset($tree['layouts']),'sports_api_tree returns layouts');
    $dupId=sports_api_duplicate($sportLayoutId);$dup=sports_api_get($dupId);$orig=sports_api_get($sportLayoutId);
    check($dupId!==$sportLayoutId&&$dup['title']===$orig['title'].' (copy)'&&!$dup['enabled']&&$dup['sport']===$orig['sport']&&$dup['layout_config']===$orig['layout_config']&&$dup['api_url']===$orig['api_url']&&$dup['columns_per_row']===$orig['columns_per_row']&&$dup['bookmakers']===$orig['bookmakers'],'Duplicate copies settings, starts off, original unchanged');
    sports_api_delete($dupId);try{sports_api_get($dupId);throw new LogicException('Not deleted');}catch(OutOfBoundsException $e){check(true,'Delete removes the layout');}
    check(sports_api_get($sportLayoutId)['title']===$orig['title'],'Delete leaves other layouts intact');
    sports_api_delete($bookLayoutId);
    rejects(fn()=>sports_api_delete(1),'Default layout cannot be deleted');
    $_SERVER['SCRIPT_NAME']='/admin/plugin/odds-api/sports/index.php';check(auth_route_capability()==='admin','Settings route requires admin');
    $outrightsLayout = [
        ['id'=>'l_out','type'=>'loop','path'=>'$.bookmakers[0].markets[0].outcomes[]','label'=>'Contenders','visible'=>true,'columns'=>3,'direction'=>'grid','children'=>[
            ['id'=>'c_item','type'=>'container','path'=>'','label'=>'Item','visible'=>true,'direction'=>'row','children'=>[
                ['id'=>'f_n','type'=>'field','path'=>'$.name','label'=>'Name','visible'=>true,'role'=>'data','format'=>'text','fallback'=>''],
                ['id'=>'b_p','type'=>'button','path'=>'$.price','label'=>'Odds','visible'=>true,'style'=>'gold','url'=>'','target'=>'_blank']
            ]]
        ]]
    ];
    $valOutrights = sports_api_validate_layout($outrightsLayout);
    check(count($valOutrights)===1,'Outrights layout validates with container inside loop');
    $rawFeed = json_decode('[{"sport_key":"basketball_nba_championship_winner","bookmakers":[{"markets":[{"key":"outrights","outcomes":[{"name":"Thunder","price":3.4}]}]}]}]');
    $projOutrights = sports_api_project($rawFeed,$valOutrights,1,0,'basketball_nba_championship_winner');
    $outItem = $projOutrights['records'][0]['blocks'][0]['items'][0]['blocks'][0]['blocks'];
    check($outItem[0]['value']==='Thunder'&&$outItem[1]['label']==='3.4','Outrights loop container children project relative values');
    $on=sports_category_save(['name'=>'Visible sport','slug'=>'visible','enabled'=>1]);
    $off=sports_category_save(['name'=>'Hidden sport','slug'=>'hidden','enabled'=>0]);
    foreach ([['visible_on',$on,1],['visible_off',$on,0],['hidden_on',$off,1],['hidden_off',$off,0]] as [$key,$cat,$enabled]) {
        tournament_save(['sports_category_id'=>$cat['id'],'title'=>$key,'odds_sport_key'=>$key,'enabled'=>$enabled,'active'=>1]);
        sports_api_save(array_replace($inputPlain,['id'=>0,'sport'=>$key,'enabled'=>'1']));
    }
    tournament_save(['sports_category_id'=>$on['id'],'title'=>'No layout','odds_sport_key'=>'visible_no_layout','enabled'=>1,'active'=>1]);
    $emptyCategory=sports_category_save(['name'=>'Without layouts','slug'=>'without_layouts','enabled'=>1]);
    tournament_save(['sports_category_id'=>$emptyCategory['id'],'title'=>'No saved layouts','odds_sport_key'=>'empty_no_layout','enabled'=>1,'active'=>1]);
    $tree=sports_api_tree();
    check(array_column($tree['layouts'],'sport')===['visible_on'],'Only category-on/tournament-on layouts are public');
    check($tree['total_categories']===1 && $tree['total_tournaments']===1 && $tree['total_layouts']===1,'Tree totals match eligible entries');
    check(array_column($tree['categories'][0]['tournaments'],'odds_sport_key')===['visible_on'],'Enabled tournaments without layouts are excluded');
    check(sports_api_tree(['sport'=>'visible_no_layout'])['total_tournaments']===0,'Sport filter cannot reveal a tournament without layouts');
    $emptyTree=sports_api_tree(['category'=>$emptyCategory['id'],'include_empty_categories'=>true]);
    check($emptyTree['total_categories']===1 && $emptyTree['total_tournaments']===0,'Explicit empty-category option preserves category but excludes layout-free tournaments');
    check(sports_api_tree(['category'=>'hidden'])['total_layouts']===0,'Disabled category filter cannot leak layouts');
    check(sports_api_tree(['sport'=>'visible_off'])['total_layouts']===0,'Disabled tournament filter cannot leak layouts');
    sports_registry_pdo()->exec("UPDATE sports_categories SET enabled=0 WHERE slug='visible'");
    check(sports_api_tree()['total_layouts']===0,'Category toggle takes effect immediately');
    sports_registry_pdo()->exec("UPDATE sports_categories SET enabled=1 WHERE slug='visible'");
    check(sports_api_tree()['total_layouts']===1,'Re-enabling restores eligible layouts');
    sports_registry_pdo()->exec("UPDATE tournaments SET active=0 WHERE odds_sport_key='visible_on'");
    check(sports_api_tree(['only_active'=>true])['total_layouts']===0,'Active-season filter also applies to flat layouts');
    check(sports_api_tree()['total_layouts']===1,'Enabled off-season tournaments remain available without active filter');
    $offLayout=sports_api_save(array_replace($inputPlain,['id'=>0,'sport'=>'visible_on','enabled'=>'0']));
    check(sports_api_tree(['only_enabled_layouts'=>true])['total_layouts']===1,'Disabled layouts excluded in public listing mode');
    $visibleLayoutId=sports_api_tree(['only_enabled_layouts'=>true])['layouts'][0]['id'];
    sports_api_pdo()->prepare('UPDATE iframes SET enabled=0 WHERE id=?')->execute([$visibleLayoutId]);
    check(sports_api_tree(['only_enabled_layouts'=>true])['total_tournaments']===0,'Last enabled layout switched off removes tournament');
    sports_api_pdo()->prepare('UPDATE iframes SET enabled=1 WHERE id=?')->execute([$visibleLayoutId]);
    $runner='$_SERVER["REQUEST_METHOD"]="GET"; $_GET=["all"=>"1","include_disabled"=>"1","view"=>"layouts"]; require '.var_export(dirname(__DIR__,2).'/odds-api/sports.php',true).';';
    $process=proc_open([PHP_BINARY,'-r',$runner],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $body=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    check(proc_close($process)===0 && $error==='','Public sports adapter runs');
    $response=json_decode($body,true,512,JSON_THROW_ON_ERROR);
    check(array_column($response['layouts'],'sport')===['visible_on'],'Public query flags cannot bypass status switches');
    $export=sports_api_export_layouts([1,$visibleLayoutId]);
    check($export['format']==='sports-layouts' && count($export['layouts'])===2,'Export one or multiple saved layouts');
    check(!isset($export['layouts'][0]['id'],$export['layouts'][0]['enabled']),'Export excludes installation IDs and live state');
    $importIds=sports_api_import_layouts(json_encode($export,JSON_THROW_ON_ERROR));
    check(count($importIds)===2 && $importIds[0]!==1,'Import creates new identities');
    foreach ($importIds as $i=>$importId) {
        $imported=sports_api_get($importId);
        check(!$imported['enabled'] && $imported['layout_config']===sports_api_validate_layout($export['layouts'][$i]['layout_config']),'Imported layout blocks preserved and disabled');
        check($imported['title']===$export['layouts'][$i]['title'] && $imported['sport']===$export['layouts'][$i]['sport'],'Imported title and sport preserved');
    }
    $beforeCount=(int)sports_api_pdo()->query('SELECT COUNT(*) FROM iframes')->fetchColumn();
    $invalid=$export;$invalid['layouts'][1]['columns_per_row']=99;
    rejects(fn()=>sports_api_import_layouts(json_encode($invalid)),'Invalid batch rejected');
    check((int)sports_api_pdo()->query('SELECT COUNT(*) FROM iframes')->fetchColumn()===$beforeCount,'Invalid batch does not partially import');
    rejects(fn()=>sports_api_import_layouts('{broken'),'Malformed import rejected');
    rejects(fn()=>sports_api_import_layouts('{"format":"sports-layouts","version":2,"layouts":[]}'),'Unknown export version rejected');
    sports_api_pdo()->prepare('UPDATE iframes SET api_url=? WHERE id=?')->execute(['https://example.com/feed?apiKey=private-test-key',$importIds[0]]);
    check(!str_contains(json_encode(sports_api_export_layouts([$importIds[0]])),'private-test-key'),'Export strips literal API credential query values');
    echo "PASS: $count sports API checks\n";
}finally{foreach(glob($dir.'/*') as $file)if(is_file($file))unlink($file);rmdir($dir);}
