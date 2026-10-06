<?php
declare(strict_types=1);
// Extracted from the former blog/view.php renderer (Git e63bd79 parent).
require_once __DIR__ . '/game-public.php';

function blog_h(mixed $value): string
{
  return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function blog_render_inline_markdown(string $text): string
{
  $text = preg_replace_callback('/\[!\[([^\]]*)\]\((\/uploads\/blogs\/[A-Za-z0-9._\/-]+)\)\]\((https?:\/\/[^)\s]+|\/[^)\s]*)\)(\{nofollow\})?/', static function (array $matches): string {
    $rel = 'noopener noreferrer' . (!empty($matches[4]) ? ' nofollow' : '');
    return '<a href="' . $matches[3] . '" target="_blank" rel="' . $rel . '"><img src="' . $matches[2] . '" alt="' . $matches[1] . '" width="1200" height="675" loading="lazy" decoding="async"></a>';
  }, $text) ?? $text;
  $text = preg_replace('/!\[([^\]]*)\]\((\/uploads\/blogs\/[A-Za-z0-9._\/-]+)\)/', '<img src="$2" alt="$1" width="1200" height="675" loading="lazy" decoding="async">', $text) ?? $text;
  $text = preg_replace_callback('/\[([^\]]+)\]\((https?:\/\/[^)\s]+|\/[^)\s]*)\)(\{nofollow\})?/', static function (array $matches): string {
    $rel = 'noopener noreferrer' . (!empty($matches[3]) ? ' nofollow' : '');
    return '<a href="' . $matches[2] . '" target="_blank" rel="' . $rel . '">' . $matches[1] . '</a>';
  }, $text) ?? $text;
  $text = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text) ?? $text;
  $text = preg_replace('/\*([^*]+)\*/', '<em>$1</em>', $text) ?? $text;
  $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text) ?? $text;

  return $text;
}

function blog_parse_faq_items(string $content): array
{
  $lines = preg_split('/\n/', str_replace(["\r\n", "\r"], "\n", trim($content))) ?: [];
  $items = [];
  $current = null;

  foreach ($lines as $line) {
    if (preg_match('/^Q:\s*(.*)$/i', $line, $matches) === 1) {
      if (is_array($current)) {
        $items[] = $current;
      }
      $current = [
        'question' => trim($matches[1]),
        'answer' => '',
      ];
      continue;
    }

    if (preg_match('/^A:\s*(.*)$/i', $line, $matches) === 1) {
      if (!is_array($current)) {
        $current = [
          'question' => 'FAQ question',
          'answer' => '',
        ];
      }
      $current['answer'] = trim($matches[1]);
      continue;
    }

    if (is_array($current) && $current['answer'] !== '' && trim($line) !== '') {
      $current['answer'] .= ' ' . trim($line);
    }
  }

  if (is_array($current)) {
    $items[] = $current;
  }

  if ($items === []) {
    $items[] = [
      'question' => 'FAQ question',
      'answer' => 'FAQ answer',
    ];
  }

  return array_map(
    static fn(array $item): array => [
      'question' => trim((string) ($item['question'] ?? '')) ?: 'FAQ question',
      'answer' => trim((string) ($item['answer'] ?? '')) ?: 'FAQ answer',
    ],
    $items
  );
}

function blog_parse_block_options(string $content): array
{
  $options = [];
  foreach (preg_split('/\n/', str_replace(["\r\n", "\r"], "\n", trim($content))) ?: [] as $line) {
    if (preg_match('/^\s*([a-z][a-z0-9_-]*)\s*:\s*(.*?)\s*$/i', $line, $matches) === 1) {
      $options[strtolower($matches[1])] = trim($matches[2]);
    }
  }

  return $options;
}

function blog_safe_block_url(?string $url, string $fallback = '/playnow'): string
{
  $url = trim((string) $url);
  if ($url === '') {
    return $fallback;
  }

  if (preg_match('/^https?:\/\/[^\s<>"\']+$/i', $url) === 1 || preg_match('#^/[^\s<>"\']*$#', $url) === 1) {
    return $url;
  }

  return $fallback;
}

function blog_parse_custom_code_sections(string $content): array
{
  $sections = ['html' => '', 'css' => '', 'js' => ''];
  $source = str_replace(["\r\n", "\r"], "\n", $content);
  if (preg_match_all('/^---(html|css|js)\s*$/im', $source, $matches, PREG_OFFSET_CAPTURE) === false || $matches[0] === []) {
    $sections['html'] = trim($source);
    return $sections;
  }

  $count = count($matches[0]);
  for ($index = 0; $index < $count; $index++) {
    $name = strtolower($matches[1][$index][0]);
    $start = $matches[0][$index][1] + strlen($matches[0][$index][0]);
    $end = $index + 1 < $count ? $matches[0][$index + 1][1] : strlen($source);
    $sections[$name] = trim(substr($source, $start, $end - $start));
  }

  return $sections;
}

function blog_render_custom_code_block(string $content, int $index): string
{
  $sections = blog_parse_custom_code_sections($content);
  $id = 'blog-custom-code-' . $index;
  $html = trim($sections['html']);
  $css = trim((string) $sections['css']);
  $js = trim((string) $sections['js']);
  $output = '<section id="' . blog_h($id) . '" class="blog-custom-code-block">' . $html . '</section>';
  if ($css !== '') {
    $output .= '<style>' . $css . '</style>';
  }
  if ($js !== '') {
    $output .= '<script>(function(root){' . $js . "\n})(document.getElementById(" . json_encode($id) . '));</script>';
  }

  return $output;
}

function blog_button_url_valid(string $url): bool
{
  if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f<>"\'\\\\]/', $url)) return false;
  if (str_starts_with($url, '/') && !str_starts_with($url, '//')) return true;
  return filter_var($url, FILTER_VALIDATE_URL) !== false
    && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
    && parse_url($url, PHP_URL_USER) === null && parse_url($url, PHP_URL_PASS) === null;
}

function blog_button_options(string $content, bool $validate = false): array
{
  $options = blog_parse_block_options($content);
  $label = trim($options['label'] ?? $options['text'] ?? 'Open Link');
  $url = trim($options['url'] ?? '/playnow');
  $style = strtolower($options['style'] ?? 'primary');
  $align = strtolower($options['align'] ?? 'left');
  if ($validate && (!blog_button_url_valid($url) || $label === '' || strlen($label) > 200
    || !in_array($style, ['primary', 'secondary'], true) || !in_array($align, ['left', 'center', 'right'], true))) {
    throw new InvalidArgumentException('Button requires a label (up to 200 characters), a valid HTTP(S) or site-relative URL, and a supported style/alignment.');
  }
  return ['label'=>$label ?: 'Open Link', 'url'=>blog_button_url_valid($url) ? $url : '/playnow',
    'style'=>in_array($style,['primary','secondary'],true) ? $style : 'primary',
    'align'=>in_array($align,['left','center','right'],true) ? $align : 'left',
    'new_tab'=>!isset($options['new_tab']) || preg_match('/^(1|true|yes|on)$/i',$options['new_tab']) === 1,
    'nofollow'=>isset($options['nofollow']) && preg_match('/^(1|true|yes|on)$/i',$options['nofollow']) === 1];
}

function blog_validate_button_blocks(string $markdown): void
{
  preg_match_all('/^:::button[ \t]*\n([\s\S]*?)^:::[ \t]*$/m',str_replace(["\r\n","\r"],"\n",$markdown),$matches);
  foreach ($matches[1] as $content) blog_button_options($content,true);
}

function blog_render_button_block(string $content): string
{
  $button = blog_button_options($content);
  $url = str_starts_with($button['url'], '/') ? public_url($button['url']) : $button['url'];
  $rel = 'noopener noreferrer' . ($button['nofollow'] ? ' nofollow' : '');
  $class = 'blog-button-link' . ($button['style'] === 'secondary' ? ' blog-slot-demo-real' : '');
  return '<p class="blog-button-block" style="text-align:' . $button['align'] . '"><a class="' . $class . '" href="' . blog_h($url) . '"'
    . ($button['new_tab'] ? ' target="_blank"' : '') . ' rel="' . $rel . '">' . blog_h($button['label']) . '</a></p>';
}

function blog_render_slot_demo_block(string $content): string
{
  $options = blog_parse_block_options($content);
  $slug = public_detail_slug($options['slug'] ?? null);
  $game = $slug !== null ? public_game_find($slug) : null;
  if ($game === null) return '<p class="blog-slot-demo-unavailable">Game demo unavailable.</p>';
  $label = (string)$game['name'] . ' Demo';
  $url = blog_safe_block_url(slot_list_iframe_url($game['url'] ?? ''), '');
  if ($url === '') return '<p class="blog-slot-demo-unavailable">Game demo unavailable.</p>';
  $fullscreen = 'const frame=this.closest(".blog-slot-demo-frame"); const active=document.fullscreenElement||document.webkitFullscreenElement; const action=active?(document.exitFullscreen||document.webkitExitFullscreen):(frame.requestFullscreen||frame.webkitRequestFullscreen); const status=frame.querySelector(".blog-slot-demo-status"); status.textContent=""; if(!action){status.textContent="Fullscreen is unavailable in this browser.";return;} try{Promise.resolve(action.call(active?document:frame)).catch(()=>{status.textContent="Unable to open fullscreen. Try View Game.";});}catch(error){status.textContent="Unable to open fullscreen. Try View Game.";}';
  return '<section class="blog-slot-demo-block"><div class="blog-slot-demo-frame"><div class="blog-slot-demo-header"><p class="blog-slot-demo-title">' . blog_h($label) . '</p><div class="blog-slot-demo-actions">'
    . '<a class="blog-slot-demo-link" href="' . blog_h(public_url('/game/' . rawurlencode($slug) . '/')) . '">View Game</a>'
    . '<a class="blog-slot-demo-link blog-slot-demo-real" href="' . blog_h(public_url('/playnow')) . '">Play for Real</a>'
    . '<button type="button" class="blog-slot-demo-link" onclick="' . blog_h($fullscreen) . '"><span class="blog-demo-enter">Fullscreen</span><span class="blog-demo-exit">Exit fullscreen</span></button></div><span class="blog-slot-demo-status" role="status"></span></div>'
    . '<iframe src="' . blog_h(public_url($url)) . '" title="' . blog_h($label) . '" loading="lazy" allow="fullscreen" allowfullscreen></iframe></div></section>';
}

function blog_render_table_block(string $content): string
{
  $rows = [];
  foreach (preg_split('/\n/', str_replace(["\r\n", "\r"], "\n", trim($content))) ?: [] as $line) {
    $line = trim($line);
    if ($line === '' || preg_match('/^\s*(headings|header|header_row)\s*:/i', $line) === 1 || preg_match('/^\|?\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)+\|?$/', $line) === 1) {
      continue;
    }
    if (str_contains($line, '|')) {
      $line = trim($line, '| ');
      $rows[] = array_map(static fn(string $cell): string => trim(str_replace('\\|', '|', $cell)), preg_split('/(?<!\\\\)\|/', $line) ?: []);
    }
  }
  if ($rows === []) {
    return '';
  }

  $headings = preg_match('/^\s*(?:headings|header|header_row)\s*:\s*(?:1|true|yes|on)\s*$/im',$content) === 1;
  $body = '';
  foreach ($rows as $i => $row) {
    $tag = $headings && $i === 0 ? 'th' : 'td';
    $cells = implode('',array_map(static fn(string $cell): string => '<' . $tag . '>' . blog_render_inline_markdown(blog_h($cell)) . '</' . $tag . '>', $row));
    $body .= ($headings && $i === 0 ? '<thead><tr>' : ($i === ($headings ? 1 : 0) ? '<tbody><tr>' : '<tr>')) . $cells . '</tr>' . ($headings && $i === 0 ? '</thead>' : '');
  }
  if (count($rows) > ($headings ? 1 : 0)) $body .= '</tbody>';
  return '<div class="blog-table-block"><table>' . $body . '</table></div>';
}

function blog_render_countdown_banner(array $options): string
{
    $title = trim((string)($options['title'] ?? 'Limited Time Offer'));
    $target = trim((string)($options['target'] ?? ''));
    $endText = trim((string)($options['end_text'] ?? 'This promotion has ended.'));
    $link = trim((string)($options['link'] ?? ''));
    $button = trim((string)($options['button'] ?? ''));
    if ($target === '') {
        return '';
    }
    $id = 'blog-countdown-' . bin2hex(random_bytes(4));
    $escapedTitle = blog_h($title);
    $escapedTarget = blog_h($target);
    $escapedEndText = blog_h($endText);

    $ctaHtml = '';
    if ($link !== '') {
        $btnLabel = $button !== '' ? blog_h($button) : 'Claim Now';
        $btnUrl = str_starts_with($link, '/') ? public_url($link) : $link;
        $ctaHtml = '<div class="blog-countdown-cta"><a class="blog-button-link" href="' . blog_h($btnUrl) . '" target="_blank" rel="noopener noreferrer">' . $btnLabel . '</a></div>';
    }

    $js = '(()=>{const r=document.getElementById(' . json_encode($id) . ');if(!r)return;const t=new Date(r.dataset.target).getTime();if(isNaN(t))return;const d=r.querySelector(".cd-d"),h=r.querySelector(".cd-h"),m=r.querySelector(".cd-m"),s=r.querySelector(".cd-s"),exp=r.querySelector(".blog-countdown-expired"),timer=r.querySelector(".blog-countdown-timer");function u(){const diff=t-Date.now();if(diff<=0){if(timer)timer.style.display="none";if(exp)exp.style.display="block";return;}const days=Math.floor(diff/86400000),hours=Math.floor((diff%86400000)/3600000),mins=Math.floor((diff%3600000)/60000),secs=Math.floor((diff%60000)/1000);if(d)d.textContent=String(days).padStart(2,"0");if(h)h.textContent=String(hours).padStart(2,"0");if(m)m.textContent=String(mins).padStart(2,"0");if(s)s.textContent=String(secs).padStart(2,"0");}u();setInterval(u,1000);})();';

    return '<section id="' . blog_h($id) . '" class="blog-countdown-block blog-countdown-banner" data-target="' . $escapedTarget . '">'
        . '<div class="blog-countdown-inner">'
        . ($title !== '' ? '<p class="blog-countdown-title">' . $escapedTitle . '</p>' : '')
        . '<div class="blog-countdown-timer">'
        . '<div class="cd-box"><span class="cd-num cd-d">00</span><span class="cd-lbl">DAYS</span></div>'
        . '<span class="cd-sep">:</span>'
        . '<div class="cd-box"><span class="cd-num cd-h">00</span><span class="cd-lbl">HOURS</span></div>'
        . '<span class="cd-sep">:</span>'
        . '<div class="cd-box"><span class="cd-num cd-m">00</span><span class="cd-lbl">MINS</span></div>'
        . '<span class="cd-sep">:</span>'
        . '<div class="cd-box"><span class="cd-num cd-s">00</span><span class="cd-lbl">SECS</span></div>'
        . '</div>'
        . '<div class="blog-countdown-expired" style="display:none;"><p class="cd-expired-text">' . $escapedEndText . '</p></div>'
        . $ctaHtml
        . '</div>'
        . '<script>' . $js . '</script>'
        . '</section>';
}

function blog_render_countdown_block(string $content): string
{
    $options = blog_parse_block_options($content);
    return blog_render_countdown_banner([
        'title' => $options['title'] ?? $options['label'] ?? 'Limited Time Offer',
        'target' => $options['target'] ?? $options['date'] ?? $options['time'] ?? '',
        'end_text' => $options['end_text'] ?? $options['expired'] ?? 'This promotion has ended.',
        'link' => $options['link'] ?? $options['url'] ?? '',
        'button' => $options['button'] ?? $options['btn_text'] ?? '',
    ]);
}

function blog_render_markdown(string $markdown): string
{
  $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
  $rendered = [];
  $prefix = '@@BLOG_BLOCK_' . bin2hex(random_bytes(8)) . '_';
  // Extract once: marker-like text inside trusted custom code is not parsed as Markdown.
  $markdown = preg_replace_callback('/^:::(button|table|custom-code|slot-demo|faq|quote|countdown)[ \t]*\n([\s\S]*?)^:::[ \t]*$/m', static function(array $match) use (&$rendered,$prefix): string {
    $content = $match[2];
    $html = match ($match[1]) {
      'button' => blog_render_button_block($content),
      'table' => blog_render_table_block($content),
      'custom-code' => blog_render_custom_code_block($content,count($rendered)),
      'slot-demo' => blog_render_slot_demo_block($content),
      'quote' => '<blockquote>' . blog_render_inline_markdown(blog_h(trim($content))) . '</blockquote>',
      'faq' => '<section class="blog-faq-block">' . implode('',array_map(static fn(array $item): string => '<details class="blog-faq-item"><summary>' . blog_render_inline_markdown(blog_h($item['question'])) . '</summary><p>' . blog_render_inline_markdown(blog_h($item['answer'])) . '</p></details>',blog_parse_faq_items($content))) . '</section>',
      'countdown' => blog_render_countdown_block($content),
    };
    $token = $prefix . count($rendered) . '@@';
    $rendered[$token] = $html;
    return "\n\n" . $token . "\n\n";
  },$markdown) ?? $markdown;
  // Do not leak unclosed/unknown block delimiters into public output.
  $markdown = preg_replace('/^:::[^\n]*$/m','',$markdown) ?? $markdown;
  $escaped = blog_h($markdown);
  $html = []; $lines = []; $mode = '';
  $flush = static function() use (&$html,&$lines,&$mode): void {
    if ($lines === []) return;
    if ($mode === 'ul' || $mode === 'ol') {
      $html[] = '<' . $mode . '>' . implode('',array_map(static fn(string $line): string => '<li>' . blog_render_inline_markdown($line) . '</li>',$lines)) . '</' . $mode . '>';
    } elseif ($mode === 'pre') $html[] = '<pre><code>' . implode("\n",$lines) . '</code></pre>';
    else $html[] = '<' . $mode . '>' . blog_render_inline_markdown(implode('<br>',$lines)) . '</' . $mode . '>';
    $lines = []; $mode = '';
  };
  foreach (explode("\n",$escaped) as $line) {
    if (str_starts_with(trim($line),'```')) {
      if ($mode === 'pre') $flush(); else { $flush(); $mode = 'pre'; }
      continue;
    }
    if ($mode === 'pre') { $lines[] = $line; continue; }
    $line = trim($line);
    if ($line === '') { $flush(); continue; }
    if (isset($rendered[$line])) { $flush(); $html[] = $rendered[$line]; continue; }
    if (preg_match('/^(#{1,3})\s+(.+)$/',$line,$match)) {
      $flush(); $tag = 'h' . strlen($match[1]);
      $html[] = '<' . $tag . '>' . blog_render_inline_markdown($match[2]) . '</' . $tag . '>'; continue;
    }
    $next = 'p';
    if (preg_match('/^[-*]\s+(.+)$/',$line,$match)) { $next = 'ul'; $line = $match[1]; }
    elseif (preg_match('/^\d+\.\s+(.+)$/',$line,$match)) { $next = 'ol'; $line = $match[1]; }
    elseif (preg_match('/^&gt;\s*(.+)$/',$line,$match)) { $next = 'blockquote'; $line = $match[1]; }
    if ($next !== $mode) $flush();
    $mode = $next; $lines[] = $line;
  }
  $flush();

  return implode('', $html);
}


/** Shared final output. Only :::custom-code is treated as trusted administrator HTML. */
function blog_render_content(string $markdown): array
{
    $html = public_html_absolute_urls(blog_render_markdown($markdown));
    $faq = [];
    $previous = libxml_use_internal_errors(true);
    try {
        $doc = new DOMDocument();
        $doc->loadHTML('<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>',LIBXML_NONET);
        $xpath = new DOMXPath($doc);
        foreach ($xpath->query('//section[contains(concat(" ",normalize-space(@class)," ")," blog-faq-block ")]/details') as $item) {
            $question = $xpath->query('./summary',$item)->item(0);
            $answer = $xpath->query('./p',$item)->item(0);
            if ($question && $answer) $faq[] = ['@type'=>'Question','name'=>$question->textContent,
                'acceptedAnswer'=>['@type'=>'Answer','text'=>$answer->textContent]];
        }
    } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
    // Compact our stylesheet only; author-supplied code may depend on newlines.
    $css = preg_replace('/\s+/', ' ', (string) file_get_contents(__DIR__ . '/blog-content.css'));
    return ['content_html'=>'<style>' . trim($css ?? '') . '</style>' . $html,
        'faq_schema'=>$faq ? ['@context'=>'https://schema.org','@type'=>'FAQPage','mainEntity'=>$faq] : null];
}

function public_blog_payload(array $post): array
{
    $rendered = blog_render_content($post['content']);
    $contentHtml = $rendered['content_html'];
    $countdown = null;
    $countdownHtml = null;
    if (!empty($post['countdown_enabled']) && !empty($post['countdown_target'])) {
        $banner = blog_render_countdown_banner([
            'title' => (string)($post['countdown_title'] ?? '') ?: 'Limited Time Offer',
            'target' => (string)($post['countdown_target'] ?? ''),
            'end_text' => (string)($post['countdown_end_text'] ?? '') ?: 'This promotion has ended.',
        ]);
        if ($banner !== '') {
            $countdownHtml = $banner;
            $countdown = [
                'enabled' => true,
                'target' => (string)($post['countdown_target'] ?? ''),
                'title' => (string)($post['countdown_title'] ?? '') ?: 'Limited Time Offer',
                'end_text' => (string)($post['countdown_end_text'] ?? '') ?: 'This promotion has ended.',
                'html' => $banner,
            ];
        }
    }
    if (preg_match('/<section[^>]*class="[^"]*blog-countdown-block[^"]*"[^>]*>[\s\S]*?<\/section>/i', $contentHtml, $cdMatch)) {
        if (!$countdownHtml) {
            $countdownHtml = $cdMatch[0];
            $countdown = [
                'enabled' => true,
                'target' => (string)($post['countdown_target'] ?? ''),
                'title' => (string)($post['countdown_title'] ?? '') ?: 'Limited Time Offer',
                'end_text' => (string)($post['countdown_end_text'] ?? '') ?: 'This promotion has ended.',
                'html' => $countdownHtml,
            ];
        }
        $contentHtml = str_replace($cdMatch[0], '', $contentHtml);
    }
    $writer = $post['writer'] ?? ['name'=>$post['author'] ?: 'Editorial Team','role_name'=>'','profile_image'=>'','bio'=>'','socials'=>[]];
    return ['ok'=>true,'blog'=>[
        'title'=>$post['title'],'slug'=>$post['slug'],'category'=>$post['categoryHierarchy'] ?? null,'tags'=>blog_public_tags($post),
        'author'=>$post['writer']['name'] ?? $post['author'],
        'writer'=>$writer,
        'about_author'=>$writer,
        'excerpt'=>$post['excerpt'],
        'content_html'=>$contentHtml,
        'countdown'=>$countdown,
        'countdown_html'=>$countdownHtml,
        'featuredImage'=>public_detail_url($post['featuredImage']),
        'blogUrl'=>public_url('/blog/' . rawurlencode($post['slug']) . '/'),
        'publishedAt'=>$post['publishedAt'] ?? $post['scheduledAt'], 'updatedAt'=>$post['updatedAt'],
        'readTime'=>blog_read_minutes($post['content']),
    ],'countdown'=>$countdown,'countdown_html'=>$countdownHtml,'faq_schema'=>$rendered['faq_schema']];
}
