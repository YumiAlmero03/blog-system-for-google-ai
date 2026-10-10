<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$dir = sys_get_temp_dir().'/sports-images-test-'.bin2hex(random_bytes(5));
mkdir($dir,0700,true);
putenv('APP_STORAGE_DIR='.$dir);
putenv('SPORTS_IMAGE_UPLOAD_DIR='.$dir.'/uploads');
require_once __DIR__.'/../includes/sports-images.php';
$count=0;
function verify(bool $value,string $message): void { global $count; if (!$value) throw new RuntimeException($message); $count++; }
function remove_tree(string $dir): void { foreach (new FilesystemIterator($dir) as $f) { if ($f->isDir()) remove_tree($f->getPathname()); else unlink($f->getPathname()); } rmdir($dir); }
$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
try {
    $pdo=sports_images_db();
    $cat=sports_category_save(['name'=>'Basketball','slug'=>'basketball','enabled'=>1]);
    $t=tournament_save(['sports_category_id'=>$cat['id'],'odds_sport_key'=>'basketball_nba','title'=>'NBA','enabled'=>1]);
    foreach (['Atlanta Hawks','Commons Team','Manual Team','Existing Team'] as $name) {
        $stmt=$pdo->prepare('INSERT INTO participants(odds_sport_key,sports_category_id,participant_id,name,slug,image_key,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)');
        $stmt->execute(['basketball_nba',$cat['id'],$name,$name,sports_registry_slugify($name),sports_registry_slugify($name),time(),time()]);
        $pdo->prepare('INSERT INTO participant_tournaments(participant_id,tournament_id,created_at) VALUES(?,?,?)')->execute([$pdo->lastInsertId(),$t['id'],time()]);
    }
    $pdo->exec("UPDATE participants SET image_source='manual',image_path='/uploads/manual.png' WHERE name='Manual Team'");
    $pdo->exec("UPDATE participants SET image_path='/uploads/existing.png' WHERE name='Existing Team'");
    $mark=['league'=>'nba','entity_id'=>'1','entity_name'=>'Atlanta Hawks','variant'=>'default','last_seen'=>'2026-09-30','archive_url'=>'https://sdv.nyc3.cdn.digitaloceanspaces.com/logo.png','url'=>'https://a.espncdn.com/logo.png'];
    $index=['team'=>[sports_images_name('Atlanta Hawks')=>[$mark]],'league'=>['nba'=>[array_merge($mark,['entity_id'=>'NBA','entity_name'=>'NBA'])]]];
    $team=participant_get_all(['search'=>'Atlanta Hawks'])[0];
    verify(sports_images_sdv($team,'participant',$index)['provider_id']==='1','Exact team and league match');
    $wrong=$team; $wrong['tournaments_keys']='baseball_mlb';
    verify(sports_images_sdv($wrong,'participant',$index)===null,'Reject cross-sport names');
    $ambiguous=$index; $ambiguous['team']['atlantahawks'][]=array_merge($mark,['entity_id'=>'2']);
    verify(sports_images_sdv($team,'participant',$ambiguous)===null,'Ambiguous identities are rejected');
    $requests=[];
    $transport=static function(string $url) use (&$requests,$png): string {
        $requests[]=$url; parse_str(parse_url($url,PHP_URL_QUERY) ?? '',$q);
        if (str_contains($url,'digitaloceanspaces.com') || str_contains($url,'upload.wikimedia.org')) return $png;
        if (($q['action'] ?? '')==='wbsearchentities') return json_encode(['search'=>[['id'=>'Q123','label'=>'Commons Team']]]);
        if (($q['action'] ?? '')==='wbgetentities') return json_encode(['entities'=>['Q123'=>['labels'=>['en'=>['value'=>'Commons Team']], 'claims'=>[
            'P641'=>[['mainsnak'=>['datavalue'=>['value'=>['id'=>'Q5372']]]]],
            'P154'=>[['rank'=>'normal','mainsnak'=>['datavalue'=>['value'=>'Example.svg']]]]]]]]);
        return json_encode(['query'=>['pages'=>[['imageinfo'=>[['thumburl'=>'https://upload.wikimedia.org/example.png','descriptionurl'=>'https://commons.wikimedia.org/wiki/File:Example.svg',
            'extmetadata'=>['LicenseShortName'=>['value'=>'CC BY-SA 4.0'],'Artist'=>['value'=>'<b>Example Artist</b>'],'LicenseUrl'=>['value'=>'https://creativecommons.org/licenses/by-sa/4.0/']]]]]]]]);
    };
    $wrongSport=static function(string $url) use ($transport): string {
        $body=$transport($url);
        return str_contains($url,'wbgetentities') ? str_replace('Q5372','Q2736',$body) : $body;
    };
    verify(sports_images_wikidata(['name'=>'Commons Team','odds_sport_key'=>'basketball_nba'],$wrongSport)===null,'Wikidata rejects wrong sport');
    $historic=static function(string $url) use ($transport): string {
        $body=$transport($url);
        if (str_contains($url,'wbgetentities')) {
            $data=json_decode($body,true);$data['entities']['Q123']['claims']['P154'][0]['qualifiers']['P582']=[];
            return json_encode($data);
        }
        return $body;
    };
    verify(sports_images_wikidata(['name'=>'Commons Team','odds_sport_key'=>'basketball_nba'],$historic)===null,'Wikidata rejects ended logo claims');
    $duplicate=static function(string $url) use ($transport): string {
        $body=$transport($url);
        if (str_contains($url,'wbgetentities')) {
            $data=json_decode($body,true);$data['entities']['Q456']=$data['entities']['Q123'];return json_encode($data);
        }
        return $body;
    };
    verify(sports_images_wikidata(['name'=>'Commons Team','odds_sport_key'=>'basketball_nba'],$duplicate)===null,'Wikidata rejects duplicate exact-name entities');
    verify(sports_images_leagues(['odds_sport_key'=>'basketball_nba_preseason'])===['nba'],'NBA preseason shares NBA identity mapping');
    verify(sports_images_leagues(['odds_sport_key'=>'basketball_ncaab_championship_winner'])===['mbb'],'NCAA outright markets share college basketball mapping');
    verify(sports_images_leagues(['odds_sport_key'=>'icehockey_ahl'])===['ahl'],'AHL has its own source league');
    $teamOnlyIndex=$index;unset($teamOnlyIndex['league']);
    $prioritized=sports_images_enrich((int)$t['id'],['limit'=>1,'dry_run'=>true,'manifest'=>$teamOnlyIndex,'transport'=>static function(){throw new RuntimeException('Wikimedia must not run ahead of a known team match');}]);
    verify($prioritized['enriched']===1 && $prioritized['results'][0]['name']==='Atlanta Hawks','Known team logo is processed before unmatched tournament');
    $preview=sports_images_enrich((int)$t['id'],['manifest'=>$index,'transport'=>$transport,'dry_run'=>true]);
    verify($preview['enriched']===3 && $preview['wikimedia']===1,'SDV followed by Commons fallback');
    verify($pdo->query('SELECT COUNT(*) FROM sports_image_assets')->fetchColumn()===0,'Preview does not assign or record images');
    verify(!is_dir($dir.'/uploads'),'Preview does not download files');
    $stats=sports_images_enrich((int)$t['id'],['manifest'=>$index,'transport'=>$transport]);
    verify($stats['enriched']===3 && $stats['sportsdataverse']===2 && $stats['wikimedia']===1,'Both image sources saved');
    verify($stats['skipped_manual']===1 && $stats['skipped_existing']===1,'Existing and manual logos skipped');
    verify(participant_get_all(['search'=>'Manual Team'])[0]['image_path']==='/uploads/manual.png','Manual logo unchanged');
    verify(str_contains(sports_registry_resolve_sport_image('basketball_nba'),'/uploads/sports/'),'Tournament logo resolved for iframe');
    $saved=$pdo->query("SELECT metadata FROM sports_image_assets WHERE source='wikimedia'")->fetchColumn();
    verify(json_decode($saved,true)['artist']==='Example Artist','Attribution stored as text');
    $again=sports_images_enrich((int)$t['id'],['manifest'=>$index,'transport'=>static function(){throw new RuntimeException('Should not fetch');}]);
    verify($again['processed']===0,'Repeat batch preserves existing logos without requests');
    $pdo->exec("UPDATE participants SET image_path='' WHERE name='Commons Team'");
    $pdo->exec("DELETE FROM sports_image_assets WHERE source='wikimedia'");
    $rate=sports_images_enrich((int)$t['id'],['manifest'=>$index,'transport'=>static function(){throw new SportsImagesRateLimit('HTTP 429');}]);
    verify($rate['stopped'] && $rate['processed']===0,'429 stops immediately without marking unmatched');
    $missing=sports_images_enrich((int)$t['id'],['manifest'=>$index,'transport'=>static fn()=>'{"search":[]}']);
    verify($missing['unmatched']===1,'No match recorded');
    verify(sports_images_enrich((int)$t['id'],['manifest'=>$index])['processed']===0,'Unmatched cooldown prevents repeated requests');
    $unsafe=false; try { sports_images_fetch('https://127.0.0.1/private',10,static fn()=>'x'); } catch (RuntimeException) { $unsafe=true; }
    verify($unsafe,'Untrusted hosts blocked even with test transport');
    $unsafe=false; try { sports_images_store(['url'=>'https://upload.wikimedia.org/file.svg'],static fn()=>'<svg onload="alert(1)"></svg>'); } catch (RuntimeException) { $unsafe=true; }
    verify($unsafe,'Active SVG rejected');
    // Exercise real CSV parsing, ignoring historic, dark and wordmark variants.
    $csv="level,league,entity_id,entity_name,mark_type,variant,valid_to,last_seen,archive_url,ext,url\n";
    $csv.="team,nba,1,Atlanta Hawks,logo,default,,2026-09-30,https://sdv.nyc3.cdn.digitaloceanspaces.com/logo.png,png,https://example.com\n";
    $csv.="team,nba,2,Atlanta Hawks,logo,default,2020,2026-09-30,https://sdv.nyc3.cdn.digitaloceanspaces.com/old.png,png,https://example.com\n";
    $parsed=sports_images_manifest(static fn()=>$csv);
    verify(count($parsed['team']['atlantahawks'])===1,'Historical logos excluded by parser');
    require_once __DIR__.'/../includes/sports-api.php';
    putenv('SITE_BASE_URL=[insert_domain_here]');
    $images=[];
    foreach (['sport','home_team','away_team'] as $source) $images[]=['id'=>$source,'type'=>'image','path'=>'$.'.$source,'label'=>$source,'image_source'=>$source,'section'=>$source==='sport'?'title':'loop'];
    $projection=sports_api_project([(object)['sport_key'=>'basketball_nba','home_team'=>'Atlanta Hawks','away_team'=>'Manual Team']],$images,1,0,'basketball_nba');
    verify(str_starts_with($projection['title_blocks'][0]['url'],'/uploads/sports/'),'Tournament image uses local path despite placeholder site origin');
    verify(str_starts_with($projection['records'][0]['blocks'][0]['url'],'/uploads/sports/'),'Home logo uses local path');
    verify($projection['records'][0]['blocks'][1]['url']==='/uploads/manual.png','Manual away logo uses local path');
    $missing=sports_api_project([(object)['home_team'=>'Unknown Team']],[$images[1]],1,0,'basketball_nba');
    verify($missing['records'][0]['blocks'][0]['url']==='','Missing logo never falls back to team name as URL');
    $nested=['id'=>'events','type'=>'loop','path'=>'$.events[]','label'=>'Events','columns'=>1,'children'=>[array_merge($images[1],['path'=>'$.events[].home_team'])]];
    $projected=sports_api_project((object)['events'=>[(object)['home_team'=>'Atlanta Hawks']]],[$nested],1,0,'basketball_nba');
    verify(str_starts_with($projected['records'][0]['blocks'][0]['items'][0]['blocks'][0]['url'],'/uploads/sports/'),'Nested image uses configured team path');
    echo "PASS: {$count} sports image checks\n";
} finally { remove_tree($dir); }
