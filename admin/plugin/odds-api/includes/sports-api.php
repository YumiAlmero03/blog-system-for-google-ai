<?php
declare(strict_types=1);
require_once __DIR__ . '/service.php';
require_once __DIR__ . '/sports-registry.php';

/** Saved API layouts share the existing sports database and embed IDs. */
function sports_api_pdo(): PDO
{
    static $ready = false;
    $pdo = odds_api_iframe_pdo();
    if ($ready) return $pdo;
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $columns = array_column($pdo->query('PRAGMA table_info(iframes)')->fetchAll(), 'name');
        foreach (['api_url'=>"TEXT NOT NULL DEFAULT ''", 'sport'=>"TEXT NOT NULL DEFAULT ''", 'enabled'=>'INTEGER NOT NULL DEFAULT 0', 'root_path'=>"TEXT NOT NULL DEFAULT '$'", 'columns_per_row'=>'INTEGER NOT NULL DEFAULT 1', 'layout_config'=>"TEXT NOT NULL DEFAULT ''", 'catalog_url'=>"TEXT NOT NULL DEFAULT ''", 'item_limit'=>'INTEGER NOT NULL DEFAULT 0', 'bookmakers'=>"TEXT NOT NULL DEFAULT ''"] as $name=>$definition) {
            if (!in_array($name, $columns, true)) $pdo->exec('ALTER TABLE iframes ADD COLUMN ' . $name . ' ' . $definition);
        }
        $pdo->exec("UPDATE iframes SET sport='basketball_nba' WHERE id=1 AND (sport='' OR sport IS NULL)");
        $global = odds_api_pdo()->query('SELECT enabled FROM settings WHERE id=1')->fetchColumn();
        foreach ($pdo->query("SELECT * FROM iframes WHERE layout_config='' ")->fetchAll() as $row) {
            $blocks = json_decode($row['blocks'], true, 32, JSON_THROW_ON_ERROR); $nodes = []; $n = 0;
            $field = static function(array $block, string $path) use (&$n): array {
                return ['id'=>'m'.++$n,'type'=>'field','path'=>$path,'label'=>$block['label'],'role'=>$block['role'],'visible'=>true,'format'=>str_ends_with($path, 'last_update') || $path==='$.commence_time' ? 'datetime' : 'text','fallback'=>'—'];
            };
            foreach ($blocks['event_fields'] as $block) $nodes[] = $field($block, '$.' . $block['field']);
            if ($blocks['outcome_fields']) {
                $children = [];
                // Repeat ancestors in scope, preserving original configured column order.
                foreach ($blocks['outcome_fields'] as $block) {
                    $path = str_replace(['bookmaker.','market.','outcome.'], ['$.bookmakers[].','$.bookmakers[].markets[].','$.bookmakers[].markets[].outcomes[].'], $block['field']);
                    $children[] = $field($block, $path);
                }
                $nodes[] = ['id'=>'m'.++$n,'type'=>'loop','path'=>'$.bookmakers[]','label'=>'Bookmakers','visible'=>true,'columns'=>1,'children'=>[
                    ['id'=>'m'.++$n,'type'=>'loop','path'=>'$.bookmakers[].markets[]','label'=>'Markets','visible'=>true,'columns'=>1,'children'=>[
                        ['id'=>'m'.++$n,'type'=>'loop','path'=>'$.bookmakers[].markets[].outcomes[]','label'=>'Outcomes','visible'=>true,'columns'=>1,'children'=>$children],
                    ]],
                ]];
            }
            $pdo->prepare('UPDATE iframes SET api_url=?,catalog_url=?,enabled=?,layout_config=?,sport=? WHERE id=?')->execute([
                'https://api.the-odds-api.com/v4/sports/{sport}/odds/?apiKey={apiKey}&regions={regions}&markets={markets}&bookmakers={bookmakers}',
                'https://api.the-odds-api.com/v4/sports/?apiKey={apiKey}', (int)$global,
                json_encode($nodes, JSON_THROW_ON_ERROR),'basketball_nba',$row['id'],
            ]);
        }
        $pdo->exec('CREATE TABLE IF NOT EXISTS sport_api_cache (cache_key TEXT PRIMARY KEY, payload TEXT NOT NULL, fetched_at INTEGER NOT NULL, expires_at INTEGER NOT NULL)');
        $pdo->exec('COMMIT');
    } catch (Throwable $e) { $pdo->exec('ROLLBACK'); throw $e; }
    $ready = true;
    return $pdo;
}

function sports_api_get(int $id): array
{
    $stmt = sports_api_pdo()->prepare('SELECT * FROM iframes WHERE id=?'); $stmt->execute([$id]); $row = $stmt->fetch();
    if (!$row) throw new OutOfBoundsException('Configuration not found.');
    $row['layout_config'] = json_decode($row['layout_config'], true, 32, JSON_THROW_ON_ERROR);
    $row['id']=(int)$row['id']; $row['enabled']=(bool)$row['enabled']; $row['columns_per_row']=(int)$row['columns_per_row'];
    $row['item_limit'] = isset($row['item_limit']) ? (int)$row['item_limit'] : 0;
    $row['sport'] = (string)($row['sport'] ?? '');
    $row['bookmakers'] = (string)($row['bookmakers'] ?? '');
    return $row;
}

function sports_api_tokens_match(array $a, array $b): bool
{
    if (count($a) !== count($b)) return false;
    foreach ($a as $i => $va) {
        $vb = $b[$i];
        if ($va === $vb) continue;
        if (($va === null && is_int($vb)) || ($vb === null && is_int($va))) continue;
        return false;
    }
    return true;
}

function sports_api_path(string $path): array
{
    if (strlen($path)>1000 || !str_starts_with($path,'$')) throw new InvalidArgumentException('Paths must start with $.');
    $tokens=[]; $offset=1;
    while ($offset<strlen($path)) {
        if (!preg_match('/\G(?:\.([A-Za-z0-9_-]+)|\[("(?:[^"\\\\]|\\\\.)*")\]|\[(\d+)\]|(\[\]))/A', $path, $m, 0, $offset)) throw new InvalidArgumentException('Invalid field path.');
        $tokens[] = !empty($m[1]) ? (is_numeric($m[1]) ? (int)$m[1] : $m[1]) : (!empty($m[2]) ? json_decode($m[2], true, 8, JSON_THROW_ON_ERROR) : (isset($m[3]) && $m[3] !== '' ? (int)$m[3] : null));
        $offset += strlen($m[0]);
    }
    return $tokens;
}

function sports_api_key_path(string $base, string $key): string
{
    return $base . (preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/D',$key) ? '.'.$key : '['.json_encode($key,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).']');
}

function sports_api_root(mixed $data, string $path): mixed
{
    $tokens = sports_api_path($path); if ($tokens && end($tokens)===null) array_pop($tokens);
    foreach ($tokens as $key) {
        if ($key===null || !is_object($data) || !property_exists($data,$key)) throw new InvalidArgumentException('The selected root path was not found.');
        $data=$data->$key;
    }
    return $data;
}

function sports_api_discover(mixed $data): array
{
    $found=[]; $budget=20000;
    $walk = static function(mixed $value,string $path,int $depth) use (&$walk,&$found,&$budget): void {
        if (--$budget<0 || $depth>12 || count($found)>=500) return;
        $kind = is_array($value) ? 'array' : (is_object($value) ? 'object' : 'field');
        $name = $kind==='array' ? $path.'[]' : $path;
        if (!isset($found[$name]) || ($value!==null && $found[$name]['kind']!=='array')) $found[$name]=['path'=>$name,'kind'=>$kind,'value_type'=>get_debug_type($value)];
        if (is_object($value)) foreach (get_object_vars($value) as $key=>$child) $walk($child,sports_api_key_path($path,(string)$key),$depth+1);
        elseif (is_array($value)) foreach (array_slice($value,0,100) as $child) $walk($child,$name,$depth+1);
    };
    foreach (is_array($data) ? array_slice($data,0,100) : [$data] as $record) $walk($record,'$',0);
    return array_values($found);
}

function sports_api_validate_layout(mixed $nodes): array
{
    if (is_string($nodes)) {
        if (strlen($nodes)>100000) throw new InvalidArgumentException('Layout is too large.');
        try {$nodes=json_decode($nodes,true,32,JSON_THROW_ON_ERROR);} catch(Throwable $e){throw new InvalidArgumentException('Invalid layout JSON.');}
    }
    $count=0; $ids=[];
    $walk=static function(mixed $list,array $scope,int $depth) use (&$walk,&$count,&$ids): array {
        if (!is_array($list) || !array_is_list($list) || $depth>8) throw new InvalidArgumentException('Invalid nested layout.');
        $out=[];
        foreach ($list as $node) {
            if (++$count>120 || !is_array($node)) throw new InvalidArgumentException('Use at most 120 blocks.');
            $id=$node['id']??''; $type=$node['type']??''; $path=$node['path']??''; $label=$node['label']??'';
            if (!is_string($id) || !preg_match('/^[a-zA-Z0-9_-]{1,80}$/D',$id) || isset($ids[$id])) throw new InvalidArgumentException('Each block needs a unique ID.');
            $ids[$id]=true;
            if (!in_array($type,['field','loop','button','link','text','image','container'],true) || !is_string($path) || !is_string($label) || strlen($label)>200) throw new InvalidArgumentException('Invalid block settings.');

            $tokens = [];
            if ($path !== '') {
                $tokens=sports_api_path($path);
                // Fields can reference an ancestor's scalar values, but never cross an unconfigured loop.
                $arrays=[]; foreach ($tokens as $i=>$token) if ($token===null) $arrays[]=array_slice($tokens,0,$i+1);
                if ($type==='loop' && (!$tokens || end($tokens)!==null)) throw new InvalidArgumentException('Loop paths must end with [].');
                $prefix=array_slice($tokens,0,count($scope));
                if ($type==='loop' && !sports_api_tokens_match($prefix,$scope)) throw new InvalidArgumentException('Place nested loops inside their parent loop.');
                $check=$type==='loop' ? array_slice($arrays,0,-1) : $arrays;
                foreach ($check as $ancestor) if (!sports_api_tokens_match(array_slice($scope,0,count($ancestor)),$ancestor)) throw new InvalidArgumentException('Add the parent loop before this field.');
                if ($type==='loop' && count($tokens)<=count($scope)) throw new InvalidArgumentException('A loop must select a child array.');
            } else {
                if ($type==='field' || $type==='loop') throw new InvalidArgumentException('Field or loop paths cannot be empty.');
            }

            $section = in_array($node['section']??'loop', ['title','loop'], true) ? ($node['section']??'loop') : 'loop';
            $item=['id'=>$id,'type'=>$type,'path'=>$path,'label'=>$label,'section'=>$section,'visible'=>($node['visible']??true)!==false];
            if (isset($node['width']) && is_string($node['width']) && strlen(trim($node['width'])) > 0) {
                $item['width'] = substr(trim($node['width']), 0, 40);
            }
            if (isset($node['align']) && is_string($node['align']) && in_array(trim($node['align']), ['left', 'center', 'right', 'justify', 'full'], true)) {
                $item['align'] = trim($node['align']);
            }
            if ($type==='field') {
                $role=$node['role']??'data'; $format=$node['format']??'text'; $fallback=$node['fallback']??'';
                if (!in_array($role,['data','title','description'],true) || !in_array($format,['text','decimal','date','datetime','time'],true) || !is_string($fallback) || strlen($fallback)>200) throw new InvalidArgumentException('Invalid field format or fallback.');
                $item+=['role'=>$role,'format'=>$format,'fallback'=>$fallback];
                if (isset($node['url']) && is_string($node['url']) && strlen($node['url'])<=2048) $item['url']=$node['url'];
            } elseif ($type==='container') {
                $direction = in_array($node['direction']??'row', ['row','column'], true) ? ($node['direction']??'row') : 'row';
                $item+=['direction'=>$direction,'children'=>$walk($node['children']??[],$scope,$depth+1)];
            } elseif ($type==='loop') {
                $cols=filter_var($node['columns']??1,FILTER_VALIDATE_INT);
                if ($cols===false || $cols<1 || $cols>4) throw new InvalidArgumentException('Choose 1–4 columns.');
                $direction = in_array($node['direction']??'grid', ['grid','row'], true) ? ($node['direction']??'grid') : 'grid';
                $item+=['columns'=>$cols,'direction'=>$direction,'children'=>$walk($node['children']??[],$tokens,$depth+1)];
            } elseif ($type==='button' || $type==='link') {
                $url = isset($node['url']) && is_string($node['url']) ? substr($node['url'], 0, 2048) : '';
                $style = in_array($node['style']??'primary', ['primary','gold','brand','secondary','outline'], true) ? ($node['style']??'primary') : 'primary';
                $target = in_array($node['target']??'_blank', ['_blank','_self'], true) ? ($node['target']??'_blank') : '_blank';
                $btnWidth = isset($node['btn_width']) && is_string($node['btn_width']) ? substr(trim($node['btn_width']), 0, 40) : '';
                $item+=['url'=>$url,'style'=>$style,'target'=>$target];
                if ($btnWidth !== '') $item['btn_width'] = $btnWidth;
            } elseif ($type==='text') {
                $text = isset($node['text']) && is_string($node['text']) ? substr($node['text'], 0, 2000) : '';
                $role = in_array($node['role']??'description', ['data','title','description','badge'], true) ? ($node['role']??'description') : 'description';
                $item+=['text'=>$text,'role'=>$role];
            } elseif ($type==='image') {
                $url = isset($node['url']) && is_string($node['url']) ? substr($node['url'], 0, 2048) : '';
                $alt = isset($node['alt']) && is_string($node['alt']) ? substr($node['alt'], 0, 200) : '';
                $width = isset($node['width']) && is_string($node['width']) ? substr($node['width'], 0, 40) : '';
                $height = isset($node['height']) && is_string($node['height']) ? substr($node['height'], 0, 40) : '';
                $imageWidth = isset($node['image_width']) && is_string($node['image_width']) ? substr(trim($node['image_width']), 0, 40) : '';
                $imageHeight = isset($node['image_height']) && is_string($node['image_height']) ? substr(trim($node['image_height']), 0, 40) : '';
                $imgSrc = isset($node['image_source']) && is_string($node['image_source']) ? substr($node['image_source'], 0, 32) : (isset($node['source_mode']) && is_string($node['source_mode']) ? substr($node['source_mode'], 0, 32) : 'custom');
                $item+=['url'=>$url,'alt'=>$alt,'width'=>$width,'height'=>$height,'image_width'=>$imageWidth,'image_height'=>$imageHeight,'image_source'=>$imgSrc];
            }
            $out[]=$item;
        }
        return $out;
    };
    return $walk($nodes,[],0);
}

function sports_api_valid_url(string $url): array
{
    $test=preg_replace('/\{(?:apiKey|sport|regions|markets|bookmakers)\}/','value',$url);
    if (strlen($url)>4096 || !filter_var($test,FILTER_VALIDATE_URL) || preg_match('/[\x00-\x20\x7f\\\\]/',$url)) throw new InvalidArgumentException('Enter a valid HTTP or HTTPS API URL.');
    $parts=parse_url($test); $host=strtolower(trim($parts['host']??'','[]'));
    if (!in_array(strtolower($parts['scheme']??''),['http','https'],true) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || !in_array($parts['port']??(strtolower($parts['scheme'])==='https'?443:80),[80,443],true)) throw new InvalidArgumentException('Use an HTTP(S) URL on port 80 or 443 without user info or fragments.');
    if (str_contains($url,'{apiKey}') && $host!=='api.the-odds-api.com') throw new InvalidArgumentException('The saved Odds API key is restricted to its provider host.');
    if (filter_var($host,FILTER_VALIDATE_IP)) {
        if (!sports_api_public_ip($host)) throw new InvalidArgumentException('Private or reserved API addresses are not allowed.');
    } elseif (!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{1,62}$/D',$host) || preg_match('/\.(?:localhost|local|internal|test|invalid)$/D',$host)) throw new InvalidArgumentException('Use a public API hostname.');
    return $parts;
}

function sports_api_public_ip(string $ip): bool
{
    return filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_GLOBAL_RANGE)!==false;
}

function sports_api_http(string $url): mixed
{
    $parts=sports_api_valid_url($url); $host=trim($parts['host'],'[]'); $port=$parts['port']??(strtolower($parts['scheme'])==='https'?443:80);
    $ips=[];
    if (filter_var($host,FILTER_VALIDATE_IP)) $ips=[$host];
    else foreach (@dns_get_record($host,DNS_A|DNS_AAAA)?:[] as $record) if (isset($record['ip']) || isset($record['ipv6'])) $ips[]=$record['ip']??$record['ipv6'];
    if (!$ips) throw new RuntimeException('API hostname could not be resolved.');
    foreach ($ips as $ip) if (!sports_api_public_ip($ip)) throw new RuntimeException('API hostname resolves to a non-public address.');
    $body=''; $curl=curl_init($url);
    curl_setopt_array($curl,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_PROXY=>'',CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>['Accept: application/json'],CURLOPT_WRITEFUNCTION=>static function($ch,string $chunk) use (&$body):int {if(strlen($body)+strlen($chunk)>2097152)return 0;$body.=$chunk;return strlen($chunk);}]);
    if (!filter_var($host,FILTER_VALIDATE_IP)) curl_setopt($curl,CURLOPT_RESOLVE,[$host.':'.$port.':'.(str_contains($ips[0],':')?'['.$ips[0].']':$ips[0])]);
    try {
        $ok=curl_exec($curl); $status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
        if ($ok===false || $status!==200) throw new RuntimeException('API request failed.');
        try {return json_decode($body,false,64,JSON_THROW_ON_ERROR);} catch(Throwable $e){throw new RuntimeException('API did not return valid JSON.');}
    } finally {curl_close($curl);}
}

function sports_api_fetch(string $url,?callable $transport=null): array
{
    sports_api_valid_url($url);
    $pdo=sports_api_pdo(); $key=hash('sha256',$url); $lock=fopen(odds_api_directory().'/sports-api.lock','c');
    if (!$lock) throw new RuntimeException('API cache unavailable.');
    try {
        if(!flock($lock,LOCK_EX))throw new RuntimeException('API cache unavailable.');
        $pdo->prepare('DELETE FROM sport_api_cache WHERE expires_at<=?')->execute([time()]);
        $query=$pdo->prepare('SELECT * FROM sport_api_cache WHERE cache_key=?');$query->execute([$key]);$cached=$query->fetch();
        if($cached){$data=json_decode($cached['payload'],false,64,JSON_THROW_ON_ERROR);if(isset($data->error))throw new RuntimeException('API temporarily unavailable.');return ['data'=>$data->data,'updatedAt'=>gmdate('c',(int)$cached['fetched_at'])];}
        try {
            $data=($transport??'sports_api_http')($url); $payload=['data'=>$data];$ttl=300;
        } catch(Throwable $e){error_log('Sports API fetch failed [source='.substr($key,0,12).'].');$payload=['error'=>true];$ttl=60;}
        $pdo->prepare('INSERT OR REPLACE INTO sport_api_cache VALUES(?,?,?,?)')->execute([$key,json_encode($payload,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE),time(),time()+$ttl]);
        if(isset($payload['error']))throw new RuntimeException('API temporarily unavailable.');
        return ['data'=>$data,'updatedAt'=>gmdate('c')];
    } finally {flock($lock,LOCK_UN);fclose($lock);}
}

function sports_api_configure(array $input): array
{
    $id=filter_var($input['id']??0,FILTER_VALIDATE_INT);if($id===false||$id<0)throw new InvalidArgumentException('Invalid configuration ID.');
    $old=$id?sports_api_get($id):null;
    $name=$input['name']??'';$url=$input['api_url']??'';$root=$input['root_path']??'$';
    if(!is_string($name)||trim($name)===''||strlen($name)>200||!is_string($url)||!is_string($root))throw new InvalidArgumentException('Enter a name and API URL.');
    sports_api_valid_url($url);sports_api_path($root);
    $rootTokens=sports_api_path($root);if($rootTokens&&end($rootTokens)===null)array_pop($rootTokens);if(in_array(null,$rootTokens,true))throw new InvalidArgumentException('Root paths cannot cross repeating arrays. Select their enclosing object.');
    $columns=filter_var($input['columns_per_row']??1,FILTER_VALIDATE_INT);if($columns===false||$columns<1||$columns>4)throw new InvalidArgumentException('Choose 1–4 columns.');
    $limit=filter_var($input['item_limit']??($input['limit']??($old['item_limit']??0)),FILTER_VALIDATE_INT);
    if($limit===false||$limit<0)$limit=0;
    $enabled=$input['enabled']??'0';if(!in_array($enabled,['0','1',0,1,false,true],true))throw new InvalidArgumentException('Invalid enabled status.');
    $layout=sports_api_validate_layout($input['layout_config']??[]);
    $catalog = ($old && $old['catalog_url'] !== '') ? $old['catalog_url'] : (str_contains($url, '{sport}') || str_contains($url, 'the-odds-api.com') ? 'https://api.the-odds-api.com/v4/sports/?apiKey={apiKey}' : '');
    if(str_contains($url,'{sport}') && !$catalog)throw new InvalidArgumentException('Enter a complete API URL with a sport key.');
    $sport = trim((string)($input['sport'] ?? ($old['sport'] ?? '')));
    if((str_contains($url,'{sport}') || $catalog !== '') && ($sport === '' || !preg_match('/^[a-z0-9_]{1,100}$/D', $sport))) {
        throw new InvalidArgumentException('Please select a sport.');
    }
    return [
        'id'=>$id,
        'title'=>trim($name),
        'api_url'=>$url,
        'sport'=>$sport,
        'enabled'=>(bool)$enabled,
        'root_path'=>$root,
        'columns_per_row'=>$columns,
        'item_limit'=>$limit,
        'layout_config'=>$layout,
        'catalog_url'=>$catalog,
        'markets'=>odds_api_csv($input['markets']??($old['markets']??'h2h'),'markets',['h2h','spreads','totals','outrights']),
        'bookmakers'=>odds_api_csv($input['bookmakers']??($old['bookmakers']??''),'bookmakers',null,true),
    ];
}

function sports_api_save(array $input): int
{
    $row=sports_api_configure($input);$pdo=sports_api_pdo();
    $params=[$row['title'],$row['api_url'],$row['sport'],(int)$row['enabled'],$row['root_path'],$row['columns_per_row'],json_encode($row['layout_config'],JSON_THROW_ON_ERROR),$row['catalog_url'],$row['markets'],$row['bookmakers'],$row['item_limit'],time()];
    if($row['id']){$params[]=$row['id'];$pdo->prepare('UPDATE iframes SET title=?,api_url=?,sport=?,enabled=?,root_path=?,columns_per_row=?,layout_config=?,catalog_url=?,markets=?,bookmakers=?,item_limit=?,updated_at=? WHERE id=?')->execute($params);return $row['id'];}
    $params[]=time();$pdo->prepare("INSERT INTO iframes(title,api_url,sport,enabled,root_path,columns_per_row,layout_config,catalog_url,markets,bookmakers,item_limit,updated_at,created_at,blocks) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,'{\"event_fields\":[],\"outcome_fields\":[]}')")->execute($params);return (int)$pdo->lastInsertId();
}

function sports_api_duplicate(int $id): int
{
    $src=sports_api_get($id);
    $title=substr(trim($src['title']).' (copy)',0,200);
    $pdo=sports_api_pdo();
    // Copies start switched off so a duplicate never goes live by accident.
    $pdo->prepare("INSERT INTO iframes(title,api_url,sport,enabled,root_path,columns_per_row,layout_config,catalog_url,markets,bookmakers,item_limit,updated_at,created_at,blocks) VALUES(?,?,?,0,?,?,?,?,?,?,?,?,?,?)")->execute([
        $title,$src['api_url'],$src['sport'],$src['root_path'],$src['columns_per_row'],json_encode($src['layout_config'],JSON_THROW_ON_ERROR),$src['catalog_url'],$src['markets'],$src['bookmakers']??'',$src['item_limit'],time(),time(),$src['blocks'],
    ]);
    return (int)$pdo->lastInsertId();
}

function sports_api_delete(int $id): void
{
    // Layout 1 is the default embed (/odds-api/) and is re-imported if missing, so it can be edited but not deleted.
    if ($id === 1) throw new InvalidArgumentException('The default layout cannot be deleted. Edit it or duplicate it instead.');
    sports_api_get($id);
    sports_api_pdo()->prepare('DELETE FROM iframes WHERE id=?')->execute([$id]);
}

function sports_api_url(array $config,string $template,string $sport=''): string
{
    $settings=odds_api_pdo()->query('SELECT * FROM settings WHERE id=1')->fetch();
    if(str_contains($template,'{apiKey}') && $settings['api_key']==='')throw new RuntimeException('Provider key not configured.');
    $bookmakers = !empty($config['bookmakers']) ? $config['bookmakers'] : (string)($settings['bookmakers'] ?? '');
    $url = strtr($template,[
        '{sport}'=>rawurlencode($sport),
        '{apiKey}'=>rawurlencode($settings['api_key']),
        '{regions}'=>rawurlencode($settings['regions']),
        '{markets}'=>rawurlencode($config['markets'] ?? 'h2h'),
        '{bookmakers}'=>rawurlencode($bookmakers),
    ]);
    if ($bookmakers === '') {
        $url = preg_replace('/(?<=[?&])bookmakers=(?:&|$)/', '', $url);
        $url = rtrim($url, '?&');
    }
    return $url;
}

function sports_api_resolve_category_name(string $sportKey): string
{
    $sportKey = trim($sportKey);
    if ($sportKey === '') return '';
    try {
        $t = tournament_get($sportKey);
        if ($t && !empty($t['category_name'])) {
            return (string)$t['category_name'];
        }
        $parts = explode('_', strtolower($sportKey));
        $prefix = $parts[0] ?? '';
        if ($prefix !== '') {
            $cat = sports_category_get($prefix);
            if ($cat && !empty($cat['name'])) {
                return (string)$cat['name'];
            }
            if ($prefix === 'soccer') {
                $cat = sports_category_get('football');
                if ($cat && !empty($cat['name'])) return (string)$cat['name'];
            }
        }
    } catch (Throwable) {}
    return '';
}

function sports_api_source(array $config,string $sport='',?callable $transport=null): array
{
    if ($sport === '' && !empty($config['sport'])) {
        $sport = $config['sport'];
    }
    $sports=[];
    if($config['catalog_url']!==''){
        $catalog=sports_api_fetch(sports_api_url($config,$config['catalog_url']),$transport)['data'];
        if(!is_array($catalog))throw new RuntimeException('Invalid sports catalogue.');
        foreach($catalog as $item)if(is_object($item)&&!empty($item->active)&&is_string($item->key??null)&&preg_match('/^[a-z0-9_]{1,100}$/D',$item->key))$sports[]=['key'=>$item->key,'title'=>(string)($item->title??$item->key),'group'=>(string)($item->group??''),'description'=>(string)($item->description??''),'active'=>true,'has_outrights'=>!empty($item->has_outrights)];
        if(!$sports)return ['data'=>[],'updatedAt'=>null,'sports'=>[],'sport'=>''];
        if($sport==='')$sport=!empty($config['sport'])?$config['sport']:$sports[0]['key'];
        if(!in_array($sport,array_column($sports,'key'),true))throw new InvalidArgumentException('Sport is unavailable.');
    }
    $result=sports_api_fetch(sports_api_url($config,$config['api_url'],$sport),$transport);
    $catName = sports_api_resolve_category_name($sport);
    if(is_array($result['data'] ?? null)){
        $map = $sports ? array_column($sports,null,'key') : [];
        foreach($result['data'] as $event){
            if(is_object($event)){
                if ($sports) {
                    $event->sport=isset($map[$event->sport_key??''])?(object)$map[$event->sport_key]:null;
                }
                $eventCat = !empty($event->sport_key) ? sports_api_resolve_category_name($event->sport_key) : $catName;
                if ($eventCat === '' && isset($event->sport->group)) {
                    $eventCat = (string)$event->sport->group;
                }
                $event->sport_category = $eventCat ?: 'Sports';
            }
        }
        sports_api_merge_bookmakers($result['data']);
    }
    return $result+['sports'=>$sports,'sport'=>$sport];
}

function sports_api_positive_point(mixed $point): mixed
{
    if ($point === null || $point === '' || !is_numeric($point)) {
        return $point;
    }
    return (is_float($point) || str_contains((string)$point, '.')) ? abs((float)$point) : abs((int)$point);
}

function sports_api_merge_bookmakers(mixed &$data): void
{
    if (empty($data)) return;
    $records = is_array($data) ? $data : [$data];
    foreach ($records as &$event) {
        $bookmakers = is_object($event) ? ($event->bookmakers ?? null) : ($event['bookmakers'] ?? null);
        if (!is_array($bookmakers) || empty($bookmakers)) continue;

        // 1. Ensure all points across all bookmakers are positive
        foreach ($bookmakers as &$bm) {
            $markets = is_object($bm) ? ($bm->markets ?? []) : ($bm['markets'] ?? []);
            if (!is_array($markets)) continue;
            foreach ($markets as &$m) {
                $outcomes = is_object($m) ? ($m->outcomes ?? []) : ($m['outcomes'] ?? []);
                if (!is_array($outcomes)) continue;
                foreach ($outcomes as &$o) {
                    if (is_object($o) && isset($o->point)) {
                        $o->point = sports_api_positive_point($o->point);
                    } elseif (is_array($o) && isset($o['point'])) {
                        $o['point'] = sports_api_positive_point($o['point']);
                    }
                }
                unset($o);
                if (is_object($m)) { $m->outcomes = $outcomes; }
                else { $m['outcomes'] = $outcomes; }
            }
            unset($m);
            if (is_object($bm)) { $bm->markets = $markets; }
            else { $bm['markets'] = $markets; }
        }
        unset($bm);

        // 2. If multiple bookmakers exist, merge them into bookmakers[0] to fill missing data
        if (count($bookmakers) > 1) {
            $primary = $bookmakers[0];
            $allMarketKeys = [];
            foreach ($bookmakers as $bm) {
                $markets = is_object($bm) ? ($bm->markets ?? []) : ($bm['markets'] ?? []);
                if (!is_array($markets)) continue;
                foreach ($markets as $m) {
                    $key = is_object($m) ? ($m->key ?? '') : ($m['key'] ?? '');
                    if ($key !== '' && !in_array($key, $allMarketKeys, true)) {
                        $allMarketKeys[] = $key;
                    }
                }
            }

            $primaryMarkets = is_object($primary) ? ($primary->markets ?? []) : ($primary['markets'] ?? []);
            $primaryMarketMap = [];
            if (is_array($primaryMarkets)) {
                foreach ($primaryMarkets as $m) {
                    $key = is_object($m) ? ($m->key ?? '') : ($m['key'] ?? '');
                    if ($key !== '') {
                        $primaryMarketMap[$key] = $m;
                    }
                }
            }

            foreach ($allMarketKeys as $mKey) {
                if (!isset($primaryMarketMap[$mKey])) {
                    // Find first subsequent bookmaker that provides this market
                    for ($i = 1; $i < count($bookmakers); $i++) {
                        $other = $bookmakers[$i];
                        $otherMarkets = is_object($other) ? ($other->markets ?? []) : ($other['markets'] ?? []);
                        if (!is_array($otherMarkets)) continue;
                        foreach ($otherMarkets as $om) {
                            $omKey = is_object($om) ? ($om->key ?? '') : ($om['key'] ?? '');
                            if ($omKey === $mKey) {
                                $cloned = is_object($om) ? clone $om : $om;
                                if (is_object($cloned) && isset($cloned->outcomes) && is_array($cloned->outcomes)) {
                                    $cloned->outcomes = array_map(function($co) {
                                        $obj = is_object($co) ? clone $co : $co;
                                        if (is_object($obj) && isset($obj->point)) {
                                            $obj->point = sports_api_positive_point($obj->point);
                                        } elseif (is_array($obj) && isset($obj['point'])) {
                                            $obj['point'] = sports_api_positive_point($obj['point']);
                                        }
                                        return $obj;
                                    }, $cloned->outcomes);
                                }
                                $primaryMarketMap[$mKey] = $cloned;
                                break 2;
                            }
                        }
                    }
                } else {
                    // Market exists in primary. Merge missing outcomes or missing prices/points from other bookmakers
                    $pm = $primaryMarketMap[$mKey];
                    $pmOutcomes = is_object($pm) ? ($pm->outcomes ?? []) : ($pm['outcomes'] ?? []);
                    if (!is_array($pmOutcomes)) $pmOutcomes = [];
                    $outcomeIndexMap = [];
                    foreach ($pmOutcomes as $idx => $o) {
                        $name = is_object($o) ? ($o->name ?? '') : ($o['name'] ?? '');
                        $nameKey = strtolower(trim((string)$name));
                        if ($nameKey !== '') {
                            $outcomeIndexMap[$nameKey] = $idx;
                        }
                    }

                    for ($i = 1; $i < count($bookmakers); $i++) {
                        $other = $bookmakers[$i];
                        $otherMarkets = is_object($other) ? ($other->markets ?? []) : ($other['markets'] ?? []);
                        if (!is_array($otherMarkets)) continue;
                        foreach ($otherMarkets as $om) {
                            $omKey = is_object($om) ? ($om->key ?? '') : ($om['key'] ?? '');
                            if ($omKey === $mKey) {
                                $otherOutcomes = is_object($om) ? ($om->outcomes ?? []) : ($om['outcomes'] ?? []);
                                if (!is_array($otherOutcomes)) continue;
                                foreach ($otherOutcomes as $oo) {
                                    $name = is_object($oo) ? ($oo->name ?? '') : ($oo['name'] ?? '');
                                    $nameKey = strtolower(trim((string)$name));
                                    if ($nameKey === '') continue;
                                    if (!isset($outcomeIndexMap[$nameKey])) {
                                        // Missing outcome! Append it
                                        $clonedOutcome = is_object($oo) ? clone $oo : $oo;
                                        if (is_object($clonedOutcome) && isset($clonedOutcome->point)) {
                                            $clonedOutcome->point = sports_api_positive_point($clonedOutcome->point);
                                        } elseif (is_array($clonedOutcome) && isset($clonedOutcome['point'])) {
                                            $clonedOutcome['point'] = sports_api_positive_point($clonedOutcome['point']);
                                        }
                                        $pmOutcomes[] = $clonedOutcome;
                                        $outcomeIndexMap[$nameKey] = count($pmOutcomes) - 1;
                                    } else {
                                        // Existing outcome. Fill missing price or point
                                        $tIdx = $outcomeIndexMap[$nameKey];
                                        $cur = $pmOutcomes[$tIdx];
                                        $otherPrice = is_object($oo) ? ($oo->price ?? null) : ($oo['price'] ?? null);
                                        $otherPoint = is_object($oo) ? ($oo->point ?? null) : ($oo['point'] ?? null);

                                        if (is_object($cur)) {
                                            if ((!isset($cur->price) || $cur->price === null || $cur->price === '' || $cur->price === 0) && $otherPrice) {
                                                $cur->price = $otherPrice;
                                            }
                                            if ((!isset($cur->point) || $cur->point === null || $cur->point === '') && $otherPoint !== null) {
                                                $cur->point = sports_api_positive_point($otherPoint);
                                            }
                                        } elseif (is_array($cur)) {
                                            if ((!isset($cur['price']) || $cur['price'] === null || $cur['price'] === '' || $cur['price'] === 0) && $otherPrice) {
                                                $cur['price'] = $otherPrice;
                                            }
                                            if ((!isset($cur['point']) || $cur['point'] === null || $cur['point'] === '') && $otherPoint !== null) {
                                                $cur['point'] = sports_api_positive_point($otherPoint);
                                            }
                                        }
                                        $pmOutcomes[$tIdx] = $cur;
                                    }
                                }
                            }
                        }
                    }

                    if (is_object($pm)) {
                        $pm->outcomes = array_values($pmOutcomes);
                    } else {
                        $pm['outcomes'] = array_values($pmOutcomes);
                    }
                    $primaryMarketMap[$mKey] = $pm;
                }
            }

            // Standard market sorting: h2h (1), spreads (2), totals (3), outrights (4)
            $priority = ['h2h' => 1, 'spreads' => 2, 'totals' => 3, 'outrights' => 4];
            uksort($primaryMarketMap, function($a, $b) use ($priority) {
                $pA = $priority[$a] ?? 99;
                $pB = $priority[$b] ?? 99;
                return $pA <=> $pB;
            });

            $mergedMarkets = array_values($primaryMarketMap);
            if (is_object($primary)) {
                $primary->markets = $mergedMarkets;
                foreach ($primaryMarketMap as $k => $m) {
                    if (is_string($k) && preg_match('/^[a-z0-9_]+$/i', $k)) {
                        $primary->$k = $m;
                    }
                }
            } else {
                $primary['markets'] = $mergedMarkets;
                foreach ($primaryMarketMap as $k => $m) {
                    if (is_string($k) && preg_match('/^[a-z0-9_]+$/i', $k)) {
                        $primary[$k] = $m;
                    }
                }
            }
            $bookmakers[0] = $primary;
        }

        if (is_object($event)) {
            $event->bookmakers = $bookmakers;
        } else {
            $event['bookmakers'] = $bookmakers;
        }
    }
    unset($event);
}

function sports_api_project(mixed $data,array $layout,int $columns,int $limit=0,string $currentSport=''): array
{
    $budget=20000;$truncated=false;$defaultImage=site_default_image();
    $resolve=static function(string $path,array $contexts,bool $loop) use ($currentSport):mixed{
        if ($path==='') return null;
        if ($path==='$.sport_category' || $path==='$.sport.category' || $path==='$.category') {
            foreach (array_reverse($contexts) as $ctx) {
                if (is_object($ctx['value']) && isset($ctx['value']->sport_category) && (string)$ctx['value']->sport_category !== '') {
                    return (string)$ctx['value']->sport_category;
                }
                if (is_array($ctx['value']) && isset($ctx['value']['sport_category']) && (string)$ctx['value']['sport_category'] !== '') {
                    return (string)$ctx['value']['sport_category'];
                }
            }
            $cat = sports_api_resolve_category_name($currentSport);
            if ($cat !== '') return $cat;
        }
        $tokens=sports_api_path($path);if($loop&&$tokens&&end($tokens)===null)array_pop($tokens);
        foreach(array_reverse($contexts) as $ctx){
            $prefix=$ctx['tokens'];
            $prefixCount=count($prefix);
            $matched=false;
            $remainingTokens=[];
            if(count($tokens)>=$prefixCount && sports_api_tokens_match(array_slice($tokens,0,$prefixCount),$prefix)){
                $matched=true;
                $remainingTokens=array_slice($tokens,$prefixCount);
            } elseif(!empty($ctx['tokens'])){
                // Relative field within loop context (e.g. $.name, $.price, $.point)
                $testVal=$ctx['value'];
                $canResolve=true;
                foreach($tokens as $k){
                    if($k===null){$canResolve=false;break;}
                    if(is_array($testVal)&&(is_string($k)||is_int($k))&&array_key_exists($k,$testVal)){$testVal=$testVal[$k];}
                    elseif(is_object($testVal)&&(is_string($k)||is_int($k))&&property_exists($testVal,(string)$k)){$testVal=$testVal->{(string)$k};}
                    else{$canResolve=false;break;}
                }
                if($canResolve){
                    $matched=true;
                    $remainingTokens=$tokens;
                }
            }
            if(!$matched)continue;

            $value=$ctx['value'];
            foreach($remainingTokens as $key){
                if($key===null){
                    if(is_array($value)&&isset($value[0])){$value=$value[0];continue;}
                    return null;
                }
                if(is_array($value)){
                    if(is_int($key) && isset($value[$key])) { $value = $value[$key]; }
                    elseif(is_numeric($key) && isset($value[(int)$key])) { $value = $value[(int)$key]; }
                    elseif(is_string($key) && isset($value[$key])) { $value = $value[$key]; }
                    else return null;
                } elseif(is_object($value)){
                    if(is_int($key) && property_exists($value, (string)$key)) { $value=$value->{(string)$key}; }
                    elseif(is_string($key) && property_exists($value, (string)$key)) { $value=$value->{(string)$key}; }
                    else return null;
                } else return null;
            }
            return $value;
        }
        return null;
    };
    $interpolate=static function(string $template,array $contexts) use ($resolve): string {
        if ($template==='' || !str_contains($template,'{')) return $template;
        return (string)preg_replace_callback('/\{([^}]+)\}/', static function($m) use ($contexts,$resolve) {
            $token=trim($m[1]);
            if ($token==='') return '';
            if (str_starts_with($token,'$')) {
                try {
                    $v = $resolve($token, $contexts, false);
                    if (is_scalar($v)) return rawurlencode((string)$v);
                } catch(Throwable) {}
            } else {
                try {
                    $v = $resolve('$.'.$token, $contexts, false);
                    if (is_scalar($v)) return rawurlencode((string)$v);
                } catch(Throwable) {}
                foreach (array_reverse($contexts) as $ctx) {
                    if (is_object($ctx['value']) && property_exists($ctx['value'], $token) && is_scalar($ctx['value']->$token)) {
                        return rawurlencode((string)$ctx['value']->$token);
                    }
                }
            }
            return '';
        }, $template);
    };
    $walk=static function(array $nodes,array $contexts) use (&$walk,$resolve,$interpolate,&$budget,&$truncated,$currentSport,$defaultImage):array{
        $out=[];
        foreach($nodes as $node){
            if(!($node['visible']??true))continue;
            if(--$budget<0){$truncated=true;break;}
            $nodePath = (string)($node['path'] ?? '');
            $value = $nodePath!=='' ? $resolve($nodePath,$contexts,($node['type']??'')==='loop') : null;
            if ($nodePath !== '' && (str_ends_with($nodePath, '.point') || $nodePath === 'point' || $nodePath === '$.point')) {
                $value = sports_api_positive_point($value);
            }
            if(($node['type']??'')==='field'){
                if(is_object($value)||is_array($value)||$value===null||$value===''){
                    $value=($node['fallback']??'')!==''?$node['fallback']:((($node['section']??'')==='title')?($node['label']??'Sports Odds'):'');
                    if($value==='')continue;
                }
                $fieldItem=['type'=>'field','path'=>$nodePath,'label'=>$node['label']??'','role'=>$node['role']??'data','format'=>$node['format']??'text','value'=>$value,'section'=>$node['section']??'loop'];
                if(isset($node['url']) && $node['url']!=='') {
                    $fieldItem['url']=$interpolate($node['url'],$contexts);
                }
                if(isset($node['width']) && $node['width']!=='') {
                    $fieldItem['width']=$node['width'];
                }
                if(isset($node['align']) && $node['align']!=='') {
                    $fieldItem['align']=$node['align'];
                }
                $out[]=$fieldItem;
            }elseif(($node['type']??'')==='container'){
                $childList = $node['children'] ?? $node['blocks'] ?? [];
                $containerItem=['type'=>'container','id'=>$node['id']??'','label'=>$node['label']??'','direction'=>$node['direction']??'row','section'=>$node['section']??'loop','blocks'=>$walk($childList,$contexts)];
                if(isset($node['width']) && $node['width']!=='') {
                    $containerItem['width']=$node['width'];
                }
                if(isset($node['align']) && $node['align']!=='') {
                    $containerItem['align']=$node['align'];
                }
                $out[]=$containerItem;
            }elseif($node['type']==='loop'){
                if(!is_array($value)||!$value)continue;$items=[];
                if(count($value)>100)$truncated=true;
                foreach(array_slice($value,0,100) as $item){if($budget<=0){$truncated=true;break;}$items[]=['blocks'=>$walk($node['children'],[...$contexts,['tokens'=>sports_api_path($node['path']),'value'=>$item]])];}
                $loopItem=['type'=>'loop','path'=>$node['path'],'label'=>$node['label'],'columns'=>$node['columns'],'direction'=>$node['direction']??'grid','section'=>$node['section']??'loop','items'=>$items];
                if(isset($node['width']) && $node['width']!=='') {
                    $loopItem['width']=$node['width'];
                }
                if(isset($node['align']) && $node['align']!=='') {
                    $loopItem['align']=$node['align'];
                }
                $out[]=$loopItem;
            }elseif($node['type']==='button'||$node['type']==='link'){
                $btnVal = $value;
                if ($nodePath !== '' && (str_ends_with($nodePath, '.point') || $nodePath === 'point' || $nodePath === '$.point')) {
                    $btnVal = sports_api_positive_point($btnVal);
                }
                $btnLabel = is_scalar($btnVal) && (string)$btnVal!=='' ? (string)$btnVal : $node['label'];
                $btnUrl = $interpolate($node['url']??'', $contexts);
                $btnItem=['type'=>$node['type'],'path'=>$node['path'],'label'=>$btnLabel,'url'=>$btnUrl,'style'=>$node['style']??'primary','target'=>$node['target']??'_blank','section'=>$node['section']??'loop'];
                if(isset($node['width']) && $node['width']!=='') {
                    $btnItem['width']=$node['width'];
                }
                if(isset($node['btn_width']) && $node['btn_width']!=='') {
                    $btnItem['btn_width']=$node['btn_width'];
                }
                if(isset($node['align']) && $node['align']!=='') {
                    $btnItem['align']=$node['align'];
                }
                $out[]=$btnItem;
            }elseif($node['type']==='text'){
                $textVal = is_scalar($value) && (string)$value!=='' ? (string)$value : ($node['text'] ?? $node['label']);
                $textItem=['type'=>'text','path'=>$node['path'],'label'=>$node['label'],'value'=>$textVal,'role'=>$node['role']??'description','section'=>$node['section']??'loop'];
                if(isset($node['width']) && $node['width']!=='') {
                    $textItem['width']=$node['width'];
                }
                if(isset($node['align']) && $node['align']!=='') {
                    $textItem['align']=$node['align'];
                }
                $out[]=$textItem;
            }elseif($node['type']==='image'){
                $sourceMode = (string)($node['image_source'] ?? $node['source_mode'] ?? 'custom');
                $imgUrl = '';
                $sportLookup = $currentSport ?: (string)($resolve('$.sport_key', $contexts, false) ?: ($resolve('$.sport.key', $contexts, false) ?: ''));

                if ($sourceMode === 'sport' || $sourceMode === 'sport_image') {
                    $imgUrl = sports_registry_resolve_sport_image($sportLookup, false);
                } elseif ($sourceMode === 'home_team' || $sourceMode === 'home_team_image') {
                    $homeName = (string)((is_scalar($value) ? $value : '') ?: $resolve('$.home_team', $contexts, false) ?: ($resolve('$.home.name', $contexts, false) ?: ''));
                    if ($homeName !== '') {
                        $matched = sports_registry_match_team($sportLookup, $homeName);
                        $imgUrl = $matched['image_path'] ?? '';
                    }
                } elseif ($sourceMode === 'away_team' || $sourceMode === 'away_team_image') {
                    $awayName = (string)((is_scalar($value) ? $value : '') ?: $resolve('$.away_team', $contexts, false) ?: ($resolve('$.away.name', $contexts, false) ?: ''));
                    if ($awayName !== '') {
                        $matched = sports_registry_match_team($sportLookup, $awayName);
                        $imgUrl = $matched['image_path'] ?? '';
                    }
                } elseif ($sourceMode === 'team' || $sourceMode === 'team_image') {
                    $teamIdentifier = is_scalar($value) && (string)$value !== '' ? (string)$value : (string)$resolve($nodePath ?: '$.name', $contexts, false);
                    if ($teamIdentifier !== '') {
                        $matched = sports_registry_match_team($sportLookup, $teamIdentifier);
                        $imgUrl = $matched['image_path'] ?? '';
                    }
                }

                if ($imgUrl === '') {
                    // Registry values are team names, not URLs. Only custom images use the field value.
                    $imgUrl = $sourceMode === 'custom' && is_scalar($value) && (string)$value!=='' ? (string)$value : $interpolate($node['url']??'', $contexts);
                }

                $fallbackImage = $defaultImage;
                if ($imgUrl === '') $imgUrl = $fallbackImage;
                $rawImgW = isset($node['image_width']) && (string)$node['image_width'] !== '' ? trim((string)$node['image_width']) : '';
                if ($rawImgW === '' && isset($node['width']) && preg_match('/^\d+(?:px|rem|em)$/i', trim((string)$node['width']))) {
                    $rawImgW = trim((string)$node['width']);
                }
                if ($rawImgW === '') {
                    $rawImgW = in_array($sourceMode, ['sport', 'sport_image'], true) ? '48px' : '36px';
                }
                $imgWidth = $rawImgW;
                $imgHeight = isset($node['image_height']) && (string)$node['image_height'] !== '' ? (string)$node['image_height'] : (isset($node['height']) ? (string)$node['height'] : '');
                $imgItem=['type'=>'image','path'=>$node['path'],'label'=>$node['label'],'url'=>$imgUrl,'fallback_url'=>$fallbackImage,'alt'=>$node['alt']??$node['label']??'','width'=>$node['width']??'','height'=>$node['height']??'','image_width'=>$imgWidth,'image_height'=>$imgHeight,'section'=>$node['section']??'loop','image_source'=>$sourceMode];
                if(isset($node['align']) && $node['align']!=='') {
                    $imgItem['align']=$node['align'];
                }
                $out[]=$imgItem;
            }
        }return $out;
    };
    $records=is_array($data)?$data:[$data];if(count($records)>200)$truncated=true;
    sports_api_merge_bookmakers($records);
    if($limit>0)$records=array_slice($records,0,$limit);
    $titleNodes=[]; $loopNodes=[];
    foreach($layout as $node){
        if(($node['section']??'loop')==='title')$titleNodes[]=$node;
        else $loopNodes[]=$node;
    }
    $projectedTitle=[];
    if($titleNodes){
        $leadRecord = $records[0] ?? (is_object($data)?$data:(object)[]);
        if(is_object($leadRecord) && empty($leadRecord->sport_category)){
            $leadRecord->sport_category = sports_api_resolve_category_name($currentSport);
        }
        $projectedTitle = $walk($titleNodes,[['tokens'=>[],'value'=>$leadRecord]]);
    }
    $effectiveLoop = $loopNodes ?: $layout;
    $out=[];
    foreach(array_slice($records,0,200) as $record){if($budget<=0){$truncated=true;break;}$out[]=['blocks'=>$walk($effectiveLoop,[['tokens'=>[],'value'=>$record]])];}
    return ['records'=>$out,'title_blocks'=>$projectedTitle,'columns'=>$columns,'limit'=>$limit,'truncated'=>$truncated];
}

function sports_api_output(int $id,string $sport='',?callable $transport=null,?int $columnsOverride=null): array
{
    $config=sports_api_get($id);
    if ($columnsOverride !== null && in_array($columnsOverride, [1, 2, 3, 4], true)) {
        $config['columns_per_row'] = $columnsOverride;
        $applyCols = static function(array &$nodes) use (&$applyCols, $columnsOverride): void {
            foreach ($nodes as &$node) {
                if (($node['type'] ?? '') === 'loop') {
                    $node['columns'] = $columnsOverride;
                }
                if (!empty($node['children']) && is_array($node['children'])) {
                    $applyCols($node['children']);
                }
            }
        };
        $applyCols($config['layout_config']);
    }
    $result=['ok'=>true,'enabled'=>$config['enabled'],'title'=>$config['title'],'columns'=>$config['columns_per_row'],'limit'=>(int)($config['item_limit']??0),'records'=>[],'title_blocks'=>[],'sports'=>[],'sport'=>'','updatedAt'=>null,'truncated'=>false];
    if(!$config['enabled'])return $result;
    // Existing provider-wide switch continues to protect migrated Odds API configurations.
    if($config['catalog_url']!=='' && !(int)odds_api_pdo()->query('SELECT enabled FROM settings WHERE id=1')->fetchColumn()){$result['enabled']=false;return $result;}
    if($sport==='' && !empty($config['sport'])){
        $sport=$config['sport'];
    }
    $source=sports_api_source($config,$sport,$transport);$root=sports_api_root($source['data'],$config['root_path']);
    $projection=sports_api_project($root,$config['layout_config'],$config['columns_per_row'],(int)($config['item_limit']??0),$sport);
    return array_replace($result,$projection,['sports'=>$source['sports'],'sport'=>$source['sport'],'updatedAt'=>$source['updatedAt']]);
}

function sports_api_available_sports(?callable $transport=null, bool $onlyActive=true): array
{
    try {
        $tournaments = tournament_get_all(null, true, true, $onlyActive);
        if (!empty($tournaments)) {
            $list = [];
            foreach ($tournaments as $t) {
                $list[] = [
                    'key' => (string)$t['odds_sport_key'],
                    'title' => (string)$t['title'],
                    'group' => (string)($t['category_name'] ?: 'Other'),
                    'description' => (string)($t['description'] ?? ''),
                    'active' => (bool)$t['active'],
                    'has_outrights' => (bool)$t['has_outrights'],
                ];
            }
            return $list;
        }
    } catch (Throwable) {}

    $fallback = [
        ['key'=>'basketball_nba','title'=>'NBA','group'=>'Basketball','description'=>'US Basketball','active'=>true,'has_outrights'=>false],
        ['key'=>'americanfootball_nfl','title'=>'NFL','group'=>'American Football','description'=>'US Football','active'=>true,'has_outrights'=>false],
        ['key'=>'americanfootball_ncaaf','title'=>'NCAAF','group'=>'American Football','description'=>'US College Football','active'=>true,'has_outrights'=>false],
        ['key'=>'baseball_mlb','title'=>'MLB','group'=>'Baseball','description'=>'Major League Baseball','active'=>true,'has_outrights'=>false],
        ['key'=>'icehockey_nhl','title'=>'NHL','group'=>'Ice Hockey','description'=>'National Hockey League','active'=>true,'has_outrights'=>false],
        ['key'=>'soccer_epl','title'=>'EPL','group'=>'Soccer','description'=>'English Premier League','active'=>true,'has_outrights'=>false],
        ['key'=>'soccer_uefa_champs_league','title'=>'UEFA Champions League','group'=>'Soccer','description'=>'European Champions League','active'=>true,'has_outrights'=>false],
        ['key'=>'soccer_spain_la_liga','title'=>'La Liga - Spain','group'=>'Soccer','description'=>'Spanish Soccer','active'=>true,'has_outrights'=>false],
        ['key'=>'soccer_germany_bundesliga','title'=>'Bundesliga - Germany','group'=>'Soccer','description'=>'German Soccer','active'=>true,'has_outrights'=>false],
        ['key'=>'soccer_italy_serie_a','title'=>'Serie A - Italy','group'=>'Soccer','description'=>'Italian Soccer','active'=>true,'has_outrights'=>false],
        ['key'=>'soccer_usa_mls','title'=>'MLS','group'=>'Soccer','description'=>'Major League Soccer','active'=>true,'has_outrights'=>false],
        ['key'=>'americanfootball_cfl','title'=>'CFL','group'=>'American Football','description'=>'Canadian Football League','active'=>true,'has_outrights'=>false],
    ];

    try {
        $settings = odds_api_pdo()->query('SELECT api_key FROM settings WHERE id=1')->fetch();
        $apiKey = is_array($settings) ? trim((string)($settings['api_key'] ?? '')) : '';
        if ($apiKey !== '') {
            $url = 'https://api.the-odds-api.com/v4/sports/?apiKey=' . rawurlencode($apiKey);
            $res = sports_api_fetch($url, $transport);
            if (is_array($res['data'] ?? null)) {
                $list = [];
                foreach ($res['data'] as $item) {
                    if (is_object($item) && !empty($item->active) && is_string($item->key ?? null) && preg_match('/^[a-z0-9_]{1,100}$/D', $item->key)) {
                        $list[] = [
                            'key' => $item->key,
                            'title' => (string)($item->title ?? $item->key),
                            'group' => (string)($item->group ?? 'Other'),
                            'description' => (string)($item->description ?? ''),
                            'active' => true,
                            'has_outrights' => !empty($item->has_outrights),
                        ];
                    }
                }
                if ($list) {
                    usort($list, static function(array $a, array $b): int {
                        $g = strcmp($a['group'], $b['group']);
                        return $g !== 0 ? $g : strcmp($a['title'], $b['title']);
                    });
                    return $list;
                }
            }
        }
    } catch (Throwable) {}
    return $fallback;
}

/** Legacy SQLite rows may use UTC datetime strings instead of Unix seconds. */
function sports_api_layout_timestamp(mixed $value): int
{
    if (is_int($value) || (is_string($value) && ctype_digit($value))) return (int)$value;
    if (!is_string($value) || trim($value) === '') return 0;
    try { return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->getTimestamp(); }
    catch (Exception) { return 0; }
}

/**
 * Retrieve all saved iframe layouts, optionally filtered by sport key or enabled status.
 */
function sports_api_layouts_get_all(?string $sport = null, bool $onlyEnabled = false): array
{
    $pdo = sports_api_pdo();
    $sql = 'SELECT id, title, sport, enabled, columns_per_row, item_limit, api_url, markets, bookmakers, created_at, updated_at FROM iframes';
    $where = [];
    $params = [];
    if ($sport !== null && $sport !== '') {
        $where[] = 'sport = ?';
        $params[] = $sport;
    }
    if ($onlyEnabled) {
        $where[] = 'enabled = 1';
    }
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY id ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $layouts = [];
    foreach ($rows as $row) {
        $id = (int)$row['id'];
        $title = (string)$row['title'];
        $layouts[] = [
            'id' => $id,
            'title' => $title,
            'sport' => (string)($row['sport'] ?? ''),
            'enabled' => (int)$row['enabled'],
            'columns_per_row' => (int)$row['columns_per_row'],
            'item_limit' => (int)($row['item_limit'] ?? 0),
            'markets' => (string)($row['markets'] ?? ''),
            'bookmakers' => (string)($row['bookmakers'] ?? ''),
            'embed_url' => '/odds-api/?iframe=' . $id,
            'embed_code' => '<iframe src="/odds-api/?iframe=' . $id . '" title="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '" width="100%" height="650" style="border:0" loading="lazy"></iframe>',
            'api_url' => '/api/sports-layout.php?id=' . $id,
            'created_at' => sports_api_layout_timestamp($row['created_at'] ?? 0),
            'updated_at' => sports_api_layout_timestamp($row['updated_at'] ?? 0),
        ];
    }
    return $layouts;
}

/**
 * Build the full sports tree:
 * Sports Categories -> Tournaments -> Iframe Layouts,
 * plus a flat list of all generated iframe layouts.
 */
function sports_api_tree(array $options = []): array
{
    $onlyEnabled = !isset($options['only_enabled']) || !empty($options['only_enabled']);
    if (!empty($options['include_disabled']) || !empty($options['all'])) {
        $onlyEnabled = false;
    }
    $onlyActive = !empty($options['only_active']) || !empty($options['active']);
    $categoryFilter = $options['category'] ?? ($options['category_id'] ?? ($options['category_slug'] ?? null));
    $sportFilter = $options['sport'] ?? ($options['sport_key'] ?? null);
    $includeEmptyCategories = !empty($options['include_empty_categories']);
    $onlyEnabledLayouts = !empty($options['only_enabled_layouts']);

    // 1. Fetch layouts
    $allLayouts = sports_api_layouts_get_all(null, $onlyEnabledLayouts);
    $layoutsBySport = [];
    foreach ($allLayouts as $layout) {
        $sportKey = $layout['sport'];
        if ($sportKey !== '') {
            $layoutsBySport[$sportKey][] = $layout;
        }
    }

    // 2. Fetch categories
    $allCategories = sports_category_get_all($onlyEnabled);
    if ($categoryFilter !== null && $categoryFilter !== '') {
        $categoryFilterStr = (string)$categoryFilter;
        $allCategories = array_values(array_filter($allCategories, static function(array $cat) use ($categoryFilterStr): bool {
            if (is_numeric($categoryFilterStr)) {
                return (int)$cat['id'] === (int)$categoryFilterStr;
            }
            return strcasecmp((string)$cat['slug'], $categoryFilterStr) === 0;
        }));
    }

    $allowedCategoryIds = array_fill_keys(array_column($allCategories, 'id'), true);

    // 3. Fetch tournaments
    $tournamentsList = tournament_get_all(null, $onlyEnabled, $onlyEnabled, $onlyActive);
    if ($sportFilter !== null && (string)$sportFilter !== '') {
        $sportFilterStr = (string)$sportFilter;
        $tournamentsList = array_values(array_filter($tournamentsList, static function(array $t) use ($sportFilterStr): bool {
            return strcasecmp((string)$t['odds_sport_key'], $sportFilterStr) === 0;
        }));
    }

    // Index tournaments by category and build lookup maps
    $tournamentsByCat = [];
    $tournamentBySportKey = [];
    foreach ($tournamentsList as $t) {
        $catId = (int)$t['sports_category_id'];
        if (!isset($allowedCategoryIds[$catId])) continue;
        $sportKey = (string)$t['odds_sport_key'];
        $tournLayouts = $layoutsBySport[$sportKey] ?? [];
        if (!$tournLayouts) continue;

        $tournamentItem = [
            'id' => (int)$t['id'],
            'sports_category_id' => $catId,
            'odds_sport_key' => $sportKey,
            'title' => (string)$t['title'],
            'source_title' => (string)($t['source_title'] ?? ''),
            'custom_title' => (string)($t['custom_title'] ?? ''),
            'description' => (string)($t['description'] ?? ''),
            'active' => (int)$t['active'],
            'has_outrights' => (int)$t['has_outrights'],
            'enabled' => (int)$t['enabled'],
            'sort_order' => (int)($t['sort_order'] ?? 0),
            'enrich_api_url' => (string)($t['enrich_api_url'] ?? ''),
            'layouts' => $tournLayouts,
            'layouts_count' => count($tournLayouts),
        ];

        $tournamentsByCat[$catId][] = $tournamentItem;
        $tournamentBySportKey[$sportKey] = $tournamentItem;
    }

    // Category lookup map by ID
    $categoryById = [];
    foreach ($allCategories as $cat) {
        $categoryById[(int)$cat['id']] = $cat;
    }

    // Use the same eligible tournament set for flat and nested responses.
    // Never reintroduce a disabled/filtered tournament via a fallback lookup.
    $allLayouts = array_values(array_filter($allLayouts, static fn(array $layout): bool => isset($tournamentBySportKey[$layout['sport']])));
    foreach ($allLayouts as &$layout) {
        $t = $tournamentBySportKey[$layout['sport']];
        $catId = (int)$t['sports_category_id'];
        $layout['tournament_id'] = $t['id'];
        $layout['tournament_title'] = $t['title'];
        $layout['sports_category_id'] = $catId;
        $layout['sports_category_name'] = (string)$categoryById[$catId]['name'];
        $layout['sports_category_slug'] = (string)$categoryById[$catId]['slug'];
    }
    unset($layout);

    // 5. Build structured categories list
    $categoriesOut = [];
    foreach ($allCategories as $cat) {
        $catId = (int)$cat['id'];
        $catTournaments = $tournamentsByCat[$catId] ?? [];
        if (empty($catTournaments) && !$includeEmptyCategories && ($onlyEnabled || $onlyActive || $sportFilter !== null)) {
            continue;
        }

        $imagePath = (string)($cat['image_path'] ?? '');
        $categoriesOut[] = [
            'id' => $catId,
            'name' => (string)$cat['name'],
            'slug' => (string)$cat['slug'],
            'image_key' => (string)$cat['image_key'],
            'image_path' => $imagePath,
            'image_url' => $imagePath,
            'enabled' => (int)$cat['enabled'],
            'tournaments_count' => count($catTournaments),
            'tournaments' => $catTournaments,
        ];
    }

    $totalTournaments = array_sum(array_column($categoriesOut, 'tournaments_count'));

    return [
        'ok' => true,
        'categories' => $categoriesOut,
        'data' => $categoriesOut,
        'layouts' => $allLayouts,
        'total_categories' => count($categoriesOut),
        'total_tournaments' => $totalTournaments,
        'total_layouts' => count($allLayouts),
    ];
}

/** Portable saved layouts; excludes provider credentials and runtime/cache data. */
function sports_api_export_layouts(array $ids): array
{
    $layouts = [];
    foreach ($ids as $id) {
        $row = sports_api_get((int)$id);
        $item = array_intersect_key($row, array_flip(['title','api_url','sport','root_path','columns_per_row','item_limit','layout_config','markets','bookmakers']));
        // Export URL templates without embedding query-string API credentials.
        $item['api_url'] = preg_replace('/([?&](?:api_?key|token|access_token|secret)=)[^&]*/i', '$1{apiKey}', $item['api_url']);
        $layouts[] = $item;
    }
    return ['format'=>'sports-layouts','version'=>1,'layouts'=>$layouts];
}

function sports_api_import_layouts(string $json): array
{
    if (strlen($json)>5*1024*1024) throw new InvalidArgumentException('Layout file must be 5 MB or smaller.');
    try { $data=json_decode($json,true,64,JSON_THROW_ON_ERROR); }
    catch (JsonException) { throw new InvalidArgumentException('Invalid layout JSON file.'); }
    if (!is_array($data) || ($data['format']??null)!=='sports-layouts' || ($data['version']??null)!==1
        || !is_array($data['layouts']??null) || !array_is_list($data['layouts']) || !$data['layouts'] || count($data['layouts'])>1000) {
        throw new InvalidArgumentException('Choose a version 1 sports-layouts export containing 1–1000 layouts.');
    }
    $inputs=[];
    foreach ($data['layouts'] as $row) {
        if (!is_array($row)) throw new InvalidArgumentException('Invalid layout entry.');
        $input=array_intersect_key($row,array_flip(['api_url','sport','root_path','columns_per_row','item_limit','layout_config','markets','bookmakers']));
        $input['name']=$row['title']??'';
        $input['id']=0;
        $input['enabled']='0';
        sports_api_configure($input); // Validate the entire file before inserting anything.
        $inputs[]=$input;
    }
    $pdo=sports_api_pdo();$pdo->beginTransaction();$ids=[];
    try {
        foreach ($inputs as $input) $ids[]=sports_api_save($input);
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack();throw $e; }
    return $ids;
}
