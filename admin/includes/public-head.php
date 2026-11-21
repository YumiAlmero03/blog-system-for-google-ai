<?php
declare(strict_types=1);
require_once __DIR__ . '/seo-settings.php';

function public_head_html(array $page = []): string
{
    $settings = seo_settings();
    $escape = static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES | ENT_SUBSTITUTE,'UTF-8');
    $title = trim($page['title'] ?? '') ?: ($settings['default_seo_title'] ?: $settings['website_name']);
    $description = trim($page['description'] ?? '') ?: $settings['default_meta_description'];
    $image = trim($page['image'] ?? '') ?: ($settings['default_og_image'] ?: trim($page['fallback_image'] ?? ''));
    $html = '<title>' . $escape($title) . '</title>' . "\n";
    $meta = ['og:title'=>$title,'og:description'=>$description,'og:site_name'=>$settings['website_name'],'og:type'=>$page['type'] ?? 'website'];
    if ($description !== '') $html .= '<meta name="description" content="' . $escape($description) . '">' . "\n";
    if ($image !== '') $meta['og:image'] = public_url($image);
    if (!empty($page['canonical'])) {
        $canonical = public_url($page['canonical']);
        $html .= '<link rel="canonical" href="' . $escape($canonical) . '">' . "\n";
        $meta['og:url'] = $canonical;
    }
    foreach ($meta as $key=>$value) if ($value !== '') $html .= '<meta property="' . $key . '" content="' . $escape($value) . '">' . "\n";
    if (empty($GLOBALS['seo_tracking_rendered'])) {
        $GLOBALS['seo_tracking_rendered'] = true;
        if ($settings['google_search_console_verification'] !== '') $html .= '<meta name="google-site-verification" content="' . $escape($settings['google_search_console_verification']) . '">' . "\n";
        $id = $settings['google_analytics_id'];
        $hasGtag = false;
        if ($id !== '' && preg_match('/^G-[A-Z0-9]{10}$/D',$id)) {
            $hasGtag = true;
            $html .= '<script async src="https://www.googletagmanager.com/gtag/js?id=' . rawurlencode($id) . '"></script>' . "\n";
            $html .= '<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag("js",new Date());gtag("config",' . json_encode($id,JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ');</script>' . "\n";
        }
        $tagId = $settings['google_tag_id'] ?? '';
        if ($tagId !== '' && preg_match('/^GTM-[A-Z0-9]{4,16}$/D',$tagId)) {
            $tagJson = json_encode($tagId,JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            $html .= '<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({\'gtm.start\':new Date().getTime(),event:\'gtm.js\'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!=\'dataLayer\'?\'&l=\'+l:\'\';j.async=true;j.src=\'https://www.googletagmanager.com/gtm.js?id=\'+i+dl;f.parentNode.insertBefore(j,f);})(window,document,\'script\',\'dataLayer\',' . $tagJson . ');</script>' . "\n";
        } elseif ($tagId !== '' && preg_match('/^(?:GT|G|AW)-[A-Z0-9]{4,16}$/D',$tagId) && $tagId !== $id) {
            $tagJson = json_encode($tagId,JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            if (!$hasGtag) {
                $html .= '<script async src="https://www.googletagmanager.com/gtag/js?id=' . rawurlencode($tagId) . '"></script>' . "\n";
                $html .= '<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag("js",new Date());gtag("config",' . $tagJson . ');</script>' . "\n";
            } else {
                $html .= '<script>gtag("config",' . $tagJson . ');</script>' . "\n";
            }
        }
    }
    return $html;
}
