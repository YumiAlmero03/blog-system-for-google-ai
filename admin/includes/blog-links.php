<?php
declare(strict_types=1);
require_once __DIR__ . '/env.php';

function blog_normalize_internal_url(string $url): string
{
    $parts=parse_url($url);
    if ($parts===false || $url==='' || preg_match('/[\x00-\x20\x7f\\\\]/',$url)) return $url;
    if (str_starts_with($url,'/') && !str_starts_with($url,'//')) {
        $prefix='';
    } elseif (in_array(strtolower($parts['scheme'] ?? ''),['http','https'],true)
        && !isset($parts['user']) && !isset($parts['pass'])
        && strtolower($parts['host'] ?? '')===strtolower((string)parse_url(site_base_url(),PHP_URL_HOST))
        && ($parts['port'] ?? (strtolower($parts['scheme'])==='https' ? 443 : 80))===(parse_url(site_base_url(),PHP_URL_PORT) ?: (strtolower($parts['scheme'])==='https' ? 443 : 80))) {
        preg_match('~^https?://[^/?#]+~i',$url,$match); $prefix=$match[0];
    } else return $url;
    $path=$parts['path'] ?? '';
    $decoded=rawurldecode($path);
    if (str_ends_with($path,'/') || str_contains(basename($decoded),'.')
        || preg_match('~^/(?:api|admin|assets|uploads|storage|includes|scripts|chats)(?:/|$)~i',$decoded)) return $url;
    // Preserve the original query and fragment byte-for-byte.
    $suffix=substr($url,strlen($prefix)+strlen($path));
    return $prefix.$path.'/'.$suffix;
}

function blog_normalize_html_links(string $html): string
{
    return preg_replace_callback('~(<script\b[^>]*>[\s\S]*?</script>|<style\b[^>]*>[\s\S]*?</style>)|(\bhref\s*=\s*)(["\'])([^"\']*)\3~i',static function(array $m): string {
        if (($m[1] ?? '') !== '') return $m[0];
        return $m[2].$m[3].blog_normalize_internal_url($m[4]).$m[3];
    },$html) ?? $html;
}

function blog_normalize_content_links(string $content): string
{
    $protected=[];
    $token='BLOG_LINK_LITERAL_'.bin2hex(random_bytes(8)).'_';
    $content=preg_replace_callback('~^:::custom-code[^\r\n]*\R[\s\S]*?^:::[ \t]*\r?$|^(`{3,}|\~{3,})[^\r\n]*\R[\s\S]*?^\1[ \t]*\r?$|`[^`\r\n]+`~m',static function(array $m) use (&$protected,$token): string {
        $key=$token.count($protected); $literal=$m[0];
        if (str_starts_with($literal,':::custom-code')) {
            $literal=preg_replace_callback('~(^---html[ \t]*\R)([\s\S]*?)(?=^---(?:css|js)[ \t]*\r?$|^:::[ \t]*\r?$)~m',static fn(array $html): string => $html[1].blog_normalize_html_links($html[2]),$literal) ?? $literal;
        }
        $protected[$key]=$literal; return $key;
    },$content) ?? $content;
    $content=preg_replace_callback('~(\]\([ \t]*<?)([^\s<>\)]+)~',static fn(array $m): string => $m[1].blog_normalize_internal_url($m[2]),$content) ?? $content;
    $content=blog_normalize_html_links($content);
    $content=preg_replace_callback('~<(https?://[^\s<>]+)>~i',static fn(array $m): string => '<'.blog_normalize_internal_url($m[1]).'>',$content) ?? $content;
    $content=preg_replace_callback('~^(\s*\[[^\]\r\n]+\]:[ \t]*<?)([^\s<>]+)~m',static fn(array $m): string => $m[1].blog_normalize_internal_url($m[2]),$content) ?? $content;
    $content=preg_replace_callback('~(^:::(?:button|slot-demo)[ \t]*\R)([\s\S]*?)(^:::[ \t]*\r?$)~m',static function(array $m): string {
        $body=preg_replace_callback('~^([ \t]*url:[ \t]*)([^\r\n]*?)([ \t]*\r?)$~mi',static fn(array $line): string => $line[1].blog_normalize_internal_url($line[2]).$line[3],$m[2]);
        return $m[1].$body.$m[3];
    },$content) ?? $content;
    return strtr($content,$protected);
}
