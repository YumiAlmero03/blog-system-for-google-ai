<?php
declare(strict_types=1);
require_once __DIR__ . '/sports-registry.php';

const SPORTS_IMAGES_MANIFEST = 'https://raw.githubusercontent.com/sportsdataverse/sdv-assets/main/manifest/marks.csv';

final class SportsImagesRateLimit extends RuntimeException {}

/** Fixed source hosts only; redirects are disabled, including for downloaded images. */
function sports_images_fetch(string $url, int $maxBytes = 8388608, ?callable $transport = null): string
{
    $parts = parse_url($url);
    if (!$parts || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) ||
        !in_array($parts['host'] ?? '', ['raw.githubusercontent.com', 'sdv.nyc3.cdn.digitaloceanspaces.com', 'www.wikidata.org', 'commons.wikimedia.org', 'upload.wikimedia.org', 'thumb.wikimedia.org'], true)) {
        throw new RuntimeException('Image source URL is not allowed.');
    }
    if ($transport) {
        $body = $transport($url);
        if (!is_string($body) || strlen($body) > $maxBytes) throw new RuntimeException('Invalid image source response.');
        return $body;
    }
    if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL is required for image enrichment.');
    $body = '';
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_USERAGENT => 'SportsRegistry/1.0 (logo enrichment; admin/plugin/odds-api)',
        CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body, $maxBytes): int {
            if (strlen($body) + strlen($chunk) > $maxBytes) return 0;
            $body .= $chunk;
            return strlen($chunk);
        }]);
    $ok = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch);
    if ($status === 429) throw new SportsImagesRateLimit('Image provider rate limit (HTTP 429); batch stopped. Try again later.');
    if ($ok === false || $status !== 200) throw new RuntimeException("Image provider request failed (HTTP {$status}).");
    return $body;
}

function sports_images_json(string $url, ?callable $transport = null): array
{
    $data = json_decode(sports_images_fetch($url, 8388608, $transport), true, 128, JSON_THROW_ON_ERROR);
    if (!is_array($data) || isset($data['error'])) throw new RuntimeException('Image provider returned an API error.');
    return $data;
}

function sports_images_db(): PDO
{
    return sports_registry_pdo();
}

function sports_images_name(string $name): string
{
    $name = function_exists('mb_strtolower') ? mb_strtolower(trim($name), 'UTF-8') : strtolower(trim($name));
    // Keep accents, youth/women qualifiers and all words; never match shortened nicknames.
    return preg_replace('/[^\p{L}\p{N}]+/u', '', $name) ?? '';
}

function sports_images_manifest(?callable $transport = null): array
{
    $path = blog_storage_dir() . '/sports-image-manifest.csv';
    if (!is_file($path) || filemtime($path) < time() - 86400) {
        $body = sports_images_fetch(SPORTS_IMAGES_MANIFEST, 67108864, $transport);
        if (!str_starts_with($body, 'level,league,entity_id,entity_name,')) throw new RuntimeException('Unexpected SportsDataverse manifest format.');
        $temp = tempnam(dirname($path), 'sdv-');
        if ($temp === false) throw new RuntimeException('Cannot cache image manifest.');
        try {
            if (file_put_contents($temp, $body) === false || !rename($temp, $path)) throw new RuntimeException('Cannot save image manifest.');
            chmod($path, 0600);
        } finally { if (is_file($temp)) unlink($temp); }
    }
    $handle = fopen($path, 'r');
    if (!$handle) throw new RuntimeException('Cannot read image manifest.');
    $index = [];
    try {
        $header = fgetcsv($handle, 0, ',', '"', '');
        foreach (['level','league','entity_id','entity_name','mark_type','variant','valid_to','last_seen','archive_url','ext'] as $key) {
            if (!is_array($header) || !in_array($key, $header, true)) throw new RuntimeException('Invalid image manifest columns.');
        }
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            if (count($row) !== count($header)) continue;
            $mark = array_combine($header, $row);
            // Historic season ranges are excluded. Raster assets only: no remote SVG execution.
            if ($mark['mark_type'] !== 'logo' || $mark['valid_to'] !== '' || !in_array($mark['level'], ['team','league'], true) ||
                !in_array($mark['ext'], ['png','jpg','webp'], true) || !in_array($mark['variant'], ['default','on_light'], true)) continue;
            $index[$mark['level']][sports_images_name($mark['entity_name'])][] = $mark;
        }
    } finally { fclose($handle); }
    return $index;
}

function sports_images_leagues(array $entity): array
{
    $keys = explode(',', $entity['tournaments_keys'] ?? $entity['odds_sport_key'] ?? '');
    $map = ['basketball_nba'=>'nba','basketball_wnba'=>'wnba','basketball_ncaab'=>'mbb',
        'basketball_wncaab'=>'wbb','americanfootball_nfl'=>'nfl','americanfootball_ncaaf'=>'cfb',
        'baseball_mlb'=>'mlb','icehockey_nhl'=>'nhl'];
    $leagues = [];
    foreach ($keys as $key) {
        $key = trim($key);
        if (isset($map[$key])) $leagues[] = $map[$key];
        elseif (str_starts_with($key, 'soccer_')) $leagues[] = 'soccer';
        elseif (str_starts_with($key, 'cricket_')) $leagues[] = 'cricket';
    }
    return array_unique($leagues);
}

function sports_images_sdv(array $entity, string $type, array $index): ?array
{
    $names = [$entity['name'] ?? $entity['source_title'] ?? $entity['title'] ?? ''];
    // League codes map only for explicitly supported competitions, never team nicknames.
    if ($type === 'tournament') {
        $codes = ['basketball_nba'=>'NBA','basketball_wnba'=>'WNBA','americanfootball_nfl'=>'NFL','baseball_mlb'=>'MLB','icehockey_nhl'=>'NHL'];
        if (isset($codes[$entity['odds_sport_key']])) $names[] = $codes[$entity['odds_sport_key']];
    }
    $found = [];
    foreach ($names as $name) foreach ($index[$type === 'participant' ? 'team' : 'league'][sports_images_name($name)] ?? [] as $mark) {
        if (!in_array($mark['league'], sports_images_leagues($entity), true)) continue;
        $found[$mark['league'] . ':' . $mark['entity_id']][] = $mark;
    }
    if (count($found) !== 1) return null;
    $marks = reset($found);
    usort($marks, static fn($a,$b) => strcmp($b['last_seen'],$a['last_seen']) ?: strcmp($a['variant'],$b['variant']));
    $mark = $marks[0];
    return ['source'=>'sportsdataverse', 'url'=>$mark['archive_url'], 'source_page'=>'https://github.com/sportsdataverse/sdv-assets',
        'provider_id'=>$mark['entity_id'], 'provider_name'=>$mark['entity_name'], 'captured_at'=>$mark['last_seen'],
        'original_url'=>$mark['url'], 'license'=>'Logo belongs to its owner; SportsDataverse does not grant a reuse license.'];
}

/** Only unambiguous exact English labels/aliases with a matching sport and current P154 logo. */
function sports_images_wikidata(array $entity, ?callable $transport = null): ?array
{
    $name = $entity['name'] ?? $entity['source_title'] ?? $entity['title'] ?? '';
    $sport = explode('_', $entity['odds_sport_key'] ?? '')[0];
    $sports = ['soccer'=>'Q2736','basketball'=>'Q5372','baseball'=>'Q5369','icehockey'=>'Q41466',
        'americanfootball'=>'Q41323','cricket'=>'Q5375','rugbyunion'=>'Q5849','tennis'=>'Q847','aussierules'=>'Q50776'];
    if (!isset($sports[$sport]) || $name === '') return null;
    $base = 'https://www.wikidata.org/w/api.php?';
    $search = sports_images_json($base . http_build_query(['action'=>'wbsearchentities','search'=>$name,'language'=>'en','limit'=>50,'format'=>'json']), $transport);
    $ids = [];
    foreach ($search['search'] ?? [] as $hit) {
        $exact = sports_images_name($hit['label'] ?? '') === sports_images_name($name) || sports_images_name($hit['match']['text'] ?? '') === sports_images_name($name);
        if ($exact && preg_match('/^Q[1-9][0-9]*$/D', $hit['id'] ?? '')) $ids[] = $hit['id'];
    }
    if (!$ids) return null;
    $data = sports_images_json($base . http_build_query(['action'=>'wbgetentities','ids'=>implode('|',$ids),'props'=>'labels|aliases|claims','languages'=>'en','format'=>'json']), $transport);
    $matches = [];
    foreach ($data['entities'] ?? [] as $id => $item) {
        $names = array_column($item['aliases']['en'] ?? [], 'value');
        $names[] = $item['labels']['en']['value'] ?? '';
        if (!in_array(sports_images_name($name), array_map('sports_images_name', $names), true)) continue;
        $sportIds = array_map(static fn($c) => $c['mainsnak']['datavalue']['value']['id'] ?? '', $item['claims']['P641'] ?? []);
        if (!in_array($sports[$sport], $sportIds, true)) continue;
        $logos = [];
        foreach ($item['claims']['P154'] ?? [] as $claim) {
            if (($claim['rank'] ?? '') === 'deprecated' || isset($claim['qualifiers']['P582'])) continue;
            $value = $claim['mainsnak']['datavalue']['value'] ?? null;
            if (is_string($value)) $logos[$claim['rank'] ?? 'normal'][] = $value;
        }
        $files = array_unique($logos['preferred'] ?? $logos['normal'] ?? []);
        $matches[$id] = count($files) === 1 ? reset($files) : null;
    }
    if (count($matches) !== 1 || !($file = reset($matches))) return null;
    $id = key($matches);
    $info = sports_images_json('https://commons.wikimedia.org/w/api.php?' . http_build_query([
        'action'=>'query','titles'=>'File:'.$file,'prop'=>'imageinfo','iiprop'=>'url|mime|extmetadata',
        'iiurlwidth'=>400,'format'=>'json']), $transport);
    foreach ($info['query']['pages'] ?? [] as $page) {
        $image = $page['imageinfo'][0] ?? null;
        if (!$image) continue;
        $meta = $image['extmetadata'] ?? [];
        $license = strip_tags($meta['LicenseShortName']['value'] ?? '');
        if ($license === '') return null;
        // Commons renders SVG logos to a PNG thumbnail; originals are never saved as active SVG.
        $url = $image['thumburl'] ?? $image['url'] ?? '';
        return ['source'=>'wikimedia', 'url'=>$url, 'source_page'=>$image['descriptionurl'] ?? 'https://commons.wikimedia.org/wiki/File:'.rawurlencode($file),
            'provider_id'=>$id, 'provider_name'=>$name, 'wikidata_url'=>'https://www.wikidata.org/wiki/'.$id,
            'license'=>$license, 'license_url'=>strip_tags($meta['LicenseUrl']['value'] ?? ''),
            'artist'=>strip_tags($meta['Artist']['value'] ?? ''), 'credit'=>strip_tags($meta['Credit']['value'] ?? ''),
            'attribution_required'=>strip_tags($meta['AttributionRequired']['value'] ?? '')];
    }
    return null;
}

function sports_images_store(array $candidate, ?callable $transport = null): string
{
    $bytes = sports_images_fetch($candidate['url'], 8388608, $transport);
    $info = @getimagesizefromstring($bytes);
    $types = ['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'];
    if (!$info || !isset($types[$info['mime'] ?? '']) || $info[0] * $info[1] > 25000000) throw new RuntimeException('Source did not return a supported raster logo.');
    $root = getenv('SPORTS_IMAGE_UPLOAD_DIR') ?: dirname(__DIR__, 4) . '/uploads/sports';
    if (!is_dir($root) && !mkdir($root, 0755, true) && !is_dir($root)) throw new RuntimeException('Cannot create sports image directory.');
    $file = hash('sha256', $bytes) . '.' . $types[$info['mime']];
    if (!is_file($root.'/'.$file)) {
        $temp = tempnam($root, '.logo-');
        if (!$temp) throw new RuntimeException('Cannot create image file.');
        try {
            if (file_put_contents($temp, $bytes) !== strlen($bytes) || !rename($temp, $root.'/'.$file)) throw new RuntimeException('Cannot save image.');
            chmod($root.'/'.$file, 0644);
        } finally { if (is_file($temp)) unlink($temp); }
    }
    return '/uploads/sports/'.$file;
}

function sports_images_record(string $type, array $entity, string $status, string $path, array $candidate, string $message): void
{
    $pdo = sports_images_db();
    $pdo->prepare('INSERT INTO sports_image_assets(entity_type,entity_id,name,status,image_path,source,metadata,message,checked_at) VALUES(?,?,?,?,?,?,?,?,?)
        ON CONFLICT(entity_type,entity_id) DO UPDATE SET name=excluded.name,status=excluded.status,image_path=excluded.image_path,source=excluded.source,metadata=excluded.metadata,message=excluded.message,checked_at=excluded.checked_at')
        ->execute([$type,$entity['id'],$entity['name'] ?? $entity['title'],$status,$path,$candidate['source'] ?? '',json_encode($candidate, JSON_UNESCAPED_SLASHES),$message,time()]);
}

/** Bounded batches resume automatically; existing/manual images are never replaced. */
function sports_images_enrich(?int $tournamentId = null, array $options = []): array
{
    $pdo = sports_images_db();
    $limit = max(1, min(25, (int)($options['limit'] ?? 5)));
    $dry = !empty($options['dry_run']);
    $transport = $options['transport'] ?? null;
    $stats = ['enriched'=>0,'sportsdataverse'=>0,'wikimedia'=>0,'image_failures'=>0,'skipped_manual'=>0,'skipped_existing'=>0,'unmatched'=>0,'processed'=>0,'remaining'=>0,'stopped'=>false,'errors'=>[],'results'=>[]];
    $entities = [];
    $enabledKeys = [];
    foreach (tournament_get_all(null, true, true) as $t) {
        // Daily jobs also ignore competitions marked inactive by the provider.
        if (!empty($options['active_only']) && (int)$t['active'] !== 1) continue;
        if ($tournamentId !== null && (int)$t['id'] !== $tournamentId) continue;
        $entities[] = ['tournament', $t];
        $enabledKeys[] = $t['odds_sport_key'];
    }
    foreach (participant_get_all($tournamentId ? ['tournament_id'=>$tournamentId,'enabled'=>1] : ['enabled'=>1]) as $p) {
        $keys = array_map('trim', explode(',', $p['tournaments_keys'] ?? $p['odds_sport_key']));
        if (!array_intersect($keys, $enabledKeys)) continue;
        $entities[] = ['participant', $p];
    }
    $records = [];
    foreach ($pdo->query('SELECT * FROM sports_image_assets')->fetchAll() as $record) $records[$record['entity_type'].':'.$record['entity_id']] = $record;
    $pending = [];
    foreach ($entities as [$type,$entity]) {
        if (($entity['image_source'] ?? '') === 'manual') { $stats['skipped_manual']++; continue; }
        $record = $records[$type.':'.$entity['id']] ?? null;
        if (!empty($entity['image_path']) || ($type === 'tournament' && !empty($record['image_path']))) { $stats['skipped_existing']++; continue; }
        if ($record && isset($options['checked_since']) && (int)$record['checked_at'] >= (int)$options['checked_since']) continue;
        if ($record && empty($options['retry']) && (int)$record['checked_at'] > time() - ($record['status'] === 'error' ? 3600 : 604800)) continue;
        $pending[] = [$type,$entity];
    }
    $stats['remaining'] = count($pending);
    if (!$pending) return $stats;
    $index = [];
    try { $index = $options['manifest'] ?? sports_images_manifest($transport); }
    catch (SportsImagesRateLimit $e) { $stats['stopped']=true; $stats['errors'][]=$e->getMessage(); return $stats; }
    catch (Throwable $e) { $stats['errors'][]='SportsDataverse unavailable; trying Wikimedia. '.$e->getMessage(); }
    $start = microtime(true);
    foreach (array_slice($pending, 0, $limit) as [$type,$entity]) {
        if (microtime(true) - $start > 45 || (isset($options['deadline']) && microtime(true) >= $options['deadline'])) break;
        $candidate = null;
        try {
            $candidate = sports_images_sdv($entity, $type, $index);
            $path = '';
            if ($candidate && !$dry) {
                try { $path = sports_images_store($candidate, $transport); }
                catch (SportsImagesRateLimit $e) { throw $e; }
                catch (Throwable $e) { $candidate = null; }
            }
            if (!$candidate) {
                $candidate = sports_images_wikidata($entity, $transport);
                if ($candidate && !$dry) $path = sports_images_store($candidate, $transport);
            }
            $status = $candidate ? ($dry ? 'candidate' : 'saved') : 'unmatched';
            $message = $candidate ? '' : 'No unique logo match with matching sport; manual review needed.';
            if (!$dry) {
                $pdo->beginTransaction();
                try {
                    if ($candidate && $type === 'participant') {
                        $stmt = $pdo->prepare("UPDATE participants SET image_path=?,image_source=?,image_failure=0,updated_at=? WHERE id=? AND image_path='' AND image_source!='manual'");
                        $stmt->execute([$path,$candidate['source'],time(),$entity['id']]);
                        if (!$stmt->rowCount()) { $pdo->rollBack(); $stats['skipped_existing']++; continue; }
                        $pdo->prepare("UPDATE teams SET image_path=?,image_source=?,image_failure=0,updated_at=? WHERE id=? AND image_path='' AND image_source!='manual'")
                            ->execute([$path,$candidate['source'],time(),$entity['id']]);
                    }
                    sports_images_record($type,$entity,$status,$path,$candidate ?? [],$message);
                    $pdo->commit();
                } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
            }
            if ($candidate) { $stats['enriched']++; $stats[$candidate['source']]++; } else $stats['unmatched']++;
            $stats['results'][] = ['type'=>$type,'id'=>$entity['id'],'name'=>$entity['name'] ?? $entity['title'],'status'=>$status,'source'=>$candidate['source'] ?? '', 'image_path'=>$path,'metadata'=>$candidate];
            $stats['processed']++; $stats['remaining']--;
        } catch (SportsImagesRateLimit $e) {
            $stats['stopped']=true; $stats['errors'][]=$e->getMessage(); break;
        } catch (Throwable $e) {
            $stats['image_failures']++; $stats['processed']++; $stats['remaining']--;
            $stats['errors'][]=($entity['name'] ?? $entity['title']).': '.$e->getMessage();
            if (!$dry) sports_images_record($type,$entity,'error','',[], $e->getMessage());
        }
    }
    return $stats;
}
