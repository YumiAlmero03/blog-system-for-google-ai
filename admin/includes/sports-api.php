<?php
declare(strict_types=1);
require_once __DIR__ . '/../odds-api/includes/service.php';

/** Saved API layouts share the existing sports database and embed IDs. */
function sports_api_pdo(): PDO
{
    static $ready = false;
    $pdo = odds_api_iframe_pdo();
    if ($ready) return $pdo;
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $columns = array_column($pdo->query('PRAGMA table_info(iframes)')->fetchAll(), 'name');
        foreach (['api_url'=>"TEXT NOT NULL DEFAULT ''", 'enabled'=>'INTEGER NOT NULL DEFAULT 0', 'root_path'=>"TEXT NOT NULL DEFAULT '$'", 'columns_per_row'=>'INTEGER NOT NULL DEFAULT 1', 'layout_config'=>"TEXT NOT NULL DEFAULT ''", 'catalog_url'=>"TEXT NOT NULL DEFAULT ''"] as $name=>$definition) {
            if (!in_array($name, $columns, true)) $pdo->exec('ALTER TABLE iframes ADD COLUMN ' . $name . ' ' . $definition);
        }
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
            $pdo->prepare('UPDATE iframes SET api_url=?,catalog_url=?,enabled=?,layout_config=? WHERE id=?')->execute([
                'https://api.the-odds-api.com/v4/sports/{sport}/odds/?apiKey={apiKey}&regions={regions}&markets={markets}&bookmakers={bookmakers}',
                'https://api.the-odds-api.com/v4/sports/?apiKey={apiKey}', (int)$global,
                json_encode($nodes, JSON_THROW_ON_ERROR),$row['id'],
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
    return $row;
}

function sports_api_path(string $path): array
{
    if (strlen($path)>1000 || !str_starts_with($path,'$')) throw new InvalidArgumentException('Paths must start with $.');
    $tokens=[]; $offset=1;
    while ($offset<strlen($path)) {
        if (!preg_match('/\G(?:\.([A-Za-z_][A-Za-z0-9_-]*)|\[("(?:[^"\\\\]|\\\\.)*")\]|(\[\]))/A', $path, $m, 0, $offset)) throw new InvalidArgumentException('Invalid field path.');
        $tokens[] = !empty($m[1]) ? $m[1] : (!empty($m[2]) ? json_decode($m[2], true, 8, JSON_THROW_ON_ERROR) : null);
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
        if (!isset($found[$name]) || $value!==null) $found[$name]=['path'=>$name,'kind'=>$kind,'value_type'=>get_debug_type($value)];
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
            if (!in_array($type,['field','loop'],true) || !is_string($path) || !is_string($label) || strlen($label)>200) throw new InvalidArgumentException('Invalid block settings.');
            $tokens=sports_api_path($path);
            // Fields can reference an ancestor's scalar values, but never cross an unconfigured loop.
            $arrays=[]; foreach ($tokens as $i=>$token) if ($token===null) $arrays[]=array_slice($tokens,0,$i+1);
            $expected = $type==='loop' ? array_slice($tokens,0,-1) : $tokens;
            if ($type==='loop' && (!$tokens || end($tokens)!==null)) throw new InvalidArgumentException('Loop paths must end with [].');
            $prefix=array_slice($tokens,0,count($scope));
            if ($type==='loop' && $prefix!==$scope) throw new InvalidArgumentException('Place nested loops inside their parent loop.');
            $check=$type==='loop' ? array_slice($arrays,0,-1) : $arrays;
            foreach ($check as $ancestor) if (array_slice($scope,0,count($ancestor))!==$ancestor) throw new InvalidArgumentException('Add the parent loop before this field.');
            if ($type==='loop' && count($tokens)<=count($scope)) throw new InvalidArgumentException('A loop must select a child array.');
            $item=['id'=>$id,'type'=>$type,'path'=>$path,'label'=>$label,'visible'=>($node['visible']??true)!==false];
            if ($type==='field') {
                $role=$node['role']??'data'; $format=$node['format']??'text'; $fallback=$node['fallback']??'';
                if (!in_array($role,['data','title','description'],true) || !in_array($format,['text','decimal','date','datetime'],true) || !is_string($fallback) || strlen($fallback)>200) throw new InvalidArgumentException('Invalid field format or fallback.');
                $item+=['role'=>$role,'format'=>$format,'fallback'=>$fallback];
            } else {
                $cols=filter_var($node['columns']??1,FILTER_VALIDATE_INT);
                if ($cols===false || $cols<1 || $cols>4) throw new InvalidArgumentException('Choose 1–4 columns.');
                $item+=['columns'=>$cols,'children'=>$walk($node['children']??[],$tokens,$depth+1)];
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
    $enabled=$input['enabled']??'0';if(!in_array($enabled,['0','1',0,1,false,true],true))throw new InvalidArgumentException('Invalid enabled status.');
    $layout=sports_api_validate_layout($input['layout_config']??[]);
    $catalog = $old && str_contains($url,'{sport}') ? $old['catalog_url'] : '';
    if(str_contains($url,'{sport}') && !$catalog)throw new InvalidArgumentException('Enter a complete API URL with a sport key.');
    return ['id'=>$id,'title'=>trim($name),'api_url'=>$url,'enabled'=>(bool)$enabled,'root_path'=>$root,'columns_per_row'=>$columns,'layout_config'=>$layout,'catalog_url'=>$catalog,'markets'=>$old['markets']??'h2h'];
}

function sports_api_save(array $input): int
{
    $row=sports_api_configure($input);$pdo=sports_api_pdo();
    $params=[$row['title'],$row['api_url'],(int)$row['enabled'],$row['root_path'],$row['columns_per_row'],json_encode($row['layout_config'],JSON_THROW_ON_ERROR),$row['catalog_url'],time()];
    if($row['id']){$params[]=$row['id'];$pdo->prepare('UPDATE iframes SET title=?,api_url=?,enabled=?,root_path=?,columns_per_row=?,layout_config=?,catalog_url=?,updated_at=? WHERE id=?')->execute($params);return $row['id'];}
    $params[]=time();$pdo->prepare("INSERT INTO iframes(title,api_url,enabled,root_path,columns_per_row,layout_config,catalog_url,updated_at,created_at,markets,blocks) VALUES(?,?,?,?,?,?,?,?,?,'h2h','{\"event_fields\":[],\"outcome_fields\":[]}')")->execute($params);return (int)$pdo->lastInsertId();
}

function sports_api_url(array $config,string $template,string $sport=''): string
{
    $settings=odds_api_pdo()->query('SELECT * FROM settings WHERE id=1')->fetch();
    if(str_contains($template,'{apiKey}') && $settings['api_key']==='')throw new RuntimeException('Provider key not configured.');
    return strtr($template,['{sport}'=>rawurlencode($sport),'{apiKey}'=>rawurlencode($settings['api_key']),'{regions}'=>rawurlencode($settings['regions']),'{markets}'=>rawurlencode($config['markets']),'{bookmakers}'=>rawurlencode($settings['bookmakers'])]);
}

function sports_api_source(array $config,string $sport='',?callable $transport=null): array
{
    $sports=[];
    if($config['catalog_url']!==''){
        $catalog=sports_api_fetch(sports_api_url($config,$config['catalog_url']),$transport)['data'];
        if(!is_array($catalog))throw new RuntimeException('Invalid sports catalogue.');
        foreach($catalog as $item)if(is_object($item)&&!empty($item->active)&&is_string($item->key??null)&&preg_match('/^[a-z0-9_]{1,100}$/D',$item->key))$sports[]=['key'=>$item->key,'title'=>(string)($item->title??$item->key),'group'=>(string)($item->group??''),'description'=>(string)($item->description??''),'active'=>true,'has_outrights'=>!empty($item->has_outrights)];
        if(!$sports)return ['data'=>[],'updatedAt'=>null,'sports'=>[],'sport'=>''];
        if($sport==='')$sport=$sports[0]['key'];
        if(!in_array($sport,array_column($sports,'key'),true))throw new InvalidArgumentException('Sport is unavailable.');
    }
    $result=sports_api_fetch(sports_api_url($config,$config['api_url'],$sport),$transport);
    if($sports&&is_array($result['data'])){$map=array_column($sports,null,'key');foreach($result['data'] as $event)if(is_object($event))$event->sport=isset($map[$event->sport_key??''])?(object)$map[$event->sport_key]:null;}
    return $result+['sports'=>$sports,'sport'=>$sport];
}

function sports_api_project(mixed $data,array $layout,int $columns): array
{
    $budget=20000;$truncated=false;
    $resolve=static function(string $path,array $contexts):mixed{
        $tokens=sports_api_path($path);if($tokens&&end($tokens)===null)array_pop($tokens);
        foreach(array_reverse($contexts) as $ctx){$prefix=$ctx['tokens'];if(array_slice($tokens,0,count($prefix))!==$prefix)continue;$value=$ctx['value'];foreach(array_slice($tokens,count($prefix)) as $key){if($key===null||!is_object($value)||!property_exists($value,$key))return null;$value=$value->$key;}return $value;}
        return null;
    };
    $walk=static function(array $nodes,array $contexts) use (&$walk,$resolve,&$budget,&$truncated):array{
        $out=[];
        foreach($nodes as $node){if(!$node['visible'])continue;if(--$budget<0){$truncated=true;break;}$value=$resolve($node['path'],$contexts);
            if($node['type']==='field'){
                if(is_object($value)||is_array($value)||$value===null||$value===''){$value=$node['fallback'];if($value==='')continue;}
                $out[]=['type'=>'field','path'=>$node['path'],'label'=>$node['label'],'role'=>$node['role'],'format'=>$node['format'],'value'=>$value];
            }else{
                if(!is_array($value)||!$value)continue;$items=[];
                if(count($value)>100)$truncated=true;
                foreach(array_slice($value,0,100) as $item){if($budget<=0){$truncated=true;break;}$items[]=['blocks'=>$walk($node['children'],[...$contexts,['tokens'=>sports_api_path($node['path']),'value'=>$item]])];}
                $out[]=['type'=>'loop','path'=>$node['path'],'label'=>$node['label'],'columns'=>$node['columns'],'items'=>$items];
            }
        }return $out;
    };
    $records=is_array($data)?$data:[$data];if(count($records)>200)$truncated=true;$out=[];
    foreach(array_slice($records,0,200) as $record){if($budget<=0){$truncated=true;break;}$out[]=['blocks'=>$walk($layout,[['tokens'=>[],'value'=>$record]])];}
    return ['records'=>$out,'columns'=>$columns,'truncated'=>$truncated];
}

function sports_api_output(int $id,string $sport='',?callable $transport=null): array
{
    $config=sports_api_get($id);
    $result=['ok'=>true,'enabled'=>$config['enabled'],'title'=>$config['title'],'columns'=>$config['columns_per_row'],'records'=>[],'sports'=>[],'sport'=>'','updatedAt'=>null,'truncated'=>false];
    if(!$config['enabled'])return $result;
    // Existing provider-wide switch continues to protect migrated Odds API configurations.
    if($config['catalog_url']!=='' && !(int)odds_api_pdo()->query('SELECT enabled FROM settings WHERE id=1')->fetchColumn()){$result['enabled']=false;return $result;}
    $source=sports_api_source($config,$sport,$transport);$root=sports_api_root($source['data'],$config['root_path']);
    $projection=sports_api_project($root,$config['layout_config'],$config['columns_per_row']);
    if(sports_api_get($id)!==$config)throw new RuntimeException('Configuration changed. Reload.');
    return array_replace($result,$projection,['sports'=>$source['sports'],'sport'=>$source['sport'],'updatedAt'=>$source['updatedAt']]);
}
