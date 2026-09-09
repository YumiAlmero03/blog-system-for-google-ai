<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/blog-storage.php';

$slug = isset($_GET['slug']) && is_string($_GET['slug']) ? normalize_slug($_GET['slug']) : '';
$post = $slug !== '' ? blogs_find($slug) : null;

if ($post === null || ($post['status'] ?? 'published') !== 'published') {
  http_response_code(404);
  readfile(__DIR__ . '/../404.html');
  exit;
}

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

function blog_render_button_block(string $content): string
{
  $options = blog_parse_block_options($content);
  $url = blog_safe_block_url($options['url'] ?? '', '/playnow');
  $label = trim((string) ($options['label'] ?? 'Open Link')) ?: 'Open Link';
  $nofollow = isset($options['nofollow']) && preg_match('/^(1|true|yes|on)$/i', $options['nofollow']) === 1;
  $rel = 'noopener noreferrer' . ($nofollow ? ' nofollow' : '');

  return '<p class="blog-button-block"><a class="blog-button-link" href="' . blog_h($url) . '" target="_blank" rel="' . blog_h($rel) . '">' . blog_h($label) . '</a></p>';
}

function blog_render_slot_demo_block(string $content): string
{
  $options = blog_parse_block_options($content);
  $url = blog_safe_block_url($options['url'] ?? '', '/playnow');
  $title = trim((string) ($options['title'] ?? ''));
  $label = $title !== '' ? $title . ' Demo' : 'Selected Game Demo';
  $gameSlug = normalize_slug((string) ($options['slug'] ?? ''));
  $gamePageUrl = '';
  if ($gameSlug !== '') {
    $stmt = blogs_pdo()->prepare('SELECT slug FROM games WHERE slug = :slug AND published = 1 AND done_processing = 1 LIMIT 1');
    $stmt->execute([':slug' => $gameSlug]);
    $gameSlug = (string) ($stmt->fetchColumn() ?: '');
    if ($gameSlug !== '') {
      $gamePageUrl = '/game/' . rawurlencode($gameSlug) . '/';
    }
  }

  return '<section class="blog-slot-demo-block"><div class="blog-slot-demo-header"><h2>' . blog_h($label) . '</h2><div class="blog-slot-demo-actions">'
    . ($gamePageUrl !== '' ? '<a class="blog-slot-demo-link" href="' . blog_h($gamePageUrl) . '">View Game</a>' : '')
    . '<a class="blog-slot-demo-link blog-slot-demo-real" href="/playnow">Play for Real</a></div></div>'
    . '<div class="blog-slot-demo-frame"><iframe src="' . blog_h($url) . '" title="' . blog_h($label) . '" loading="lazy" allowfullscreen></iframe></div></section>';
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

  $body = array_map(static fn(array $row): string => '<tr>' . implode('', array_map(static fn(string $cell): string => '<td>' . blog_render_inline_markdown(blog_h($cell)) . '</td>', $row)) . '</tr>', $rows);
  return '<div class="blog-table-block"><table>' . implode('', $body) . '</table></div>';
}

function blog_render_markdown(string $markdown): string
{
  $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
  $faqBlocks = [];
  $buttonBlocks = [];
  $tableBlocks = [];
  $customCodeBlocks = [];
  $slotDemoBlocks = [];
  $markdown = preg_replace_callback('/:::faq\s*\n?([\s\S]*?)\n?:::/', static function (array $matches) use (&$faqBlocks): string {
    $token = '@@FAQ_BLOCK_' . count($faqBlocks) . '@@';
    $faqBlocks[] = $matches[1];
    return "\n\n" . $token . "\n\n";
  }, $markdown) ?? $markdown;
  $markdown = preg_replace_callback('/:::button\s*\n?([\s\S]*?)\n?:::/', static function (array $matches) use (&$buttonBlocks): string {
    $token = '@@BUTTON_BLOCK_' . count($buttonBlocks) . '@@';
    $buttonBlocks[] = $matches[1];
    return "\n\n" . $token . "\n\n";
  }, $markdown) ?? $markdown;
  $markdown = preg_replace_callback('/:::table\s*\n?([\s\S]*?)\n?:::/', static function (array $matches) use (&$tableBlocks): string {
    $token = '@@TABLE_BLOCK_' . count($tableBlocks) . '@@';
    $tableBlocks[] = $matches[1];
    return "\n\n" . $token . "\n\n";
  }, $markdown) ?? $markdown;
  $markdown = preg_replace_callback('/:::custom-code\s*\n?([\s\S]*?)\n?:::/', static function (array $matches) use (&$customCodeBlocks): string {
    $token = '@@CUSTOM_CODE_BLOCK_' . count($customCodeBlocks) . '@@';
    $customCodeBlocks[] = $matches[1];
    return "\n\n" . $token . "\n\n";
  }, $markdown) ?? $markdown;
  $markdown = preg_replace_callback('/:::slot-demo\s*\n?([\s\S]*?)\n?:::/', static function (array $matches) use (&$slotDemoBlocks): string {
    $token = '@@SLOT_DEMO_BLOCK_' . count($slotDemoBlocks) . '@@';
    $slotDemoBlocks[] = $matches[1];
    return "\n\n" . $token . "\n\n";
  }, $markdown) ?? $markdown;
  $escaped = blog_h($markdown);
  $blocks = preg_split('/\n\s*\n/', $escaped) ?: [];
  $html = [];

  foreach ($blocks as $block) {
    $block = trim($block);
    if ($block === '') {
      continue;
    }

    if (str_starts_with($block, '```')) {
      $code = trim($block, "`\n");
      $html[] = '<pre><code>' . $code . '</code></pre>';
      continue;
    }

    if (preg_match('/^@@FAQ_BLOCK_(\d+)@@$/', $block, $matches) === 1) {
      $faqItems = blog_parse_faq_items($faqBlocks[(int) $matches[1]] ?? '');
      $faqHtml = [];
      foreach ($faqItems as $item) {
        $faqHtml[] = '<div class="blog-faq-item"><h3>' . blog_render_inline_markdown(blog_h($item['question'])) . '</h3><p>' . blog_render_inline_markdown(blog_h($item['answer'])) . '</p></div>';
      }
      $html[] = '<section class="blog-faq-block">' . implode('', $faqHtml) . '</section>';
      continue;
    }

    if (preg_match('/^@@BUTTON_BLOCK_(\d+)@@$/', $block, $matches) === 1) {
      $html[] = blog_render_button_block($buttonBlocks[(int) $matches[1]] ?? '');
      continue;
    }
    if (preg_match('/^@@TABLE_BLOCK_(\d+)@@$/', $block, $matches) === 1) {
      $html[] = blog_render_table_block($tableBlocks[(int) $matches[1]] ?? '');
      continue;
    }
    if (preg_match('/^@@CUSTOM_CODE_BLOCK_(\d+)@@$/', $block, $matches) === 1) {
      $html[] = blog_render_custom_code_block($customCodeBlocks[(int) $matches[1]] ?? '', (int) $matches[1]);
      continue;
    }
    if (preg_match('/^@@SLOT_DEMO_BLOCK_(\d+)@@$/', $block, $matches) === 1) {
      $html[] = blog_render_slot_demo_block($slotDemoBlocks[(int) $matches[1]] ?? '');
      continue;
    }

    if (preg_match('/^###\s+(.+)$/s', $block, $matches)) {
      $html[] = '<h3>' . blog_render_inline_markdown($matches[1]) . '</h3>';
      continue;
    }

    if (preg_match('/^##\s+(.+)$/s', $block, $matches)) {
      $html[] = '<h2>' . blog_render_inline_markdown($matches[1]) . '</h2>';
      continue;
    }

    if (preg_match('/^#\s+(.+)$/s', $block, $matches)) {
      $html[] = '<h1>' . blog_render_inline_markdown($matches[1]) . '</h1>';
      continue;
    }

    if (preg_match('/^&gt;\s+(.+)$/s', $block, $matches)) {
      $html[] = '<blockquote>' . blog_render_inline_markdown($matches[1]) . '</blockquote>';
      continue;
    }

    $lines = preg_split('/\n/', $block) ?: [];
    $isUnorderedList = $lines !== [] && array_reduce($lines, static fn(bool $carry, string $line): bool => $carry && preg_match('/^\s*[-*]\s+/', $line) === 1, true);
    $isOrderedList = $lines !== [] && array_reduce($lines, static fn(bool $carry, string $line): bool => $carry && preg_match('/^\s*\d+\.\s+/', $line) === 1, true);

    if ($isUnorderedList || $isOrderedList) {
      $items = array_map(static function (string $line): string {
        $line = preg_replace('/^\s*(?:[-*]|\d+\.)\s+/', '', $line) ?? $line;
        return '<li>' . blog_render_inline_markdown($line) . '</li>';
      }, $lines);
      $tag = $isOrderedList ? 'ol' : 'ul';
      $html[] = '<' . $tag . '>' . implode('', $items) . '</' . $tag . '>';
      continue;
    }

    $html[] = '<p>' . blog_render_inline_markdown(str_replace("\n", '<br>', $block)) . '</p>';
  }

  return implode("\n", $html);
}

function blog_base_url(): string
{
  return site_base_url();
}

function blog_absolute_url(string $url, string $baseUrl): string
{
  $url = trim($url);
  if ($url === '') {
    return $baseUrl . str_replace(' ', '%20', BLOG_DEFAULT_IMAGE);
  }
  if (preg_match('/^https?:\/\//i', $url) === 1) {
    return str_replace(' ', '%20', $url);
  }

  return str_replace(' ', '%20', public_url($url, $baseUrl));
}

function blog_meta_text(string $text, int $maxLength): string
{
  $text = trim(preg_replace('/\s+/', ' ', html_entity_decode($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?? '');
  $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
  if ($length <= $maxLength) {
    return $text;
  }

  $trimmed = function_exists('mb_substr') ? mb_substr($text, 0, $maxLength - 1, 'UTF-8') : substr($text, 0, $maxLength - 1);
  return rtrim($trimmed, " \t\n\r\0\x0B.,;:-") . '...';
}

function blog_collect_faq_blocks(string $markdown): array
{
  $matchCount = preg_match_all('/:::faq\s*\n?([\s\S]*?)\n?:::/s', $markdown, $matches, PREG_SET_ORDER);
  if ($matchCount === false || $matchCount < 1) {
    return [];
  }

  $faqs = [];
  foreach ($matches as $match) {
    foreach (blog_parse_faq_items($match[1] ?? '') as $item) {
      $question = blog_meta_text(strip_tags(blog_render_inline_markdown(blog_h($item['question']))), 180);
      $answer = blog_meta_text(strip_tags(blog_render_inline_markdown(blog_h($item['answer']))), 600);
      if ($question === '' || $answer === '') {
        continue;
      }
      $faqs[] = [
        '@type' => 'Question',
        'name' => $question,
        'acceptedAnswer' => [
          '@type' => 'Answer',
          'text' => $answer,
        ],
      ];
    }
  }

  return $faqs;
}

function blog_iso_datetime(mixed $timestamp): string
{
  $timestamp = is_int($timestamp) ? $timestamp : (is_numeric($timestamp) ? (int) $timestamp : time());
  return gmdate('c', max(0, $timestamp));
}

function blog_image_type(string $image): string
{
  $path = strtolower(parse_url($image, PHP_URL_PATH) ?: $image);
  if (str_ends_with($path, '.png')) {
    return 'image/png';
  }
  if (str_ends_with($path, '.jpg') || str_ends_with($path, '.jpeg')) {
    return 'image/jpeg';
  }
  if (str_ends_with($path, '.svg')) {
    return 'image/svg+xml';
  }

  return 'image/webp';
}

$siteBaseUrl = blog_base_url();
$siteName = blog_website_title();
$displayTitle = (string) $post['title'];
$seoTitleSource = normalize_seo_title($post['seoTitle'] ?? '');
$seoTitleSource = $seoTitleSource !== '' ? $seoTitleSource : $displayTitle;
if ($siteName !== '' && stripos($seoTitleSource, $siteName) === false) {
  $seoTitleSource .= ' | ' . $siteName;
}
$articleTitle = blog_meta_text($displayTitle, 90);
$seoTitle = blog_meta_text($seoTitleSource, 120);
$pageTitle = $seoTitle;
$excerpt = blog_meta_text($post['excerpt'], 160);
$image = $post['featuredImage'] ?: BLOG_DEFAULT_IMAGE;
$canonical = $siteBaseUrl . '/blog/' . rawurlencode($post['slug']) . '/';
$blogIndexUrl = $siteBaseUrl . '/blog/';
$homeUrl = $siteBaseUrl . '/';
$absoluteImage = blog_absolute_url($image, $siteBaseUrl);
$absoluteLogo = blog_absolute_url('/assets/images/website logo.webp', $siteBaseUrl);
$imageType = blog_image_type($absoluteImage);
$authorName = blog_meta_text((string) ($post['author'] ?? BLOG_DEFAULT_AUTHOR), 80);
$publishedIso = blog_iso_datetime($post['createdAt'] ?? null);
$modifiedIso = blog_iso_datetime($post['updatedAt'] ?? ($post['createdAt'] ?? null));
$focusKeyphrase = isset($post['focusKeyphrase']) && is_string($post['focusKeyphrase']) ? blog_meta_text($post['focusKeyphrase'], 120) : '';
$faqEntities = blog_collect_faq_blocks((string) ($post['content'] ?? ''));
$fbAppId = env_value('FACEBOOK_APP_ID');
$viewResult = blog_record_engagement((string) $post['id'], 'view');
$engagementCounts = is_array($viewResult['counts'] ?? null) ? $viewResult['counts'] : blog_engagement_counts((string) $post['id']);
$readMinutes = blog_read_minutes((string) ($post['content'] ?? ''));
$recommendedQuery = blogs_pdo()->prepare(
  'SELECT slug, title, excerpt, featured_image, category
   FROM blog_posts
   WHERE status = "published" AND slug <> :slug
   ORDER BY RANDOM()
   LIMIT 3'
);
$recommendedQuery->execute([':slug' => (string) $post['slug']]);
$recommendedPosts = $recommendedQuery->fetchAll();
ob_start(static fn(string $html): string => public_html_absolute_urls($html, $siteBaseUrl));
$jsonLd = [
  '@context' => 'https://schema.org',
  '@graph' => [
    [
      '@type' => 'Organization',
      '@id' => $homeUrl . '#organization',
      'name' => $siteName,
      'url' => $homeUrl,
      'logo' => [
        '@type' => 'ImageObject',
        'url' => $absoluteLogo,
      ],
    ],
    [
      '@type' => 'WebSite',
      '@id' => $homeUrl . '#website',
      'url' => $homeUrl,
      'name' => $siteName,
      'publisher' => [
        '@id' => $homeUrl . '#organization',
      ],
    ],
    [
      '@type' => 'BreadcrumbList',
      '@id' => $canonical . '#breadcrumb',
      'itemListElement' => [
        [
          '@type' => 'ListItem',
          'position' => 1,
          'name' => 'Home',
          'item' => $homeUrl,
        ],
        [
          '@type' => 'ListItem',
          'position' => 2,
          'name' => 'Blog',
          'item' => $blogIndexUrl,
        ],
        [
          '@type' => 'ListItem',
          'position' => 3,
          'name' => $articleTitle,
          'item' => $canonical,
        ],
      ],
    ],
    [
      '@type' => 'BlogPosting',
      '@id' => $canonical . '#article',
      'mainEntityOfPage' => [
        '@type' => 'WebPage',
        '@id' => $canonical,
      ],
      'headline' => $seoTitle,
      'alternativeHeadline' => $articleTitle,
      'description' => $excerpt,
      'image' => [
        $absoluteImage,
      ],
      'url' => $canonical,
      'datePublished' => $publishedIso,
      'dateModified' => $modifiedIso,
      'articleSection' => $post['category'],
      'timeRequired' => 'PT' . $readMinutes . 'M',
      'author' => [
        '@type' => 'Person',
        'name' => $authorName,
      ],
      'publisher' => [
        '@id' => $homeUrl . '#organization',
      ],
    ],
  ],
];
if ($focusKeyphrase !== '') {
  $jsonLd['@graph'][3]['keywords'] = $focusKeyphrase;
}
if ($faqEntities !== []) {
  $jsonLd['@graph'][] = [
    '@type' => 'FAQPage',
    '@id' => $canonical . '#faq',
    'mainEntity' => $faqEntities,
  ];
}
?>
<!DOCTYPE html>
<html lang="en-PH" class="theme-dark" style="color-scheme: dark;">

<head>
  <script type="module">import { injectIntoGlobalHook } from "/@react-refresh";
    injectIntoGlobalHook(window);
    window.$RefreshReg$ = () => { };
    window.$RefreshSig$ = () => (type) => type;</script>

  <script type="module" src="/@vite/client"></script>

  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">

  <!-- Primary Search Engine Optimization (SEO) Meta Tags -->
  <title><?= blog_h($pageTitle) ?></title>
  <meta name="title" content="<?= blog_h($pageTitle) ?>">
  <meta name="description" content="<?= blog_h($excerpt) ?>">
  <?php if ($focusKeyphrase !== ''): ?>
    <meta name="keywords" content="<?= blog_h($focusKeyphrase) ?>">
  <?php endif; ?>
  <meta name="author" content="Tabitha Cruz">
  <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">
  <meta name="theme-color" content="#0e0a07">
  <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
  <link rel="alternate icon" href="/assets/favicon.ico">
  <link rel="apple-touch-icon" href="/assets/favicon.svg">
  <link rel="canonical" href="<?= blog_h($canonical) ?>">
  <script defer src="/assets/absolute-urls.min.js"></script>
  <script defer src="/assets/playnow-click-tracker.min.js"></script>

  <!-- Open Graph / Facebook / Social Sharing Meta -->
  <meta property="og:type" content="website">
  <meta property="og:url" content="<?= blog_h($canonical) ?>">
  <meta property="og:title" content="<?= blog_h($pageTitle) ?>">
  <meta property="og:description" content="<?= blog_h($excerpt) ?>">
  <meta property="og:image" content="<?= blog_h($absoluteImage) ?>">
  <meta property="og:site_name" content="<?= blog_h($siteName) ?>">
  <meta property="og:locale" content="en_PH">
  <meta property="og:image:width" content="1200">
  <meta property="og:image:height" content="675">
  <meta property="og:image:alt" content="<?= blog_h($articleTitle) ?>">
  <meta property="article:published_time" content="<?= blog_h($publishedIso) ?>">
  <meta property="article:modified_time" content="<?= blog_h($modifiedIso) ?>">
  <meta property="og:updated_time" content="<?= blog_h($modifiedIso) ?>">
  <meta property="article:section" content="<?= blog_h($post['category']) ?>">
  <?php if ($focusKeyphrase !== ''): ?>
    <meta property="article:tag" content="<?= blog_h($focusKeyphrase) ?>">
  <?php endif; ?>
  <?php if (is_string($fbAppId) && trim($fbAppId) !== ''): ?>
    <meta property="fb:app_id" content="<?= blog_h(trim($fbAppId)) ?>">
  <?php endif; ?>
  <!-- Twitter Card Meta -->
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:url" content="<?= blog_h($canonical) ?>">
  <meta name="twitter:title" content="<?= blog_h($pageTitle) ?>">
  <meta name="twitter:description" content="<?= blog_h($excerpt) ?>">
  <meta name="twitter:image" content="<?= blog_h($absoluteImage) ?>">
  <meta name="twitter:image:alt" content="<?= blog_h($articleTitle) ?>">

  <!-- Geo & Regional Targeting for Philippines -->
  <meta name="geo.region" content="PH">
  <meta name="geo.placename" content="Manila, Philippines">

  <!-- Performance & Preconnect Optimization -->
  <link rel="preconnect" href="https://images.unsplash.com">
  <link rel="dns-prefetch" href="https://images.unsplash.com">

  <!-- Structured Data: Organization & Online Casino Schema -->
  <script type="application/ld+json">
    <?= json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>
    </script>
  <style type="text/css" data-vite-dev-id="/Users/lanie/Sites/free games oline/src/index.css">
    /*! tailwindcss v4.3.3 | MIT License | https://tailwindcss.com */
    @layer properties;
    @layer theme, base, components, utilities;

    @layer theme {

      :root,
      :host {
        --font-sans: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue",
          "Noto Sans", Arial, sans-serif, "Apple Color Emoji", "Segoe UI Emoji",
          "Segoe UI Symbol", "Noto Color Emoji";
        --font-mono: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono",
          "Courier New", monospace;
        --color-red-300: oklch(80.8% 0.114 19.571);
        --color-red-400: oklch(70.4% 0.191 22.216);
        --color-red-500: oklch(63.7% 0.237 25.331);
        --color-red-600: oklch(57.7% 0.245 27.325);
        --color-red-700: oklch(50.5% 0.213 27.518);
        --color-red-900: oklch(39.6% 0.141 25.723);
        --color-red-950: oklch(25.8% 0.092 26.042);
        --color-orange-300: oklch(83.7% 0.128 66.29);
        --color-orange-400: oklch(75% 0.183 55.934);
        --color-orange-500: oklch(70.5% 0.213 47.604);
        --color-orange-600: oklch(64.6% 0.222 41.116);
        --color-orange-900: oklch(40.8% 0.123 38.172);
        --color-orange-950: oklch(26.6% 0.079 36.259);
        --color-amber-100: oklch(96.2% 0.059 95.617);
        --color-amber-200: oklch(92.4% 0.12 95.746);
        --color-amber-300: oklch(87.9% 0.169 91.605);
        --color-amber-400: oklch(82.8% 0.189 84.429);
        --color-amber-500: oklch(76.9% 0.188 70.08);
        --color-amber-600: oklch(66.6% 0.179 58.318);
        --color-amber-700: oklch(55.5% 0.163 48.998);
        --color-amber-900: oklch(41.4% 0.112 45.904);
        --color-amber-950: oklch(27.9% 0.077 45.635);
        --color-yellow-200: oklch(94.5% 0.129 101.54);
        --color-yellow-300: oklch(90.5% 0.182 98.111);
        --color-yellow-400: oklch(85.2% 0.199 91.936);
        --color-yellow-500: oklch(79.5% 0.184 86.047);
        --color-yellow-600: oklch(68.1% 0.162 75.834);
        --color-yellow-900: oklch(42.1% 0.095 57.708);
        --color-yellow-950: oklch(28.6% 0.066 53.813);
        --color-lime-400: oklch(84.1% 0.238 128.85);
        --color-lime-500: oklch(76.8% 0.233 130.85);
        --color-lime-950: oklch(27.4% 0.072 132.109);
        --color-green-400: oklch(79.2% 0.209 151.711);
        --color-green-500: oklch(72.3% 0.219 149.579);
        --color-green-950: oklch(26.6% 0.065 152.934);
        --color-emerald-300: oklch(84.5% 0.143 164.978);
        --color-emerald-400: oklch(76.5% 0.177 163.223);
        --color-emerald-500: oklch(69.6% 0.17 162.48);
        --color-emerald-600: oklch(59.6% 0.145 163.225);
        --color-emerald-950: oklch(26.2% 0.051 172.552);
        --color-teal-200: oklch(91% 0.096 180.426);
        --color-teal-400: oklch(77.7% 0.152 181.912);
        --color-teal-500: oklch(70.4% 0.14 182.503);
        --color-teal-600: oklch(60% 0.118 184.704);
        --color-teal-950: oklch(27.7% 0.046 192.524);
        --color-cyan-300: oklch(86.5% 0.127 207.078);
        --color-cyan-400: oklch(78.9% 0.154 211.53);
        --color-cyan-500: oklch(71.5% 0.143 215.221);
        --color-cyan-950: oklch(30.2% 0.056 229.695);
        --color-sky-300: oklch(82.8% 0.111 230.318);
        --color-sky-400: oklch(74.6% 0.16 232.661);
        --color-sky-500: oklch(68.5% 0.169 237.323);
        --color-sky-600: oklch(58.8% 0.158 241.966);
        --color-sky-950: oklch(29.3% 0.066 243.157);
        --color-blue-300: oklch(80.9% 0.105 251.813);
        --color-blue-400: oklch(70.7% 0.165 254.624);
        --color-blue-500: oklch(62.3% 0.214 259.815);
        --color-blue-600: oklch(54.6% 0.245 262.881);
        --color-blue-950: oklch(28.2% 0.091 267.935);
        --color-indigo-600: oklch(51.1% 0.262 276.966);
        --color-indigo-950: oklch(25.7% 0.09 281.288);
        --color-purple-200: oklch(90.2% 0.063 306.703);
        --color-purple-300: oklch(82.7% 0.119 306.383);
        --color-purple-400: oklch(71.4% 0.203 305.504);
        --color-purple-500: oklch(62.7% 0.265 303.9);
        --color-purple-600: oklch(55.8% 0.288 302.321);
        --color-purple-950: oklch(29.1% 0.149 302.717);
        --color-fuchsia-950: oklch(29.3% 0.136 325.661);
        --color-pink-300: oklch(82.3% 0.12 346.018);
        --color-pink-400: oklch(71.8% 0.202 349.761);
        --color-pink-500: oklch(65.6% 0.241 354.308);
        --color-pink-950: oklch(28.4% 0.109 3.907);
        --color-rose-200: oklch(89.2% 0.058 10.001);
        --color-rose-300: oklch(81% 0.117 11.638);
        --color-rose-400: oklch(71.2% 0.194 13.428);
        --color-rose-500: oklch(64.5% 0.246 16.439);
        --color-rose-950: oklch(27.1% 0.105 12.094);
        --color-slate-100: oklch(96.8% 0.007 247.896);
        --color-slate-200: oklch(92.9% 0.013 255.508);
        --color-slate-300: oklch(86.9% 0.022 252.894);
        --color-slate-400: oklch(70.4% 0.04 256.788);
        --color-slate-800: oklch(27.9% 0.041 260.031);
        --color-slate-900: oklch(20.8% 0.042 265.755);
        --color-slate-950: oklch(12.9% 0.042 264.695);
        --color-stone-100: oklch(97% 0.001 106.424);
        --color-stone-200: oklch(92.3% 0.003 48.717);
        --color-stone-300: oklch(86.9% 0.005 56.366);
        --color-stone-400: oklch(70.9% 0.01 56.259);
        --color-stone-500: oklch(55.3% 0.013 58.071);
        --color-stone-600: oklch(44.4% 0.011 73.639);
        --color-stone-700: oklch(37.4% 0.01 67.558);
        --color-stone-800: oklch(26.8% 0.007 34.298);
        --color-stone-900: oklch(21.6% 0.006 56.043);
        --color-stone-950: oklch(14.7% 0.004 49.25);
        --color-black: #000;
        --color-white: #fff;
        --spacing: 0.25rem;
        --container-xs: 20rem;
        --container-sm: 24rem;
        --container-md: 28rem;
        --container-lg: 32rem;
        --container-xl: 36rem;
        --container-2xl: 42rem;
        --container-3xl: 48rem;
        --container-4xl: 56rem;
        --text-xs: 0.75rem;
        --text-xs--line-height: calc(1 / 0.75);
        --text-sm: 0.875rem;
        --text-sm--line-height: calc(1.25 / 0.875);
        --text-base: 1rem;
        --text-base--line-height: calc(1.5 / 1);
        --text-lg: 1.125rem;
        --text-lg--line-height: calc(1.75 / 1.125);
        --text-xl: 1.25rem;
        --text-xl--line-height: calc(1.75 / 1.25);
        --text-2xl: 1.5rem;
        --text-2xl--line-height: calc(2 / 1.5);
        --text-3xl: 1.875rem;
        --text-3xl--line-height: calc(2.25 / 1.875);
        --text-4xl: 2.25rem;
        --text-4xl--line-height: calc(2.5 / 2.25);
        --text-5xl: 3rem;
        --text-5xl--line-height: 1;
        --text-6xl: 3.75rem;
        --text-6xl--line-height: 1;
        --font-weight-normal: 400;
        --font-weight-medium: 500;
        --font-weight-semibold: 600;
        --font-weight-bold: 700;
        --font-weight-extrabold: 800;
        --font-weight-black: 900;
        --tracking-tighter: -0.05em;
        --tracking-tight: -0.025em;
        --tracking-wide: 0.025em;
        --tracking-wider: 0.05em;
        --tracking-widest: 0.1em;
        --leading-tight: 1.25;
        --leading-snug: 1.375;
        --leading-relaxed: 1.625;
        --radius-md: 0.375rem;
        --radius-lg: 0.5rem;
        --radius-xl: 0.75rem;
        --radius-2xl: 1rem;
        --radius-3xl: 1.5rem;
        --drop-shadow-md: 0 3px 3px rgb(0 0 0 / 0.12);
        --ease-in: cubic-bezier(0.4, 0, 1, 1);
        --ease-out: cubic-bezier(0, 0, 0.2, 1);
        --ease-in-out: cubic-bezier(0.4, 0, 0.2, 1);
        --animate-spin: spin 1s linear infinite;
        --animate-ping: ping 1s cubic-bezier(0, 0, 0.2, 1) infinite;
        --animate-pulse: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        --animate-bounce: bounce 1s infinite;
        --blur-sm: 8px;
        --blur-md: 12px;
        --blur-2xl: 40px;
        --blur-3xl: 64px;
        --aspect-video: 16 / 9;
        --default-transition-duration: 150ms;
        --default-transition-timing-function: cubic-bezier(0.4, 0, 0.2, 1);
        --default-font-family: var(--font-sans);
        --default-mono-font-family: var(--font-mono);
      }
    }

    @layer base {

      *,
      ::after,
      ::before,
      ::backdrop,
      ::file-selector-button {
        box-sizing: border-box;
        margin: 0;
        padding: 0;
        border: 0 solid;
      }

      html,
      :host {
        line-height: 1.5;
        -webkit-text-size-adjust: 100%;
        tab-size: 4;
        font-family: var(--default-font-family, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", "Noto Sans", Arial, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol", "Noto Color Emoji");
        font-feature-settings: var(--default-font-feature-settings, normal);
        font-variation-settings: var(--default-font-variation-settings, normal);
        -webkit-tap-highlight-color: transparent;
      }

      hr {
        height: 0;
        color: inherit;
        border-top-width: 1px;
      }

      abbr:where([title]) {
        -webkit-text-decoration: underline dotted;
        text-decoration: underline dotted;
      }

      h1,
      h2,
      h3,
      h4,
      h5,
      h6 {
        font-size: inherit;
        font-weight: inherit;
      }

      a {
        color: inherit;
        -webkit-text-decoration: inherit;
        text-decoration: inherit;
      }

      b,
      strong {
        font-weight: bolder;
      }

      code,
      kbd,
      samp,
      pre {
        font-family: var(--default-mono-font-family, ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace);
        font-feature-settings: var(--default-mono-font-feature-settings, normal);
        font-variation-settings: var(--default-mono-font-variation-settings, normal);
        font-size: 1em;
      }

      small {
        font-size: 80%;
      }

      sub,
      sup {
        font-size: 75%;
        line-height: 0;
        position: relative;
        vertical-align: baseline;
      }

      sub {
        bottom: -0.25em;
      }

      sup {
        top: -0.5em;
      }

      table {
        text-indent: 0;
        border-color: inherit;
        border-collapse: collapse;
      }

      :-moz-focusring:where(:not(iframe)) {
        outline: auto;
      }

      progress {
        vertical-align: baseline;
      }

      summary {
        display: list-item;
      }

      ol,
      ul,
      menu {
        list-style: none;
      }

      img,
      svg,
      video,
      canvas,
      audio,
      iframe,
      embed,
      object {
        display: block;
        vertical-align: middle;
      }

      img,
      video {
        max-width: 100%;
        height: auto;
      }

      button,
      input,
      select,
      optgroup,
      textarea,
      ::file-selector-button {
        font: inherit;
        font-feature-settings: inherit;
        font-variation-settings: inherit;
        letter-spacing: inherit;
        color: inherit;
        border-radius: 0;
        background-color: transparent;
        opacity: 1;
      }

      :where(select:is([multiple], [size])) optgroup {
        font-weight: bolder;
      }

      :where(select:is([multiple], [size])) optgroup option {
        padding-inline-start: 20px;
      }

      ::file-selector-button {
        margin-inline-end: 4px;
      }

      ::placeholder {
        opacity: 1;
      }

      @supports (not (-webkit-appearance: -apple-pay-button)) or (contain-intrinsic-size: 1px) {
        ::placeholder {
          color: currentcolor;

          @supports (color: color-mix(in lab, red, red)) {
            color: color-mix(in oklab, currentcolor 50%, transparent);
          }
        }
      }

      textarea {
        resize: vertical;
      }

      ::-webkit-search-decoration {
        -webkit-appearance: none;
      }

      ::-webkit-date-and-time-value {
        min-height: 1lh;
        text-align: inherit;
      }

      ::-webkit-datetime-edit {
        display: inline-flex;
      }

      ::-webkit-datetime-edit-fields-wrapper {
        padding: 0;
      }

      ::-webkit-datetime-edit,
      ::-webkit-datetime-edit-year-field,
      ::-webkit-datetime-edit-month-field,
      ::-webkit-datetime-edit-day-field,
      ::-webkit-datetime-edit-hour-field,
      ::-webkit-datetime-edit-minute-field,
      ::-webkit-datetime-edit-second-field,
      ::-webkit-datetime-edit-millisecond-field,
      ::-webkit-datetime-edit-meridiem-field {
        padding-block: 0;
      }

      ::-webkit-calendar-picker-indicator {
        line-height: 1;
      }

      :-moz-ui-invalid {
        box-shadow: none;
      }

      button,
      input:where([type="button"], [type="reset"], [type="submit"]),
      ::file-selector-button {
        appearance: button;
      }

      ::-webkit-inner-spin-button,
      ::-webkit-outer-spin-button {
        height: auto;
      }

      [hidden]:where(:not([hidden="until-found"])) {
        display: none !important;
      }
    }

    @layer utilities {
      .pointer-events-none {
        pointer-events: none;
      }

      .visible {
        visibility: visible;
      }

      .absolute {
        position: absolute;
      }

      .fixed {
        position: fixed;
      }

      .relative {
        position: relative;
      }

      .sticky {
        position: sticky;
      }

      .-inset-1 {
        inset: calc(var(--spacing) * -1);
      }

      .inset-0 {
        inset: 0px;
      }

      .-top-0\.5 {
        top: calc(var(--spacing) * -0.5);
      }

      .-top-1 {
        top: calc(var(--spacing) * -1);
      }

      .-top-2 {
        top: calc(var(--spacing) * -2);
      }

      .-top-3 {
        top: calc(var(--spacing) * -3);
      }

      .-top-10 {
        top: calc(var(--spacing) * -10);
      }

      .-top-12 {
        top: calc(var(--spacing) * -12);
      }

      .-top-24 {
        top: calc(var(--spacing) * -24);
      }

      .top-0 {
        top: 0px;
      }

      .top-1\.5 {
        top: calc(var(--spacing) * 1.5);
      }

      .top-1\/2 {
        top: calc(1 / 2 * 100%);
      }

      .top-2\.5 {
        top: calc(var(--spacing) * 2.5);
      }

      .top-3 {
        top: calc(var(--spacing) * 3);
      }

      .top-3\.5 {
        top: calc(var(--spacing) * 3.5);
      }

      .top-\[101px\] {
        top: 101px;
      }

      .-right-0\.5 {
        right: calc(var(--spacing) * -0.5);
      }

      .-right-1 {
        right: calc(var(--spacing) * -1);
      }

      .-right-2 {
        right: calc(var(--spacing) * -2);
      }

      .-right-3 {
        right: calc(var(--spacing) * -3);
      }

      .-right-10 {
        right: calc(var(--spacing) * -10);
      }

      .-right-12 {
        right: calc(var(--spacing) * -12);
      }

      .-right-24 {
        right: calc(var(--spacing) * -24);
      }

      .right-0 {
        right: 0px;
      }

      .right-1\.5 {
        right: calc(var(--spacing) * 1.5);
      }

      .right-1\/4 {
        right: calc(1 / 4 * 100%);
      }

      .right-2 {
        right: calc(var(--spacing) * 2);
      }

      .right-2\.5 {
        right: calc(var(--spacing) * 2.5);
      }

      .right-3 {
        right: calc(var(--spacing) * 3);
      }

      .right-3\.5 {
        right: calc(var(--spacing) * 3.5);
      }

      .right-4 {
        right: calc(var(--spacing) * 4);
      }

      .-bottom-1 {
        bottom: calc(var(--spacing) * -1);
      }

      .-bottom-10 {
        bottom: calc(var(--spacing) * -10);
      }

      .-bottom-12 {
        bottom: calc(var(--spacing) * -12);
      }

      .-bottom-24 {
        bottom: calc(var(--spacing) * -24);
      }

      .bottom-0 {
        bottom: 0px;
      }

      .bottom-1 {
        bottom: var(--spacing);
      }

      .bottom-2 {
        bottom: calc(var(--spacing) * 2);
      }

      .bottom-3 {
        bottom: calc(var(--spacing) * 3);
      }

      .bottom-4 {
        bottom: calc(var(--spacing) * 4);
      }

      .bottom-20 {
        bottom: calc(var(--spacing) * 20);
      }

      .-left-10 {
        left: calc(var(--spacing) * -10);
      }

      .-left-12 {
        left: calc(var(--spacing) * -12);
      }

      .-left-24 {
        left: calc(var(--spacing) * -24);
      }

      .left-0 {
        left: 0px;
      }

      .left-1 {
        left: var(--spacing);
      }

      .left-1\.5 {
        left: calc(var(--spacing) * 1.5);
      }

      .left-1\/2 {
        left: calc(1 / 2 * 100%);
      }

      .left-2 {
        left: calc(var(--spacing) * 2);
      }

      .left-2\.5 {
        left: calc(var(--spacing) * 2.5);
      }

      .left-3 {
        left: calc(var(--spacing) * 3);
      }

      .left-3\.5 {
        left: calc(var(--spacing) * 3.5);
      }

      .left-4 {
        left: calc(var(--spacing) * 4);
      }

      .z-0 {
        z-index: 0;
      }

      .z-10 {
        z-index: 10;
      }

      .z-20 {
        z-index: 20;
      }

      .z-40 {
        z-index: 40;
      }

      .z-50 {
        z-index: 50;
      }

      .z-\[35\] {
        z-index: 35;
      }

      .order-3 {
        order: 3;
      }

      .col-span-2 {
        grid-column: span 2 / span 2;
      }

      .-mx-3 {
        margin-inline: calc(var(--spacing) * -3);
      }

      .mx-auto {
        margin-inline: auto;
      }

      .my-0\.5 {
        margin-block: calc(var(--spacing) * 0.5);
      }

      .my-2 {
        margin-block: calc(var(--spacing) * 2);
      }

      .my-8 {
        margin-block: calc(var(--spacing) * 8);
      }

      .my-10 {
        margin-block: calc(var(--spacing) * 10);
      }

      .-mt-3 {
        margin-top: calc(var(--spacing) * -3);
      }

      .-mt-10 {
        margin-top: calc(var(--spacing) * -10);
      }

      .mt-0\.5 {
        margin-top: calc(var(--spacing) * 0.5);
      }

      .mt-1 {
        margin-top: var(--spacing);
      }

      .mt-1\.5 {
        margin-top: calc(var(--spacing) * 1.5);
      }

      .mt-2 {
        margin-top: calc(var(--spacing) * 2);
      }

      .mt-2\.5 {
        margin-top: calc(var(--spacing) * 2.5);
      }

      .mt-3 {
        margin-top: calc(var(--spacing) * 3);
      }

      .-mr-10 {
        margin-right: calc(var(--spacing) * -10);
      }

      .mr-2 {
        margin-right: calc(var(--spacing) * 2);
      }

      .mb-2 {
        margin-bottom: calc(var(--spacing) * 2);
      }

      .mb-3 {
        margin-bottom: calc(var(--spacing) * 3);
      }

      .ml-0\.5 {
        margin-left: calc(var(--spacing) * 0.5);
      }

      .ml-1 {
        margin-left: var(--spacing);
      }

      .ml-2 {
        margin-left: calc(var(--spacing) * 2);
      }

      .line-clamp-1 {
        overflow: hidden;
        display: -webkit-box;
        -webkit-box-orient: vertical;
        -webkit-line-clamp: 1;
      }

      .line-clamp-2 {
        overflow: hidden;
        display: -webkit-box;
        -webkit-box-orient: vertical;
        -webkit-line-clamp: 2;
      }

      .line-clamp-3 {
        overflow: hidden;
        display: -webkit-box;
        -webkit-box-orient: vertical;
        -webkit-line-clamp: 3;
      }

      .block {
        display: block;
      }

      .contents {
        display: contents;
      }

      .flex {
        display: flex;
      }

      .grid {
        display: grid;
      }

      .hidden {
        display: none;
      }

      .inline {
        display: inline;
      }

      .inline-block {
        display: inline-block;
      }

      .inline-flex {
        display: inline-flex;
      }

      .table {
        display: table;
      }

      .aspect-\[4\/3\] {
        aspect-ratio: 4/3;
      }

      .aspect-square {
        aspect-ratio: 1 / 1;
      }

      .aspect-video {
        aspect-ratio: var(--aspect-video);
      }

      .h-1\.5 {
        height: calc(var(--spacing) * 1.5);
      }

      .h-2 {
        height: calc(var(--spacing) * 2);
      }

      .h-2\.5 {
        height: calc(var(--spacing) * 2.5);
      }

      .h-3 {
        height: calc(var(--spacing) * 3);
      }

      .h-3\.5 {
        height: calc(var(--spacing) * 3.5);
      }

      .h-4 {
        height: calc(var(--spacing) * 4);
      }

      .h-5 {
        height: calc(var(--spacing) * 5);
      }

      .h-6 {
        height: calc(var(--spacing) * 6);
      }

      .h-7 {
        height: calc(var(--spacing) * 7);
      }

      .h-8 {
        height: calc(var(--spacing) * 8);
      }

      .h-9 {
        height: calc(var(--spacing) * 9);
      }

      .h-10 {
        height: calc(var(--spacing) * 10);
      }

      .h-11 {
        height: calc(var(--spacing) * 11);
      }

      .h-12 {
        height: calc(var(--spacing) * 12);
      }

      .h-16 {
        height: calc(var(--spacing) * 16);
      }

      .h-20 {
        height: calc(var(--spacing) * 20);
      }

      .h-24 {
        height: calc(var(--spacing) * 24);
      }

      .h-32 {
        height: calc(var(--spacing) * 32);
      }

      .h-40 {
        height: calc(var(--spacing) * 40);
      }

      .h-44 {
        height: calc(var(--spacing) * 44);
      }

      .h-48 {
        height: calc(var(--spacing) * 48);
      }

      .h-56 {
        height: calc(var(--spacing) * 56);
      }

      .h-60 {
        height: calc(var(--spacing) * 60);
      }

      .h-64 {
        height: calc(var(--spacing) * 64);
      }

      .h-72 {
        height: calc(var(--spacing) * 72);
      }

      .h-80 {
        height: calc(var(--spacing) * 80);
      }

      .h-96 {
        height: calc(var(--spacing) * 96);
      }

      .h-\[2px\] {
        height: 2px;
      }

      .h-\[90vh\] {
        height: 90vh;
      }

      .h-\[460px\] {
        height: 460px;
      }

      .h-\[520px\] {
        height: 520px;
      }

      .h-\[540px\] {
        height: 540px;
      }

      .h-\[calc\(100vh-101px\)\] {
        height: calc(100vh - 101px);
      }

      .h-full {
        height: 100%;
      }

      .max-h-60 {
        max-height: calc(var(--spacing) * 60);
      }

      .max-h-\[80vh\] {
        max-height: 80vh;
      }

      .max-h-\[85vh\] {
        max-height: 85vh;
      }

      .max-h-\[90vh\] {
        max-height: 90vh;
      }

      .max-h-\[700px\] {
        max-height: 700px;
      }

      .max-h-full {
        max-height: 100%;
      }

      .min-h-9 {
        min-height: calc(var(--spacing) * 9);
      }

      .min-h-\[96px\] {
        min-height: 96px;
      }

      .min-h-\[180px\] {
        min-height: 180px;
      }

      .min-h-\[460px\] {
        min-height: 460px;
      }

      .min-h-\[480px\] {
        min-height: 480px;
      }

      .min-h-screen {
        min-height: 100vh;
      }

      .w-1\.5 {
        width: calc(var(--spacing) * 1.5);
      }

      .w-2 {
        width: calc(var(--spacing) * 2);
      }

      .w-2\.5 {
        width: calc(var(--spacing) * 2.5);
      }

      .w-2\/5 {
        width: calc(2 / 5 * 100%);
      }

      .w-3 {
        width: calc(var(--spacing) * 3);
      }

      .w-3\.5 {
        width: calc(var(--spacing) * 3.5);
      }

      .w-4 {
        width: calc(var(--spacing) * 4);
      }

      .w-5 {
        width: calc(var(--spacing) * 5);
      }

      .w-6 {
        width: calc(var(--spacing) * 6);
      }

      .w-7 {
        width: calc(var(--spacing) * 7);
      }

      .w-8 {
        width: calc(var(--spacing) * 8);
      }

      .w-9 {
        width: calc(var(--spacing) * 9);
      }

      .w-10 {
        width: calc(var(--spacing) * 10);
      }

      .w-11 {
        width: calc(var(--spacing) * 11);
      }

      .w-12 {
        width: calc(var(--spacing) * 12);
      }

      .w-14 {
        width: calc(var(--spacing) * 14);
      }

      .w-16 {
        width: calc(var(--spacing) * 16);
      }

      .w-20 {
        width: calc(var(--spacing) * 20);
      }

      .w-24 {
        width: calc(var(--spacing) * 24);
      }

      .w-32 {
        width: calc(var(--spacing) * 32);
      }

      .w-40 {
        width: calc(var(--spacing) * 40);
      }

      .w-48 {
        width: calc(var(--spacing) * 48);
      }

      .w-60 {
        width: calc(var(--spacing) * 60);
      }

      .w-72 {
        width: calc(var(--spacing) * 72);
      }

      .w-80 {
        width: calc(var(--spacing) * 80);
      }

      .w-96 {
        width: calc(var(--spacing) * 96);
      }

      .w-\[42vw\] {
        width: 42vw;
      }

      .w-\[92vw\] {
        width: 92vw;
      }

      .w-auto {
        width: auto;
      }

      .w-fit {
        width: fit-content;
      }

      .w-full {
        width: 100%;
      }

      .max-w-2xl {
        max-width: var(--container-2xl);
      }

      .max-w-3xl {
        max-width: var(--container-3xl);
      }

      .max-w-4xl {
        max-width: var(--container-4xl);
      }

      .max-w-\[82vw\] {
        max-width: 82vw;
      }

      .max-w-\[85\%\] {
        max-width: 85%;
      }

      .max-w-\[110px\] {
        max-width: 110px;
      }

      .max-w-\[112px\] {
        max-width: 112px;
      }

      .max-w-\[150px\] {
        max-width: 150px;
      }

      .max-w-\[172px\] {
        max-width: 172px;
      }

      .max-w-\[180px\] {
        max-width: 180px;
      }

      .max-w-\[240px\] {
        max-width: 240px;
      }

      .max-w-\[260px\] {
        max-width: 260px;
      }

      .max-w-full {
        max-width: 100%;
      }

      .max-w-lg {
        max-width: var(--container-lg);
      }

      .max-w-md {
        max-width: var(--container-md);
      }

      .max-w-sm {
        max-width: var(--container-sm);
      }

      .max-w-xl {
        max-width: var(--container-xl);
      }

      .max-w-xs {
        max-width: var(--container-xs);
      }

      .min-w-0 {
        min-width: 0px;
      }

      .min-w-36 {
        min-width: calc(var(--spacing) * 36);
      }

      .min-w-\[142px\] {
        min-width: 142px;
      }

      .flex-1 {
        flex: 1;
      }

      .shrink-0 {
        flex-shrink: 0;
      }

      .grow {
        flex-grow: 1;
      }

      .border-collapse {
        border-collapse: collapse;
      }

      .-translate-x-1\/2 {
        --tw-translate-x: calc(calc(1 / 2 * 100%) * -1);
        translate: var(--tw-translate-x) var(--tw-translate-y);
      }

      .translate-x-0\.5 {
        --tw-translate-x: calc(var(--spacing) * 0.5);
        translate: var(--tw-translate-x) var(--tw-translate-y);
      }

      .translate-x-1 {
        --tw-translate-x: var(--spacing);
        translate: var(--tw-translate-x) var(--tw-translate-y);
      }

      .-translate-y-1\/2 {
        --tw-translate-y: calc(calc(1 / 2 * 100%) * -1);
        translate: var(--tw-translate-x) var(--tw-translate-y);
      }

      .scale-75 {
        --tw-scale-x: 75%;
        --tw-scale-y: 75%;
        --tw-scale-z: 75%;
        scale: var(--tw-scale-x) var(--tw-scale-y);
      }

      .scale-95 {
        --tw-scale-x: 95%;
        --tw-scale-y: 95%;
        --tw-scale-z: 95%;
        scale: var(--tw-scale-x) var(--tw-scale-y);
      }

      .scale-100 {
        --tw-scale-x: 100%;
        --tw-scale-y: 100%;
        --tw-scale-z: 100%;
        scale: var(--tw-scale-x) var(--tw-scale-y);
      }

      .rotate-2 {
        rotate: 2deg;
      }

      .rotate-6 {
        rotate: 6deg;
      }

      .rotate-180 {
        rotate: 180deg;
      }

      .transform {
        transform: var(--tw-rotate-x, ) var(--tw-rotate-y, ) var(--tw-rotate-z, ) var(--tw-skew-x, ) var(--tw-skew-y, );
      }

      .animate-bounce {
        animation: var(--animate-bounce);
      }

      .animate-ping {
        animation: var(--animate-ping);
      }

      .animate-pulse {
        animation: var(--animate-pulse);
      }

      .animate-spin {
        animation: var(--animate-spin);
      }

      .cursor-default {
        cursor: default;
      }

      .cursor-not-allowed {
        cursor: not-allowed;
      }

      .cursor-pointer {
        cursor: pointer;
      }

      .resize {
        resize: both;
      }

      .snap-x {
        scroll-snap-type: x var(--tw-scroll-snap-strictness);
      }

      .snap-mandatory {
        --tw-scroll-snap-strictness: mandatory;
      }

      .snap-start {
        scroll-snap-align: start;
      }

      .scroll-mt-20 {
        scroll-margin-top: calc(var(--spacing) * 20);
      }

      .scroll-mt-24 {
        scroll-margin-top: calc(var(--spacing) * 24);
      }

      .scrollbar-none {
        scrollbar-width: none;
      }

      .scrollbar-thin {
        scrollbar-width: thin;
      }

      .list-inside {
        list-style-position: inside;
      }

      .list-disc {
        list-style-type: disc;
      }

      .appearance-none {
        appearance: none;
      }

      .grid-cols-1 {
        grid-template-columns: repeat(1, minmax(0, 1fr));
      }

      .grid-cols-2 {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }

      .grid-cols-3 {
        grid-template-columns: repeat(3, minmax(0, 1fr));
      }

      .grid-cols-4 {
        grid-template-columns: repeat(4, minmax(0, 1fr));
      }

      .grid-cols-5 {
        grid-template-columns: repeat(5, minmax(0, 1fr));
      }

      .flex-col {
        flex-direction: column;
      }

      .flex-row {
        flex-direction: row;
      }

      .flex-wrap {
        flex-wrap: wrap;
      }

      .items-center {
        align-items: center;
      }

      .items-end {
        align-items: flex-end;
      }

      .items-start {
        align-items: flex-start;
      }

      .justify-around {
        justify-content: space-around;
      }

      .justify-between {
        justify-content: space-between;
      }

      .justify-center {
        justify-content: center;
      }

      .justify-end {
        justify-content: flex-end;
      }

      .justify-start {
        justify-content: flex-start;
      }

      .gap-0\.5 {
        gap: calc(var(--spacing) * 0.5);
      }

      .gap-1 {
        gap: var(--spacing);
      }

      .gap-1\.5 {
        gap: calc(var(--spacing) * 1.5);
      }

      .gap-2 {
        gap: calc(var(--spacing) * 2);
      }

      .gap-2\.5 {
        gap: calc(var(--spacing) * 2.5);
      }

      .gap-3 {
        gap: calc(var(--spacing) * 3);
      }

      .gap-4 {
        gap: calc(var(--spacing) * 4);
      }

      .gap-5 {
        gap: calc(var(--spacing) * 5);
      }

      .gap-6 {
        gap: calc(var(--spacing) * 6);
      }

      .gap-8 {
        gap: calc(var(--spacing) * 8);
      }

      :where(.space-y-0\.5 > :not(:last-child)) {
        --tw-space-y-reverse: 0;
        margin-block-start: calc(calc(var(--spacing) * 0.5) * var(--tw-space-y-reverse));
        margin-block-end: calc(calc(var(--spacing) * 0.5) * calc(1 - var(--tw-space-y-reverse)));
      }

      :where(.space-y-1 > :not(:last-child)) {
        --tw-space-y-reverse: 0;
        margin-block-start: calc(var(--spacing) * var(--tw-space-y-reverse));
        margin-block-end: calc(var(--spacing) * calc(1 - var(--tw-space-y-reverse)));
      }

      :where(.space-y-1\.5 > :not(:last-child)) {
        --tw-space-y-reverse: 0;
        margin-block-start: calc(calc(var(--spacing) * 1.5) * var(--tw-space-y-reverse));
        margin-block-end: calc(calc(var(--spacing) * 1.5) * calc(1 - var(--tw-space-y-reverse)));
      }

      :where(.space-y-2 > :not(:last-child)) {
        --tw-space-y-reverse: 0;
        margin-block-start: calc(calc(var(--spacing) * 2) * var(--tw-space-y-reverse));
        margin-block-end: calc(calc(var(--spacing) * 2) * calc(1 - var(--tw-space-y-reverse)));
      }

      :where(.space-y-2\.5 > :not(:last-child)) {
        --tw-space-y-reverse: 0;
        margin-block-start: calc(calc(var(--spacing) * 2.5) * var(--tw-space-y-reverse));
        margin-block-end: calc(calc(var(--spacing) * 2.5) * calc(1 - var(--tw-space-y-reverse)));
      }

      :where(.space-y-3 > :not(:last-child)) {
        --tw-space-y-reverse: 0;
        margin-block-start: calc(calc(var(--spacing) * 3) * var(--tw-space-y-reverse));
        margin-block-end: calc(calc(var(--spacing) * 3) * calc(1 - var(--tw-space-y-reverse)));
      }

      :where(.space-y-3\.5 > :not(:last-child)) {
        --tw-space-y-reverse: 0;
        margin-block-start: calc(calc(var(--spacing) * 3.5) * var(--tw-space-y-reverse));
        margin-block-end: calc(calc(var(--spacing) * 3.5) * calc(1 - var(--tw-space-y-reverse)));
      }

      :where(.space-y-4 > :not(:last-child)) {
        --tw-space-y-reverse: 0;
        margin-block-start: calc(calc(var(--spacing) * 4) * var(--tw-space-y-reverse));
        margin-block-end: calc(calc(var(--spacing) * 4) * calc(1 - var(--tw-space-y-reverse)));
      }

      :where(.space-y-5 > :not(:last-child)) {
        --tw-space-y-reverse: 0;
        margin-block-start: calc(calc(var(--spacing) * 5) * var(--tw-space-y-reverse));
        margin-block-end: calc(calc(var(--spacing) * 5) * calc(1 - var(--tw-space-y-reverse)));
      }

      :where(.space-y-6 > :not(:last-child)) {
        --tw-space-y-reverse: 0;
        margin-block-start: calc(calc(var(--spacing) * 6) * var(--tw-space-y-reverse));
        margin-block-end: calc(calc(var(--spacing) * 6) * calc(1 - var(--tw-space-y-reverse)));
      }

      :where(.space-y-8 > :not(:last-child)) {
        --tw-space-y-reverse: 0;
        margin-block-start: calc(calc(var(--spacing) * 8) * var(--tw-space-y-reverse));
        margin-block-end: calc(calc(var(--spacing) * 8) * calc(1 - var(--tw-space-y-reverse)));
      }

      :where(.space-y-10 > :not(:last-child)) {
        --tw-space-y-reverse: 0;
        margin-block-start: calc(calc(var(--spacing) * 10) * var(--tw-space-y-reverse));
        margin-block-end: calc(calc(var(--spacing) * 10) * calc(1 - var(--tw-space-y-reverse)));
      }

      :where(.divide-y > :not(:last-child)) {
        --tw-divide-y-reverse: 0;
        border-bottom-style: var(--tw-border-style);
        border-top-style: var(--tw-border-style);
        border-top-width: calc(1px * var(--tw-divide-y-reverse));
        border-bottom-width: calc(1px * calc(1 - var(--tw-divide-y-reverse)));
      }

      :where(.divide-\[\#2d1b0f\] > :not(:last-child)) {
        border-color: #2d1b0f;
      }

      .self-start {
        align-self: flex-start;
      }

      .truncate {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
      }

      .overflow-hidden {
        overflow: hidden;
      }

      .overflow-x-auto {
        overflow-x: auto;
      }

      .overflow-x-hidden {
        overflow-x: hidden;
      }

      .overflow-y-auto {
        overflow-y: auto;
      }

      .overscroll-x-contain {
        overscroll-behavior-x: contain;
      }

      .rounded {
        border-radius: 0.25rem;
      }

      .rounded-2xl {
        border-radius: var(--radius-2xl);
      }

      .rounded-3xl {
        border-radius: var(--radius-3xl);
      }

      .rounded-\[6px\] {
        border-radius: 6px;
      }

      .rounded-\[12px\] {
        border-radius: 12px;
      }

      .rounded-\[14px\] {
        border-radius: 14px;
      }

      .rounded-full {
        border-radius: calc(infinity * 1px);
      }

      .rounded-lg {
        border-radius: var(--radius-lg);
      }

      .rounded-md {
        border-radius: var(--radius-md);
      }

      .rounded-xl {
        border-radius: var(--radius-xl);
      }

      .rounded-br-none {
        border-bottom-right-radius: 0;
      }

      .rounded-bl-none {
        border-bottom-left-radius: 0;
      }

      .border {
        border-style: var(--tw-border-style);
        border-width: 1px;
      }

      .border-2 {
        border-style: var(--tw-border-style);
        border-width: 2px;
      }

      .border-4 {
        border-style: var(--tw-border-style);
        border-width: 4px;
      }

      .border-y {
        border-block-style: var(--tw-border-style);
        border-block-width: 1px;
      }

      .border-t {
        border-top-style: var(--tw-border-style);
        border-top-width: 1px;
      }

      .border-r {
        border-right-style: var(--tw-border-style);
        border-right-width: 1px;
      }

      .border-b {
        border-bottom-style: var(--tw-border-style);
        border-bottom-width: 1px;
      }

      .border-\[\#1d1108\] {
        border-color: #1d1108;
      }

      .border-\[\#2a180c\] {
        border-color: #2a180c;
      }

      .border-\[\#2b1a0e\] {
        border-color: #2b1a0e;
      }

      .border-\[\#2b170a\] {
        border-color: #2b170a;
      }

      .border-\[\#2b170c\] {
        border-color: #2b170c;
      }

      .border-\[\#2b180c\] {
        border-color: #2b180c;
      }

      .border-\[\#2b190d\] {
        border-color: #2b190d;
      }

      .border-\[\#2c1a0e\] {
        border-color: #2c1a0e;
      }

      .border-\[\#2c1b10\] {
        border-color: #2c1b10;
      }

      .border-\[\#2c190d\] {
        border-color: #2c190d;
      }

      .border-\[\#2d1a0d\] {
        border-color: #2d1a0d;
      }

      .border-\[\#2d1a0e\] {
        border-color: #2d1a0e;
      }

      .border-\[\#2d1b0e\] {
        border-color: #2d1b0e;
      }

      .border-\[\#2d1b0f\] {
        border-color: #2d1b0f;
      }

      .border-\[\#2d1b0f\]\/60 {
        border-color: color-mix(in oklab, #2d1b0f 60%, transparent);
      }

      .border-\[\#2d1b10\] {
        border-color: #2d1b10;
      }

      .border-\[\#2d1e13\] {
        border-color: #2d1e13;
      }

      .border-\[\#2d180d\] {
        border-color: #2d180d;
      }

      .border-\[\#2e1a0e\] {
        border-color: #2e1a0e;
      }

      .border-\[\#2e1b0f\] {
        border-color: #2e1b0f;
      }

      .border-\[\#2e1c0e\] {
        border-color: #2e1c0e;
      }

      .border-\[\#2e1c10\] {
        border-color: #2e1c10;
      }

      .border-\[\#2e180c\] {
        border-color: #2e180c;
      }

      .border-\[\#3a2213\] {
        border-color: #3a2213;
      }

      .border-\[\#3b2313\] {
        border-color: #3b2313;
      }

      .border-\[\#3b2515\] {
        border-color: #3b2515;
      }

      .border-\[\#3c2515\] {
        border-color: #3c2515;
      }

      .border-\[\#3d2414\] {
        border-color: #3d2414;
      }

      .border-\[\#3d2719\] {
        border-color: #3d2719;
      }

      .border-\[\#3e2719\] {
        border-color: #3e2719;
      }

      .border-\[\#3f2716\] {
        border-color: #3f2716;
      }

      .border-\[\#25D366\]\/50 {
        border-color: color-mix(in oklab, #25D366 50%, transparent);
      }

      .border-\[\#229ED9\]\/40 {
        border-color: color-mix(in oklab, #229ED9 40%, transparent);
      }

      .border-\[\#229ED9\]\/50 {
        border-color: color-mix(in oklab, #229ED9 50%, transparent);
      }

      .border-\[\#291a10\] {
        border-color: #291a10;
      }

      .border-\[\#311d0e\] {
        border-color: #311d0e;
      }

      .border-\[\#311f12\] {
        border-color: #311f12;
      }

      .border-\[\#311f13\] {
        border-color: #311f13;
      }

      .border-\[\#331f11\] {
        border-color: #331f11;
      }

      .border-\[\#381e0f\] {
        border-color: #381e0f;
      }

      .border-\[\#1877F2\]\/40 {
        border-color: color-mix(in oklab, #1877F2 40%, transparent);
      }

      .border-\[\#1877F2\]\/50 {
        border-color: color-mix(in oklab, #1877F2 50%, transparent);
      }

      .border-\[\#23140a\] {
        border-color: #23140a;
      }

      .border-\[\#24150b\] {
        border-color: #24150b;
      }

      .border-\[\#25170d\] {
        border-color: #25170d;
      }

      .border-\[\#26140a\] {
        border-color: #26140a;
      }

      .border-\[\#26150a\] {
        border-color: #26150a;
      }

      .border-\[\#241409\] {
        border-color: #241409;
      }

      .border-\[\#332013\] {
        border-color: #332013;
      }

      .border-\[\#362011\] {
        border-color: #362011;
      }

      .border-\[\#362113\] {
        border-color: #362113;
      }

      .border-\[\#382112\] {
        border-color: #382112;
      }

      .border-\[\#382213\] {
        border-color: #382213;
      }

      .border-\[\#382214\] {
        border-color: #382214;
      }

      .border-\[\#382315\] {
        border-color: #382315;
      }

      .border-\[\#422918\] {
        border-color: #422918;
      }

      .border-amber-200 {
        border-color: var(--color-amber-200);
      }

      .border-amber-300 {
        border-color: var(--color-amber-300);
      }

      .border-amber-300\/50 {
        border-color: color-mix(in srgb, oklch(87.9% 0.169 91.605) 50%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          border-color: color-mix(in oklab, var(--color-amber-300) 50%, transparent);
        }
      }

      .border-amber-300\/60 {
        border-color: color-mix(in srgb, oklch(87.9% 0.169 91.605) 60%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          border-color: color-mix(in oklab, var(--color-amber-300) 60%, transparent);
        }
      }

      .border-amber-400 {
        border-color: var(--color-amber-400);
      }

      .border-amber-400\/30 {
        border-color: color-mix(in srgb, oklch(82.8% 0.189 84.429) 30%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          border-color: color-mix(in oklab, var(--color-amber-400) 30%, transparent);
        }
      }

      .border-amber-400\/40 {
        border-color: color-mix(in srgb, oklch(82.8% 0.189 84.429) 40%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          border-color: color-mix(in oklab, var(--color-amber-400) 40%, transparent);
        }
      }

      .border-amber-400\/50 {
        border-color: color-mix(in srgb, oklch(82.8% 0.189 84.429) 50%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          border-color: color-mix(in oklab, var(--color-amber-400) 50%, transparent);
        }
      }

      .border-amber-400\/80 {
        border-color: color-mix(in srgb, oklch(82.8% 0.189 84.429) 80%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          border-color: color-mix(in oklab, var(--color-amber-400) 80%, transparent);
        }
      }

      .border-amber-500 {
        border-color: var(--color-amber-500);
      }

      .border-amber-500\/20 {
        border-color: color-mix(in srgb, oklch(76.9% 0.188 70.08) 20%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          border-color: color-mix(in oklab, var(--color-amber-500) 20%, transparent);
        }
      }

      .border-amber-500\/30 {
        border-color: color-mix(in srgb, oklch(76.9% 0.188 70.08) 30%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          border-color: color-mix(in oklab, var(--color-amber-500) 30%, transparent);
        }
      }

      .border-amber-500\/40 {
        border-color: color-mix(in srgb, oklch(76.9% 0.188 70.08) 40%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          border-color: color-mix(in oklab, var(--color-amber-500) 40%, transparent);
        }
      }

      .border-amber-500\/50 {
        border-color: color-mix(in srgb, oklch(76.9% 0.188 70.08) 50%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          border-color: color-mix(in oklab, var(--color-amber-500) 50%, transparent);
        }
      }

      .border-amber-500\/60 {
        border-color: color-mix(in srgb, oklch(76.9% 0.188 70.08) 60%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          border-color: color-mix(in oklab, var(--color-amber-500) 60%, transparent);
        }
      }

      .border-amber-500\/70 {
        border-color: color-mix(in srgb, oklch(76.9% 0.188 70.08) 70%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          border-color: color-mix(in oklab, var(--color-amber-500) 70%, transparent);
        }
      }

      .border-amber-600\/25 {
        border-color: color-mix(in srgb, oklch(66.6% 0.179 58.318) 25%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          border-color: color-mix(in oklab, var(--color-amber-600) 25%, transparent);
        }
      }

      .border-amber-600\/30 {
        border-color: color-mix(in srgb, oklch(66.6% 0.179 58.318) 30%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          border-color: color-mix(in oklab, var(--color-amber-600) 30%, transparent);
        }
      }

      .border-amber-600\/40 {
        border-color: color-mix(in srgb, oklch(66.6% 0.179 58.318) 40%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          border-color: color-mix(in oklab, var(--color-amber-600) 40%, transparent);
        }
      }

      .border-amber-600\/50 {
        border-color: color-mix(in srgb, oklch(66.6% 0.179 58.318) 50%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          border-color: color-mix(in oklab, var(--color-amber-600) 50%, transparent);
        }
      }

      .border-amber-600\/60 {
        border-color: color-mix(in srgb, oklch(66.6% 0.179 58.318) 60%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          border-color: color-mix(in oklab, var(--color-amber-600) 60%, transparent);
        }
      }

      .border-amber-600\/70 {
        border-color: color-mix(in srgb, oklch(66.6% 0.179 58.318) 70%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          border-color: color-mix(in oklab, var(--color-amber-600) 70%, transparent);
        }
      }

      .border-amber-700\/40 {
        border-color: color-mix(in srgb, oklch(55.5% 0.163 48.998) 40%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          border-color: color-mix(in oklab, var(--color-amber-700) 40%, transparent);
        }
      }

      .border-blue-400\/40 {
        border-color: color-mix(in srgb, oklch(70.7% 0.165 254.624) 40%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          border-color: color-mix(in oklab, var(--color-blue-400) 40%, transparent);
        }
      }

      .border-blue-500\/30 {
        border-color: color-mix(in srgb, oklch(62.3% 0.214 259.815) 30%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          border-color: color-mix(in oklab, var(--color-blue-500) 30%, transparent);
        }
      }

      .border-blue-500\/40 {
        border-color: color-mix(in srgb, oklch(62.3% 0.214 259.815) 40%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          border-color: color-mix(in oklab, var(--color-blue-500) 40%, transparent);
        }
      }

      .border-blue-600\/30 {
        border-color: color-mix(in srgb, oklch(54.6% 0.245 262.881) 30%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          border-color: color-mix(in oklab, var(--color-blue-600) 30%, transparent);
        }
      }

      .border-cyan-500\/20 {
        border-color: color-mix(in srgb, oklch(71.5% 0.143 215.221) 20%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          border-color: color-mix(in oklab, var(--color-cyan-500) 20%, transparent);
        }
      }

      .border-cyan-500\/40 {
        border-color: color-mix(in srgb, oklch(71.5% 0.143 215.221) 40%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          border-color: color-mix(in oklab, var(--color-cyan-500) 40%, transparent);
        }
      }

      .border-emerald-400 {
        border-color: var(--color-emerald-400);
      }

      .border-emerald-400\/40 {
        border-color: color-mix(in srgb, oklch(76.5% 0.177 163.223) 40%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          border-color: color-mix(in oklab, var(--color-emerald-400) 40%, transparent);
        }
      }

      .border-emerald-500 {
        border-color: var(--color-emerald-500);
      }

      .border-emerald-500\/20 {
        border-color: color-mix(in srgb, oklch(69.6% 0.17 162.48) 20%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          border-color: color-mix(in oklab, var(--color-emerald-500) 20%, transparent);
        }
      }

      .border-emerald-500\/30 {
        border-color: color-mix(in srgb, oklch(69.6% 0.17 162.48) 30%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          border-color: color-mix(in oklab, var(--color-emerald-500) 30%, transparent);
        }
      }

      .border-emerald-500\/40 {
        border-color: color-mix(in srgb, oklch(69.6% 0.17 162.48) 40%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          border-color: color-mix(in oklab, var(--color-emerald-500) 40%, transparent);
        }
      }

      .border-emerald-600\/30 {
        border-color: color-mix(in srgb, oklch(59.6% 0.145 163.225) 30%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          border-color: color-mix(in oklab, var(--color-emerald-600) 30%, transparent);
        }
      }

      .border-emerald-600\/40 {
        border-color: color-mix(in srgb, oklch(59.6% 0.145 163.225) 40%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          border-color: color-mix(in oklab, var(--color-emerald-600) 40%, transparent);
        }
      }

      .border-green-500\/30 {
        border-color: color-mix(in srgb, oklch(72.3% 0.219 149.579) 30%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          border-color: color-mix(in oklab, var(--color-green-500) 30%, transparent);
        }
      }

      .border-green-500\/40 {
        border-color: color-mix(in srgb, oklch(72.3% 0.219 149.579) 40%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          border-color: color-mix(in oklab, var(--color-green-500) 40%, transparent);
        }
      }

      .border-lime-500\/40 {
        border-color: color-mix(in srgb, oklch(76.8% 0.233 130.85) 40%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          border-color: color-mix(in oklab, var(--color-lime-500) 40%, transparent);
        }
      }

      .border-orange-500\/40 {
        border-color: color-mix(in srgb, oklch(70.5% 0.213 47.604) 40%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          border-color: color-mix(in oklab, var(--color-orange-500) 40%, transparent);
        }
      }

      .border-orange-500\/50 {
        border-color: color-mix(in srgb, oklch(70.5% 0.213 47.604) 50%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          border-color: color-mix(in oklab, var(--color-orange-500) 50%, transparent);
        }
      }

      .border-pink-500\/40 {
        border-color: color-mix(in srgb, oklch(65.6% 0.241 354.308) 40%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          border-color: color-mix(in oklab, var(--color-pink-500) 40%, transparent);
        }
      }

      .border-purple-500\/20 {
        border-color: color-mix(in srgb, oklch(62.7% 0.265 303.9) 20%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          border-color: color-mix(in oklab, var(--color-purple-500) 20%, transparent);
        }
      }

      .border-purple-500\/30 {
        border-color: color-mix(in srgb, oklch(62.7% 0.265 303.9) 30%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          border-color: color-mix(in oklab, var(--color-purple-500) 30%, transparent);
        }
      }

      .border-purple-500\/40 {
        border-color: color-mix(in srgb, oklch(62.7% 0.265 303.9) 40%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          border-color: color-mix(in oklab, var(--color-purple-500) 40%, transparent);
        }
      }

      .border-purple-600\/30 {
        border-color: color-mix(in srgb, oklch(55.8% 0.288 302.321) 30%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          border-color: color-mix(in oklab, var(--color-purple-600) 30%, transparent);
        }
      }

      .border-red-400 {
        border-color: var(--color-red-400);
      }

      .border-red-500\/40 {
        border-color: color-mix(in srgb, oklch(63.7% 0.237 25.331) 40%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          border-color: color-mix(in oklab, var(--color-red-500) 40%, transparent);
        }
      }

      .border-red-600\/30 {
        border-color: color-mix(in srgb, oklch(57.7% 0.245 27.325) 30%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          border-color: color-mix(in oklab, var(--color-red-600) 30%, transparent);
        }
      }

      .border-red-600\/40 {
        border-color: color-mix(in srgb, oklch(57.7% 0.245 27.325) 40%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          border-color: color-mix(in oklab, var(--color-red-600) 40%, transparent);
        }
      }

      .border-rose-500\/40 {
        border-color: color-mix(in srgb, oklch(64.5% 0.246 16.439) 40%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          border-color: color-mix(in oklab, var(--color-rose-500) 40%, transparent);
        }
      }

      .border-rose-500\/50 {
        border-color: color-mix(in srgb, oklch(64.5% 0.246 16.439) 50%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          border-color: color-mix(in oklab, var(--color-rose-500) 50%, transparent);
        }
      }

      .border-sky-400 {
        border-color: var(--color-sky-400);
      }

      .border-sky-500\/40 {
        border-color: color-mix(in srgb, oklch(68.5% 0.169 237.323) 40%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          border-color: color-mix(in oklab, var(--color-sky-500) 40%, transparent);
        }
      }

      .border-sky-600\/30 {
        border-color: color-mix(in srgb, oklch(58.8% 0.158 241.966) 30%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          border-color: color-mix(in oklab, var(--color-sky-600) 30%, transparent);
        }
      }

      .border-slate-400\/40 {
        border-color: color-mix(in srgb, oklch(70.4% 0.04 256.788) 40%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          border-color: color-mix(in oklab, var(--color-slate-400) 40%, transparent);
        }
      }

      .border-stone-600 {
        border-color: var(--color-stone-600);
      }

      .border-stone-700 {
        border-color: var(--color-stone-700);
      }

      .border-stone-800 {
        border-color: var(--color-stone-800);
      }

      .border-stone-800\/80 {
        border-color: color-mix(in srgb, oklch(26.8% 0.007 34.298) 80%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          border-color: color-mix(in oklab, var(--color-stone-800) 80%, transparent);
        }
      }

      .border-stone-900 {
        border-color: var(--color-stone-900);
      }

      .border-stone-950 {
        border-color: var(--color-stone-950);
      }

      .border-teal-500\/30 {
        border-color: color-mix(in srgb, oklch(70.4% 0.14 182.503) 30%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          border-color: color-mix(in oklab, var(--color-teal-500) 30%, transparent);
        }
      }

      .border-teal-500\/40 {
        border-color: color-mix(in srgb, oklch(70.4% 0.14 182.503) 40%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          border-color: color-mix(in oklab, var(--color-teal-500) 40%, transparent);
        }
      }

      .border-white\/10 {
        border-color: color-mix(in srgb, #fff 10%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          border-color: color-mix(in oklab, var(--color-white) 10%, transparent);
        }
      }

      .border-white\/20 {
        border-color: color-mix(in srgb, #fff 20%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          border-color: color-mix(in oklab, var(--color-white) 20%, transparent);
        }
      }

      .border-yellow-500\/20 {
        border-color: color-mix(in srgb, oklch(79.5% 0.184 86.047) 20%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          border-color: color-mix(in oklab, var(--color-yellow-500) 20%, transparent);
        }
      }

      .border-yellow-500\/40 {
        border-color: color-mix(in srgb, oklch(79.5% 0.184 86.047) 40%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          border-color: color-mix(in oklab, var(--color-yellow-500) 40%, transparent);
        }
      }

      .bg-\[\#0a0502\] {
        background-color: #0a0502;
      }

      .bg-\[\#0a0503\] {
        background-color: #0a0503;
      }

      .bg-\[\#0b0704\] {
        background-color: #0b0704;
      }

      .bg-\[\#0d0602\] {
        background-color: #0d0602;
      }

      .bg-\[\#0d0703\] {
        background-color: #0d0703;
      }

      .bg-\[\#0d0703\]\/90 {
        background-color: color-mix(in oklab, #0d0703 90%, transparent);
      }

      .bg-\[\#0d0704\] {
        background-color: #0d0704;
      }

      .bg-\[\#0e0a07\] {
        background-color: #0e0a07;
      }

      .bg-\[\#0e0a07\]\/95 {
        background-color: color-mix(in oklab, #0e0a07 95%, transparent);
      }

      .bg-\[\#0e0602\] {
        background-color: #0e0602;
      }

      .bg-\[\#0e1625\]\/90 {
        background-color: color-mix(in oklab, #0e1625 90%, transparent);
      }

      .bg-\[\#1a110a\] {
        background-color: #1a110a;
      }

      .bg-\[\#1a1008\] {
        background-color: #1a1008;
      }

      .bg-\[\#1b0f07\] {
        background-color: #1b0f07;
      }

      .bg-\[\#1b1008\] {
        background-color: #1b1008;
      }

      .bg-\[\#1b1008\]\/80 {
        background-color: color-mix(in oklab, #1b1008 80%, transparent);
      }

      .bg-\[\#1b1109\] {
        background-color: #1b1109;
      }

      .bg-\[\#1c0e05\] {
        background-color: #1c0e05;
      }

      .bg-\[\#1c120a\] {
        background-color: #1c120a;
      }

      .bg-\[\#1c1008\] {
        background-color: #1c1008;
      }

      .bg-\[\#1c1108\] {
        background-color: #1c1108;
      }

      .bg-\[\#1c1109\]\/80 {
        background-color: color-mix(in oklab, #1c1109 80%, transparent);
      }

      .bg-\[\#1c1307\]\/90 {
        background-color: color-mix(in oklab, #1c1307 90%, transparent);
      }

      .bg-\[\#1d2d3a\] {
        background-color: #1d2d3a;
      }

      .bg-\[\#1e2f42\] {
        background-color: #1e2f42;
      }

      .bg-\[\#1e120a\] {
        background-color: #1e120a;
      }

      .bg-\[\#1e1007\] {
        background-color: #1e1007;
      }

      .bg-\[\#1e1107\] {
        background-color: #1e1107;
      }

      .bg-\[\#1e1108\] {
        background-color: #1e1108;
      }

      .bg-\[\#1f120a\] {
        background-color: #1f120a;
      }

      .bg-\[\#1f130a\] {
        background-color: #1f130a;
      }

      .bg-\[\#1f140c\] {
        background-color: #1f140c;
      }

      .bg-\[\#1f1209\] {
        background-color: #1f1209;
      }

      .bg-\[\#2a1a0f\] {
        background-color: #2a1a0f;
      }

      .bg-\[\#2a1b10\] {
        background-color: #2a1b10;
      }

      .bg-\[\#2a1b10\]\/90 {
        background-color: color-mix(in oklab, #2a1b10 90%, transparent);
      }

      .bg-\[\#2b180d\] {
        background-color: #2b180d;
      }

      .bg-\[\#2d1e12\] {
        background-color: #2d1e12;
      }

      .bg-\[\#3b2312\] {
        background-color: #3b2312;
      }

      .bg-\[\#25D366\]\/20 {
        background-color: color-mix(in oklab, #25D366 20%, transparent);
      }

      .bg-\[\#111a24\]\/90 {
        background-color: color-mix(in oklab, #111a24 90%, transparent);
      }

      .bg-\[\#120a05\] {
        background-color: #120a05;
      }

      .bg-\[\#120a05\]\/60 {
        background-color: color-mix(in oklab, #120a05 60%, transparent);
      }

      .bg-\[\#120a05\]\/80 {
        background-color: color-mix(in oklab, #120a05 80%, transparent);
      }

      .bg-\[\#120a05\]\/90 {
        background-color: color-mix(in oklab, #120a05 90%, transparent);
      }

      .bg-\[\#120b06\] {
        background-color: #120b06;
      }

      .bg-\[\#121c14\] {
        background-color: #121c14;
      }

      .bg-\[\#140b05\] {
        background-color: #140b05;
      }

      .bg-\[\#150a04\] {
        background-color: #150a04;
      }

      .bg-\[\#150c06\] {
        background-color: #150c06;
      }

      .bg-\[\#150d07\] {
        background-color: #150d07;
      }

      .bg-\[\#160d07\] {
        background-color: #160d07;
      }

      .bg-\[\#170c06\] {
        background-color: #170c06;
      }

      .bg-\[\#170e08\] {
        background-color: #170e08;
      }

      .bg-\[\#170e08\]\/90 {
        background-color: color-mix(in oklab, #170e08 90%, transparent);
      }

      .bg-\[\#170f08\]\/90 {
        background-color: color-mix(in oklab, #170f08 90%, transparent);
      }

      .bg-\[\#180d06\] {
        background-color: #180d06;
      }

      .bg-\[\#180e07\] {
        background-color: #180e07;
      }

      .bg-\[\#180e08\] {
        background-color: #180e08;
      }

      .bg-\[\#180f08\] {
        background-color: #180f08;
      }

      .bg-\[\#180f08\]\/80 {
        background-color: color-mix(in oklab, #180f08 80%, transparent);
      }

      .bg-\[\#229ED9\]\/20 {
        background-color: color-mix(in oklab, #229ED9 20%, transparent);
      }

      .bg-\[\#311d0e\] {
        background-color: #311d0e;
      }

      .bg-\[\#1877F2\]\/20 {
        background-color: color-mix(in oklab, #1877F2 20%, transparent);
      }

      .bg-\[\#22130a\] {
        background-color: #22130a;
      }

      .bg-\[\#22140a\] {
        background-color: #22140a;
      }

      .bg-\[\#23150b\] {
        background-color: #23150b;
      }

      .bg-\[\#24140a\] {
        background-color: #24140a;
      }

      .bg-\[\#24150b\] {
        background-color: #24150b;
      }

      .bg-\[\#24160d\] {
        background-color: #24160d;
      }

      .bg-\[\#25170d\] {
        background-color: #25170d;
      }

      .bg-\[\#26160b\] {
        background-color: #26160b;
      }

      .bg-\[\#26190f\] {
        background-color: #26190f;
      }

      .bg-\[\#27170c\] {
        background-color: #27170c;
      }

      .bg-\[\#27170d\] {
        background-color: #27170d;
      }

      .bg-\[\#27180e\] {
        background-color: #27180e;
      }

      .bg-\[\#28150a\] {
        background-color: #28150a;
      }

      .bg-\[\#29170b\] {
        background-color: #29170b;
      }

      .bg-\[\#080402\] {
        background-color: #080402;
      }

      .bg-\[\#100702\] {
        background-color: #100702;
      }

      .bg-\[\#100703\] {
        background-color: #100703;
      }

      .bg-\[\#100803\] {
        background-color: #100803;
      }

      .bg-\[\#110904\] {
        background-color: #110904;
      }

      .bg-\[\#120702\] {
        background-color: #120702;
      }

      .bg-\[\#120703\] {
        background-color: #120703;
      }

      .bg-\[\#120803\] {
        background-color: #120803;
      }

      .bg-\[\#120803\]\/90 {
        background-color: color-mix(in oklab, #120803 90%, transparent);
      }

      .bg-\[\#183626\] {
        background-color: #183626;
      }

      .bg-\[\#201006\] {
        background-color: #201006;
      }

      .bg-\[\#201209\] {
        background-color: #201209;
      }

      .bg-\[\#211209\] {
        background-color: #211209;
      }

      .bg-\[\#211309\] {
        background-color: #211309;
      }

      .bg-\[\#221309\] {
        background-color: #221309;
      }

      .bg-\[\#241307\] {
        background-color: #241307;
      }

      .bg-\[\#241308\] {
        background-color: #241308;
      }

      .bg-\[\#261206\] {
        background-color: #261206;
      }

      .bg-amber-400 {
        background-color: var(--color-amber-400);
      }

      .bg-amber-400\/80 {
        background-color: color-mix(in srgb, oklch(82.8% 0.189 84.429) 80%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          background-color: color-mix(in oklab, var(--color-amber-400) 80%, transparent);
        }
      }

      .bg-amber-500 {
        background-color: var(--color-amber-500);
      }

      .bg-amber-500\/10 {
        background-color: color-mix(in srgb, oklch(76.9% 0.188 70.08) 10%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          background-color: color-mix(in oklab, var(--color-amber-500) 10%, transparent);
        }
      }

      .bg-amber-500\/15 {
        background-color: color-mix(in srgb, oklch(76.9% 0.188 70.08) 15%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          background-color: color-mix(in oklab, var(--color-amber-500) 15%, transparent);
        }
      }

      .bg-amber-500\/20 {
        background-color: color-mix(in srgb, oklch(76.9% 0.188 70.08) 20%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          background-color: color-mix(in oklab, var(--color-amber-500) 20%, transparent);
        }
      }

      .bg-amber-600 {
        background-color: var(--color-amber-600);
      }

      .bg-amber-600\/20 {
        background-color: color-mix(in srgb, oklch(66.6% 0.179 58.318) 20%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          background-color: color-mix(in oklab, var(--color-amber-600) 20%, transparent);
        }
      }

      .bg-amber-700 {
        background-color: var(--color-amber-700);
      }

      .bg-amber-950\/80 {
        background-color: color-mix(in srgb, oklch(27.9% 0.077 45.635) 80%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          background-color: color-mix(in oklab, var(--color-amber-950) 80%, transparent);
        }
      }

      .bg-black\/40 {
        background-color: color-mix(in srgb, #000 40%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          background-color: color-mix(in oklab, var(--color-black) 40%, transparent);
        }
      }

      .bg-black\/50 {
        background-color: color-mix(in srgb, #000 50%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          background-color: color-mix(in oklab, var(--color-black) 50%, transparent);
        }
      }

      .bg-black\/60 {
        background-color: color-mix(in srgb, #000 60%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          background-color: color-mix(in oklab, var(--color-black) 60%, transparent);
        }
      }

      .bg-black\/70 {
        background-color: color-mix(in srgb, #000 70%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          background-color: color-mix(in oklab, var(--color-black) 70%, transparent);
        }
      }

      .bg-black\/80 {
        background-color: color-mix(in srgb, #000 80%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          background-color: color-mix(in oklab, var(--color-black) 80%, transparent);
        }
      }

      .bg-black\/90 {
        background-color: color-mix(in srgb, #000 90%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          background-color: color-mix(in oklab, var(--color-black) 90%, transparent);
        }
      }

      .bg-blue-500\/10 {
        background-color: color-mix(in srgb, oklch(62.3% 0.214 259.815) 10%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          background-color: color-mix(in oklab, var(--color-blue-500) 10%, transparent);
        }
      }

      .bg-blue-500\/20 {
        background-color: color-mix(in srgb, oklch(62.3% 0.214 259.815) 20%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          background-color: color-mix(in oklab, var(--color-blue-500) 20%, transparent);
        }
      }

      .bg-cyan-400 {
        background-color: var(--color-cyan-400);
      }

      .bg-cyan-500\/10 {
        background-color: color-mix(in srgb, oklch(71.5% 0.143 215.221) 10%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          background-color: color-mix(in oklab, var(--color-cyan-500) 10%, transparent);
        }
      }

      .bg-emerald-400 {
        background-color: var(--color-emerald-400);
      }

      .bg-emerald-500 {
        background-color: var(--color-emerald-500);
      }

      .bg-emerald-500\/10 {
        background-color: color-mix(in srgb, oklch(69.6% 0.17 162.48) 10%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          background-color: color-mix(in oklab, var(--color-emerald-500) 10%, transparent);
        }
      }

      .bg-emerald-500\/20 {
        background-color: color-mix(in srgb, oklch(69.6% 0.17 162.48) 20%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          background-color: color-mix(in oklab, var(--color-emerald-500) 20%, transparent);
        }
      }

      .bg-emerald-600 {
        background-color: var(--color-emerald-600);
      }

      .bg-emerald-600\/20 {
        background-color: color-mix(in srgb, oklch(59.6% 0.145 163.225) 20%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          background-color: color-mix(in oklab, var(--color-emerald-600) 20%, transparent);
        }
      }

      .bg-emerald-950\/80 {
        background-color: color-mix(in srgb, oklch(26.2% 0.051 172.552) 80%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          background-color: color-mix(in oklab, var(--color-emerald-950) 80%, transparent);
        }
      }

      .bg-green-500\/10 {
        background-color: color-mix(in srgb, oklch(72.3% 0.219 149.579) 10%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          background-color: color-mix(in oklab, var(--color-green-500) 10%, transparent);
        }
      }

      .bg-green-500\/20 {
        background-color: color-mix(in srgb, oklch(72.3% 0.219 149.579) 20%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          background-color: color-mix(in oklab, var(--color-green-500) 20%, transparent);
        }
      }

      .bg-orange-400 {
        background-color: var(--color-orange-400);
      }

      .bg-orange-500 {
        background-color: var(--color-orange-500);
      }

      .bg-orange-500\/10 {
        background-color: color-mix(in srgb, oklch(70.5% 0.213 47.604) 10%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          background-color: color-mix(in oklab, var(--color-orange-500) 10%, transparent);
        }
      }

      .bg-orange-500\/20 {
        background-color: color-mix(in srgb, oklch(70.5% 0.213 47.604) 20%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          background-color: color-mix(in oklab, var(--color-orange-500) 20%, transparent);
        }
      }

      .bg-orange-600 {
        background-color: var(--color-orange-600);
      }

      .bg-orange-600\/20 {
        background-color: color-mix(in srgb, oklch(64.6% 0.222 41.116) 20%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          background-color: color-mix(in oklab, var(--color-orange-600) 20%, transparent);
        }
      }

      .bg-orange-600\/90 {
        background-color: color-mix(in srgb, oklch(64.6% 0.222 41.116) 90%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          background-color: color-mix(in oklab, var(--color-orange-600) 90%, transparent);
        }
      }

      .bg-purple-500 {
        background-color: var(--color-purple-500);
      }

      .bg-purple-500\/10 {
        background-color: color-mix(in srgb, oklch(62.7% 0.265 303.9) 10%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          background-color: color-mix(in oklab, var(--color-purple-500) 10%, transparent);
        }
      }

      .bg-purple-500\/20 {
        background-color: color-mix(in srgb, oklch(62.7% 0.265 303.9) 20%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          background-color: color-mix(in oklab, var(--color-purple-500) 20%, transparent);
        }
      }

      .bg-red-600 {
        background-color: var(--color-red-600);
      }

      .bg-rose-500\/20 {
        background-color: color-mix(in srgb, oklch(64.5% 0.246 16.439) 20%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          background-color: color-mix(in oklab, var(--color-rose-500) 20%, transparent);
        }
      }

      .bg-sky-400 {
        background-color: var(--color-sky-400);
      }

      .bg-sky-500 {
        background-color: var(--color-sky-500);
      }

      .bg-sky-500\/20 {
        background-color: color-mix(in srgb, oklch(68.5% 0.169 237.323) 20%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          background-color: color-mix(in oklab, var(--color-sky-500) 20%, transparent);
        }
      }

      .bg-sky-600 {
        background-color: var(--color-sky-600);
      }

      .bg-slate-200 {
        background-color: var(--color-slate-200);
      }

      .bg-slate-800 {
        background-color: var(--color-slate-800);
      }

      .bg-stone-200 {
        background-color: var(--color-stone-200);
      }

      .bg-stone-300 {
        background-color: var(--color-stone-300);
      }

      .bg-stone-600 {
        background-color: var(--color-stone-600);
      }

      .bg-stone-600\/70 {
        background-color: color-mix(in srgb, oklch(44.4% 0.011 73.639) 70%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          background-color: color-mix(in oklab, var(--color-stone-600) 70%, transparent);
        }
      }

      .bg-stone-800 {
        background-color: var(--color-stone-800);
      }

      .bg-stone-800\/60 {
        background-color: color-mix(in srgb, oklch(26.8% 0.007 34.298) 60%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          background-color: color-mix(in oklab, var(--color-stone-800) 60%, transparent);
        }
      }

      .bg-stone-900 {
        background-color: var(--color-stone-900);
      }

      .bg-stone-900\/80 {
        background-color: color-mix(in srgb, oklch(21.6% 0.006 56.043) 80%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          background-color: color-mix(in oklab, var(--color-stone-900) 80%, transparent);
        }
      }

      .bg-stone-900\/90 {
        background-color: color-mix(in srgb, oklch(21.6% 0.006 56.043) 90%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          background-color: color-mix(in oklab, var(--color-stone-900) 90%, transparent);
        }
      }

      .bg-stone-950 {
        background-color: var(--color-stone-950);
      }

      .bg-stone-950\/15 {
        background-color: color-mix(in srgb, oklch(14.7% 0.004 49.25) 15%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          background-color: color-mix(in oklab, var(--color-stone-950) 15%, transparent);
        }
      }

      .bg-stone-950\/20 {
        background-color: color-mix(in srgb, oklch(14.7% 0.004 49.25) 20%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          background-color: color-mix(in oklab, var(--color-stone-950) 20%, transparent);
        }
      }

      .bg-stone-950\/30 {
        background-color: color-mix(in srgb, oklch(14.7% 0.004 49.25) 30%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          background-color: color-mix(in oklab, var(--color-stone-950) 30%, transparent);
        }
      }

      .bg-stone-950\/50 {
        background-color: color-mix(in srgb, oklch(14.7% 0.004 49.25) 50%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          background-color: color-mix(in oklab, var(--color-stone-950) 50%, transparent);
        }
      }

      .bg-stone-950\/60 {
        background-color: color-mix(in srgb, oklch(14.7% 0.004 49.25) 60%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          background-color: color-mix(in oklab, var(--color-stone-950) 60%, transparent);
        }
      }

      .bg-stone-950\/70 {
        background-color: color-mix(in srgb, oklch(14.7% 0.004 49.25) 70%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          background-color: color-mix(in oklab, var(--color-stone-950) 70%, transparent);
        }
      }

      .bg-stone-950\/80 {
        background-color: color-mix(in srgb, oklch(14.7% 0.004 49.25) 80%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          background-color: color-mix(in oklab, var(--color-stone-950) 80%, transparent);
        }
      }

      .bg-teal-500\/10 {
        background-color: color-mix(in srgb, oklch(70.4% 0.14 182.503) 10%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          background-color: color-mix(in oklab, var(--color-teal-500) 10%, transparent);
        }
      }

      .bg-teal-500\/20 {
        background-color: color-mix(in srgb, oklch(70.4% 0.14 182.503) 20%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          background-color: color-mix(in oklab, var(--color-teal-500) 20%, transparent);
        }
      }

      .bg-transparent {
        background-color: transparent;
      }

      .bg-yellow-500\/10 {
        background-color: color-mix(in srgb, oklch(79.5% 0.184 86.047) 10%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          background-color: color-mix(in oklab, var(--color-yellow-500) 10%, transparent);
        }
      }

      .bg-gradient-to-b {
        --tw-gradient-position: to bottom in oklab;
        background-image: linear-gradient(var(--tw-gradient-stops));
      }

      .bg-gradient-to-bl {
        --tw-gradient-position: to bottom left in oklab;
        background-image: linear-gradient(var(--tw-gradient-stops));
      }

      .bg-gradient-to-br {
        --tw-gradient-position: to bottom right in oklab;
        background-image: linear-gradient(var(--tw-gradient-stops));
      }

      .bg-gradient-to-r {
        --tw-gradient-position: to right in oklab;
        background-image: linear-gradient(var(--tw-gradient-stops));
      }

      .bg-gradient-to-t {
        --tw-gradient-position: to top in oklab;
        background-image: linear-gradient(var(--tw-gradient-stops));
      }

      .bg-gradient-to-tr {
        --tw-gradient-position: to top right in oklab;
        background-image: linear-gradient(var(--tw-gradient-stops));
      }

      .bg-\[radial-gradient\(circle_at_center\,_var\(--tw-gradient-stops\)\)\] {
        background-image: radial-gradient(circle at center, var(--tw-gradient-stops));
      }

      .bg-\[radial-gradient\(ellipse_at_center\,_var\(--tw-gradient-stops\)\)\] {
        background-image: radial-gradient(ellipse at center, var(--tw-gradient-stops));
      }

      .from-\[\#0d0603\]\/80 {
        --tw-gradient-from: color-mix(in oklab, #0d0603 80%, transparent);
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-\[\#1a0e06\] {
        --tw-gradient-from: #1a0e06;
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-\[\#1b0d06\] {
        --tw-gradient-from: #1b0d06;
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-\[\#1b0f07\] {
        --tw-gradient-from: #1b0f07;
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-\[\#1c0f06\] {
        --tw-gradient-from: #1c0f06;
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-\[\#1c120a\] {
        --tw-gradient-from: #1c120a;
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-\[\#1c1007\] {
        --tw-gradient-from: #1c1007;
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-\[\#1c1108\] {
        --tw-gradient-from: #1c1108;
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-\[\#1f1007\] {
        --tw-gradient-from: #1f1007;
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-\[\#2a1b10\] {
        --tw-gradient-from: #2a1b10;
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-\[\#2a160b\] {
        --tw-gradient-from: #2a160b;
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-\[\#2a170a\] {
        --tw-gradient-from: #2a170a;
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-\[\#2a1608\] {
        --tw-gradient-from: #2a1608;
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-\[\#2c1305\] {
        --tw-gradient-from: #2c1305;
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-\[\#2e1506\] {
        --tw-gradient-from: #2e1506;
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-\[\#3a2010\] {
        --tw-gradient-from: #3a2010;
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-\[\#3b200c\] {
        --tw-gradient-from: #3b200c;
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-\[\#120a05\] {
        --tw-gradient-from: #120a05;
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-\[\#140b05\] {
        --tw-gradient-from: #140b05;
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-\[\#180f0a\] {
        --tw-gradient-from: #180f0a;
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-\[\#24150a\] {
        --tw-gradient-from: #24150a;
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-\[\#29170a\] {
        --tw-gradient-from: #29170a;
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-\[\#120803\] {
        --tw-gradient-from: #120803;
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-\[\#211107\] {
        --tw-gradient-from: #211107;
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-\[\#211208\] {
        --tw-gradient-from: #211208;
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-\[\#231206\] {
        --tw-gradient-from: #231206;
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-\[\#251308\] {
        --tw-gradient-from: #251308;
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-amber-300 {
        --tw-gradient-from: var(--color-amber-300);
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-amber-400 {
        --tw-gradient-from: var(--color-amber-400);
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-amber-500 {
        --tw-gradient-from: var(--color-amber-500);
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-amber-500\/10 {
        --tw-gradient-from: color-mix(in srgb, oklch(76.9% 0.188 70.08) 10%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          --tw-gradient-from: color-mix(in oklab, var(--color-amber-500) 10%, transparent);
        }

        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-amber-500\/20 {
        --tw-gradient-from: color-mix(in srgb, oklch(76.9% 0.188 70.08) 20%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          --tw-gradient-from: color-mix(in oklab, var(--color-amber-500) 20%, transparent);
        }

        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-amber-500\/30 {
        --tw-gradient-from: color-mix(in srgb, oklch(76.9% 0.188 70.08) 30%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          --tw-gradient-from: color-mix(in oklab, var(--color-amber-500) 30%, transparent);
        }

        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-amber-600 {
        --tw-gradient-from: var(--color-amber-600);
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-amber-900 {
        --tw-gradient-from: var(--color-amber-900);
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-amber-900\/90 {
        --tw-gradient-from: color-mix(in srgb, oklch(41.4% 0.112 45.904) 90%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          --tw-gradient-from: color-mix(in oklab, var(--color-amber-900) 90%, transparent);
        }

        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-amber-950 {
        --tw-gradient-from: var(--color-amber-950);
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-amber-950\/40 {
        --tw-gradient-from: color-mix(in srgb, oklch(27.9% 0.077 45.635) 40%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          --tw-gradient-from: color-mix(in oklab, var(--color-amber-950) 40%, transparent);
        }

        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-amber-950\/60 {
        --tw-gradient-from: color-mix(in srgb, oklch(27.9% 0.077 45.635) 60%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          --tw-gradient-from: color-mix(in oklab, var(--color-amber-950) 60%, transparent);
        }

        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-amber-950\/80 {
        --tw-gradient-from: color-mix(in srgb, oklch(27.9% 0.077 45.635) 80%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          --tw-gradient-from: color-mix(in oklab, var(--color-amber-950) 80%, transparent);
        }

        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-black\/60 {
        --tw-gradient-from: color-mix(in srgb, #000 60%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          --tw-gradient-from: color-mix(in oklab, var(--color-black) 60%, transparent);
        }

        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-black\/80 {
        --tw-gradient-from: color-mix(in srgb, #000 80%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          --tw-gradient-from: color-mix(in oklab, var(--color-black) 80%, transparent);
        }

        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-blue-950 {
        --tw-gradient-from: var(--color-blue-950);
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-blue-950\/80 {
        --tw-gradient-from: color-mix(in srgb, oklch(28.2% 0.091 267.935) 80%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          --tw-gradient-from: color-mix(in oklab, var(--color-blue-950) 80%, transparent);
        }

        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-cyan-950 {
        --tw-gradient-from: var(--color-cyan-950);
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-emerald-300 {
        --tw-gradient-from: var(--color-emerald-300);
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-emerald-400 {
        --tw-gradient-from: var(--color-emerald-400);
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-emerald-500 {
        --tw-gradient-from: var(--color-emerald-500);
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-emerald-600 {
        --tw-gradient-from: var(--color-emerald-600);
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-emerald-950 {
        --tw-gradient-from: var(--color-emerald-950);
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-emerald-950\/80 {
        --tw-gradient-from: color-mix(in srgb, oklch(26.2% 0.051 172.552) 80%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          --tw-gradient-from: color-mix(in oklab, var(--color-emerald-950) 80%, transparent);
        }

        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-fuchsia-950 {
        --tw-gradient-from: var(--color-fuchsia-950);
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-green-950 {
        --tw-gradient-from: var(--color-green-950);
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-orange-500 {
        --tw-gradient-from: var(--color-orange-500);
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-orange-600 {
        --tw-gradient-from: var(--color-orange-600);
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-orange-950 {
        --tw-gradient-from: var(--color-orange-950);
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-purple-500 {
        --tw-gradient-from: var(--color-purple-500);
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-purple-950 {
        --tw-gradient-from: var(--color-purple-950);
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-purple-950\/80 {
        --tw-gradient-from: color-mix(in srgb, oklch(29.1% 0.149 302.717) 80%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          --tw-gradient-from: color-mix(in oklab, var(--color-purple-950) 80%, transparent);
        }

        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-purple-950\/90 {
        --tw-gradient-from: color-mix(in srgb, oklch(29.1% 0.149 302.717) 90%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          --tw-gradient-from: color-mix(in oklab, var(--color-purple-950) 90%, transparent);
        }

        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-red-600 {
        --tw-gradient-from: var(--color-red-600);
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-red-950 {
        --tw-gradient-from: var(--color-red-950);
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-red-950\/80 {
        --tw-gradient-from: color-mix(in srgb, oklch(25.8% 0.092 26.042) 80%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          --tw-gradient-from: color-mix(in oklab, var(--color-red-950) 80%, transparent);
        }

        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-rose-950 {
        --tw-gradient-from: var(--color-rose-950);
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-sky-500 {
        --tw-gradient-from: var(--color-sky-500);
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-sky-950 {
        --tw-gradient-from: var(--color-sky-950);
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-slate-900 {
        --tw-gradient-from: var(--color-slate-900);
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-stone-900 {
        --tw-gradient-from: var(--color-stone-900);
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-stone-950 {
        --tw-gradient-from: var(--color-stone-950);
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-yellow-900\/90 {
        --tw-gradient-from: color-mix(in srgb, oklch(42.1% 0.095 57.708) 90%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          --tw-gradient-from: color-mix(in oklab, var(--color-yellow-900) 90%, transparent);
        }

        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .from-yellow-950 {
        --tw-gradient-from: var(--color-yellow-950);
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .via-\[\#0e0703\] {
        --tw-gradient-via: #0e0703;
        --tw-gradient-via-stops: var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-via) var(--tw-gradient-via-position), var(--tw-gradient-to) var(--tw-gradient-to-position);
        --tw-gradient-stops: var(--tw-gradient-via-stops);
      }

      .via-\[\#1a0c03\] {
        --tw-gradient-via: #1a0c03;
        --tw-gradient-via-stops: var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-via) var(--tw-gradient-via-position), var(--tw-gradient-to) var(--tw-gradient-to-position);
        --tw-gradient-stops: var(--tw-gradient-via-stops);
      }

      .via-\[\#1a0e06\] {
        --tw-gradient-via: #1a0e06;
        --tw-gradient-via-stops: var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-via) var(--tw-gradient-via-position), var(--tw-gradient-to) var(--tw-gradient-to-position);
        --tw-gradient-stops: var(--tw-gradient-via-stops);
      }

      .via-\[\#1d0f07\] {
        --tw-gradient-via: #1d0f07;
        --tw-gradient-via-stops: var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-via) var(--tw-gradient-via-position), var(--tw-gradient-to) var(--tw-gradient-to-position);
        --tw-gradient-stops: var(--tw-gradient-via-stops);
      }

      .via-\[\#1e0e05\] {
        --tw-gradient-via: #1e0e05;
        --tw-gradient-via-stops: var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-via) var(--tw-gradient-via-position), var(--tw-gradient-to) var(--tw-gradient-to-position);
        --tw-gradient-stops: var(--tw-gradient-via-stops);
      }

      .via-\[\#1f0f06\] {
        --tw-gradient-via: #1f0f06;
        --tw-gradient-via-stops: var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-via) var(--tw-gradient-via-position), var(--tw-gradient-to) var(--tw-gradient-to-position);
        --tw-gradient-stops: var(--tw-gradient-via-stops);
      }

      .via-\[\#2a170a\] {
        --tw-gradient-via: #2a170a;
        --tw-gradient-via-stops: var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-via) var(--tw-gradient-via-position), var(--tw-gradient-to) var(--tw-gradient-to-position);
        --tw-gradient-stops: var(--tw-gradient-via-stops);
      }

      .via-\[\#2a1608\] {
        --tw-gradient-via: #2a1608;
        --tw-gradient-via-stops: var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-via) var(--tw-gradient-via-position), var(--tw-gradient-to) var(--tw-gradient-to-position);
        --tw-gradient-stops: var(--tw-gradient-via-stops);
      }

      .via-\[\#120a04\] {
        --tw-gradient-via: #120a04;
        --tw-gradient-via-stops: var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-via) var(--tw-gradient-via-position), var(--tw-gradient-to) var(--tw-gradient-to-position);
        --tw-gradient-stops: var(--tw-gradient-via-stops);
      }

      .via-\[\#140a04\] {
        --tw-gradient-via: #140a04;
        --tw-gradient-via-stops: var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-via) var(--tw-gradient-via-position), var(--tw-gradient-to) var(--tw-gradient-to-position);
        --tw-gradient-stops: var(--tw-gradient-via-stops);
      }

      .via-\[\#140b04\] {
        --tw-gradient-via: #140b04;
        --tw-gradient-via-stops: var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-via) var(--tw-gradient-via-position), var(--tw-gradient-to) var(--tw-gradient-to-position);
        --tw-gradient-stops: var(--tw-gradient-via-stops);
      }

      .via-\[\#140b05\]\/60 {
        --tw-gradient-via: color-mix(in oklab, #140b05 60%, transparent);
        --tw-gradient-via-stops: var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-via) var(--tw-gradient-via-position), var(--tw-gradient-to) var(--tw-gradient-to-position);
        --tw-gradient-stops: var(--tw-gradient-via-stops);
      }

      .via-\[\#160b05\] {
        --tw-gradient-via: #160b05;
        --tw-gradient-via-stops: var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-via) var(--tw-gradient-via-position), var(--tw-gradient-to) var(--tw-gradient-to-position);
        --tw-gradient-stops: var(--tw-gradient-via-stops);
      }

      .via-\[\#160d06\] {
        --tw-gradient-via: #160d06;
        --tw-gradient-via-stops: var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-via) var(--tw-gradient-via-position), var(--tw-gradient-to) var(--tw-gradient-to-position);
        --tw-gradient-stops: var(--tw-gradient-via-stops);
      }

      .via-\[\#180f0a\] {
        --tw-gradient-via: #180f0a;
        --tw-gradient-via-stops: var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-via) var(--tw-gradient-via-position), var(--tw-gradient-to) var(--tw-gradient-to-position);
        --tw-gradient-stops: var(--tw-gradient-via-stops);
      }

      .via-\[\#381d0c\] {
        --tw-gradient-via: #381d0c;
        --tw-gradient-via-stops: var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-via) var(--tw-gradient-via-position), var(--tw-gradient-to) var(--tw-gradient-to-position);
        --tw-gradient-stops: var(--tw-gradient-via-stops);
      }

      .via-\[\#431d08\] {
        --tw-gradient-via: #431d08;
        --tw-gradient-via-stops: var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-via) var(--tw-gradient-via-position), var(--tw-gradient-to) var(--tw-gradient-to-position);
        --tw-gradient-stops: var(--tw-gradient-via-stops);
      }

      .via-\[\#24160d\] {
        --tw-gradient-via: #24160d;
        --tw-gradient-via-stops: var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-via) var(--tw-gradient-via-position), var(--tw-gradient-to) var(--tw-gradient-to-position);
        --tw-gradient-stops: var(--tw-gradient-via-stops);
      }

      .via-\[\#42220b\] {
        --tw-gradient-via: #42220b;
        --tw-gradient-via-stops: var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-via) var(--tw-gradient-via-position), var(--tw-gradient-to) var(--tw-gradient-to-position);
        --tw-gradient-stops: var(--tw-gradient-via-stops);
      }

      .via-\[\#120803\]\/40 {
        --tw-gradient-via: color-mix(in oklab, #120803 40%, transparent);
        --tw-gradient-via-stops: var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-via) var(--tw-gradient-via-position), var(--tw-gradient-to) var(--tw-gradient-to-position);
        --tw-gradient-stops: var(--tw-gradient-via-stops);
      }

      .via-\[\#482208\] {
        --tw-gradient-via: #482208;
        --tw-gradient-via-stops: var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-via) var(--tw-gradient-via-position), var(--tw-gradient-to) var(--tw-gradient-to-position);
        --tw-gradient-stops: var(--tw-gradient-via-stops);
      }

      .via-amber-400 {
        --tw-gradient-via: var(--color-amber-400);
        --tw-gradient-via-stops: var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-via) var(--tw-gradient-via-position), var(--tw-gradient-to) var(--tw-gradient-to-position);
        --tw-gradient-stops: var(--tw-gradient-via-stops);
      }

      .via-amber-500 {
        --tw-gradient-via: var(--color-amber-500);
        --tw-gradient-via-stops: var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-via) var(--tw-gradient-via-position), var(--tw-gradient-to) var(--tw-gradient-to-position);
        --tw-gradient-stops: var(--tw-gradient-via-stops);
      }

      .via-amber-900\/80 {
        --tw-gradient-via: color-mix(in srgb, oklch(41.4% 0.112 45.904) 80%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          --tw-gradient-via: color-mix(in oklab, var(--color-amber-900) 80%, transparent);
        }

        --tw-gradient-via-stops: var(--tw-gradient-position),
        var(--tw-gradient-from) var(--tw-gradient-from-position),
        var(--tw-gradient-via) var(--tw-gradient-via-position),
        var(--tw-gradient-to) var(--tw-gradient-to-position);
        --tw-gradient-stops: var(--tw-gradient-via-stops);
      }

      .via-amber-950 {
        --tw-gradient-via: var(--color-amber-950);
        --tw-gradient-via-stops: var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-via) var(--tw-gradient-via-position), var(--tw-gradient-to) var(--tw-gradient-to-position);
        --tw-gradient-stops: var(--tw-gradient-via-stops);
      }

      .via-amber-950\/40 {
        --tw-gradient-via: color-mix(in srgb, oklch(27.9% 0.077 45.635) 40%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          --tw-gradient-via: color-mix(in oklab, var(--color-amber-950) 40%, transparent);
        }

        --tw-gradient-via-stops: var(--tw-gradient-position),
        var(--tw-gradient-from) var(--tw-gradient-from-position),
        var(--tw-gradient-via) var(--tw-gradient-via-position),
        var(--tw-gradient-to) var(--tw-gradient-to-position);
        --tw-gradient-stops: var(--tw-gradient-via-stops);
      }

      .via-blue-950 {
        --tw-gradient-via: var(--color-blue-950);
        --tw-gradient-via-stops: var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-via) var(--tw-gradient-via-position), var(--tw-gradient-to) var(--tw-gradient-to-position);
        --tw-gradient-stops: var(--tw-gradient-via-stops);
      }

      .via-indigo-950 {
        --tw-gradient-via: var(--color-indigo-950);
        --tw-gradient-via-stops: var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-via) var(--tw-gradient-via-position), var(--tw-gradient-to) var(--tw-gradient-to-position);
        --tw-gradient-stops: var(--tw-gradient-via-stops);
      }

      .via-lime-950 {
        --tw-gradient-via: var(--color-lime-950);
        --tw-gradient-via-stops: var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-via) var(--tw-gradient-via-position), var(--tw-gradient-to) var(--tw-gradient-to-position);
        --tw-gradient-stops: var(--tw-gradient-via-stops);
      }

      .via-orange-400 {
        --tw-gradient-via: var(--color-orange-400);
        --tw-gradient-via-stops: var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-via) var(--tw-gradient-via-position), var(--tw-gradient-to) var(--tw-gradient-to-position);
        --tw-gradient-stops: var(--tw-gradient-via-stops);
      }

      .via-orange-500 {
        --tw-gradient-via: var(--color-orange-500);
        --tw-gradient-via-stops: var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-via) var(--tw-gradient-via-position), var(--tw-gradient-to) var(--tw-gradient-to-position);
        --tw-gradient-stops: var(--tw-gradient-via-stops);
      }

      .via-orange-500\/10 {
        --tw-gradient-via: color-mix(in srgb, oklch(70.5% 0.213 47.604) 10%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          --tw-gradient-via: color-mix(in oklab, var(--color-orange-500) 10%, transparent);
        }

        --tw-gradient-via-stops: var(--tw-gradient-position),
        var(--tw-gradient-from) var(--tw-gradient-from-position),
        var(--tw-gradient-via) var(--tw-gradient-via-position),
        var(--tw-gradient-to) var(--tw-gradient-to-position);
        --tw-gradient-stops: var(--tw-gradient-via-stops);
      }

      .via-orange-600 {
        --tw-gradient-via: var(--color-orange-600);
        --tw-gradient-via-stops: var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-via) var(--tw-gradient-via-position), var(--tw-gradient-to) var(--tw-gradient-to-position);
        --tw-gradient-stops: var(--tw-gradient-via-stops);
      }

      .via-orange-600\/10 {
        --tw-gradient-via: color-mix(in srgb, oklch(64.6% 0.222 41.116) 10%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          --tw-gradient-via: color-mix(in oklab, var(--color-orange-600) 10%, transparent);
        }

        --tw-gradient-via-stops: var(--tw-gradient-position),
        var(--tw-gradient-from) var(--tw-gradient-from-position),
        var(--tw-gradient-via) var(--tw-gradient-via-position),
        var(--tw-gradient-to) var(--tw-gradient-to-position);
        --tw-gradient-stops: var(--tw-gradient-via-stops);
      }

      .via-orange-900\/80 {
        --tw-gradient-via: color-mix(in srgb, oklch(40.8% 0.123 38.172) 80%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          --tw-gradient-via: color-mix(in oklab, var(--color-orange-900) 80%, transparent);
        }

        --tw-gradient-via-stops: var(--tw-gradient-position),
        var(--tw-gradient-from) var(--tw-gradient-from-position),
        var(--tw-gradient-via) var(--tw-gradient-via-position),
        var(--tw-gradient-to) var(--tw-gradient-to-position);
        --tw-gradient-stops: var(--tw-gradient-via-stops);
      }

      .via-orange-950 {
        --tw-gradient-via: var(--color-orange-950);
        --tw-gradient-via-stops: var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-via) var(--tw-gradient-via-position), var(--tw-gradient-to) var(--tw-gradient-to-position);
        --tw-gradient-stops: var(--tw-gradient-via-stops);
      }

      .via-pink-950 {
        --tw-gradient-via: var(--color-pink-950);
        --tw-gradient-via-stops: var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-via) var(--tw-gradient-via-position), var(--tw-gradient-to) var(--tw-gradient-to-position);
        --tw-gradient-stops: var(--tw-gradient-via-stops);
      }

      .via-red-950 {
        --tw-gradient-via: var(--color-red-950);
        --tw-gradient-via-stops: var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-via) var(--tw-gradient-via-position), var(--tw-gradient-to) var(--tw-gradient-to-position);
        --tw-gradient-stops: var(--tw-gradient-via-stops);
      }

      .via-red-950\/80 {
        --tw-gradient-via: color-mix(in srgb, oklch(25.8% 0.092 26.042) 80%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          --tw-gradient-via: color-mix(in oklab, var(--color-red-950) 80%, transparent);
        }

        --tw-gradient-via-stops: var(--tw-gradient-position),
        var(--tw-gradient-from) var(--tw-gradient-from-position),
        var(--tw-gradient-via) var(--tw-gradient-via-position),
        var(--tw-gradient-to) var(--tw-gradient-to-position);
        --tw-gradient-stops: var(--tw-gradient-via-stops);
      }

      .via-rose-950 {
        --tw-gradient-via: var(--color-rose-950);
        --tw-gradient-via-stops: var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-via) var(--tw-gradient-via-position), var(--tw-gradient-to) var(--tw-gradient-to-position);
        --tw-gradient-stops: var(--tw-gradient-via-stops);
      }

      .via-slate-900 {
        --tw-gradient-via: var(--color-slate-900);
        --tw-gradient-via-stops: var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-via) var(--tw-gradient-via-position), var(--tw-gradient-to) var(--tw-gradient-to-position);
        --tw-gradient-stops: var(--tw-gradient-via-stops);
      }

      .via-slate-950 {
        --tw-gradient-via: var(--color-slate-950);
        --tw-gradient-via-stops: var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-via) var(--tw-gradient-via-position), var(--tw-gradient-to) var(--tw-gradient-to-position);
        --tw-gradient-stops: var(--tw-gradient-via-stops);
      }

      .via-stone-900 {
        --tw-gradient-via: var(--color-stone-900);
        --tw-gradient-via-stops: var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-via) var(--tw-gradient-via-position), var(--tw-gradient-to) var(--tw-gradient-to-position);
        --tw-gradient-stops: var(--tw-gradient-via-stops);
      }

      .via-stone-950 {
        --tw-gradient-via: var(--color-stone-950);
        --tw-gradient-via-stops: var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-via) var(--tw-gradient-via-position), var(--tw-gradient-to) var(--tw-gradient-to-position);
        --tw-gradient-stops: var(--tw-gradient-via-stops);
      }

      .via-teal-200 {
        --tw-gradient-via: var(--color-teal-200);
        --tw-gradient-via-stops: var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-via) var(--tw-gradient-via-position), var(--tw-gradient-to) var(--tw-gradient-to-position);
        --tw-gradient-stops: var(--tw-gradient-via-stops);
      }

      .via-transparent {
        --tw-gradient-via: transparent;
        --tw-gradient-via-stops: var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-via) var(--tw-gradient-via-position), var(--tw-gradient-to) var(--tw-gradient-to-position);
        --tw-gradient-stops: var(--tw-gradient-via-stops);
      }

      .via-yellow-200 {
        --tw-gradient-via: var(--color-yellow-200);
        --tw-gradient-via-stops: var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-via) var(--tw-gradient-via-position), var(--tw-gradient-to) var(--tw-gradient-to-position);
        --tw-gradient-stops: var(--tw-gradient-via-stops);
      }

      .via-yellow-300 {
        --tw-gradient-via: var(--color-yellow-300);
        --tw-gradient-via-stops: var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-via) var(--tw-gradient-via-position), var(--tw-gradient-to) var(--tw-gradient-to-position);
        --tw-gradient-stops: var(--tw-gradient-via-stops);
      }

      .via-yellow-400 {
        --tw-gradient-via: var(--color-yellow-400);
        --tw-gradient-via-stops: var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-via) var(--tw-gradient-via-position), var(--tw-gradient-to) var(--tw-gradient-to-position);
        --tw-gradient-stops: var(--tw-gradient-via-stops);
      }

      .via-yellow-500\/20 {
        --tw-gradient-via: color-mix(in srgb, oklch(79.5% 0.184 86.047) 20%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          --tw-gradient-via: color-mix(in oklab, var(--color-yellow-500) 20%, transparent);
        }

        --tw-gradient-via-stops: var(--tw-gradient-position),
        var(--tw-gradient-from) var(--tw-gradient-from-position),
        var(--tw-gradient-via) var(--tw-gradient-via-position),
        var(--tw-gradient-to) var(--tw-gradient-to-position);
        --tw-gradient-stops: var(--tw-gradient-via-stops);
      }

      .via-yellow-950 {
        --tw-gradient-via: var(--color-yellow-950);
        --tw-gradient-via-stops: var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-via) var(--tw-gradient-via-position), var(--tw-gradient-to) var(--tw-gradient-to-position);
        --tw-gradient-stops: var(--tw-gradient-via-stops);
      }

      .to-\[\#0c0502\] {
        --tw-gradient-to: #0c0502;
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .to-\[\#0c0603\] {
        --tw-gradient-to: #0c0603;
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .to-\[\#0d0603\] {
        --tw-gradient-to: #0d0603;
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .to-\[\#0d0703\] {
        --tw-gradient-to: #0d0703;
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .to-\[\#0e0703\] {
        --tw-gradient-to: #0e0703;
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .to-\[\#0f0703\] {
        --tw-gradient-to: #0f0703;
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .to-\[\#1a0c04\] {
        --tw-gradient-to: #1a0c04;
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .to-\[\#1a0e05\] {
        --tw-gradient-to: #1a0e05;
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .to-\[\#1a0e06\] {
        --tw-gradient-to: #1a0e06;
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .to-\[\#1c0d04\] {
        --tw-gradient-to: #1c0d04;
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .to-\[\#1c0e07\] {
        --tw-gradient-to: #1c0e07;
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .to-\[\#1e0a02\] {
        --tw-gradient-to: #1e0a02;
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .to-\[\#120a05\] {
        --tw-gradient-to: #120a05;
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .to-\[\#140b05\] {
        --tw-gradient-to: #140b05;
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .to-\[\#150a04\] {
        --tw-gradient-to: #150a04;
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .to-\[\#180f08\] {
        --tw-gradient-to: #180f08;
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .to-\[\#210c02\] {
        --tw-gradient-to: #210c02;
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .to-\[\#120701\] {
        --tw-gradient-to: #120701;
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .to-\[\#120703\] {
        --tw-gradient-to: #120703;
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .to-\[\#120803\] {
        --tw-gradient-to: #120803;
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .to-\[\#140803\] {
        --tw-gradient-to: #140803;
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .to-\[\#201006\] {
        --tw-gradient-to: #201006;
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .to-amber-200 {
        --tw-gradient-to: var(--color-amber-200);
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .to-amber-300 {
        --tw-gradient-to: var(--color-amber-300);
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .to-amber-400 {
        --tw-gradient-to: var(--color-amber-400);
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .to-amber-500 {
        --tw-gradient-to: var(--color-amber-500);
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .to-amber-500\/5 {
        --tw-gradient-to: color-mix(in srgb, oklch(76.9% 0.188 70.08) 5%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          --tw-gradient-to: color-mix(in oklab, var(--color-amber-500) 5%, transparent);
        }

        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .to-amber-600 {
        --tw-gradient-to: var(--color-amber-600);
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .to-amber-950 {
        --tw-gradient-to: var(--color-amber-950);
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .to-blue-600 {
        --tw-gradient-to: var(--color-blue-600);
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .to-emerald-500\/30 {
        --tw-gradient-to: color-mix(in srgb, oklch(69.6% 0.17 162.48) 30%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          --tw-gradient-to: color-mix(in oklab, var(--color-emerald-500) 30%, transparent);
        }

        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .to-indigo-600 {
        --tw-gradient-to: var(--color-indigo-600);
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .to-indigo-950\/80 {
        --tw-gradient-to: color-mix(in srgb, oklch(25.7% 0.09 281.288) 80%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          --tw-gradient-to: color-mix(in oklab, var(--color-indigo-950) 80%, transparent);
        }

        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .to-orange-400 {
        --tw-gradient-to: var(--color-orange-400);
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .to-orange-500 {
        --tw-gradient-to: var(--color-orange-500);
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .to-orange-500\/10 {
        --tw-gradient-to: color-mix(in srgb, oklch(70.5% 0.213 47.604) 10%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          --tw-gradient-to: color-mix(in oklab, var(--color-orange-500) 10%, transparent);
        }

        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .to-orange-500\/20 {
        --tw-gradient-to: color-mix(in srgb, oklch(70.5% 0.213 47.604) 20%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          --tw-gradient-to: color-mix(in oklab, var(--color-orange-500) 20%, transparent);
        }

        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .to-orange-500\/40 {
        --tw-gradient-to: color-mix(in srgb, oklch(70.5% 0.213 47.604) 40%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          --tw-gradient-to: color-mix(in oklab, var(--color-orange-500) 40%, transparent);
        }

        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .to-orange-600 {
        --tw-gradient-to: var(--color-orange-600);
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .to-orange-950 {
        --tw-gradient-to: var(--color-orange-950);
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .to-orange-950\/40 {
        --tw-gradient-to: color-mix(in srgb, oklch(26.6% 0.079 36.259) 40%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          --tw-gradient-to: color-mix(in oklab, var(--color-orange-950) 40%, transparent);
        }

        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .to-orange-950\/80 {
        --tw-gradient-to: color-mix(in srgb, oklch(26.6% 0.079 36.259) 80%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          --tw-gradient-to: color-mix(in oklab, var(--color-orange-950) 80%, transparent);
        }

        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .to-red-500 {
        --tw-gradient-to: var(--color-red-500);
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .to-red-500\/20 {
        --tw-gradient-to: color-mix(in srgb, oklch(63.7% 0.237 25.331) 20%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          --tw-gradient-to: color-mix(in oklab, var(--color-red-500) 20%, transparent);
        }

        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .to-red-600 {
        --tw-gradient-to: var(--color-red-600);
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .to-red-700 {
        --tw-gradient-to: var(--color-red-700);
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .to-red-950 {
        --tw-gradient-to: var(--color-red-950);
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .to-rose-950\/80 {
        --tw-gradient-to: color-mix(in srgb, oklch(27.1% 0.105 12.094) 80%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          --tw-gradient-to: color-mix(in oklab, var(--color-rose-950) 80%, transparent);
        }

        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .to-sky-950\/80 {
        --tw-gradient-to: color-mix(in srgb, oklch(29.3% 0.066 243.157) 80%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          --tw-gradient-to: color-mix(in oklab, var(--color-sky-950) 80%, transparent);
        }

        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .to-slate-950 {
        --tw-gradient-to: var(--color-slate-950);
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .to-stone-900 {
        --tw-gradient-to: var(--color-stone-900);
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .to-stone-950 {
        --tw-gradient-to: var(--color-stone-950);
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .to-teal-400 {
        --tw-gradient-to: var(--color-teal-400);
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .to-teal-600 {
        --tw-gradient-to: var(--color-teal-600);
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .to-teal-950\/80 {
        --tw-gradient-to: color-mix(in srgb, oklch(27.7% 0.046 192.524) 80%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          --tw-gradient-to: color-mix(in oklab, var(--color-teal-950) 80%, transparent);
        }

        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .to-transparent {
        --tw-gradient-to: transparent;
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .to-yellow-300 {
        --tw-gradient-to: var(--color-yellow-300);
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .to-yellow-400 {
        --tw-gradient-to: var(--color-yellow-400);
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .to-yellow-600 {
        --tw-gradient-to: var(--color-yellow-600);
        --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
      }

      .bg-clip-text {
        background-clip: text;
      }

      .fill-amber-300 {
        fill: var(--color-amber-300);
      }

      .fill-emerald-400 {
        fill: var(--color-emerald-400);
      }

      .fill-stone-950 {
        fill: var(--color-stone-950);
      }

      .fill-white {
        fill: var(--color-white);
      }

      .object-contain {
        object-fit: contain;
      }

      .object-cover {
        object-fit: cover;
      }

      .object-center {
        object-position: center;
      }

      .p-0\.5 {
        padding: calc(var(--spacing) * 0.5);
      }

      .p-1 {
        padding: var(--spacing);
      }

      .p-1\.5 {
        padding: calc(var(--spacing) * 1.5);
      }

      .p-2 {
        padding: calc(var(--spacing) * 2);
      }

      .p-2\.5 {
        padding: calc(var(--spacing) * 2.5);
      }

      .p-3 {
        padding: calc(var(--spacing) * 3);
      }

      .p-3\.5 {
        padding: calc(var(--spacing) * 3.5);
      }

      .p-4 {
        padding: calc(var(--spacing) * 4);
      }

      .p-5 {
        padding: calc(var(--spacing) * 5);
      }

      .p-6 {
        padding: calc(var(--spacing) * 6);
      }

      .p-8 {
        padding: calc(var(--spacing) * 8);
      }

      .p-10 {
        padding: calc(var(--spacing) * 10);
      }

      .px-1 {
        padding-inline: var(--spacing);
      }

      .px-1\.5 {
        padding-inline: calc(var(--spacing) * 1.5);
      }

      .px-2 {
        padding-inline: calc(var(--spacing) * 2);
      }

      .px-2\.5 {
        padding-inline: calc(var(--spacing) * 2.5);
      }

      .px-3 {
        padding-inline: calc(var(--spacing) * 3);
      }

      .px-3\.5 {
        padding-inline: calc(var(--spacing) * 3.5);
      }

      .px-4 {
        padding-inline: calc(var(--spacing) * 4);
      }

      .px-5 {
        padding-inline: calc(var(--spacing) * 5);
      }

      .px-6 {
        padding-inline: calc(var(--spacing) * 6);
      }

      .px-8 {
        padding-inline: calc(var(--spacing) * 8);
      }

      .py-0\.5 {
        padding-block: calc(var(--spacing) * 0.5);
      }

      .py-1 {
        padding-block: var(--spacing);
      }

      .py-1\.5 {
        padding-block: calc(var(--spacing) * 1.5);
      }

      .py-2 {
        padding-block: calc(var(--spacing) * 2);
      }

      .py-2\.5 {
        padding-block: calc(var(--spacing) * 2.5);
      }

      .py-3 {
        padding-block: calc(var(--spacing) * 3);
      }

      .py-3\.5 {
        padding-block: calc(var(--spacing) * 3.5);
      }

      .py-4 {
        padding-block: calc(var(--spacing) * 4);
      }

      .py-10 {
        padding-block: calc(var(--spacing) * 10);
      }

      .pt-0 {
        padding-top: 0px;
      }

      .pt-0\.5 {
        padding-top: calc(var(--spacing) * 0.5);
      }

      .pt-1 {
        padding-top: var(--spacing);
      }

      .pt-2 {
        padding-top: calc(var(--spacing) * 2);
      }

      .pt-3 {
        padding-top: calc(var(--spacing) * 3);
      }

      .pt-4 {
        padding-top: calc(var(--spacing) * 4);
      }

      .pt-6 {
        padding-top: calc(var(--spacing) * 6);
      }

      .pt-7 {
        padding-top: calc(var(--spacing) * 7);
      }

      .pt-10 {
        padding-top: calc(var(--spacing) * 10);
      }

      .pr-2 {
        padding-right: calc(var(--spacing) * 2);
      }

      .pr-3 {
        padding-right: calc(var(--spacing) * 3);
      }

      .pr-4 {
        padding-right: calc(var(--spacing) * 4);
      }

      .pr-6 {
        padding-right: calc(var(--spacing) * 6);
      }

      .pr-8 {
        padding-right: calc(var(--spacing) * 8);
      }

      .pr-10 {
        padding-right: calc(var(--spacing) * 10);
      }

      .pb-1 {
        padding-bottom: var(--spacing);
      }

      .pb-1\.5 {
        padding-bottom: calc(var(--spacing) * 1.5);
      }

      .pb-2 {
        padding-bottom: calc(var(--spacing) * 2);
      }

      .pb-3 {
        padding-bottom: calc(var(--spacing) * 3);
      }

      .pb-4 {
        padding-bottom: calc(var(--spacing) * 4);
      }

      .pb-5 {
        padding-bottom: calc(var(--spacing) * 5);
      }

      .pb-8 {
        padding-bottom: calc(var(--spacing) * 8);
      }

      .pb-10 {
        padding-bottom: calc(var(--spacing) * 10);
      }

      .pb-12 {
        padding-bottom: calc(var(--spacing) * 12);
      }

      .pb-20 {
        padding-bottom: calc(var(--spacing) * 20);
      }

      .pl-1 {
        padding-left: var(--spacing);
      }

      .pl-3 {
        padding-left: calc(var(--spacing) * 3);
      }

      .pl-8 {
        padding-left: calc(var(--spacing) * 8);
      }

      .pl-10 {
        padding-left: calc(var(--spacing) * 10);
      }

      .pl-20 {
        padding-left: calc(var(--spacing) * 20);
      }

      .text-center {
        text-align: center;
      }

      .text-left {
        text-align: left;
      }

      .text-right {
        text-align: right;
      }

      .font-mono {
        font-family: var(--font-mono);
      }

      .font-sans {
        font-family: var(--font-sans);
      }

      .text-2xl {
        font-size: var(--text-2xl);
        line-height: var(--tw-leading, var(--text-2xl--line-height));
      }

      .text-3xl {
        font-size: var(--text-3xl);
        line-height: var(--tw-leading, var(--text-3xl--line-height));
      }

      .text-4xl {
        font-size: var(--text-4xl);
        line-height: var(--tw-leading, var(--text-4xl--line-height));
      }

      .text-base {
        font-size: var(--text-base);
        line-height: var(--tw-leading, var(--text-base--line-height));
      }

      .text-lg {
        font-size: var(--text-lg);
        line-height: var(--tw-leading, var(--text-lg--line-height));
      }

      .text-sm {
        font-size: var(--text-sm);
        line-height: var(--tw-leading, var(--text-sm--line-height));
      }

      .text-xl {
        font-size: var(--text-xl);
        line-height: var(--tw-leading, var(--text-xl--line-height));
      }

      .text-xs {
        font-size: var(--text-xs);
        line-height: var(--tw-leading, var(--text-xs--line-height));
      }

      .text-\[7px\] {
        font-size: 7px;
      }

      .text-\[8px\] {
        font-size: 8px;
      }

      .text-\[9px\] {
        font-size: 9px;
      }

      .text-\[10px\] {
        font-size: 10px;
      }

      .text-\[11px\] {
        font-size: 11px;
      }

      .leading-none {
        --tw-leading: 1;
        line-height: 1;
      }

      .leading-relaxed {
        --tw-leading: var(--leading-relaxed);
        line-height: var(--leading-relaxed);
      }

      .leading-snug {
        --tw-leading: var(--leading-snug);
        line-height: var(--leading-snug);
      }

      .leading-tight {
        --tw-leading: var(--leading-tight);
        line-height: var(--leading-tight);
      }

      .font-black {
        --tw-font-weight: var(--font-weight-black);
        font-weight: var(--font-weight-black);
      }

      .font-bold {
        --tw-font-weight: var(--font-weight-bold);
        font-weight: var(--font-weight-bold);
      }

      .font-extrabold {
        --tw-font-weight: var(--font-weight-extrabold);
        font-weight: var(--font-weight-extrabold);
      }

      .font-medium {
        --tw-font-weight: var(--font-weight-medium);
        font-weight: var(--font-weight-medium);
      }

      .font-normal {
        --tw-font-weight: var(--font-weight-normal);
        font-weight: var(--font-weight-normal);
      }

      .font-semibold {
        --tw-font-weight: var(--font-weight-semibold);
        font-weight: var(--font-weight-semibold);
      }

      .tracking-tight {
        --tw-tracking: var(--tracking-tight);
        letter-spacing: var(--tracking-tight);
      }

      .tracking-tighter {
        --tw-tracking: var(--tracking-tighter);
        letter-spacing: var(--tracking-tighter);
      }

      .tracking-wide {
        --tw-tracking: var(--tracking-wide);
        letter-spacing: var(--tracking-wide);
      }

      .tracking-wider {
        --tw-tracking: var(--tracking-wider);
        letter-spacing: var(--tracking-wider);
      }

      .tracking-widest {
        --tw-tracking: var(--tracking-widest);
        letter-spacing: var(--tracking-widest);
      }

      .whitespace-nowrap {
        white-space: nowrap;
      }

      .text-\[\#25D366\] {
        color: #25D366;
      }

      .text-\[\#229ED9\] {
        color: #229ED9;
      }

      .text-\[\#1877F2\] {
        color: #1877F2;
      }

      .text-amber-100\/70 {
        color: color-mix(in srgb, oklch(96.2% 0.059 95.617) 70%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          color: color-mix(in oklab, var(--color-amber-100) 70%, transparent);
        }
      }

      .text-amber-100\/80 {
        color: color-mix(in srgb, oklch(96.2% 0.059 95.617) 80%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          color: color-mix(in oklab, var(--color-amber-100) 80%, transparent);
        }
      }

      .text-amber-200 {
        color: var(--color-amber-200);
      }

      .text-amber-200\/80 {
        color: color-mix(in srgb, oklch(92.4% 0.12 95.746) 80%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          color: color-mix(in oklab, var(--color-amber-200) 80%, transparent);
        }
      }

      .text-amber-200\/90 {
        color: color-mix(in srgb, oklch(92.4% 0.12 95.746) 90%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          color: color-mix(in oklab, var(--color-amber-200) 90%, transparent);
        }
      }

      .text-amber-300 {
        color: var(--color-amber-300);
      }

      .text-amber-300\/90 {
        color: color-mix(in srgb, oklch(87.9% 0.169 91.605) 90%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          color: color-mix(in oklab, var(--color-amber-300) 90%, transparent);
        }
      }

      .text-amber-400 {
        color: var(--color-amber-400);
      }

      .text-amber-400\/90 {
        color: color-mix(in srgb, oklch(82.8% 0.189 84.429) 90%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          color: color-mix(in oklab, var(--color-amber-400) 90%, transparent);
        }
      }

      .text-amber-500 {
        color: var(--color-amber-500);
      }

      .text-amber-950 {
        color: var(--color-amber-950);
      }

      .text-amber-950\/90 {
        color: color-mix(in srgb, oklch(27.9% 0.077 45.635) 90%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          color: color-mix(in oklab, var(--color-amber-950) 90%, transparent);
        }
      }

      .text-blue-300 {
        color: var(--color-blue-300);
      }

      .text-blue-400 {
        color: var(--color-blue-400);
      }

      .text-cyan-300 {
        color: var(--color-cyan-300);
      }

      .text-cyan-400 {
        color: var(--color-cyan-400);
      }

      .text-emerald-300 {
        color: var(--color-emerald-300);
      }

      .text-emerald-400 {
        color: var(--color-emerald-400);
      }

      .text-green-400 {
        color: var(--color-green-400);
      }

      .text-lime-400 {
        color: var(--color-lime-400);
      }

      .text-orange-400 {
        color: var(--color-orange-400);
      }

      .text-orange-500 {
        color: var(--color-orange-500);
      }

      .text-orange-500\/60 {
        color: color-mix(in srgb, oklch(70.5% 0.213 47.604) 60%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          color: color-mix(in oklab, var(--color-orange-500) 60%, transparent);
        }
      }

      .text-pink-300 {
        color: var(--color-pink-300);
      }

      .text-pink-400 {
        color: var(--color-pink-400);
      }

      .text-purple-200 {
        color: var(--color-purple-200);
      }

      .text-purple-300 {
        color: var(--color-purple-300);
      }

      .text-purple-400 {
        color: var(--color-purple-400);
      }

      .text-red-300 {
        color: var(--color-red-300);
      }

      .text-red-400 {
        color: var(--color-red-400);
      }

      .text-red-500 {
        color: var(--color-red-500);
      }

      .text-rose-200 {
        color: var(--color-rose-200);
      }

      .text-rose-300 {
        color: var(--color-rose-300);
      }

      .text-rose-400 {
        color: var(--color-rose-400);
      }

      .text-sky-300 {
        color: var(--color-sky-300);
      }

      .text-sky-400 {
        color: var(--color-sky-400);
      }

      .text-slate-100 {
        color: var(--color-slate-100);
      }

      .text-stone-100 {
        color: var(--color-stone-100);
      }

      .text-stone-200 {
        color: var(--color-stone-200);
      }

      .text-stone-300 {
        color: var(--color-stone-300);
      }

      .text-stone-400 {
        color: var(--color-stone-400);
      }

      .text-stone-500 {
        color: var(--color-stone-500);
      }

      .text-stone-600 {
        color: var(--color-stone-600);
      }

      .text-stone-800 {
        color: var(--color-stone-800);
      }

      .text-stone-900 {
        color: var(--color-stone-900);
      }

      .text-stone-900\/80 {
        color: color-mix(in srgb, oklch(21.6% 0.006 56.043) 80%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          color: color-mix(in oklab, var(--color-stone-900) 80%, transparent);
        }
      }

      .text-stone-900\/90 {
        color: color-mix(in srgb, oklch(21.6% 0.006 56.043) 90%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          color: color-mix(in oklab, var(--color-stone-900) 90%, transparent);
        }
      }

      .text-stone-950 {
        color: var(--color-stone-950);
      }

      .text-teal-400 {
        color: var(--color-teal-400);
      }

      .text-transparent {
        color: transparent;
      }

      .text-white {
        color: var(--color-white);
      }

      .text-yellow-300 {
        color: var(--color-yellow-300);
      }

      .text-yellow-400 {
        color: var(--color-yellow-400);
      }

      .capitalize {
        text-transform: capitalize;
      }

      .uppercase {
        text-transform: uppercase;
      }

      .italic {
        font-style: italic;
      }

      .underline {
        text-decoration-line: underline;
      }

      .placeholder-stone-500::placeholder {
        color: var(--color-stone-500);
      }

      .opacity-0 {
        opacity: 0%;
      }

      .opacity-20 {
        opacity: 20%;
      }

      .opacity-30 {
        opacity: 30%;
      }

      .opacity-40 {
        opacity: 40%;
      }

      .opacity-50 {
        opacity: 50%;
      }

      .opacity-70 {
        opacity: 70%;
      }

      .opacity-75 {
        opacity: 75%;
      }

      .opacity-80 {
        opacity: 80%;
      }

      .opacity-90 {
        opacity: 90%;
      }

      .opacity-100 {
        opacity: 100%;
      }

      .mix-blend-overlay {
        mix-blend-mode: overlay;
      }

      .shadow {
        --tw-shadow: 0 1px 3px 0 var(--tw-shadow-color, rgb(0 0 0 / 0.1)), 0 1px 2px -1px var(--tw-shadow-color, rgb(0 0 0 / 0.1));
        box-shadow: var(--tw-inset-shadow), var(--tw-inset-ring-shadow), var(--tw-ring-offset-shadow), var(--tw-ring-shadow), var(--tw-shadow);
      }

      .shadow-2xl {
        --tw-shadow: 0 25px 50px -12px var(--tw-shadow-color, rgb(0 0 0 / 0.25));
        box-shadow: var(--tw-inset-shadow), var(--tw-inset-ring-shadow), var(--tw-ring-offset-shadow), var(--tw-ring-shadow), var(--tw-shadow);
      }

      .shadow-\[0_0_50px_rgba\(249\,115\,22\,0\.3\)\] {
        --tw-shadow: 0 0 50px var(--tw-shadow-color, rgba(249, 115, 22, 0.3));
        box-shadow: var(--tw-inset-shadow), var(--tw-inset-ring-shadow), var(--tw-ring-offset-shadow), var(--tw-ring-shadow), var(--tw-shadow);
      }

      .shadow-inner {
        --tw-shadow: inset 0 2px 4px 0 var(--tw-shadow-color, rgb(0 0 0 / 0.05));
        box-shadow: var(--tw-inset-shadow), var(--tw-inset-ring-shadow), var(--tw-ring-offset-shadow), var(--tw-ring-shadow), var(--tw-shadow);
      }

      .shadow-lg {
        --tw-shadow: 0 10px 15px -3px var(--tw-shadow-color, rgb(0 0 0 / 0.1)), 0 4px 6px -4px var(--tw-shadow-color, rgb(0 0 0 / 0.1));
        box-shadow: var(--tw-inset-shadow), var(--tw-inset-ring-shadow), var(--tw-ring-offset-shadow), var(--tw-ring-shadow), var(--tw-shadow);
      }

      .shadow-md {
        --tw-shadow: 0 4px 6px -1px var(--tw-shadow-color, rgb(0 0 0 / 0.1)), 0 2px 4px -2px var(--tw-shadow-color, rgb(0 0 0 / 0.1));
        box-shadow: var(--tw-inset-shadow), var(--tw-inset-ring-shadow), var(--tw-ring-offset-shadow), var(--tw-ring-shadow), var(--tw-shadow);
      }

      .shadow-sm {
        --tw-shadow: 0 1px 3px 0 var(--tw-shadow-color, rgb(0 0 0 / 0.1)), 0 1px 2px -1px var(--tw-shadow-color, rgb(0 0 0 / 0.1));
        box-shadow: var(--tw-inset-shadow), var(--tw-inset-ring-shadow), var(--tw-ring-offset-shadow), var(--tw-ring-shadow), var(--tw-shadow);
      }

      .shadow-xl {
        --tw-shadow: 0 20px 25px -5px var(--tw-shadow-color, rgb(0 0 0 / 0.1)), 0 8px 10px -6px var(--tw-shadow-color, rgb(0 0 0 / 0.1));
        box-shadow: var(--tw-inset-shadow), var(--tw-inset-ring-shadow), var(--tw-ring-offset-shadow), var(--tw-ring-shadow), var(--tw-shadow);
      }

      .ring {
        --tw-ring-shadow: var(--tw-ring-inset, ) 0 0 0 calc(1px + var(--tw-ring-offset-width)) var(--tw-ring-color, currentcolor);
        box-shadow: var(--tw-inset-shadow), var(--tw-inset-ring-shadow), var(--tw-ring-offset-shadow), var(--tw-ring-shadow), var(--tw-shadow);
      }

      .ring-2 {
        --tw-ring-shadow: var(--tw-ring-inset, ) 0 0 0 calc(2px + var(--tw-ring-offset-width)) var(--tw-ring-color, currentcolor);
        box-shadow: var(--tw-inset-shadow), var(--tw-inset-ring-shadow), var(--tw-ring-offset-shadow), var(--tw-ring-shadow), var(--tw-shadow);
      }

      .shadow-amber-500\/20 {
        --tw-shadow-color: color-mix(in srgb, oklch(76.9% 0.188 70.08) 20%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          --tw-shadow-color: color-mix(in oklab, color-mix(in oklab, var(--color-amber-500) 20%, transparent) var(--tw-shadow-alpha), transparent);
        }
      }

      .shadow-amber-950 {
        --tw-shadow-color: oklch(27.9% 0.077 45.635);

        @supports (color: color-mix(in lab, red, red)) {
          --tw-shadow-color: color-mix(in oklab, var(--color-amber-950) var(--tw-shadow-alpha), transparent);
        }
      }

      .shadow-amber-950\/80 {
        --tw-shadow-color: color-mix(in srgb, oklch(27.9% 0.077 45.635) 80%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          --tw-shadow-color: color-mix(in oklab, color-mix(in oklab, var(--color-amber-950) 80%, transparent) var(--tw-shadow-alpha), transparent);
        }
      }

      .shadow-amber-950\/90 {
        --tw-shadow-color: color-mix(in srgb, oklch(27.9% 0.077 45.635) 90%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          --tw-shadow-color: color-mix(in oklab, color-mix(in oklab, var(--color-amber-950) 90%, transparent) var(--tw-shadow-alpha), transparent);
        }
      }

      .shadow-orange-950 {
        --tw-shadow-color: oklch(26.6% 0.079 36.259);

        @supports (color: color-mix(in lab, red, red)) {
          --tw-shadow-color: color-mix(in oklab, var(--color-orange-950) var(--tw-shadow-alpha), transparent);
        }
      }

      .shadow-orange-950\/50 {
        --tw-shadow-color: color-mix(in srgb, oklch(26.6% 0.079 36.259) 50%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          --tw-shadow-color: color-mix(in oklab, color-mix(in oklab, var(--color-orange-950) 50%, transparent) var(--tw-shadow-alpha), transparent);
        }
      }

      .shadow-orange-950\/60 {
        --tw-shadow-color: color-mix(in srgb, oklch(26.6% 0.079 36.259) 60%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          --tw-shadow-color: color-mix(in oklab, color-mix(in oklab, var(--color-orange-950) 60%, transparent) var(--tw-shadow-alpha), transparent);
        }
      }

      .shadow-orange-950\/70 {
        --tw-shadow-color: color-mix(in srgb, oklch(26.6% 0.079 36.259) 70%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          --tw-shadow-color: color-mix(in oklab, color-mix(in oklab, var(--color-orange-950) 70%, transparent) var(--tw-shadow-alpha), transparent);
        }
      }

      .shadow-orange-950\/80 {
        --tw-shadow-color: color-mix(in srgb, oklch(26.6% 0.079 36.259) 80%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          --tw-shadow-color: color-mix(in oklab, color-mix(in oklab, var(--color-orange-950) 80%, transparent) var(--tw-shadow-alpha), transparent);
        }
      }

      .shadow-orange-950\/90 {
        --tw-shadow-color: color-mix(in srgb, oklch(26.6% 0.079 36.259) 90%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          --tw-shadow-color: color-mix(in oklab, color-mix(in oklab, var(--color-orange-950) 90%, transparent) var(--tw-shadow-alpha), transparent);
        }
      }

      .shadow-red-900\/50 {
        --tw-shadow-color: color-mix(in srgb, oklch(39.6% 0.141 25.723) 50%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          --tw-shadow-color: color-mix(in oklab, color-mix(in oklab, var(--color-red-900) 50%, transparent) var(--tw-shadow-alpha), transparent);
        }
      }

      .ring-amber-500\/50 {
        --tw-ring-color: color-mix(in srgb, oklch(76.9% 0.188 70.08) 50%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          --tw-ring-color: color-mix(in oklab, var(--color-amber-500) 50%, transparent);
        }
      }

      .ring-emerald-500\/50 {
        --tw-ring-color: color-mix(in srgb, oklch(69.6% 0.17 162.48) 50%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          --tw-ring-color: color-mix(in oklab, var(--color-emerald-500) 50%, transparent);
        }
      }

      .ring-sky-500\/50 {
        --tw-ring-color: color-mix(in srgb, oklch(68.5% 0.169 237.323) 50%, transparent);

        @supports (color: color-mix(in lab, red, red)) {
          --tw-ring-color: color-mix(in oklab, var(--color-sky-500) 50%, transparent);
        }
      }

      .blur {
        --tw-blur: blur(8px);
        filter: var(--tw-blur, ) var(--tw-brightness, ) var(--tw-contrast, ) var(--tw-grayscale, ) var(--tw-hue-rotate, ) var(--tw-invert, ) var(--tw-saturate, ) var(--tw-sepia, ) var(--tw-drop-shadow, );
      }

      .blur-2xl {
        --tw-blur: blur(var(--blur-2xl));
        filter: var(--tw-blur, ) var(--tw-brightness, ) var(--tw-contrast, ) var(--tw-grayscale, ) var(--tw-hue-rotate, ) var(--tw-invert, ) var(--tw-saturate, ) var(--tw-sepia, ) var(--tw-drop-shadow, );
      }

      .blur-3xl {
        --tw-blur: blur(var(--blur-3xl));
        filter: var(--tw-blur, ) var(--tw-brightness, ) var(--tw-contrast, ) var(--tw-grayscale, ) var(--tw-hue-rotate, ) var(--tw-invert, ) var(--tw-saturate, ) var(--tw-sepia, ) var(--tw-drop-shadow, );
      }

      .blur-sm {
        --tw-blur: blur(var(--blur-sm));
        filter: var(--tw-blur, ) var(--tw-brightness, ) var(--tw-contrast, ) var(--tw-grayscale, ) var(--tw-hue-rotate, ) var(--tw-invert, ) var(--tw-saturate, ) var(--tw-sepia, ) var(--tw-drop-shadow, );
      }

      .brightness-50 {
        --tw-brightness: brightness(50%);
        filter: var(--tw-blur, ) var(--tw-brightness, ) var(--tw-contrast, ) var(--tw-grayscale, ) var(--tw-hue-rotate, ) var(--tw-invert, ) var(--tw-saturate, ) var(--tw-sepia, ) var(--tw-drop-shadow, );
      }

      .brightness-110 {
        --tw-brightness: brightness(110%);
        filter: var(--tw-blur, ) var(--tw-brightness, ) var(--tw-contrast, ) var(--tw-grayscale, ) var(--tw-hue-rotate, ) var(--tw-invert, ) var(--tw-saturate, ) var(--tw-sepia, ) var(--tw-drop-shadow, );
      }

      .contrast-110 {
        --tw-contrast: contrast(110%);
        filter: var(--tw-blur, ) var(--tw-brightness, ) var(--tw-contrast, ) var(--tw-grayscale, ) var(--tw-hue-rotate, ) var(--tw-invert, ) var(--tw-saturate, ) var(--tw-sepia, ) var(--tw-drop-shadow, );
      }

      .contrast-125 {
        --tw-contrast: contrast(125%);
        filter: var(--tw-blur, ) var(--tw-brightness, ) var(--tw-contrast, ) var(--tw-grayscale, ) var(--tw-hue-rotate, ) var(--tw-invert, ) var(--tw-saturate, ) var(--tw-sepia, ) var(--tw-drop-shadow, );
      }

      .drop-shadow {
        --tw-drop-shadow-size: drop-shadow(0 1px 2px var(--tw-drop-shadow-color, rgb(0 0 0 / 0.1))) drop-shadow(0 1px 1px var(--tw-drop-shadow-color, rgb(0 0 0 / 0.06)));
        --tw-drop-shadow: drop-shadow(0 1px 2px rgb(0 0 0 / 0.1)) drop-shadow(0 1px 1px rgb(0 0 0 / 0.06));
        filter: var(--tw-blur, ) var(--tw-brightness, ) var(--tw-contrast, ) var(--tw-grayscale, ) var(--tw-hue-rotate, ) var(--tw-invert, ) var(--tw-saturate, ) var(--tw-sepia, ) var(--tw-drop-shadow, );
      }

      .drop-shadow-\[0_2px_6px_rgba\(255\,180\,0\,0\.4\)\] {
        --tw-drop-shadow-size: drop-shadow(0 2px 6px var(--tw-drop-shadow-color, rgba(255, 180, 0, 0.4)));
        --tw-drop-shadow: var(--tw-drop-shadow-size);
        filter: var(--tw-blur, ) var(--tw-brightness, ) var(--tw-contrast, ) var(--tw-grayscale, ) var(--tw-hue-rotate, ) var(--tw-invert, ) var(--tw-saturate, ) var(--tw-sepia, ) var(--tw-drop-shadow, );
      }

      .drop-shadow-\[0_2px_8px_rgba\(255\,180\,0\,0\.4\)\] {
        --tw-drop-shadow-size: drop-shadow(0 2px 8px var(--tw-drop-shadow-color, rgba(255, 180, 0, 0.4)));
        --tw-drop-shadow: var(--tw-drop-shadow-size);
        filter: var(--tw-blur, ) var(--tw-brightness, ) var(--tw-contrast, ) var(--tw-grayscale, ) var(--tw-hue-rotate, ) var(--tw-invert, ) var(--tw-saturate, ) var(--tw-sepia, ) var(--tw-drop-shadow, );
      }

      .drop-shadow-\[0_2px_10px_rgba\(251\,191\,36\,0\.6\)\] {
        --tw-drop-shadow-size: drop-shadow(0 2px 10px var(--tw-drop-shadow-color, rgba(251, 191, 36, 0.6)));
        --tw-drop-shadow: var(--tw-drop-shadow-size);
        filter: var(--tw-blur, ) var(--tw-brightness, ) var(--tw-contrast, ) var(--tw-grayscale, ) var(--tw-hue-rotate, ) var(--tw-invert, ) var(--tw-saturate, ) var(--tw-sepia, ) var(--tw-drop-shadow, );
      }

      .drop-shadow-\[0_4px_8px_rgba\(255\,100\,0\,0\.6\)\] {
        --tw-drop-shadow-size: drop-shadow(0 4px 8px var(--tw-drop-shadow-color, rgba(255, 100, 0, 0.6)));
        --tw-drop-shadow: var(--tw-drop-shadow-size);
        filter: var(--tw-blur, ) var(--tw-brightness, ) var(--tw-contrast, ) var(--tw-grayscale, ) var(--tw-hue-rotate, ) var(--tw-invert, ) var(--tw-saturate, ) var(--tw-sepia, ) var(--tw-drop-shadow, );
      }

      .drop-shadow-md {
        --tw-drop-shadow-size: drop-shadow(0 3px 3px var(--tw-drop-shadow-color, rgb(0 0 0 / 0.12)));
        --tw-drop-shadow: drop-shadow(var(--drop-shadow-md));
        filter: var(--tw-blur, ) var(--tw-brightness, ) var(--tw-contrast, ) var(--tw-grayscale, ) var(--tw-hue-rotate, ) var(--tw-invert, ) var(--tw-saturate, ) var(--tw-sepia, ) var(--tw-drop-shadow, );
      }

      .filter {
        filter: var(--tw-blur, ) var(--tw-brightness, ) var(--tw-contrast, ) var(--tw-grayscale, ) var(--tw-hue-rotate, ) var(--tw-invert, ) var(--tw-saturate, ) var(--tw-sepia, ) var(--tw-drop-shadow, );
      }

      .backdrop-blur {
        --tw-backdrop-blur: blur(8px);
        -webkit-backdrop-filter: var(--tw-backdrop-blur, ) var(--tw-backdrop-brightness, ) var(--tw-backdrop-contrast, ) var(--tw-backdrop-grayscale, ) var(--tw-backdrop-hue-rotate, ) var(--tw-backdrop-invert, ) var(--tw-backdrop-opacity, ) var(--tw-backdrop-saturate, ) var(--tw-backdrop-sepia, );
        backdrop-filter: var(--tw-backdrop-blur, ) var(--tw-backdrop-brightness, ) var(--tw-backdrop-contrast, ) var(--tw-backdrop-grayscale, ) var(--tw-backdrop-hue-rotate, ) var(--tw-backdrop-invert, ) var(--tw-backdrop-opacity, ) var(--tw-backdrop-saturate, ) var(--tw-backdrop-sepia, );
      }

      .backdrop-blur-md {
        --tw-backdrop-blur: blur(var(--blur-md));
        -webkit-backdrop-filter: var(--tw-backdrop-blur, ) var(--tw-backdrop-brightness, ) var(--tw-backdrop-contrast, ) var(--tw-backdrop-grayscale, ) var(--tw-backdrop-hue-rotate, ) var(--tw-backdrop-invert, ) var(--tw-backdrop-opacity, ) var(--tw-backdrop-saturate, ) var(--tw-backdrop-sepia, );
        backdrop-filter: var(--tw-backdrop-blur, ) var(--tw-backdrop-brightness, ) var(--tw-backdrop-contrast, ) var(--tw-backdrop-grayscale, ) var(--tw-backdrop-hue-rotate, ) var(--tw-backdrop-invert, ) var(--tw-backdrop-opacity, ) var(--tw-backdrop-saturate, ) var(--tw-backdrop-sepia, );
      }

      .backdrop-blur-sm {
        --tw-backdrop-blur: blur(var(--blur-sm));
        -webkit-backdrop-filter: var(--tw-backdrop-blur, ) var(--tw-backdrop-brightness, ) var(--tw-backdrop-contrast, ) var(--tw-backdrop-grayscale, ) var(--tw-backdrop-hue-rotate, ) var(--tw-backdrop-invert, ) var(--tw-backdrop-opacity, ) var(--tw-backdrop-saturate, ) var(--tw-backdrop-sepia, );
        backdrop-filter: var(--tw-backdrop-blur, ) var(--tw-backdrop-brightness, ) var(--tw-backdrop-contrast, ) var(--tw-backdrop-grayscale, ) var(--tw-backdrop-hue-rotate, ) var(--tw-backdrop-invert, ) var(--tw-backdrop-opacity, ) var(--tw-backdrop-saturate, ) var(--tw-backdrop-sepia, );
      }

      .transition {
        transition-property: color, background-color, border-color, outline-color, text-decoration-color, fill, stroke, --tw-gradient-from, --tw-gradient-via, --tw-gradient-to, opacity, box-shadow, transform, translate, scale, rotate, filter, -webkit-backdrop-filter, backdrop-filter, display, content-visibility, overlay, pointer-events;
        transition-timing-function: var(--tw-ease, var(--default-transition-timing-function));
        transition-duration: var(--tw-duration, var(--default-transition-duration));
      }

      .transition-all {
        transition-property: all;
        transition-timing-function: var(--tw-ease, var(--default-transition-timing-function));
        transition-duration: var(--tw-duration, var(--default-transition-duration));
      }

      .transition-colors {
        transition-property: color, background-color, border-color, outline-color, text-decoration-color, fill, stroke, --tw-gradient-from, --tw-gradient-via, --tw-gradient-to;
        transition-timing-function: var(--tw-ease, var(--default-transition-timing-function));
        transition-duration: var(--tw-duration, var(--default-transition-duration));
      }

      .transition-opacity {
        transition-property: opacity;
        transition-timing-function: var(--tw-ease, var(--default-transition-timing-function));
        transition-duration: var(--tw-duration, var(--default-transition-duration));
      }

      .transition-transform {
        transition-property: transform, translate, scale, rotate;
        transition-timing-function: var(--tw-ease, var(--default-transition-timing-function));
        transition-duration: var(--tw-duration, var(--default-transition-duration));
      }

      .delay-150 {
        transition-delay: 150ms;
      }

      .delay-300 {
        transition-delay: 300ms;
      }

      .duration-200 {
        --tw-duration: 200ms;
        transition-duration: 200ms;
      }

      .duration-300 {
        --tw-duration: 300ms;
        transition-duration: 300ms;
      }

      .duration-500 {
        --tw-duration: 500ms;
        transition-duration: 500ms;
      }

      .duration-700 {
        --tw-duration: 700ms;
        transition-duration: 700ms;
      }

      .ease-in {
        --tw-ease: var(--ease-in);
        transition-timing-function: var(--ease-in);
      }

      .ease-in-out {
        --tw-ease: var(--ease-in-out);
        transition-timing-function: var(--ease-in-out);
      }

      .ease-out {
        --tw-ease: var(--ease-out);
        transition-timing-function: var(--ease-out);
      }

      .select-none {
        -webkit-user-select: none;
        user-select: none;
      }

      @media (hover: hover) {
        .group-hover\:translate-x-0\.5:is(:where(.group):hover *) {
          --tw-translate-x: calc(var(--spacing) * 0.5);
          translate: var(--tw-translate-x) var(--tw-translate-y);
        }

        .group-hover\:translate-x-1:is(:where(.group):hover *) {
          --tw-translate-x: var(--spacing);
          translate: var(--tw-translate-x) var(--tw-translate-y);
        }

        .group-hover\:scale-100:is(:where(.group):hover *) {
          --tw-scale-x: 100%;
          --tw-scale-y: 100%;
          --tw-scale-z: 100%;
          scale: var(--tw-scale-x) var(--tw-scale-y);
        }

        .group-hover\:scale-105:is(:where(.group):hover *) {
          --tw-scale-x: 105%;
          --tw-scale-y: 105%;
          --tw-scale-z: 105%;
          scale: var(--tw-scale-x) var(--tw-scale-y);
        }

        .group-hover\:scale-110:is(:where(.group):hover *) {
          --tw-scale-x: 110%;
          --tw-scale-y: 110%;
          --tw-scale-z: 110%;
          scale: var(--tw-scale-x) var(--tw-scale-y);
        }

        .group-hover\:rotate-12:is(:where(.group):hover *) {
          rotate: 12deg;
        }

        .group-hover\:bg-amber-500:is(:where(.group):hover *) {
          background-color: var(--color-amber-500);
        }

        .group-hover\:text-amber-300:is(:where(.group):hover *) {
          color: var(--color-amber-300);
        }

        .group-hover\:text-amber-400:is(:where(.group):hover *) {
          color: var(--color-amber-400);
        }

        .group-hover\:text-emerald-300:is(:where(.group):hover *) {
          color: var(--color-emerald-300);
        }

        .group-hover\:text-stone-300:is(:where(.group):hover *) {
          color: var(--color-stone-300);
        }

        .group-hover\:text-stone-950:is(:where(.group):hover *) {
          color: var(--color-stone-950);
        }

        .group-hover\:underline:is(:where(.group):hover *) {
          text-decoration-line: underline;
        }

        .group-hover\:opacity-100:is(:where(.group):hover *) {
          opacity: 100%;
        }
      }

      .selection\:bg-orange-500 ::selection {
        background-color: var(--color-orange-500);
      }

      .selection\:bg-orange-500::selection {
        background-color: var(--color-orange-500);
      }

      .selection\:text-white ::selection {
        color: var(--color-white);
      }

      .selection\:text-white::selection {
        color: var(--color-white);
      }

      .focus-within\:border-orange-500:focus-within {
        border-color: var(--color-orange-500);
      }

      @media (hover: hover) {
        .hover\:-translate-y-1:hover {
          --tw-translate-y: calc(var(--spacing) * -1);
          translate: var(--tw-translate-x) var(--tw-translate-y);
        }

        .hover\:scale-105:hover {
          --tw-scale-x: 105%;
          --tw-scale-y: 105%;
          --tw-scale-z: 105%;
          scale: var(--tw-scale-x) var(--tw-scale-y);
        }

        .hover\:scale-110:hover {
          --tw-scale-x: 110%;
          --tw-scale-y: 110%;
          --tw-scale-z: 110%;
          scale: var(--tw-scale-x) var(--tw-scale-y);
        }

        .hover\:scale-\[1\.02\]:hover {
          scale: 1.02;
        }

        .hover\:scale-\[1\.03\]:hover {
          scale: 1.03;
        }

        .hover\:rotate-0:hover {
          rotate: 0deg;
        }

        .hover\:border-\[\#229ED9\]:hover {
          border-color: #229ED9;
        }

        .hover\:border-\[\#1877F2\]:hover {
          border-color: #1877F2;
        }

        .hover\:border-amber-400:hover {
          border-color: var(--color-amber-400);
        }

        .hover\:border-amber-400\/60:hover {
          border-color: color-mix(in srgb, oklch(82.8% 0.189 84.429) 60%, transparent);
        }

        @supports (color: color-mix(in lab, red, red)) {
          .hover\:border-amber-400\/60:hover {
            border-color: color-mix(in oklab, var(--color-amber-400) 60%, transparent);
          }
        }

        .hover\:border-amber-500\/40:hover {
          border-color: color-mix(in srgb, oklch(76.9% 0.188 70.08) 40%, transparent);
        }

        @supports (color: color-mix(in lab, red, red)) {
          .hover\:border-amber-500\/40:hover {
            border-color: color-mix(in oklab, var(--color-amber-500) 40%, transparent);
          }
        }

        .hover\:border-amber-500\/50:hover {
          border-color: color-mix(in srgb, oklch(76.9% 0.188 70.08) 50%, transparent);
        }

        @supports (color: color-mix(in lab, red, red)) {
          .hover\:border-amber-500\/50:hover {
            border-color: color-mix(in oklab, var(--color-amber-500) 50%, transparent);
          }
        }

        .hover\:border-amber-500\/60:hover {
          border-color: color-mix(in srgb, oklch(76.9% 0.188 70.08) 60%, transparent);
        }

        @supports (color: color-mix(in lab, red, red)) {
          .hover\:border-amber-500\/60:hover {
            border-color: color-mix(in oklab, var(--color-amber-500) 60%, transparent);
          }
        }

        .hover\:border-blue-300:hover {
          border-color: var(--color-blue-300);
        }

        .hover\:border-blue-400:hover {
          border-color: var(--color-blue-400);
        }

        .hover\:border-cyan-400:hover {
          border-color: var(--color-cyan-400);
        }

        .hover\:border-cyan-500\/50:hover {
          border-color: color-mix(in srgb, oklch(71.5% 0.143 215.221) 50%, transparent);
        }

        @supports (color: color-mix(in lab, red, red)) {
          .hover\:border-cyan-500\/50:hover {
            border-color: color-mix(in oklab, var(--color-cyan-500) 50%, transparent);
          }
        }

        .hover\:border-emerald-400:hover {
          border-color: var(--color-emerald-400);
        }

        .hover\:border-emerald-500\/40:hover {
          border-color: color-mix(in srgb, oklch(69.6% 0.17 162.48) 40%, transparent);
        }

        @supports (color: color-mix(in lab, red, red)) {
          .hover\:border-emerald-500\/40:hover {
            border-color: color-mix(in oklab, var(--color-emerald-500) 40%, transparent);
          }
        }

        .hover\:border-emerald-500\/50:hover {
          border-color: color-mix(in srgb, oklch(69.6% 0.17 162.48) 50%, transparent);
        }

        @supports (color: color-mix(in lab, red, red)) {
          .hover\:border-emerald-500\/50:hover {
            border-color: color-mix(in oklab, var(--color-emerald-500) 50%, transparent);
          }
        }

        .hover\:border-green-400:hover {
          border-color: var(--color-green-400);
        }

        .hover\:border-lime-400:hover {
          border-color: var(--color-lime-400);
        }

        .hover\:border-orange-400:hover {
          border-color: var(--color-orange-400);
        }

        .hover\:border-orange-500:hover {
          border-color: var(--color-orange-500);
        }

        .hover\:border-orange-500\/50:hover {
          border-color: color-mix(in srgb, oklch(70.5% 0.213 47.604) 50%, transparent);
        }

        @supports (color: color-mix(in lab, red, red)) {
          .hover\:border-orange-500\/50:hover {
            border-color: color-mix(in oklab, var(--color-orange-500) 50%, transparent);
          }
        }

        .hover\:border-orange-500\/60:hover {
          border-color: color-mix(in srgb, oklch(70.5% 0.213 47.604) 60%, transparent);
        }

        @supports (color: color-mix(in lab, red, red)) {
          .hover\:border-orange-500\/60:hover {
            border-color: color-mix(in oklab, var(--color-orange-500) 60%, transparent);
          }
        }

        .hover\:border-orange-500\/80:hover {
          border-color: color-mix(in srgb, oklch(70.5% 0.213 47.604) 80%, transparent);
        }

        @supports (color: color-mix(in lab, red, red)) {
          .hover\:border-orange-500\/80:hover {
            border-color: color-mix(in oklab, var(--color-orange-500) 80%, transparent);
          }
        }

        .hover\:border-pink-400:hover {
          border-color: var(--color-pink-400);
        }

        .hover\:border-purple-400:hover {
          border-color: var(--color-purple-400);
        }

        .hover\:border-purple-500\/50:hover {
          border-color: color-mix(in srgb, oklch(62.7% 0.265 303.9) 50%, transparent);
        }

        @supports (color: color-mix(in lab, red, red)) {
          .hover\:border-purple-500\/50:hover {
            border-color: color-mix(in oklab, var(--color-purple-500) 50%, transparent);
          }
        }

        .hover\:border-red-400:hover {
          border-color: var(--color-red-400);
        }

        .hover\:border-red-500:hover {
          border-color: var(--color-red-500);
        }

        .hover\:border-rose-400:hover {
          border-color: var(--color-rose-400);
        }

        .hover\:border-rose-500\/40:hover {
          border-color: color-mix(in srgb, oklch(64.5% 0.246 16.439) 40%, transparent);
        }

        @supports (color: color-mix(in lab, red, red)) {
          .hover\:border-rose-500\/40:hover {
            border-color: color-mix(in oklab, var(--color-rose-500) 40%, transparent);
          }
        }

        .hover\:border-sky-400:hover {
          border-color: var(--color-sky-400);
        }

        .hover\:border-slate-300:hover {
          border-color: var(--color-slate-300);
        }

        .hover\:border-stone-500:hover {
          border-color: var(--color-stone-500);
        }

        .hover\:border-teal-400:hover {
          border-color: var(--color-teal-400);
        }

        .hover\:border-yellow-400:hover {
          border-color: var(--color-yellow-400);
        }

        .hover\:border-yellow-500\/50:hover {
          border-color: color-mix(in srgb, oklch(79.5% 0.184 86.047) 50%, transparent);
        }

        @supports (color: color-mix(in lab, red, red)) {
          .hover\:border-yellow-500\/50:hover {
            border-color: color-mix(in oklab, var(--color-yellow-500) 50%, transparent);
          }
        }

        .hover\:bg-\[\#1e1109\]:hover {
          background-color: #1e1109;
        }

        .hover\:bg-\[\#1e1209\]:hover {
          background-color: #1e1209;
        }

        .hover\:bg-\[\#2a170b\]:hover {
          background-color: #2a170b;
        }

        .hover\:bg-\[\#2b180d\]:hover {
          background-color: #2b180d;
        }

        .hover\:bg-\[\#2c1a0c\]:hover {
          background-color: #2c1a0c;
        }

        .hover\:bg-\[\#2c1a0e\]:hover {
          background-color: #2c1a0e;
        }

        .hover\:bg-\[\#2c180b\]:hover {
          background-color: #2c180b;
        }

        .hover\:bg-\[\#2e1c10\]:hover {
          background-color: #2e1c10;
        }

        .hover\:bg-\[\#2e1d13\]:hover {
          background-color: #2e1d13;
        }

        .hover\:bg-\[\#2f1809\]:hover {
          background-color: #2f1809;
        }

        .hover\:bg-\[\#3a2618\]:hover {
          background-color: #3a2618;
        }

        .hover\:bg-\[\#3d2a1b\]:hover {
          background-color: #3d2a1b;
        }

        .hover\:bg-\[\#3d2210\]:hover {
          background-color: #3d2210;
        }

        .hover\:bg-\[\#3d2212\]:hover {
          background-color: #3d2212;
        }

        .hover\:bg-\[\#3d2413\]:hover {
          background-color: #3d2413;
        }

        .hover\:bg-\[\#25D366\]\/30:hover {
          background-color: color-mix(in oklab, #25D366 30%, transparent);
        }

        .hover\:bg-\[\#229ED9\]\/30:hover {
          background-color: color-mix(in oklab, #229ED9 30%, transparent);
        }

        .hover\:bg-\[\#273c4e\]:hover {
          background-color: #273c4e;
        }

        .hover\:bg-\[\#281a0f\]:hover {
          background-color: #281a0f;
        }

        .hover\:bg-\[\#281b0a\]:hover {
          background-color: #281b0a;
        }

        .hover\:bg-\[\#321c0e\]:hover {
          background-color: #321c0e;
        }

        .hover\:bg-\[\#321e10\]:hover {
          background-color: #321e10;
        }

        .hover\:bg-\[\#331d0f\]:hover {
          background-color: #331d0f;
        }

        .hover\:bg-\[\#341d0f\]:hover {
          background-color: #341d0f;
        }

        .hover\:bg-\[\#341f11\]:hover {
          background-color: #341f11;
        }

        .hover\:bg-\[\#361e0c\]:hover {
          background-color: #361e0c;
        }

        .hover\:bg-\[\#381e0c\]:hover {
          background-color: #381e0c;
        }

        .hover\:bg-\[\#1877F2\]\/30:hover {
          background-color: color-mix(in oklab, #1877F2 30%, transparent);
        }

        .hover\:bg-\[\#28170b\]:hover {
          background-color: #28170b;
        }

        .hover\:bg-\[\#28180d\]:hover {
          background-color: #28180d;
        }

        .hover\:bg-\[\#28180e\]:hover {
          background-color: #28180e;
        }

        .hover\:bg-\[\#132036\]:hover {
          background-color: #132036;
        }

        .hover\:bg-\[\#152332\]:hover {
          background-color: #152332;
        }

        .hover\:bg-\[\#251409\]:hover {
          background-color: #251409;
        }

        .hover\:bg-\[\#332013\]:hover {
          background-color: #332013;
        }

        .hover\:bg-\[\#382315\]:hover {
          background-color: #382315;
        }

        .hover\:bg-amber-400:hover {
          background-color: var(--color-amber-400);
        }

        .hover\:bg-amber-500\/20:hover {
          background-color: color-mix(in srgb, oklch(76.9% 0.188 70.08) 20%, transparent);
        }

        @supports (color: color-mix(in lab, red, red)) {
          .hover\:bg-amber-500\/20:hover {
            background-color: color-mix(in oklab, var(--color-amber-500) 20%, transparent);
          }
        }

        .hover\:bg-black\/70:hover {
          background-color: color-mix(in srgb, #000 70%, transparent);
        }

        @supports (color: color-mix(in lab, red, red)) {
          .hover\:bg-black\/70:hover {
            background-color: color-mix(in oklab, var(--color-black) 70%, transparent);
          }
        }

        .hover\:bg-black\/80:hover {
          background-color: color-mix(in srgb, #000 80%, transparent);
        }

        @supports (color: color-mix(in lab, red, red)) {
          .hover\:bg-black\/80:hover {
            background-color: color-mix(in oklab, var(--color-black) 80%, transparent);
          }
        }

        .hover\:bg-emerald-400:hover {
          background-color: var(--color-emerald-400);
        }

        .hover\:bg-emerald-500:hover {
          background-color: var(--color-emerald-500);
        }

        .hover\:bg-emerald-500\/20:hover {
          background-color: color-mix(in srgb, oklch(69.6% 0.17 162.48) 20%, transparent);
        }

        @supports (color: color-mix(in lab, red, red)) {
          .hover\:bg-emerald-500\/20:hover {
            background-color: color-mix(in oklab, var(--color-emerald-500) 20%, transparent);
          }
        }

        .hover\:bg-orange-500:hover {
          background-color: var(--color-orange-500);
        }

        .hover\:bg-orange-600:hover {
          background-color: var(--color-orange-600);
        }

        .hover\:bg-rose-500\/20:hover {
          background-color: color-mix(in srgb, oklch(64.5% 0.246 16.439) 20%, transparent);
        }

        @supports (color: color-mix(in lab, red, red)) {
          .hover\:bg-rose-500\/20:hover {
            background-color: color-mix(in oklab, var(--color-rose-500) 20%, transparent);
          }
        }

        .hover\:bg-sky-500:hover {
          background-color: var(--color-sky-500);
        }

        .hover\:bg-sky-500\/30:hover {
          background-color: color-mix(in srgb, oklch(68.5% 0.169 237.323) 30%, transparent);
        }

        @supports (color: color-mix(in lab, red, red)) {
          .hover\:bg-sky-500\/30:hover {
            background-color: color-mix(in oklab, var(--color-sky-500) 30%, transparent);
          }
        }

        .hover\:bg-stone-400:hover {
          background-color: var(--color-stone-400);
        }

        .hover\:bg-stone-700:hover {
          background-color: var(--color-stone-700);
        }

        .hover\:bg-stone-800:hover {
          background-color: var(--color-stone-800);
        }

        .hover\:bg-stone-900:hover {
          background-color: var(--color-stone-900);
        }

        .hover\:bg-stone-950\/40:hover {
          background-color: color-mix(in srgb, oklch(14.7% 0.004 49.25) 40%, transparent);
        }

        @supports (color: color-mix(in lab, red, red)) {
          .hover\:bg-stone-950\/40:hover {
            background-color: color-mix(in oklab, var(--color-stone-950) 40%, transparent);
          }
        }

        .hover\:bg-white:hover {
          background-color: var(--color-white);
        }

        .hover\:from-amber-300:hover {
          --tw-gradient-from: var(--color-amber-300);
          --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
        }

        .hover\:from-amber-400:hover {
          --tw-gradient-from: var(--color-amber-400);
          --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
        }

        .hover\:from-amber-500\/30:hover {
          --tw-gradient-from: color-mix(in srgb, oklch(76.9% 0.188 70.08) 30%, transparent);
        }

        @supports (color: color-mix(in lab, red, red)) {
          .hover\:from-amber-500\/30:hover {
            --tw-gradient-from: color-mix(in oklab, var(--color-amber-500) 30%, transparent);
          }
        }

        .hover\:from-amber-500\/30:hover {
          --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
        }

        .hover\:from-emerald-300:hover {
          --tw-gradient-from: var(--color-emerald-300);
          --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
        }

        .hover\:from-emerald-500:hover {
          --tw-gradient-from: var(--color-emerald-500);
          --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
        }

        .hover\:from-orange-500:hover {
          --tw-gradient-from: var(--color-orange-500);
          --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
        }

        .hover\:from-sky-400:hover {
          --tw-gradient-from: var(--color-sky-400);
          --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
        }

        .hover\:to-blue-500:hover {
          --tw-gradient-to: var(--color-blue-500);
          --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
        }

        .hover\:to-orange-400:hover {
          --tw-gradient-to: var(--color-orange-400);
          --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
        }

        .hover\:to-orange-500:hover {
          --tw-gradient-to: var(--color-orange-500);
          --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
        }

        .hover\:to-orange-500\/30:hover {
          --tw-gradient-to: color-mix(in srgb, oklch(70.5% 0.213 47.604) 30%, transparent);
        }

        @supports (color: color-mix(in lab, red, red)) {
          .hover\:to-orange-500\/30:hover {
            --tw-gradient-to: color-mix(in oklab, var(--color-orange-500) 30%, transparent);
          }
        }

        .hover\:to-orange-500\/30:hover {
          --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
        }

        .hover\:to-red-500:hover {
          --tw-gradient-to: var(--color-red-500);
          --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
        }

        .hover\:to-teal-500:hover {
          --tw-gradient-to: var(--color-teal-500);
          --tw-gradient-stops: var(--tw-gradient-via-stops, var(--tw-gradient-position), var(--tw-gradient-from) var(--tw-gradient-from-position), var(--tw-gradient-to) var(--tw-gradient-to-position));
        }

        .hover\:text-amber-200:hover {
          color: var(--color-amber-200);
        }

        .hover\:text-amber-300:hover {
          color: var(--color-amber-300);
        }

        .hover\:text-amber-400:hover {
          color: var(--color-amber-400);
        }

        .hover\:text-blue-300:hover {
          color: var(--color-blue-300);
        }

        .hover\:text-emerald-300:hover {
          color: var(--color-emerald-300);
        }

        .hover\:text-orange-300:hover {
          color: var(--color-orange-300);
        }

        .hover\:text-rose-300:hover {
          color: var(--color-rose-300);
        }

        .hover\:text-sky-300:hover {
          color: var(--color-sky-300);
        }

        .hover\:text-stone-200:hover {
          color: var(--color-stone-200);
        }

        .hover\:text-stone-300:hover {
          color: var(--color-stone-300);
        }

        .hover\:text-white:hover {
          color: var(--color-white);
        }

        .hover\:underline:hover {
          text-decoration-line: underline;
        }

        .hover\:opacity-100:hover {
          opacity: 100%;
        }

        .hover\:shadow-2xl:hover {
          --tw-shadow: 0 25px 50px -12px var(--tw-shadow-color, rgb(0 0 0 / 0.25));
          box-shadow: var(--tw-inset-shadow), var(--tw-inset-ring-shadow), var(--tw-ring-offset-shadow), var(--tw-ring-shadow), var(--tw-shadow);
        }

        .hover\:shadow-orange-950\/80:hover {
          --tw-shadow-color: color-mix(in srgb, oklch(26.6% 0.079 36.259) 80%, transparent);
        }

        @supports (color: color-mix(in lab, red, red)) {
          .hover\:shadow-orange-950\/80:hover {
            --tw-shadow-color: color-mix(in oklab, color-mix(in oklab, var(--color-orange-950) 80%, transparent) var(--tw-shadow-alpha), transparent);
          }
        }

        .hover\:brightness-110:hover {
          --tw-brightness: brightness(110%);
          filter: var(--tw-blur, ) var(--tw-brightness, ) var(--tw-contrast, ) var(--tw-grayscale, ) var(--tw-hue-rotate, ) var(--tw-invert, ) var(--tw-saturate, ) var(--tw-sepia, ) var(--tw-drop-shadow, );
        }

        .hover\:brightness-125:hover {
          --tw-brightness: brightness(125%);
          filter: var(--tw-blur, ) var(--tw-brightness, ) var(--tw-contrast, ) var(--tw-grayscale, ) var(--tw-hue-rotate, ) var(--tw-invert, ) var(--tw-saturate, ) var(--tw-sepia, ) var(--tw-drop-shadow, );
        }
      }

      .focus\:border-amber-400:focus {
        border-color: var(--color-amber-400);
      }

      .focus\:border-amber-500:focus {
        border-color: var(--color-amber-500);
      }

      .focus\:border-emerald-500:focus {
        border-color: var(--color-emerald-500);
      }

      .focus\:border-orange-500:focus {
        border-color: var(--color-orange-500);
      }

      .focus\:opacity-100:focus {
        opacity: 100%;
      }

      .focus\:ring-0:focus {
        --tw-ring-shadow: var(--tw-ring-inset, ) 0 0 0 calc(0px + var(--tw-ring-offset-width)) var(--tw-ring-color, currentcolor);
        box-shadow: var(--tw-inset-shadow), var(--tw-inset-ring-shadow), var(--tw-ring-offset-shadow), var(--tw-ring-shadow), var(--tw-shadow);
      }

      .focus\:outline-none:focus {
        --tw-outline-style: none;
        outline-style: none;
      }

      .active\:scale-95:active {
        --tw-scale-x: 95%;
        --tw-scale-y: 95%;
        --tw-scale-z: 95%;
        scale: var(--tw-scale-x) var(--tw-scale-y);
      }

      .active\:scale-98:active {
        --tw-scale-x: 98%;
        --tw-scale-y: 98%;
        --tw-scale-z: 98%;
        scale: var(--tw-scale-x) var(--tw-scale-y);
      }

      .active\:scale-\[0\.97\]:active {
        scale: 0.97;
      }

      .active\:scale-\[0\.98\]:active {
        scale: 0.98;
      }

      .disabled\:cursor-not-allowed:disabled {
        cursor: not-allowed;
      }

      .disabled\:border-\[\#2c1a0e\]:disabled {
        border-color: #2c1a0e;
      }

      .disabled\:text-stone-500:disabled {
        color: var(--color-stone-500);
      }

      .disabled\:opacity-40:disabled {
        opacity: 40%;
      }

      .disabled\:opacity-75:disabled {
        opacity: 75%;
      }

      @media (hover: hover) {
        .disabled\:hover\:bg-\[\#25170d\]:disabled:hover {
          background-color: #25170d;
        }
      }

      @media screen and (max-width: 350px) {
        .blog-info{
          flex-wrap: balance;
        }
      }

      @media (width >=420px) {
        .min-\[420px\]\:max-w-\[140px\] {
          max-width: 140px;
        }

        .min-\[420px\]\:grid-cols-3 {
          grid-template-columns: repeat(3, minmax(0, 1fr));
        }
        
      }

      @media (width >=40rem) {
        .sm\:fixed {
          position: fixed;
        }

        .sm\:sticky {
          position: sticky;
        }

        .sm\:top-\[57px\] {
          top: 57px;
        }

        .sm\:right-6 {
          right: calc(var(--spacing) * 6);
        }

        .sm\:right-12 {
          right: calc(var(--spacing) * 12);
        }

        .sm\:bottom-4 {
          bottom: calc(var(--spacing) * 4);
        }

        .sm\:left-6 {
          left: calc(var(--spacing) * 6);
        }

        .sm\:left-\[calc\(15rem\+1\.5rem\)\] {
          left: calc(15rem + 1.5rem);
        }

        .sm\:z-30 {
          z-index: 30;
        }

        .sm\:col-span-1 {
          grid-column: span 1 / span 1;
        }

        .sm\:mx-0 {
          margin-inline: 0px;
        }

        .sm\:mt-2 {
          margin-top: calc(var(--spacing) * 2);
        }

        .sm\:block {
          display: block;
        }

        .sm\:flex {
          display: flex;
        }

        .sm\:hidden {
          display: none;
        }

        .sm\:inline {
          display: inline;
        }

        .sm\:inline-block {
          display: inline-block;
        }

        .sm\:h-6 {
          height: calc(var(--spacing) * 6);
        }

        .sm\:h-10 {
          height: calc(var(--spacing) * 10);
        }

        .sm\:h-11 {
          height: calc(var(--spacing) * 11);
        }

        .sm\:h-16 {
          height: calc(var(--spacing) * 16);
        }

        .sm\:h-20 {
          height: calc(var(--spacing) * 20);
        }

        .sm\:h-24 {
          height: calc(var(--spacing) * 24);
        }

        .sm\:h-32 {
          height: calc(var(--spacing) * 32);
        }

        .sm\:h-64 {
          height: calc(var(--spacing) * 64);
        }

        .sm\:h-72 {
          height: calc(var(--spacing) * 72);
        }

        .sm\:h-96 {
          height: calc(var(--spacing) * 96);
        }

        .sm\:h-\[560px\] {
          height: 560px;
        }

        .sm\:h-\[calc\(100vh-57px\)\] {
          height: calc(100vh - 57px);
        }

        .sm\:min-h-\[240px\] {
          min-height: 240px;
        }

        .sm\:min-h-\[560px\] {
          min-height: 560px;
        }

        .sm\:min-h-\[580px\] {
          min-height: 580px;
        }

        .sm\:w-6 {
          width: calc(var(--spacing) * 6);
        }

        .sm\:w-10 {
          width: calc(var(--spacing) * 10);
        }

        .sm\:w-16 {
          width: calc(var(--spacing) * 16);
        }

        .sm\:w-20 {
          width: calc(var(--spacing) * 20);
        }

        .sm\:w-28 {
          width: calc(var(--spacing) * 28);
        }

        .sm\:w-32 {
          width: calc(var(--spacing) * 32);
        }

        .sm\:w-48 {
          width: calc(var(--spacing) * 48);
        }

        .sm\:w-60 {
          width: calc(var(--spacing) * 60);
        }

        .sm\:w-\[176px\] {
          width: 176px;
        }

        .sm\:w-\[380px\] {
          width: 380px;
        }

        .sm\:w-auto {
          width: auto;
        }

        .sm\:max-w-none {
          max-width: none;
        }

        .sm\:max-w-xs {
          max-width: var(--container-xs);
        }

        .sm\:min-w-\[176px\] {
          min-width: 176px;
        }

        .sm\:grid-cols-2 {
          grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .sm\:grid-cols-3 {
          grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        .sm\:grid-cols-4 {
          grid-template-columns: repeat(4, minmax(0, 1fr));
        }

        .sm\:grid-cols-5 {
          grid-template-columns: repeat(5, minmax(0, 1fr));
        }

        .sm\:flex-row {
          flex-direction: row;
        }

        .sm\:items-center {
          align-items: center;
        }

        .sm\:items-end {
          align-items: flex-end;
        }

        .sm\:gap-2 {
          gap: calc(var(--spacing) * 2);
        }

        .sm\:gap-3 {
          gap: calc(var(--spacing) * 3);
        }

        .sm\:gap-3\.5 {
          gap: calc(var(--spacing) * 3.5);
        }

        :where(.sm\:space-y-4 > :not(:last-child)) {
          --tw-space-y-reverse: 0;
          margin-block-start: calc(calc(var(--spacing) * 4) * var(--tw-space-y-reverse));
          margin-block-end: calc(calc(var(--spacing) * 4) * calc(1 - var(--tw-space-y-reverse)));
        }

        :where(.sm\:space-y-8 > :not(:last-child)) {
          --tw-space-y-reverse: 0;
          margin-block-start: calc(calc(var(--spacing) * 8) * var(--tw-space-y-reverse));
          margin-block-end: calc(calc(var(--spacing) * 8) * calc(1 - var(--tw-space-y-reverse)));
        }

        .sm\:self-auto {
          align-self: auto;
        }

        .sm\:rounded-2xl {
          border-radius: var(--radius-2xl);
        }

        .sm\:rounded-xl {
          border-radius: var(--radius-xl);
        }

        .sm\:border {
          border-style: var(--tw-border-style);
          border-width: 1px;
        }

        .sm\:p-2\.5 {
          padding: calc(var(--spacing) * 2.5);
        }

        .sm\:p-4 {
          padding: calc(var(--spacing) * 4);
        }

        .sm\:p-5 {
          padding: calc(var(--spacing) * 5);
        }

        .sm\:p-6 {
          padding: calc(var(--spacing) * 6);
        }

        .sm\:p-7 {
          padding: calc(var(--spacing) * 7);
        }

        .sm\:p-8 {
          padding: calc(var(--spacing) * 8);
        }

        .sm\:px-2\.5 {
          padding-inline: calc(var(--spacing) * 2.5);
        }

        .sm\:px-3\.5 {
          padding-inline: calc(var(--spacing) * 3.5);
        }

        .sm\:px-4 {
          padding-inline: calc(var(--spacing) * 4);
        }

        .sm\:px-5 {
          padding-inline: calc(var(--spacing) * 5);
        }

        .sm\:px-6 {
          padding-inline: calc(var(--spacing) * 6);
        }

        .sm\:px-8 {
          padding-inline: calc(var(--spacing) * 8);
        }

        .sm\:px-12 {
          padding-inline: calc(var(--spacing) * 12);
        }

        .sm\:py-2\.5 {
          padding-block: calc(var(--spacing) * 2.5);
        }

        .sm\:py-3 {
          padding-block: calc(var(--spacing) * 3);
        }

        .sm\:pb-8 {
          padding-bottom: calc(var(--spacing) * 8);
        }

        .sm\:pb-12 {
          padding-bottom: calc(var(--spacing) * 12);
        }

        .sm\:text-left {
          text-align: left;
        }

        .sm\:text-2xl {
          font-size: var(--text-2xl);
          line-height: var(--tw-leading, var(--text-2xl--line-height));
        }

        .sm\:text-3xl {
          font-size: var(--text-3xl);
          line-height: var(--tw-leading, var(--text-3xl--line-height));
        }

        .sm\:text-4xl {
          font-size: var(--text-4xl);
          line-height: var(--tw-leading, var(--text-4xl--line-height));
        }

        .sm\:text-5xl {
          font-size: var(--text-5xl);
          line-height: var(--tw-leading, var(--text-5xl--line-height));
        }

        .sm\:text-base {
          font-size: var(--text-base);
          line-height: var(--tw-leading, var(--text-base--line-height));
        }

        .sm\:text-lg {
          font-size: var(--text-lg);
          line-height: var(--tw-leading, var(--text-lg--line-height));
        }

        .sm\:text-sm {
          font-size: var(--text-sm);
          line-height: var(--tw-leading, var(--text-sm--line-height));
        }

        .sm\:text-xl {
          font-size: var(--text-xl);
          line-height: var(--tw-leading, var(--text-xl--line-height));
        }

        .sm\:text-xs {
          font-size: var(--text-xs);
          line-height: var(--tw-leading, var(--text-xs--line-height));
        }

        .sm\:text-\[9px\] {
          font-size: 9px;
        }

        .sm\:shadow-none {
          --tw-shadow: 0 0 #0000;
          box-shadow: var(--tw-inset-shadow), var(--tw-inset-ring-shadow), var(--tw-ring-offset-shadow), var(--tw-ring-shadow), var(--tw-shadow);
        }
      }

      @media (width >=48rem) {
        .md\:order-none {
          order: 0;
        }

        .md\:mx-4 {
          margin-inline: calc(var(--spacing) * 4);
        }

        .md\:block {
          display: block;
        }

        .md\:flex {
          display: flex;
        }

        .md\:inline-block {
          display: inline-block;
        }

        .md\:h-80 {
          height: calc(var(--spacing) * 80);
        }

        .md\:min-h-\[300px\] {
          min-height: 300px;
        }

        .md\:max-w-lg {
          max-width: var(--container-lg);
        }

        .md\:flex-1 {
          flex: 1;
        }

        .md\:grid-cols-2 {
          grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .md\:grid-cols-3 {
          grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        .md\:grid-cols-4 {
          grid-template-columns: repeat(4, minmax(0, 1fr));
        }

        .md\:grid-cols-5 {
          grid-template-columns: repeat(5, minmax(0, 1fr));
        }

        .md\:grid-cols-6 {
          grid-template-columns: repeat(6, minmax(0, 1fr));
        }

        .md\:flex-row {
          flex-direction: row;
        }

        .md\:items-center {
          align-items: center;
        }

        .md\:p-6 {
          padding: calc(var(--spacing) * 6);
        }

        .md\:text-4xl {
          font-size: var(--text-4xl);
          line-height: var(--tw-leading, var(--text-4xl--line-height));
        }

        .md\:text-5xl {
          font-size: var(--text-5xl);
          line-height: var(--tw-leading, var(--text-5xl--line-height));
        }

        .md\:text-6xl {
          font-size: var(--text-6xl);
          line-height: var(--tw-leading, var(--text-6xl--line-height));
        }

        .md\:text-xl {
          font-size: var(--text-xl);
          line-height: var(--tw-leading, var(--text-xl--line-height));
        }
      }

      @media (width >=64rem) {
        .lg\:col-span-2 {
          grid-column: span 2 / span 2;
        }

        .lg\:col-span-4 {
          grid-column: span 4 / span 4;
        }

        .lg\:col-span-5 {
          grid-column: span 5 / span 5;
        }

        .lg\:col-span-7 {
          grid-column: span 7 / span 7;
        }

        .lg\:col-span-8 {
          grid-column: span 8 / span 8;
        }

        .lg\:col-span-9 {
          grid-column: span 9 / span 9;
        }

        .lg\:mx-0 {
          margin-inline: 0px;
        }

        .lg\:mt-12 {
          margin-top: calc(var(--spacing) * 12);
        }

        .lg\:mt-\[58px\] {
          margin-top: 58px;
        }

        .lg\:mr-0 {
          margin-right: 0px;
        }

        .lg\:ml-auto {
          margin-left: auto;
        }

        .lg\:h-64 {
          height: calc(var(--spacing) * 64);
        }

        .lg\:h-96 {
          height: calc(var(--spacing) * 96);
        }

        .lg\:h-\[640px\] {
          height: 640px;
        }

        .lg\:min-h-\[640px\] {
          min-height: 640px;
        }

        .lg\:min-h-\[660px\] {
          min-height: 660px;
        }

        .lg\:w-1\/2 {
          width: calc(1 / 2 * 100%);
        }

        .lg\:w-64 {
          width: calc(var(--spacing) * 64);
        }

        .lg\:w-\[190px\] {
          width: 190px;
        }

        .lg\:max-w-\[300px\] {
          max-width: 300px;
        }

        .lg\:min-w-\[190px\] {
          min-width: 190px;
        }

        .lg\:grid-cols-3 {
          grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        .lg\:grid-cols-4 {
          grid-template-columns: repeat(4, minmax(0, 1fr));
        }

        .lg\:grid-cols-5 {
          grid-template-columns: repeat(5, minmax(0, 1fr));
        }

        .lg\:grid-cols-6 {
          grid-template-columns: repeat(6, minmax(0, 1fr));
        }

        .lg\:grid-cols-8 {
          grid-template-columns: repeat(8, minmax(0, 1fr));
        }

        .lg\:grid-cols-12 {
          grid-template-columns: repeat(12, minmax(0, 1fr));
        }

        .lg\:flex-col {
          flex-direction: column;
        }

        .lg\:flex-row {
          flex-direction: row;
        }

        .lg\:items-center {
          align-items: center;
        }

        .lg\:justify-center {
          justify-content: center;
        }

        .lg\:border-r {
          border-right-style: var(--tw-border-style);
          border-right-width: 1px;
        }

        .lg\:border-b-0 {
          border-bottom-style: var(--tw-border-style);
          border-bottom-width: 0px;
        }

        .lg\:pr-6 {
          padding-right: calc(var(--spacing) * 6);
        }

        .lg\:pb-0 {
          padding-bottom: 0px;
        }

        .lg\:pb-20 {
          padding-bottom: calc(var(--spacing) * 20);
        }

        .lg\:text-center {
          text-align: center;
        }
      }

      @media (width >=80rem) {
        .xl\:grid-cols-5 {
          grid-template-columns: repeat(5, minmax(0, 1fr));
        }

        .xl\:grid-cols-6 {
          grid-template-columns: repeat(6, minmax(0, 1fr));
        }

        .xl\:grid-cols-7 {
          grid-template-columns: repeat(7, minmax(0, 1fr));
        }

        .xl\:grid-cols-10 {
          grid-template-columns: repeat(10, minmax(0, 1fr));
        }
      }
    }

    @layer base {
      body {
        background-color: #0e0a07;
        color: #f3f4f6;
        font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
        overflow-x: hidden;
      }
    }

    ::-webkit-scrollbar {
      width: 6px;
      height: 6px;
    }

    ::-webkit-scrollbar-track {
      background: #18110b;
    }

    ::-webkit-scrollbar-thumb {
      background: #3e2a1b;
      border-radius: 3px;
    }

    ::-webkit-scrollbar-thumb:hover {
      background: #f97316;
    }

    @keyframes floatBob {

      0%,
      100% {
        transform: translateY(0px) rotate(0deg);
      }

      50% {
        transform: translateY(-12px) rotate(-3deg);
      }
    }

    .animate-float-bob {
      animation: floatBob 3s ease-in-out infinite;
    }

    @keyframes goldPulse {

      0%,
      100% {
        box-shadow: 0 0 10px rgba(249, 115, 22, 0.4), inset 0 0 10px rgba(251, 191, 36, 0.2);
      }

      50% {
        box-shadow: 0 0 20px rgba(249, 115, 22, 0.8), inset 0 0 15px rgba(251, 191, 36, 0.5);
      }
    }

    .animate-gold-pulse {
      animation: goldPulse 2.5s infinite ease-in-out;
    }

    @keyframes marquee {
      0% {
        transform: translateX(0%);
      }

      100% {
        transform: translateX(-50%);
      }
    }

    .animate-marquee {
      display: flex;
      width: max-content;
      animation: marquee 30s linear infinite;
    }

    .animate-marquee:hover {
      animation-play-state: paused;
    }

    .bg-vvjl-dark {
      background-color: #0e0a07;
    }

    .bg-vvjl-card {
      background: linear-gradient(180deg, #1f150e 0%, #150d08 100%);
    }

    .bg-vvjl-header {
      background: linear-gradient(180deg, #2a1d13 0%, #180f09 100%);
    }

    .bg-vvjl-sidebar {
      background: linear-gradient(180deg, #1c120b 0%, #110a05 100%);
    }

    .bg-vvjl-orange {
      background: linear-gradient(180deg, #ff6600 0%, #cc4400 100%);
    }

    .border-vvjl-orange {
      border-color: #f97316;
    }

    .text-vvjl-orange {
      color: #ff6600;
    }

    .text-vvjl-gold {
      color: #facc15;
    }

    .theme-light body {
      background-color: #fff7ed;
      color: #1c1917;
    }

    .theme-light .bg-\[\#0e0a07\] {
      background-color: #fff7ed !important;
      background-image: linear-gradient(180deg, #fff7ed 0%, #fffbeb 50%, #fef3c7 100%) !important;
    }

    .theme-light .bg-\[\#0b0704\] {
      background-color: #fff7ed !important;
      background-image: none !important;
    }

    .theme-light .bg-\[\#0e0a07\],
    .theme-light .bg-\[\#110904\],
    .theme-light .bg-\[\#120701\],
    .theme-light .bg-\[\#120703\],
    .theme-light .bg-\[\#120803\],
    .theme-light .bg-\[\#120904\],
    .theme-light .bg-\[\#120a05\],
    .theme-light .bg-\[\#130904\],
    .theme-light .bg-\[\#140b05\],
    .theme-light .bg-\[\#150d08\],
    .theme-light .bg-\[\#160d07\],
    .theme-light .bg-\[\#170e08\],
    .theme-light .bg-\[\#180d06\],
    .theme-light .bg-\[\#180e07\],
    .theme-light .bg-\[\#180f08\],
    .theme-light .bg-\[\#180f0a\],
    .theme-light .bg-\[\#1a0c03\],
    .theme-light .bg-\[\#1a0e06\],
    .theme-light .bg-\[\#1a1008\],
    .theme-light .bg-\[\#1a110a\],
    .theme-light .bg-\[\#1b1008\],
    .theme-light .bg-\[\#1b1109\],
    .theme-light .bg-\[\#1c0e05\],
    .theme-light .bg-\[\#1c1008\],
    .theme-light .bg-\[\#1c120b\],
    .theme-light .bg-\[\#1d1008\],
    .theme-light .bg-\[\#1e1007\],
    .theme-light .bg-\[\#1e1108\],
    .theme-light .bg-\[\#1e1209\],
    .theme-light .bg-\[\#1f1209\],
    .theme-light .bg-\[\#1f120a\],
    .theme-light .bg-\[\#1f130a\],
    .theme-light .bg-\[\#1f150e\],
    .theme-light .bg-\[\#211107\],
    .theme-light .bg-\[\#221309\],
    .theme-light .bg-\[\#22130a\],
    .theme-light .bg-\[\#231206\],
    .theme-light .bg-\[\#23150b\],
    .theme-light .bg-\[\#24150b\],
    .theme-light .bg-\[\#251409\],
    .theme-light .bg-\[\#25170d\],
    .theme-light .bg-\[\#261206\],
    .theme-light .bg-\[\#26150a\],
    .theme-light .bg-\[\#27170d\],
    .theme-light .bg-\[\#27180e\],
    .theme-light .bg-\[\#28150a\],
    .theme-light .bg-\[\#28180e\],
    .theme-light .bg-\[\#29170a\],
    .theme-light .bg-\[\#2a1608\],
    .theme-light .bg-\[\#2a170a\],
    .theme-light .bg-\[\#2a1b10\],
    .theme-light .bg-\[\#2b170a\],
    .theme-light .bg-\[\#2b180d\],
    .theme-light .bg-\[\#2b1a0e\],
    .theme-light .bg-\[\#2c1305\],
    .theme-light .bg-\[\#2d1e12\],
    .theme-light .bg-\[\#2e1506\],
    .theme-light .bg-\[\#2e1d13\],
    .theme-light .bg-\[\#311d0e\],
    .theme-light .bg-\[\#332013\],
    .theme-light .bg-\[\#382315\],
    .theme-light .bg-\[\#3b200c\],
    .theme-light .bg-\[\#431d08\],
    .theme-light .bg-\[\#482208\] {
      background-color: #fffdf8 !important;
      background-image: none !important;
    }

    .theme-light .bg-\[\#120701\],
    .theme-light .bg-\[\#120703\],
    .theme-light .bg-\[\#120803\],
    .theme-light .bg-\[\#120904\],
    .theme-light .bg-\[\#120a05\],
    .theme-light .bg-\[\#130904\],
    .theme-light .bg-\[\#140b05\],
    .theme-light .bg-\[\#150d08\],
    .theme-light .bg-\[\#160d07\],
    .theme-light .bg-\[\#170e08\],
    .theme-light .bg-\[\#180d06\],
    .theme-light .bg-\[\#180e07\],
    .theme-light .bg-\[\#180f08\],
    .theme-light .bg-\[\#180f0a\],
    .theme-light .bg-\[\#1a0c03\],
    .theme-light .bg-\[\#1a0e06\],
    .theme-light .bg-\[\#1a1008\],
    .theme-light .bg-\[\#1a110a\],
    .theme-light .bg-stone-900,
    .theme-light .bg-stone-900\/80,
    .theme-light .bg-stone-900\/90,
    .theme-light .bg-stone-950,
    .theme-light .bg-stone-950\/70,
    .theme-light .bg-stone-950\/80,
    .theme-light .bg-stone-950\/90 {
      background-color: #ffffff !important;
      background-image: none !important;
    }

    .theme-light .bg-\[\#1b1008\],
    .theme-light .bg-\[\#1b1109\],
    .theme-light .bg-\[\#1c0e05\],
    .theme-light .bg-\[\#1c1008\],
    .theme-light .bg-\[\#1c120b\],
    .theme-light .bg-\[\#1d1008\],
    .theme-light .bg-\[\#1e1007\],
    .theme-light .bg-\[\#1e1108\],
    .theme-light .bg-\[\#1e1209\],
    .theme-light .bg-\[\#1f1209\],
    .theme-light .bg-\[\#1f120a\],
    .theme-light .bg-\[\#1f130a\],
    .theme-light .bg-\[\#1f150e\],
    .theme-light .bg-stone-800,
    .theme-light .bg-stone-800\/60,
    .theme-light .bg-stone-800\/80 {
      background-color: #fff7ed !important;
      background-image: none !important;
    }

    .theme-light .bg-\[\#211107\],
    .theme-light .bg-\[\#221309\],
    .theme-light .bg-\[\#22130a\],
    .theme-light .bg-\[\#231206\],
    .theme-light .bg-\[\#23150b\],
    .theme-light .bg-\[\#24150b\],
    .theme-light .bg-\[\#251409\],
    .theme-light .bg-\[\#25170d\],
    .theme-light .bg-\[\#261206\],
    .theme-light .bg-\[\#26150a\],
    .theme-light .bg-\[\#27170d\],
    .theme-light .bg-\[\#27180e\],
    .theme-light .bg-\[\#28150a\],
    .theme-light .bg-\[\#28180e\],
    .theme-light .bg-\[\#29170a\],
    .theme-light .bg-\[\#2a1608\],
    .theme-light .bg-\[\#2a170a\],
    .theme-light .bg-\[\#2a1b10\],
    .theme-light .bg-\[\#2b170a\],
    .theme-light .bg-\[\#2b180d\],
    .theme-light .bg-\[\#2b1a0e\],
    .theme-light .bg-\[\#2c1305\],
    .theme-light .bg-\[\#2d1e12\],
    .theme-light .bg-\[\#2e1506\],
    .theme-light .bg-\[\#2e1d13\],
    .theme-light .bg-\[\#311d0e\],
    .theme-light .bg-\[\#332013\],
    .theme-light .bg-\[\#382315\],
    .theme-light .bg-\[\#3b200c\],
    .theme-light .bg-\[\#431d08\],
    .theme-light .bg-\[\#482208\] {
      background-color: #ffedd5 !important;
      background-image: none !important;
    }

    .theme-light .bg-gradient-to-r.from-\[\#2a170a\],
    .theme-light .bg-gradient-to-r.from-\[\#2e1506\],
    .theme-light .bg-gradient-to-r.from-\[\#2c1305\],
    .theme-light .bg-gradient-to-r.from-\[\#211107\],
    .theme-light .bg-gradient-to-r.from-\[\#29170a\],
    .theme-light .bg-gradient-to-b.from-\[\#1f1007\],
    .theme-light .bg-gradient-to-b.from-\[\#180f0a\],
    .theme-light .bg-gradient-to-br.from-\[\#231206\],
    .theme-light .bg-gradient-to-br.from-\[\#1b0f07\] {
      background-image: linear-gradient(135deg, #fff7ed 0%, #ffedd5 55%, #fed7aa 100%) !important;
    }

    .theme-light .bg-gradient-to-r.from-\[\#1a0e06\],
    .theme-light .bg-gradient-to-r.from-\[\#211107\].via-\[\#2a170a\],
    .theme-light .bg-gradient-to-r.from-\[\#211107\].via-\[\#1a0e06\] {
      background-image: linear-gradient(135deg, #ffffff 0%, #fff7ed 60%, #ffedd5 100%) !important;
    }

    .theme-light .border-\[\#2b170a\],
    .theme-light .border-\[\#2c1a0e\],
    .theme-light .border-\[\#2c1b10\],
    .theme-light .border-\[\#2d180d\],
    .theme-light .border-\[\#2d1a0e\],
    .theme-light .border-\[\#2d1b0e\],
    .theme-light .border-\[\#2d1b0f\],
    .theme-light .border-\[\#2d1b10\],
    .theme-light .border-\[\#2d1e13\],
    .theme-light .border-\[\#2e1b0f\],
    .theme-light .border-\[\#311d0e\],
    .theme-light .border-\[\#311f12\],
    .theme-light .border-\[\#332013\],
    .theme-light .border-\[\#362113\],
    .theme-light .border-\[\#382112\],
    .theme-light .border-\[\#382213\],
    .theme-light .border-\[\#382315\],
    .theme-light .border-\[\#3b2313\],
    .theme-light .border-\[\#3e2719\],
    .theme-light .border-\[\#422918\] {
      border-color: #fed7aa !important;
    }

    .theme-light .border-stone-600,
    .theme-light .border-stone-700,
    .theme-light .border-stone-800,
    .theme-light .border-stone-900 {
      border-color: #fed7aa !important;
    }

    .theme-light .text-stone-100,
    .theme-light .text-stone-200,
    .theme-light .text-stone-300,
    .theme-light .text-stone-400,
    .theme-light .text-amber-100\/80,
    .theme-light .text-amber-200,
    .theme-light .text-amber-200\/80,
    .theme-light .text-amber-200\/90 {
      color: #292524 !important;
    }

    .theme-light .text-stone-500,
    .theme-light .text-stone-600 {
      color: #57534e !important;
    }

    .theme-light .text-white {
      color: #1c1917 !important;
    }

    .theme-light input,
    .theme-light textarea,
    .theme-light select {
      background-color: #ffffff !important;
      color: #1c1917 !important;
      border-color: #fdba74 !important;
    }

    .theme-light .bg-black\/40,
    .theme-light .bg-black\/50,
    .theme-light .bg-black\/60,
    .theme-light .bg-black\/70,
    .theme-light .bg-black\/80 {
      background-color: rgb(255 247 237 / 0.92) !important;
    }

    .theme-light .backdrop-blur-sm,
    .theme-light .backdrop-blur-md {
      background-color: rgb(255 250 242 / 0.9);
    }

    .theme-light .placeholder-stone-500::placeholder {
      color: #78716c !important;
    }

    .theme-light .shadow-orange-950,
    .theme-light .shadow-orange-950\/50,
    .theme-light .shadow-orange-950\/60,
    .theme-light .shadow-orange-950\/70,
    .theme-light .shadow-orange-950\/80,
    .theme-light .shadow-amber-950 {
      --tw-shadow-color: rgb(251 146 60 / 0.18) !important;
    }

    .theme-light .bg-vvjl-dark,
    .theme-light .bg-vvjl-card,
    .theme-light .bg-vvjl-header,
    .theme-light .bg-vvjl-sidebar {
      background: #fff7ed !important;
    }

    .theme-light .shadow-xl,
    .theme-light .shadow-2xl {
      --tw-shadow-color: rgb(120 53 15 / 0.14) !important;
    }

    @property --tw-translate-x {
      syntax: "*";
      inherits: false;
      initial-value: 0;
    }

    @property --tw-translate-y {
      syntax: "*";
      inherits: false;
      initial-value: 0;
    }

    @property --tw-translate-z {
      syntax: "*";
      inherits: false;
      initial-value: 0;
    }

    @property --tw-scale-x {
      syntax: "*";
      inherits: false;
      initial-value: 1;
    }

    @property --tw-scale-y {
      syntax: "*";
      inherits: false;
      initial-value: 1;
    }

    @property --tw-scale-z {
      syntax: "*";
      inherits: false;
      initial-value: 1;
    }

    @property --tw-rotate-x {
      syntax: "*";
      inherits: false;
    }

    @property --tw-rotate-y {
      syntax: "*";
      inherits: false;
    }

    @property --tw-rotate-z {
      syntax: "*";
      inherits: false;
    }

    @property --tw-skew-x {
      syntax: "*";
      inherits: false;
    }

    @property --tw-skew-y {
      syntax: "*";
      inherits: false;
    }

    @property --tw-scroll-snap-strictness {
      syntax: "*";
      inherits: false;
      initial-value: proximity;
    }

    @property --tw-space-y-reverse {
      syntax: "*";
      inherits: false;
      initial-value: 0;
    }

    @property --tw-divide-y-reverse {
      syntax: "*";
      inherits: false;
      initial-value: 0;
    }

    @property --tw-border-style {
      syntax: "*";
      inherits: false;
      initial-value: solid;
    }

    @property --tw-gradient-position {
      syntax: "*";
      inherits: false;
    }

    @property --tw-gradient-from {
      syntax: "<color>";
      inherits: false;
      initial-value: #0000;
    }

    @property --tw-gradient-via {
      syntax: "<color>";
      inherits: false;
      initial-value: #0000;
    }

    @property --tw-gradient-to {
      syntax: "<color>";
      inherits: false;
      initial-value: #0000;
    }

    @property --tw-gradient-stops {
      syntax: "*";
      inherits: false;
    }

    @property --tw-gradient-via-stops {
      syntax: "*";
      inherits: false;
    }

    @property --tw-gradient-from-position {
      syntax: "<length-percentage>";
      inherits: false;
      initial-value: 0%;
    }

    @property --tw-gradient-via-position {
      syntax: "<length-percentage>";
      inherits: false;
      initial-value: 50%;
    }

    @property --tw-gradient-to-position {
      syntax: "<length-percentage>";
      inherits: false;
      initial-value: 100%;
    }

    @property --tw-leading {
      syntax: "*";
      inherits: false;
    }

    @property --tw-font-weight {
      syntax: "*";
      inherits: false;
    }

    @property --tw-tracking {
      syntax: "*";
      inherits: false;
    }

    @property --tw-shadow {
      syntax: "*";
      inherits: false;
      initial-value: 0 0 #0000;
    }

    @property --tw-shadow-color {
      syntax: "*";
      inherits: false;
    }

    @property --tw-shadow-alpha {
      syntax: "<percentage>";
      inherits: false;
      initial-value: 100%;
    }

    @property --tw-inset-shadow {
      syntax: "*";
      inherits: false;
      initial-value: 0 0 #0000;
    }

    @property --tw-inset-shadow-color {
      syntax: "*";
      inherits: false;
    }

    @property --tw-inset-shadow-alpha {
      syntax: "<percentage>";
      inherits: false;
      initial-value: 100%;
    }

    @property --tw-ring-color {
      syntax: "*";
      inherits: false;
    }

    @property --tw-ring-shadow {
      syntax: "*";
      inherits: false;
      initial-value: 0 0 #0000;
    }

    @property --tw-inset-ring-color {
      syntax: "*";
      inherits: false;
    }

    @property --tw-inset-ring-shadow {
      syntax: "*";
      inherits: false;
      initial-value: 0 0 #0000;
    }

    @property --tw-ring-inset {
      syntax: "*";
      inherits: false;
    }

    @property --tw-ring-offset-width {
      syntax: "<length>";
      inherits: false;
      initial-value: 0px;
    }

    @property --tw-ring-offset-color {
      syntax: "*";
      inherits: false;
      initial-value: #fff;
    }

    @property --tw-ring-offset-shadow {
      syntax: "*";
      inherits: false;
      initial-value: 0 0 #0000;
    }

    @property --tw-blur {
      syntax: "*";
      inherits: false;
    }

    @property --tw-brightness {
      syntax: "*";
      inherits: false;
    }

    @property --tw-contrast {
      syntax: "*";
      inherits: false;
    }

    @property --tw-grayscale {
      syntax: "*";
      inherits: false;
    }

    @property --tw-hue-rotate {
      syntax: "*";
      inherits: false;
    }

    @property --tw-invert {
      syntax: "*";
      inherits: false;
    }

    @property --tw-opacity {
      syntax: "*";
      inherits: false;
    }

    @property --tw-saturate {
      syntax: "*";
      inherits: false;
    }

    @property --tw-sepia {
      syntax: "*";
      inherits: false;
    }

    @property --tw-drop-shadow {
      syntax: "*";
      inherits: false;
    }

    @property --tw-drop-shadow-color {
      syntax: "*";
      inherits: false;
    }

    @property --tw-drop-shadow-alpha {
      syntax: "<percentage>";
      inherits: false;
      initial-value: 100%;
    }

    @property --tw-drop-shadow-size {
      syntax: "*";
      inherits: false;
    }

    @property --tw-backdrop-blur {
      syntax: "*";
      inherits: false;
    }

    @property --tw-backdrop-brightness {
      syntax: "*";
      inherits: false;
    }

    @property --tw-backdrop-contrast {
      syntax: "*";
      inherits: false;
    }

    @property --tw-backdrop-grayscale {
      syntax: "*";
      inherits: false;
    }

    @property --tw-backdrop-hue-rotate {
      syntax: "*";
      inherits: false;
    }

    @property --tw-backdrop-invert {
      syntax: "*";
      inherits: false;
    }

    @property --tw-backdrop-opacity {
      syntax: "*";
      inherits: false;
    }

    @property --tw-backdrop-saturate {
      syntax: "*";
      inherits: false;
    }

    @property --tw-backdrop-sepia {
      syntax: "*";
      inherits: false;
    }

    @property --tw-duration {
      syntax: "*";
      inherits: false;
    }

    @property --tw-ease {
      syntax: "*";
      inherits: false;
    }

    @keyframes spin {
      to {
        transform: rotate(360deg);
      }
    }

    @keyframes ping {

      75%,
      100% {
        transform: scale(2);
        opacity: 0;
      }
    }

    @keyframes pulse {
      50% {
        opacity: 0.5;
      }
    }

    @keyframes bounce {

      0%,
      100% {
        transform: translateY(-25%);
        animation-timing-function: cubic-bezier(0.8, 0, 1, 1);
      }

      50% {
        transform: none;
        animation-timing-function: cubic-bezier(0, 0, 0.2, 1);
      }
    }

    @layer properties {
      @supports ((-webkit-hyphens: none) and (not (margin-trim: inline))) or ((-moz-orient: inline) and (not (color:rgb(from red r g b)))) {

        *,
        ::before,
        ::after,
        ::backdrop {
          --tw-translate-x: 0;
          --tw-translate-y: 0;
          --tw-translate-z: 0;
          --tw-scale-x: 1;
          --tw-scale-y: 1;
          --tw-scale-z: 1;
          --tw-rotate-x: initial;
          --tw-rotate-y: initial;
          --tw-rotate-z: initial;
          --tw-skew-x: initial;
          --tw-skew-y: initial;
          --tw-scroll-snap-strictness: proximity;
          --tw-space-y-reverse: 0;
          --tw-divide-y-reverse: 0;
          --tw-border-style: solid;
          --tw-gradient-position: initial;
          --tw-gradient-from: #0000;
          --tw-gradient-via: #0000;
          --tw-gradient-to: #0000;
          --tw-gradient-stops: initial;
          --tw-gradient-via-stops: initial;
          --tw-gradient-from-position: 0%;
          --tw-gradient-via-position: 50%;
          --tw-gradient-to-position: 100%;
          --tw-leading: initial;
          --tw-font-weight: initial;
          --tw-tracking: initial;
          --tw-shadow: 0 0 #0000;
          --tw-shadow-color: initial;
          --tw-shadow-alpha: 100%;
          --tw-inset-shadow: 0 0 #0000;
          --tw-inset-shadow-color: initial;
          --tw-inset-shadow-alpha: 100%;
          --tw-ring-color: initial;
          --tw-ring-shadow: 0 0 #0000;
          --tw-inset-ring-color: initial;
          --tw-inset-ring-shadow: 0 0 #0000;
          --tw-ring-inset: initial;
          --tw-ring-offset-width: 0px;
          --tw-ring-offset-color: #fff;
          --tw-ring-offset-shadow: 0 0 #0000;
          --tw-blur: initial;
          --tw-brightness: initial;
          --tw-contrast: initial;
          --tw-grayscale: initial;
          --tw-hue-rotate: initial;
          --tw-invert: initial;
          --tw-opacity: initial;
          --tw-saturate: initial;
          --tw-sepia: initial;
          --tw-drop-shadow: initial;
          --tw-drop-shadow-color: initial;
          --tw-drop-shadow-alpha: 100%;
          --tw-drop-shadow-size: initial;
          --tw-backdrop-blur: initial;
          --tw-backdrop-brightness: initial;
          --tw-backdrop-contrast: initial;
          --tw-backdrop-grayscale: initial;
          --tw-backdrop-hue-rotate: initial;
          --tw-backdrop-invert: initial;
          --tw-backdrop-opacity: initial;
          --tw-backdrop-saturate: initial;
          --tw-backdrop-sepia: initial;
          --tw-duration: initial;
          --tw-ease: initial;
        }
      }
    }

    .blog-article-content {
      color: #e7ded0;
      max-width: none;
      min-width: 0;
    }

    .blog-article-content>*+* {
      margin-top: 1rem;
    }

    .blog-article-content h1,
    .blog-article-content h2,
    .blog-article-content h3 {
      color: #fff7ed;
      font-weight: 900;
      line-height: 1.18;
      letter-spacing: 0;
    }

    .blog-article-content h1 {
      font-size: clamp(1.55rem, 1.3rem + 1vw, 2.25rem);
      padding-bottom: 0.65rem;
      border-bottom: 1px solid rgba(245, 158, 11, 0.35);
    }

    .blog-article-content h2 {
      margin-top: 1.65rem;
      font-size: clamp(1.35rem, 1.15rem + 0.7vw, 1.85rem);
      color: #fcd34d;
    }

    .blog-article-content h3 {
      margin-top: 1.35rem;
      font-size: clamp(1.1rem, 1rem + 0.4vw, 1.35rem);
      color: #fed7aa;
    }

    .blog-article-content p {
      color: #e7ded0;
      line-height: 1.8;
    }

    .blog-article-content blockquote {
      margin: 1.25rem 0;
      border-left: 4px solid #f59e0b;
      background: rgba(245, 158, 11, 0.1);
      color: #fff7ed;
      padding: 1rem 1.15rem;
      border-radius: 0 0.5rem 0.5rem 0;
      font-weight: 700;
      line-height: 1.7;
    }

    .blog-article-content ul,
    .blog-article-content ol {
      padding-left: 1.35rem;
      display: grid;
      gap: 0.55rem;
    }

    .blog-article-content li {
      line-height: 1.75;
    }

    .blog-article-content a {
      color: #fbbf24;
      font-weight: 800;
      text-decoration: underline;
      text-underline-offset: 3px;
    }

    .blog-article-content img {
      width: 100%;
      height: auto;
      border-radius: 0.75rem;
      border: 1px solid rgba(245, 158, 11, 0.3);
    }

    .blog-article-content code {
      color: #fde68a;
      background: rgba(0, 0, 0, 0.28);
      border: 1px solid rgba(245, 158, 11, 0.22);
      border-radius: 0.25rem;
      padding: 0.12rem 0.3rem;
    }

    .blog-article-content pre {
      overflow-x: auto;
      background: rgba(0, 0, 0, 0.34);
      border: 1px solid rgba(245, 158, 11, 0.22);
      border-radius: 0.5rem;
      padding: 1rem;
    }

    .blog-article-content .blog-custom-code-block,
    .blog-article-content .blog-slot-demo-block,
    .blog-article-content .blog-button-block,
    .blog-article-content .blog-table-block {
      width: 100%;
      max-width: none;
      min-width: 0;
    }

    .blog-slot-demo-block {
      margin: 1.5rem 0;
      overflow: hidden;
    }

    .blog-slot-demo-header {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      justify-content: space-between;
      gap: 0.75rem;
      margin-bottom: 0.75rem;
    }

    .blog-slot-demo-header h2 {
      margin: 0;
    }

    .blog-slot-demo-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 0.5rem;
    }

    .blog-slot-demo-link,
    .blog-button-link {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border-radius: 0.75rem;
      background: linear-gradient(90deg, #ea580c, #f59e0b);
      color: #1c1208 !important;
      font-weight: 900;
      padding: 0.7rem 1rem;
      text-decoration: none !important;
    }

    .blog-slot-demo-real {
      background: #f5f5f4;
    }

    .blog-slot-demo-frame {
      width: 100%;
      max-width: none;
      aspect-ratio: 16 / 9;
      min-height: 320px;
      border: 1px solid rgba(245, 158, 11, 0.3);
      border-radius: 0.85rem;
      overflow: hidden;
      background: #080502;
    }

    .blog-slot-demo-frame iframe {
      width: 100%;
      height: 100%;
      border: 0;
    }

    .blog-table-block {
      overflow-x: auto;
    }

    .blog-table-block table {
      width: 100%;
      min-width: 560px;
    }

    .blog-provider-marquee {
      position: relative;
      overflow: hidden;
      /* Logo height plus the existing item padding and borders. */
      min-height: calc(1.5rem + 1.3rem + 2px);
    }

    .blog-provider-marquee-track {
      display: flex;
      width: max-content;
      animation: marquee 38s linear infinite;
    }

    .blog-provider-marquee:hover .blog-provider-marquee-track {
      animation-play-state: paused;
    }

    .blog-provider-item {
      display: inline-flex;
      height: calc(1.5rem + 1.3rem + 2px);
      align-items: center;
      gap: 0.6rem;
      min-width: 150px;
      margin-right: 0.75rem;
      padding: 0.65rem 0.8rem;
      border: 1px solid rgba(245, 158, 11, 0.24);
      border-radius: 0.75rem;
      background: #160d07;
      color: #f5e8d0;
      text-decoration: none;
    }

    .blog-provider-item img {
      width: 2.5rem;
      height: 1.5rem;
      object-fit: contain;
      flex: 0 0 auto;
    }

    .blog-provider-item span {
      max-width: 8rem;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      font-size: 0.75rem;
      font-weight: 900;
    }

    @media (max-width: 640px) {
      .blog-slot-demo-frame {
        aspect-ratio: auto;
        min-height: 260px;
        height: 62vh;
      }

      .blog-slot-demo-actions,
      .blog-slot-demo-link,
      .blog-button-link {
        width: 100%;
      }

      .blog-provider-item {
        min-width: 132px;
      }
    }

    .blog-faq-block {
      display: grid;
      gap: 0.85rem;
      margin: 1.5rem 0;
      padding: 1rem;
      border: 1px solid rgba(245, 158, 11, 0.35);
      border-radius: 0.75rem;
      background: rgba(20, 8, 3, 0.72);
    }

    .blog-faq-item {
      padding: 1rem;
      border: 1px solid rgba(120, 53, 15, 0.8);
      border-radius: 0.5rem;
      background: rgba(42, 22, 11, 0.72);
    }

    .blog-faq-item h3 {
      margin: 0 0 0.45rem;
      color: #fcd34d;
      font-size: 1rem;
    }

    .blog-faq-item p {
      margin: 0;
      color: #e7ded0;
      font-size: 0.95rem;
    }

  </style>

</head>

<body class="bg-[#0e0a07] text-stone-100" cz-shortcut-listen="true">
  <div id="root">
    <div
      class="min-h-screen bg-[#0e0a07] text-stone-100 font-sans flex flex-col selection:bg-orange-500 selection:text-white">
      <header class="sticky top-0 z-40 bg-[#160d07] border-b border-[#2d1e13] px-2.5 sm:px-6 py-2 shadow-lg">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <div class="flex items-center gap-2 sm:gap-3 shrink-0 min-w-0"><button
              class="p-1.5 rounded-md text-amber-100/80 hover:text-white hover:bg-[#2e1d13] transition-colors focus:outline-none cursor-pointer"
              title="Toggle Navigation Menu" id="btn-toggle-sidebar"><svg xmlns="http://www.w3.org/2000/svg" width="24"
                height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                stroke-linecap="round" stroke-linejoin="round"
                class="lucide lucide-menu w-5 h-5 sm:w-6 sm:h-6 text-orange-500" aria-hidden="true">
                <path d="M4 5h16"></path>
                <path d="M4 12h16"></path>
                <path d="M4 19h16"></path>
              </svg></button>
            <a href="/" class="flex items-center gap-2 cursor-pointer group select-none shrink-0" id="brand-logo"><img
                alt="Free Online Games"
                class="h-8 sm:h-11 w-auto max-w-[112px] min-[420px]:max-w-[140px] sm:max-w-none object-contain rounded-md transition-transform group-hover:scale-105 drop-shadow-[0_2px_8px_rgba(255,180,0,0.4)]"
                width="1254" height="1174" loading="eager" decoding="async" fetchpriority="high"
                referrerpolicy="no-referrer" src="/assets/free-online-games-logo-C1EG2Cuk.webp"></a>
          </div>
          <div class="order-3 w-full md:order-none md:flex-1 md:max-w-lg md:mx-4">
            <form action="/search/" method="get"
              class="relative flex items-center w-full bg-[#110904] border border-[#3e2719] focus-within:border-orange-500 rounded-lg px-2.5 py-1.5 transition-all shadow-inner">
              <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                class="lucide lucide-search w-4 h-4 text-orange-400 shrink-0 mr-2" aria-hidden="true">
                <path d="m21 21-4.34-4.34"></path>
                <circle cx="11" cy="11" r="8"></circle>
              </svg><input placeholder="Maghanap ng laro..."
                class="w-full bg-transparent text-xs text-stone-100 placeholder-stone-500 focus:outline-none"
                id="header-realtime-search-input" name="q" type="search" value="">
            </form>
          </div>
          <div class="flex items-center gap-1.5 sm:gap-2 shrink-0"><button
              class="h-9 px-2.5 sm:px-2.5 text-[11px] sm:text-xs font-bold rounded-lg bg-[#27180e] hover:bg-[#382315] text-amber-200 border border-[#3e2719] flex items-center justify-center gap-1 transition-colors cursor-pointer"
              title="Change Language" id="btn-language-switch"><svg xmlns="http://www.w3.org/2000/svg" width="24"
                height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                stroke-linecap="round" stroke-linejoin="round"
                class="lucide lucide-globe w-3.5 h-3.5 text-orange-400 hidden md:inline-block" aria-hidden="true">
                <circle cx="12" cy="12" r="10"></circle>
                <path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"></path>
                <path d="M2 12h20"></path>
              </svg><span>PH</span></button>
            <div class="flex items-center gap-1.5 sm:gap-2"><a href="/playnow"
                class="h-9 bg-stone-200 hover:bg-white text-stone-900 font-bold text-[11px] sm:text-xs px-3 sm:px-4 rounded-lg transition-all shadow-sm flex items-center justify-center gap-1 active:scale-95 cursor-pointer"
                id="btn-header-login"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                  fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                  class="lucide lucide-log-in w-3.5 h-3.5 text-stone-800 hidden sm:inline-block" aria-hidden="true">
                  <path d="m10 17 5-5-5-5"></path>
                  <path d="M15 12H3"></path>
                  <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"></path>
                </svg><span>Login</span></a><a href="/playnow"
                class="h-9 bg-gradient-to-r from-orange-600 via-orange-500 to-red-600 hover:from-orange-500 hover:to-red-500 text-white font-extrabold text-[11px] sm:text-xs px-3 sm:px-4 rounded-lg transition-all shadow-md shadow-orange-950/60 flex items-center justify-center gap-1 active:scale-95 cursor-pointer whitespace-nowrap"
                id="btn-header-register"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                  viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                  stroke-linejoin="round"
                  class="lucide lucide-user-plus w-3.5 h-3.5 text-amber-200 hidden sm:inline-block" aria-hidden="true">
                  <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path>
                  <circle cx="9" cy="7" r="4"></circle>
                  <line x1="19" x2="19" y1="8" y2="14"></line>
                  <line x1="22" x2="16" y1="11" y2="11"></line>
                </svg><span>Register</span></a></div>
          </div>
        </div>
      </header>
      <div class="flex-1 flex w-full">
        <aside
          class="fixed sm:sticky top-[101px] sm:top-[57px] bottom-0 left-0 z-50 sm:z-30 w-72 max-w-[82vw] sm:w-60 bg-[#170e08] border-r border-[#2c1b10] flex flex-col justify-between h-[calc(100vh-101px)] sm:h-[calc(100vh-57px)] shrink-0 select-none overflow-y-auto shadow-2xl sm:shadow-none">
          <div class="py-2 px-2 space-y-1">
            <div class="sm:hidden flex items-center justify-between px-2 py-2 border-b border-[#2c1b10] mb-2"><span
                class="text-sm font-black uppercase tracking-wider text-amber-300">Menu</span><button
                class="p-1.5 rounded-md text-stone-300 hover:text-white hover:bg-[#28180e] transition-colors cursor-pointer"
                title="Close Menu"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                  fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                  class="lucide lucide-x w-4 h-4" aria-hidden="true">
                  <path d="M18 6 6 18"></path>
                  <path d="m6 6 12 12"></path>
                </svg></button></div><a href="/"
              class="w-full text-left px-3 py-2.5 rounded-lg flex items-center justify-between transition-all group text-stone-300 hover:text-white hover:bg-[#28180e]"
              id="nav-item-home">
              <div class="flex items-center gap-3"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                  viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                  stroke-linejoin="round"
                  class="lucide lucide-house w-4 h-4 transition-transform group-hover:scale-110 text-orange-400"
                  aria-hidden="true">
                  <path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8"></path>
                  <path
                    d="M3 10a2 2 0 0 1 .709-1.528l7-6a2 2 0 0 1 2.582 0l7 6A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z">
                  </path>
                </svg><span class="text-sm font-medium">Home</span></div>
              <div class="flex items-center gap-1"></div>
            </a><a href="/slots"
              class="w-full text-left px-3 py-2.5 rounded-lg flex items-center justify-between transition-all group text-stone-300 hover:text-white hover:bg-[#28180e]"
              id="nav-item-slots">
              <div class="flex items-center gap-3"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                  viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                  stroke-linejoin="round"
                  class="lucide lucide-circle-dollar-sign w-4 h-4 transition-transform group-hover:scale-110 text-orange-400"
                  aria-hidden="true">
                  <circle cx="12" cy="12" r="10"></circle>
                  <path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8"></path>
                  <path d="M12 18V6"></path>
                </svg><span class="text-sm font-medium">Slots Games</span></div>
              <div class="flex items-center gap-1"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                  viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                  stroke-linejoin="round"
                  class="lucide lucide-chevron-right w-3.5 h-3.5 transition-transform text-stone-500 group-hover:text-stone-300"
                  aria-hidden="true">
                  <path d="m9 18 6-6-6-6"></path>
                </svg></div>
            </a><a href="/arcade"
              class="w-full text-left px-3 py-2.5 rounded-lg flex items-center justify-between transition-all group text-stone-300 hover:text-white hover:bg-[#28180e]"
              id="nav-item-arcade">
              <div class="flex items-center gap-3"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                  viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                  stroke-linejoin="round"
                  class="lucide lucide-gamepad2 lucide-gamepad-2 w-4 h-4 transition-transform group-hover:scale-110 text-orange-400"
                  aria-hidden="true">
                  <line x1="6" x2="10" y1="11" y2="11"></line>
                  <line x1="8" x2="8" y1="9" y2="13"></line>
                  <line x1="15" x2="15.01" y1="12" y2="12"></line>
                  <line x1="18" x2="18.01" y1="10" y2="10"></line>
                  <path
                    d="M17.32 5H6.68a4 4 0 0 0-3.978 3.59c-.006.052-.01.101-.017.152C2.604 9.416 2 14.456 2 16a3 3 0 0 0 3 3c1 0 1.5-.5 2-1l1.414-1.414A2 2 0 0 1 9.828 16h4.344a2 2 0 0 1 1.414.586L17 18c.5.5 1 1 2 1a3 3 0 0 0 3-3c0-1.545-.604-6.584-.685-7.258-.007-.05-.011-.1-.017-.151A4 4 0 0 0 17.32 5z">
                  </path>
                </svg><span class="text-sm font-medium">Arcade</span></div>
              <div class="flex items-center gap-1"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                  viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                  stroke-linejoin="round"
                  class="lucide lucide-chevron-right w-3.5 h-3.5 transition-transform text-stone-500 group-hover:text-stone-300"
                  aria-hidden="true">
                  <path d="m9 18 6-6-6-6"></path>
                </svg></div>
            </a><a href="/perya-games"
              class="w-full text-left px-3 py-2.5 rounded-lg flex items-center justify-between transition-all group text-stone-300 hover:text-white hover:bg-[#28180e]"
              id="nav-item-perya">
              <div class="flex items-center gap-3"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                  viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                  stroke-linejoin="round"
                  class="lucide lucide-party-popper w-4 h-4 transition-transform group-hover:scale-110 text-orange-400"
                  aria-hidden="true">
                  <path d="M5.8 11.3 2 22l10.7-3.79"></path>
                  <path d="M4 3h.01"></path>
                  <path d="M22 8h.01"></path>
                  <path d="M15 2h.01"></path>
                  <path d="M22 20h.01"></path>
                  <path
                    d="m22 2-2.24.75a2.9 2.9 0 0 0-1.96 3.12c.1.86-.57 1.63-1.45 1.63h-.38c-.86 0-1.6.6-1.76 1.44L14 10">
                  </path>
                  <path d="m22 13-.82-.33c-.86-.34-1.82.2-1.98 1.11c-.11.7-.72 1.22-1.43 1.22H17"></path>
                  <path d="m11 2 .33.82c.34.86-.2 1.82-1.11 1.98C9.52 4.9 9 5.52 9 6.23V7"></path>
                  <path
                    d="M11 13c1.93 1.93 2.83 4.17 2 5-.83.83-3.07-.07-5-2-1.93-1.93-2.83-4.17-2-5 .83-.83 3.07.07 5 2Z">
                  </path>
                </svg><span class="text-sm font-medium">Perya Games</span></div>
              <div class="flex items-center gap-1"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                  viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                  stroke-linejoin="round"
                  class="lucide lucide-chevron-right w-3.5 h-3.5 transition-transform text-stone-500 group-hover:text-stone-300"
                  aria-hidden="true">
                  <path d="m9 18 6-6-6-6"></path>
                </svg></div>
            </a><a href="/live-casino"
              class="w-full text-left px-3 py-2.5 rounded-lg flex items-center justify-between transition-all group text-stone-300 hover:text-white hover:bg-[#28180e]"
              id="nav-item-live">
              <div class="flex items-center gap-3"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                  viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                  stroke-linejoin="round"
                  class="lucide lucide-dices w-4 h-4 transition-transform group-hover:scale-110 text-orange-400"
                  aria-hidden="true">
                  <rect width="12" height="12" x="2" y="10" rx="2" ry="2"></rect>
                  <path d="m17.92 14 3.5-3.5a2.24 2.24 0 0 0 0-3l-5-4.92a2.24 2.24 0 0 0-3 0L10 6"></path>
                  <path d="M6 18h.01"></path>
                  <path d="M10 14h.01"></path>
                  <path d="M15 6h.01"></path>
                  <path d="M18 9h.01"></path>
                </svg><span class="text-sm font-medium">Live Casino</span></div>
              <div class="flex items-center gap-1"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                  viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                  stroke-linejoin="round"
                  class="lucide lucide-chevron-right w-3.5 h-3.5 transition-transform text-stone-500 group-hover:text-stone-300"
                  aria-hidden="true">
                  <path d="m9 18 6-6-6-6"></path>
                </svg></div>
            </a><a href="/card-games"
              class="w-full text-left px-3 py-2.5 rounded-lg flex items-center justify-between transition-all group text-stone-300 hover:text-white hover:bg-[#28180e]"
              id="nav-item-cards">
              <div class="flex items-center gap-3"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                  viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                  stroke-linejoin="round"
                  class="lucide lucide-club w-4 h-4 transition-transform group-hover:scale-110 text-orange-400"
                  aria-hidden="true">
                  <path d="M17.28 9.05a5.5 5.5 0 1 0-10.56 0A5.5 5.5 0 1 0 12 17.66a5.5 5.5 0 1 0 5.28-8.6Z"></path>
                  <path d="M12 17.66L12 22"></path>
                </svg><span class="text-sm font-medium">Card Games</span></div>
              <div class="flex items-center gap-1"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                  viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                  stroke-linejoin="round"
                  class="lucide lucide-chevron-right w-3.5 h-3.5 transition-transform text-stone-500 group-hover:text-stone-300"
                  aria-hidden="true">
                  <path d="m9 18 6-6-6-6"></path>
                </svg></div>
            </a><a href="/sports-betting"
              class="w-full text-left px-3 py-2.5 rounded-lg flex items-center justify-between transition-all group text-stone-300 hover:text-white hover:bg-[#28180e]"
              id="nav-item-sports">
              <div class="flex items-center gap-3"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                  viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                  stroke-linejoin="round"
                  class="lucide lucide-trophy w-4 h-4 transition-transform group-hover:scale-110 text-orange-400"
                  aria-hidden="true">
                  <path d="M10 14.66v1.626a2 2 0 0 1-.976 1.696A5 5 0 0 0 7 21.978"></path>
                  <path d="M14 14.66v1.626a2 2 0 0 0 .976 1.696A5 5 0 0 1 17 21.978"></path>
                  <path d="M18 9h1.5a1 1 0 0 0 0-5H18"></path>
                  <path d="M4 22h16"></path>
                  <path d="M6 9a6 6 0 0 0 12 0V3a1 1 0 0 0-1-1H7a1 1 0 0 0-1 1z"></path>
                  <path d="M6 9H4.5a1 1 0 0 1 0-5H6"></path>
                </svg><span class="text-sm font-medium">Sports Betting</span></div>
              <div class="flex items-center gap-1"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                  viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                  stroke-linejoin="round"
                  class="lucide lucide-chevron-right w-3.5 h-3.5 transition-transform text-stone-500 group-hover:text-stone-300"
                  aria-hidden="true">
                  <path d="m9 18 6-6-6-6"></path>
                </svg></div>
            </a>
            <div id="nav-item-sponsors" class="space-y-1"><button type="button" aria-expanded="false"
                aria-controls="nav-group-sponsors"
                class="w-full text-left px-3 py-2.5 rounded-lg flex items-center justify-between transition-all cursor-pointer text-stone-300 bg-[#1d120b] hover:text-white hover:bg-[#28180e]">
                <div class="flex items-center gap-3"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                    viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                    stroke-linejoin="round" class="lucide lucide-handshake w-4 h-4 text-amber-400" aria-hidden="true">
                    <path d="m11 17 2 2a1 1 0 1 0 3-3"></path>
                    <path
                      d="m14 14 2.5 2.5a1 1 0 1 0 3-3l-3.88-3.88a3 3 0 0 0-4.24 0l-.88.88a1 1 0 1 1-3-3l2.81-2.81a5.79 5.79 0 0 1 7.06-.87l.47.28a2 2 0 0 0 1.42.25L21 4">
                    </path>
                    <path d="m21 3 1 11h-2"></path>
                    <path d="M3 3 2 14l6.5 6.5a1 1 0 1 0 3-3"></path>
                    <path d="M3 4h8"></path>
                  </svg><span class="text-sm font-bold">Sponsors</span></div><svg xmlns="http://www.w3.org/2000/svg"
                  width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                  stroke-linecap="round" stroke-linejoin="round"
                  class="lucide lucide-chevron-right w-3.5 h-3.5 text-stone-500 transition-transform rotate-90"
                  aria-hidden="true">
                  <path d="m9 18 6-6-6-6"></path>
                </svg>
              </button>
              <div id="nav-group-sponsors" class="ml-4 border-l border-amber-600/25 pl-2 space-y-1" hidden>
                <div class="space-y-1"><button type="button" aria-expanded="true" aria-controls="nav-group-bybet"
                    class="w-full text-left pr-3 pl-10  py-2 rounded-lg flex items-center justify-between transition-all cursor-pointer text-stone-300 hover:text-white hover:bg-[#28180e]">
                    <div class="flex items-center gap-2.5"><svg xmlns="http://www.w3.org/2000/svg" width="24"
                        height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round"
                        class="lucide lucide-building2 lucide-building-2 w-3.5 h-3.5 text-orange-400"
                        aria-hidden="true">
                        <path d="M10 12h4"></path>
                        <path d="M10 8h4"></path>
                        <path d="M14 21v-3a2 2 0 0 0-4 0v3"></path>
                        <path d="M6 10H4a2 2 0 0 0-2 2v7a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-2"></path>
                        <path d="M6 21V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v16"></path>
                      </svg><span class="text-sm font-semibold"> BYBET</span></div><svg
                      xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                      stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                      class="lucide lucide-chevron-right w-3 h-3 text-stone-500 transition-transform rotate-90"
                      aria-hidden="true">
                      <path d="m9 18 6-6-6-6"></path>
                    </svg>
                  </button>
                  <div id="nav-group-bybet" class="ml-4 border-l border-amber-600/20 pl-2 space-y-1"><a href="/invite"
                      class="w-full text-left pr-3 pl-20 py-2 rounded-lg flex items-center gap-2.5 transition-all group text-stone-300 hover:text-white hover:bg-[#28180e]"
                      id="nav-item-invite"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round"
                        class="lucide lucide-gift w-3.5 h-3.5 transition-transform group-hover:scale-110 text-amber-400"
                        aria-hidden="true">
                        <rect x="3" y="8" width="18" height="4" rx="1"></rect>
                        <path d="M12 8v13"></path>
                        <path d="M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7"></path>
                        <path d="M7.5 8a2.5 2.5 0 0 1 0-5A4.8 8 0 0 1 12 8a4.8 8 0 0 1 4.5-5 2.5 2.5 0 0 1 0 5"></path>
                      </svg><span class="text-sm font-medium">Invite</span></a><a href="/vip"
                      class="w-full text-left pr-3 pl-20 py-2 rounded-lg flex items-center gap-2.5 transition-all group text-stone-300 hover:text-white hover:bg-[#28180e]"
                      id="nav-item-vip"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round"
                        class="lucide lucide-crown w-3.5 h-3.5 transition-transform group-hover:scale-110 text-amber-400"
                        aria-hidden="true">
                        <path
                          d="M11.562 3.266a.5.5 0 0 1 .876 0L15.39 8.87a1 1 0 0 0 1.516.294L21.183 5.5a.5.5 0 0 1 .798.519l-2.834 10.246a1 1 0 0 1-.956.734H5.81a1 1 0 0 1-.957-.734L2.02 6.02a.5.5 0 0 1 .798-.519l4.276 3.664a1 1 0 0 0 1.516-.294z">
                        </path>
                        <path d="M5 21h14"></path>
                      </svg><span class="text-sm font-medium">VIP</span></a><a href="/payments"
                      class="w-full text-left pr-3 pl-20 py-2 rounded-lg flex items-center gap-2.5 transition-all group text-stone-300 hover:text-white hover:bg-[#28180e]"
                      id="nav-item-payments"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round"
                        class="lucide lucide-credit-card w-3.5 h-3.5 transition-transform group-hover:scale-110 text-amber-400"
                        aria-hidden="true">
                        <rect width="20" height="14" x="2" y="5" rx="2"></rect>
                        <line x1="2" x2="22" y1="10" y2="10"></line>
                      </svg><span class="text-sm font-medium">Payments</span></a>
                    </div>
                </div>
                <a href="/become-a-partner"
                  class="w-full text-left pr-3 pl-10 py-2 rounded-lg flex items-center gap-2.5 transition-all group text-stone-300 hover:text-white hover:bg-[#28180e]"
                  id="nav-item-partner">
                  <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                    viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                    stroke-linejoin="round"
                    class="lucide lucide-handshake w-3.5 h-3.5 transition-transform group-hover:scale-110 text-amber-400"
                    aria-hidden="true">
                    <path d="m11 17 2 2a1 1 0 1 0 3-3"></path>
                    <path
                      d="m14 14 2.5 2.5a1 1 0 1 0 3-3l-3.88-3.88a3 3 0 0 0-4.24 0l-.88.88a1 1 0 1 1-3-3l2.81-2.81a5.79 5.79 0 0 1 7.06-.87l.47.28a2 2 0 0 0 1.42.25L21 4">
                    </path>
                    <path d="m21 3 1 11h-2"></path>
                    <path d="M3 3 2 14l6.5 6.5a1 1 0 1 0 3-3"></path>
                    <path d="M3 4h8"></path>
                  </svg>
                  <span class="text-sm font-medium">Become a Partner</span>
                </a>
                <a href="/promos"
                  class="w-full text-left pr-3 pl-10 py-2 rounded-lg flex items-center gap-2.5 transition-all group text-stone-300 hover:text-white hover:bg-[#28180e]"
                  id="nav-item-partner">
                  <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                    class="lucide lucide-flame w-5 h-5 text-amber-400" aria-hidden="true">
                    <path
                      d="M12 3q1 4 4 6.5t3 5.5a1 1 0 0 1-14 0 5 5 0 0 1 1-3 1 1 0 0 0 5 0c0-2-1.5-3-1.5-5q0-2 2.5-4">
                    </path>
                  </svg>
                  <span class="text-sm font-medium">Promos</span>
                </a>
              </div>
            </div><a href="/blog"
              class="w-full text-left px-3 py-2.5 rounded-lg flex items-center justify-between transition-all group bg-gradient-to-r from-orange-600 via-orange-500 to-amber-600 text-white font-bold shadow-md shadow-orange-950/60 translate-x-1"
              id="nav-item-blogs">
              <div class="flex items-center gap-3"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                  viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                  stroke-linejoin="round"
                  class="lucide lucide-book-open w-4 h-4 transition-transform group-hover:scale-110 text-white"
                  aria-hidden="true">
                  <path d="M12 7v14"></path>
                  <path
                    d="M3 18a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h5a4 4 0 0 1 4 4 4 4 0 0 1 4-4h5a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1h-6a3 3 0 0 0-3 3 3 3 0 0 0-3-3z">
                  </path>
                </svg><span class="text-sm font-medium">Blogs &amp; Strategy</span></div>
              <div class="flex items-center gap-1"></div>
            </a>
          </div>
          <div class="p-2 border-t border-[#291a10] bg-[#120a05]/60 space-y-2 mb-5">
            <div class="grid grid-cols-2 gap-1.5"><a href="https://t.me/+fRv-0z-NBJowY2Fl" target="_blank"
                rel="noopener noreferrer"
                class="flex items-center justify-center gap-1.5 py-1.5 px-2 bg-[#1d2d3a] hover:bg-[#273c4e] border border-sky-600/30 rounded-lg text-sky-400 text-[11px] font-semibold transition-colors"><svg
                  xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                  stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                  class="lucide lucide-send w-3.5 h-3.5" aria-hidden="true">
                  <path
                    d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z">
                  </path>
                  <path d="m21.854 2.147-10.94 10.939"></path>
                </svg><span>Telegram</span></a><a href="/app"
                class="flex items-center justify-center gap-1.5 py-1.5 px-2 border rounded-lg text-[11px] font-semibold transition-colors cursor-pointer bg-[#2d1e12] hover:bg-[#3d2a1b] border-amber-600/30 text-amber-300"><svg
                  xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                  stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                  class="lucide lucide-smartphone w-3.5 h-3.5 text-orange-400" aria-hidden="true">
                  <rect width="14" height="20" x="5" y="2" rx="2" ry="2"></rect>
                  <path d="M12 18h.01"></path>
                </svg><span>App</span></a></div><a href="/contact"
              class="w-full flex items-center justify-center gap-2 py-2 border rounded-lg text-sm font-medium transition-colors cursor-pointer bg-stone-900/80 hover:bg-stone-800 border-stone-800 text-stone-300"><svg
                xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                class="lucide lucide-message-square w-3.5 h-3.5 text-emerald-400" aria-hidden="true">
                <path
                  d="M22 17a2 2 0 0 1-2 2H6.828a2 2 0 0 0-1.414.586l-2.202 2.202A.71.71 0 0 1 2 21.286V5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2z">
                </path>
              </svg><span>24/7 Live Support</span></a>
          </div>
        </aside>
        <main class="flex-1 min-w-0 p-3 sm:p-5 md:p-6 space-y-6 sm:space-y-8 pb-20 sm:pb-8 overflow-x-hidden">
          <div class="space-y-6 w-full pb-12 animate-fadeIn">
            <div
              class="flex flex-wrap items-center justify-between gap-3 bg-[#180e07] border border-amber-500/40 p-4 rounded-2xl">
              <a href="/blog/"
                class="inline-flex items-center gap-2 text-xs font-black text-amber-300 hover:text-amber-200 bg-amber-500/10 hover:bg-amber-500/20 border border-amber-500/30 px-3.5 py-2 rounded-xl transition-all cursor-pointer"><svg
                  xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                  stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                  class="lucide lucide-arrow-left w-4 h-4" aria-hidden="true">
                  <path d="m12 19-7-7 7-7"></path>
                  <path d="M19 12H5"></path>
                </svg><span>BACK TO ALL ARTICLES</span></a>
              <div id="breadcrumbs" class="flex items-center gap-2 text-xs text-stone-400"><a href="/blog/"
                  class="hover:text-amber-300 cursor-pointer font-semibold">Blogs</a><svg
                  xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                  stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                  class="lucide lucide-chevron-right w-3.5 h-3.5 text-stone-500 hidden sm:inline" aria-hidden="true">
                  <path d="m9 18 6-6-6-6"></path>
                </svg><span
                  class="text-stone-300 truncate max-w-[180px] sm:max-w-xs hidden sm:inline"><?= blog_h($pageTitle) ?></span>
              </div>
            </div>
            <div
              class="bg-gradient-to-b from-[#1f1007] via-[#160b05] to-[#0d0603] border-2 border-amber-500/50 rounded-3xl p-3 sm:p-2 space-y-6 shadow-2xl">
              <div class="space-y-3">
                <div class="flex items-center gap-3"><span
                    class="bg-gradient-to-r from-amber-500 to-orange-600 text-stone-950 font-black text-xs px-3.5 py-1 rounded-full uppercase shadow"><?= $post['category'] ?></span><span
                    class="text-xs text-stone-400 flex items-center gap-1 font-semibold"><svg
                      xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                      stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                      class="lucide lucide-clock w-3.5 h-3.5 text-amber-400" aria-hidden="true">
                      <path d="M12 6v6l4 2"></path>
                      <circle cx="12" cy="12" r="10"></circle>
                    </svg><?= (int) $readMinutes ?> min read</span></div>
                <h1 class="text-2xl sm:text-4xl font-black text-white leading-tight tracking-tight">
                  <?= blog_h($pageTitle) ?></h1>
                <div
                  class="flex flex-wrap items-center justify-between gap-4 pt-2 border-t border-[#2e180c] text-xs text-stone-400">
                  <div class="flex items-center gap-3">
                    <div
                      class="w-8 h-8 rounded-full bg-amber-500/20 border border-amber-400/50 flex items-center justify-center text-amber-300 font-bold">
                      <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                        class="lucide lucide-user w-4 h-4" aria-hidden="true">
                        <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"></path>
                        <circle cx="12" cy="7" r="4"></circle>
                      </svg>
                    </div>
                    <div>
                      <p class="font-bold text-white text-xs">Tabitha Cruz</p>
                      <p class="text-[11px] text-stone-400"><?= $post['date'] ?></p>
                    </div>
                  </div>
                  <div class="flex items-center gap-3 blog-info"><span id="blog-view-count"
                      class="text-emerald-400 font-bold bg-emerald-950/80 border border-emerald-500/40 px-3 py-1 rounded-full"><?= (int) ($engagementCounts['views'] ?? 0) ?>
                      views</span><button type="button" data-blog-reaction="like"
                      class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-black border transition-all cursor-pointer bg-[#241308] text-stone-300 border-amber-500/30 hover:text-white hover:border-amber-400"><svg
                        xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                        class="lucide lucide-thumbs-up w-3.5 h-3.5" aria-hidden="true">
                        <path d="M7 10v12"></path>
                        <path
                          d="M15 5.88 14 10h5.83a2 2 0 0 1 1.92 2.56l-2.33 8A2 2 0 0 1 17.5 22H4a2 2 0 0 1-2-2v-8a2 2 0 0 1 2-2h2.76a2 2 0 0 0 1.79-1.11L12 2a3.13 3.13 0 0 1 3 3.88Z">
                        </path>
                      </svg><span>Like</span><span
                        id="blog-like-count"><?= (int) ($engagementCounts['likes'] ?? 0) ?></span></button><button
                      type="button" data-blog-reaction="dislike"
                      class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-black border transition-all cursor-pointer bg-[#241308] text-stone-300 border-red-500/30 hover:text-white hover:border-red-400"><svg
                        xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                        class="lucide lucide-thumbs-down w-3.5 h-3.5" aria-hidden="true">
                        <path d="M17 14V2"></path>
                        <path
                          d="M9 18.12 10 14H4.17a2 2 0 0 1-1.92-2.56l2.33-8A2 2 0 0 1 6.5 2H20a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2h-2.76a2 2 0 0 0-1.79 1.11L12 22a3.13 3.13 0 0 1-3-3.88Z">
                        </path>
                      </svg><span>Dislike</span><span
                        id="blog-dislike-count"><?= (int) ($engagementCounts['dislikes'] ?? 0) ?></span></button></div>
                </div>
              </div>
              <div class="relative aspect-video w-full rounded-2xl overflow-hidden border border-amber-500/30 shadow-2xl">
                <img alt="<?= blog_h($pageTitle) ?>" class="w-full h-full object-cover" src="<?= blog_h($image) ?>"
                  width="1200" height="675" loading="eager" decoding="async" fetchpriority="high">
                <div class="absolute inset-0 bg-gradient-to-t from-[#0d0603]/80 via-transparent to-transparent"></div>
              </div>
              <div id="article" class="space-y-4 text-sm sm:text-base text-stone-200 leading-relaxed">
                <div
                  class="blog-article-content bg-[#170c06] border border-[#2b170c] p-4 sm:p-5 rounded-2xl leading-relaxed">
                  <?= blog_render_markdown($post['content']) ?>
                </div>
              </div>
              <div
                class="bg-gradient-to-r from-[#2a160b] via-[#1d0f07] to-[#140803] border-2 border-amber-500/60 rounded-2xl p-5 sm:p-6 space-y-4 shadow-xl">
                <h2
                  class="font-black text-amber-300 text-base sm:text-lg flex items-center gap-2 uppercase tracking-wide">
                  <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                    class="lucide lucide-sparkles w-5 h-5 text-amber-400" aria-hidden="true">
                    <path
                      d="M11.017 2.814a1 1 0 0 1 1.966 0l1.051 5.558a2 2 0 0 0 1.594 1.594l5.558 1.051a1 1 0 0 1 0 1.966l-5.558 1.051a2 2 0 0 0-1.594 1.594l-1.051 5.558a1 1 0 0 1-1.966 0l-1.051-5.558a2 2 0 0 0-1.594-1.594l-5.558-1.051a1 1 0 0 1 0-1.966l5.558-1.051a2 2 0 0 0 1.594-1.594z">
                    </path>
                    <path d="M20 2v4"></path>
                    <path d="M22 4h-4"></path>
                    <circle cx="4" cy="20" r="2"></circle>
                  </svg><span>Disclaimer</span>
                </h2>
                <p class="space-y-3 text-xs sm:text-sm text-stone-200">Must be 21 years of age or older to enter the
                  casino. Individuals who are prohibited from attending Ontario gaming sites are not permitted to enter
                  the properties or participate in contests or promotions.</p>
              </div>
              <div class="bg-[#180d06] border border-amber-500/40 rounded-2xl p-5 space-y-3">
                <div class="flex items-center justify-between">
                  <h2
                    class="font-black text-amber-300 text-xs sm:text-sm uppercase tracking-wider flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                      stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                      class="lucide lucide-share2 lucide-share-2 w-4 h-4 text-amber-400" aria-hidden="true">
                      <circle cx="18" cy="5" r="3"></circle>
                      <circle cx="6" cy="12" r="3"></circle>
                      <circle cx="18" cy="19" r="3"></circle>
                      <line x1="8.59" x2="15.42" y1="13.51" y2="17.49"></line>
                      <line x1="15.41" x2="8.59" y1="6.51" y2="10.49"></line>
                    </svg><span>Share This Article on Social Media</span>
                  </h2>
                </div>
                <div class="grid grid-cols-2 sm:grid-cols-5 gap-2.5 pt-1"><button
                    class="bg-[#1877F2]/20 hover:bg-[#1877F2]/30 border border-[#1877F2]/50 text-[#1877F2] hover:text-blue-300 font-bold text-xs py-2.5 px-3 rounded-xl flex items-center justify-center gap-2 transition-all cursor-pointer"><svg
                      xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                      stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                      class="lucide lucide-globe w-4 h-4" aria-hidden="true">
                      <circle cx="12" cy="12" r="10"></circle>
                      <path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"></path>
                      <path d="M2 12h20"></path>
                    </svg><span>Facebook</span></button><button
                    class="bg-[#229ED9]/20 hover:bg-[#229ED9]/30 border border-[#229ED9]/50 text-[#229ED9] hover:text-sky-300 font-bold text-xs py-2.5 px-3 rounded-xl flex items-center justify-center gap-2 transition-all cursor-pointer"><svg
                      xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                      stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                      class="lucide lucide-send w-4 h-4" aria-hidden="true">
                      <path
                        d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z">
                      </path>
                      <path d="m21.854 2.147-10.94 10.939"></path>
                    </svg><span>Telegram</span></button><button
                    class="bg-stone-800 hover:bg-stone-700 border border-stone-600 text-stone-200 font-bold text-xs py-2.5 px-3 rounded-xl flex items-center justify-center gap-2 transition-all cursor-pointer"><svg
                      xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                      stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                      class="lucide lucide-message-circle w-4 h-4 text-amber-400" aria-hidden="true">
                      <path
                        d="M2.992 16.342a2 2 0 0 1 .094 1.167l-1.065 3.29a1 1 0 0 0 1.236 1.168l3.413-.998a2 2 0 0 1 1.099.092 10 10 0 1 0-4.777-4.719">
                      </path>
                    </svg><span>X (Twitter)</span></button><button
                    class="bg-[#25D366]/20 hover:bg-[#25D366]/30 border border-[#25D366]/50 text-[#25D366] hover:text-emerald-300 font-bold text-xs py-2.5 px-3 rounded-xl flex items-center justify-center gap-2 transition-all cursor-pointer"><svg
                      xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                      stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                      class="lucide lucide-message-circle w-4 h-4" aria-hidden="true">
                      <path
                        d="M2.992 16.342a2 2 0 0 1 .094 1.167l-1.065 3.29a1 1 0 0 0 1.236 1.168l3.413-.998a2 2 0 0 1 1.099.092 10 10 0 1 0-4.777-4.719">
                      </path>
                    </svg><span>WhatsApp</span></button><button
                    class="col-span-2 sm:col-span-1 bg-gradient-to-r from-amber-500/20 to-orange-500/20 hover:from-amber-500/30 hover:to-orange-500/30 border border-amber-400/40 text-amber-300 font-bold text-xs py-2.5 px-3 rounded-xl flex items-center justify-center gap-2 transition-all cursor-pointer"><svg
                      xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                      stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                      class="lucide lucide-copy w-4 h-4" aria-hidden="true">
                      <rect width="14" height="14" x="8" y="8" rx="2" ry="2"></rect>
                      <path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"></path>
                    </svg><span>Copy Link</span></button></div>
              </div>
              <div
                class="bg-gradient-to-r from-[#211107] via-[#1a0e06] to-[#120703] border-2 border-amber-500/50 rounded-2xl p-5 sm:p-6 space-y-4 shadow-xl">
                <div class="flex items-center justify-between border-b border-[#2d180d] pb-3">
                  <h2
                    class="font-black text-amber-300 text-xs sm:text-sm uppercase tracking-wider flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                      stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                      class="lucide lucide-award w-4 h-4 text-amber-400" aria-hidden="true">
                      <path
                        d="m15.477 12.89 1.515 8.526a.5.5 0 0 1-.81.47l-3.58-2.687a1 1 0 0 0-1.197 0l-3.586 2.686a.5.5 0 0 1-.81-.469l1.514-8.526">
                      </path>
                      <circle cx="12" cy="8" r="6"></circle>
                    </svg><span>About the Author</span>
                  </h2><span
                    class="bg-emerald-500/20 border border-emerald-400/40 text-emerald-300 text-[10px] font-black px-2.5 py-0.5 rounded-full flex items-center gap-1"><svg
                      xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                      stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                      class="lucide lucide-badge-check w-3.5 h-3.5 text-emerald-400" aria-hidden="true">
                      <path
                        d="M3.85 8.62a4 4 0 0 1 4.78-4.77 4 4 0 0 1 6.74 0 4 4 0 0 1 4.78 4.78 4 4 0 0 1 0 6.74 4 4 0 0 1-4.77 4.78 4 4 0 0 1-6.75 0 4 4 0 0 1-4.78-4.77 4 4 0 0 1 0-6.76Z">
                      </path>
                      <path d="m9 12 2 2 4-4"></path>
                    </svg><span>VERIFIED AUTHOR</span></span>
                </div>
                <div class="flex flex-col sm:flex-row items-start sm:items-center gap-4">
                  <div class="relative shrink-0">
                    <div
                      class="w-16 h-16 sm:w-20 sm:h-20 rounded-2xl p-0.5 bg-gradient-to-tr from-amber-400 via-orange-500 to-amber-200 shadow-lg overflow-hidden">
                      <img alt="Tabitha Cruz" class="w-full h-full object-cover rounded-[14px]" width="80" height="80"
                        loading="lazy" decoding="async"
                        src="/assets/images/Tabitha Cruz.webp">
                    </div>
                    <div class="absolute -bottom-1 -right-1 bg-amber-400 text-stone-950 p-1 rounded-full shadow"><svg
                        xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                        class="lucide lucide-badge-check w-4 h-4 fill-stone-950 text-amber-400" aria-hidden="true">
                        <path
                          d="M3.85 8.62a4 4 0 0 1 4.78-4.77 4 4 0 0 1 6.74 0 4 4 0 0 1 4.78 4.78 4 4 0 0 1 0 6.74 4 4 0 0 1-4.77 4.78 4 4 0 0 1-6.75 0 4 4 0 0 1-4.78-4.77 4 4 0 0 1 0-6.76Z">
                        </path>
                        <path d="m9 12 2 2 4-4"></path>
                      </svg></div>
                  </div>
                  <div class="space-y-2 flex-1">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                      <div>
                        <h3 class="font-black text-base sm:text-lg text-white flex items-center gap-1.5"><span>Tabitha
                            Cruz</span></h3>
                        <p class="text-xs font-bold text-amber-400">Writer</p>
                      </div>
                      <div
                        class="flex items-center gap-2 bg-[#120703] border border-amber-500/30 px-3 py-1 rounded-xl text-xs">
                        <span class="text-amber-300 font-black">4.9 ★</span>
                      </div>
                    </div>
                    <p class="text-xs text-stone-300 leading-relaxed">Tabitha Cruz is a seasoned iGaming and writer with
                      extensive experience across iGaming, casino, and travel niches. Her expertise has given her a
                      strong understanding of audience behavior, content trends, and conversion-focused optimization
                      strategies that drive measurable growth.</p>
                    <div
                      class="pt-1 flex flex-wrap items-center justify-between gap-2 border-t border-[#26140a] text-[11px]">
                      <span class="text-stone-400"><strong class="text-amber-300">Specialty:</strong> Casino Gaming
                        &amp; Strategy</span><button
                        class="text-amber-300 hover:text-amber-200 font-extrabold flex items-center gap-1 hover:underline cursor-pointer"><span>Follow
                          Official Channel</span><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                          viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                          stroke-linejoin="round" class="lucide lucide-arrow-right w-3.5 h-3.5" aria-hidden="true">
                          <path d="M5 12h14"></path>
                          <path d="m12 5 7 7-7 7"></path>
                        </svg></button>
                    </div>
                  </div>
                </div>
              </div>
              <div
                class="bg-gradient-to-br from-[#1b0f07] via-[#140b04] to-[#0c0502] border-2 border-amber-500/50 rounded-2xl p-5 sm:p-6 space-y-4 shadow-xl">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-[#2d180d] pb-3">
                  <div class="space-y-0.5">
                    <h2
                      class="font-black text-amber-300 text-xs sm:text-sm uppercase tracking-wider flex items-center gap-2">
                      <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                        class="lucide lucide-radio w-4 h-4 text-amber-400 animate-pulse" aria-hidden="true">
                        <path d="M16.247 7.761a6 6 0 0 1 0 8.478"></path>
                        <path d="M19.075 4.933a10 10 0 0 1 0 14.134"></path>
                        <path d="M4.925 19.067a10 10 0 0 1 0-14.134"></path>
                        <path d="M7.753 16.239a6 6 0 0 1 0-8.478"></path>
                        <circle cx="12" cy="12" r="2"></circle>
                      </svg><span>Follow Us on Official Channels</span>
                    </h2>
                    <p class="text-[11px] text-stone-400 font-medium">Get daily Ang Pao codes, promo announcements, and
                      VIP tips!</p>
                  </div><span
                    class="bg-amber-500/10 border border-amber-500/30 text-amber-300 font-extrabold text-[11px] px-3 py-1 rounded-full flex items-center gap-1.5"><svg
                      xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                      stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                      class="lucide lucide-bell w-3.5 h-3.5 text-amber-400" aria-hidden="true">
                      <path d="M10.268 21a2 2 0 0 0 3.464 0"></path>
                      <path
                        d="M3.262 15.326A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.673C19.41 13.956 18 12.499 18 8A6 6 0 0 0 6 8c0 4.499-1.411 5.956-2.738 7.326">
                      </path>
                    </svg><span>50K+ Active Members</span></span>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3"><a href="/playnow" target="_blank" rel="noreferrer"
                    class="group bg-[#111a24]/90 hover:bg-[#152332] border border-[#229ED9]/40 hover:border-[#229ED9] p-3.5 rounded-xl flex items-center gap-3 transition-all cursor-pointer shadow-md">
                    <div
                      class="w-10 h-10 rounded-xl bg-[#229ED9]/20 border border-[#229ED9]/50 flex items-center justify-center text-[#229ED9] shrink-0 group-hover:scale-110 transition-transform">
                      <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                        class="lucide lucide-send w-5 h-5" aria-hidden="true">
                        <path
                          d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z">
                        </path>
                        <path d="m21.854 2.147-10.94 10.939"></path>
                      </svg>
                    </div>
                    <div class="min-w-0 flex-1">
                      <p class="font-black text-xs text-white group-hover:text-amber-300 transition-colors truncate">
                        Telegram Official</p>
                      <p class="text-[10px] text-stone-400 truncate">Daily Code Giveaways</p>
                    </div><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                      stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                      class="lucide lucide-arrow-right w-4 h-4 text-stone-500 group-hover:text-amber-400 group-hover:translate-x-0.5 transition-all shrink-0"
                      aria-hidden="true">
                      <path d="M5 12h14"></path>
                      <path d="m12 5 7 7-7 7"></path>
                    </svg>
                  </a><a href="/playnow" target="_blank" rel="noreferrer"
                    class="group bg-[#0e1625]/90 hover:bg-[#132036] border border-[#1877F2]/40 hover:border-[#1877F2] p-3.5 rounded-xl flex items-center gap-3 transition-all cursor-pointer shadow-md">
                    <div
                      class="w-10 h-10 rounded-xl bg-[#1877F2]/20 border border-[#1877F2]/50 flex items-center justify-center text-[#1877F2] shrink-0 group-hover:scale-110 transition-transform">
                      <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                        class="lucide lucide-globe w-5 h-5" aria-hidden="true">
                        <circle cx="12" cy="12" r="10"></circle>
                        <path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"></path>
                        <path d="M2 12h20"></path>
                      </svg>
                    </div>
                    <div class="min-w-0 flex-1">
                      <p class="font-black text-xs text-white group-hover:text-amber-300 transition-colors truncate">
                        Facebook Community</p>
                      <p class="text-[10px] text-stone-400 truncate">Winners &amp; Event News</p>
                    </div><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                      stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                      class="lucide lucide-arrow-right w-4 h-4 text-stone-500 group-hover:text-amber-400 group-hover:translate-x-0.5 transition-all shrink-0"
                      aria-hidden="true">
                      <path d="M5 12h14"></path>
                      <path d="m12 5 7 7-7 7"></path>
                    </svg>
                  </a><a href="/playnow" target="_blank" rel="noreferrer"
                    class="group bg-[#1c1307]/90 hover:bg-[#281b0a] border border-amber-500/40 hover:border-amber-400 p-3.5 rounded-xl flex items-center gap-3 transition-all cursor-pointer shadow-md">
                    <div
                      class="w-10 h-10 rounded-xl bg-amber-500/20 border border-amber-400/50 flex items-center justify-center text-amber-300 shrink-0 group-hover:scale-110 transition-transform">
                      <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                        class="lucide lucide-users w-5 h-5" aria-hidden="true">
                        <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path>
                        <path d="M16 3.128a4 4 0 0 1 0 7.744"></path>
                        <path d="M22 21v-2a4 4 0 0 0-3-3.87"></path>
                        <circle cx="9" cy="7" r="4"></circle>
                      </svg>
                    </div>
                    <div class="min-w-0 flex-1">
                      <p class="font-black text-xs text-white group-hover:text-amber-300 transition-colors truncate">VIP
                        High Roller Group</p>
                      <p class="text-[10px] text-stone-400 truncate">Exclusive Cashback &amp; Perks</p>
                    </div><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                      stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                      class="lucide lucide-arrow-right w-4 h-4 text-stone-500 group-hover:text-amber-400 group-hover:translate-x-0.5 transition-all shrink-0"
                      aria-hidden="true">
                      <path d="M5 12h14"></path>
                      <path d="m12 5 7 7-7 7"></path>
                    </svg>
                  </a></div>
              </div>
              <div
                class="bg-gradient-to-r from-amber-500 via-orange-500 to-red-600 rounded-2xl p-6 text-stone-950 flex flex-col sm:flex-row items-center justify-between gap-5 shadow-2xl">
                <div class="space-y-1 text-center sm:text-left">
                  <h2 class="font-black text-lg sm:text-xl leading-tight">Ready to test this winning strategy?</h2>
                  <p class="text-xs sm:text-sm font-bold opacity-90">Claim your ₱180 free Welcome Bonus on your first
                    game!</p>
                </div><button
                  class="w-full sm:w-auto bg-stone-950 hover:bg-stone-900 text-amber-300 font-black text-xs sm:text-sm py-3.5 px-6 rounded-xl shadow-2xl flex items-center justify-center gap-2 cursor-pointer transition-transform hover:scale-105 whitespace-nowrap border border-amber-400/40"><svg
                    xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                    class="lucide lucide-gift w-5 h-5 text-amber-400" aria-hidden="true">
                    <rect x="3" y="8" width="18" height="4" rx="1"></rect>
                    <path d="M12 8v13"></path>
                    <path d="M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7"></path>
                    <path d="M7.5 8a2.5 2.5 0 0 1 0-5A4.8 8 0 0 1 12 8a4.8 8 0 0 1 4.5-5 2.5 2.5 0 0 1 0 5"></path>
                  </svg><span>PLAY &amp; CLAIM BONUS NOW</span><svg xmlns="http://www.w3.org/2000/svg" width="24"
                    height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                    stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-arrow-right w-5 h-5"
                    aria-hidden="true">
                    <path d="M5 12h14"></path>
                    <path d="m12 5 7 7-7 7"></path>
                  </svg></button>
              </div>
            </div>
            <div class="space-y-4 pt-4 border-t border-[#2d180d]">
              <h2 class="text-lg font-black text-white flex items-center gap-2"><svg xmlns="http://www.w3.org/2000/svg"
                  width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                  stroke-linecap="round" stroke-linejoin="round"
                  class="lucide lucide-trending-up w-5 h-5 text-amber-400" aria-hidden="true">
                  <path d="M16 7h6v6"></path>
                  <path d="m22 7-8.5 8.5-5-5L2 17"></path>
                </svg><span>Other Recommended Articles</span></h2>
              <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
                <?php foreach ($recommendedPosts as $recommended): ?>
                  <a href="/blog/<?= blog_h(rawurlencode((string) $recommended['slug'])) ?>/"
                    class="group bg-[#180e07] border border-amber-500/30 rounded-xl overflow-hidden shadow-lg hover:border-amber-400 transition-all">
                    <img src="<?= blog_h((string) ($recommended['featured_image'] ?: BLOG_DEFAULT_IMAGE)) ?>"
                      alt="<?= blog_h((string) $recommended['title']) ?>" class="w-full h-36 object-cover" loading="lazy"
                      decoding="async" width="1200" height="675">
                    <div class="p-4 space-y-2">
                      <span
                        class="text-[10px] font-black uppercase tracking-wider text-amber-400"><?= blog_h((string) ($recommended['category'] ?? 'Guides')) ?></span>
                      <h3 class="font-black text-white group-hover:text-amber-300 transition-colors">
                        <?= blog_h((string) $recommended['title']) ?></h3>
                      <p class="text-xs text-stone-400 line-clamp-2">
                        <?= blog_h((string) ($recommended['excerpt'] ?? '')) ?></p>
                    </div>
                  </a>
                <?php endforeach; ?>
              </div>
            </div>
            <div class="pt-4 text-center"><a href="/blog/"
                class="inline-flex items-center gap-2 text-xs font-black text-amber-300 hover:text-amber-200 bg-[#180e07] border border-amber-500/40 hover:border-amber-400 px-6 py-3 rounded-xl transition-all cursor-pointer shadow-lg"><svg
                  xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                  stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                  class="lucide lucide-arrow-left w-4 h-4" aria-hidden="true">
                  <path d="m12 19-7-7 7-7"></path>
                  <path d="M19 12H5"></path>
                </svg><span>BACK TO BLOGS</span></a></div>
          </div>
        </main>
      </div>
      <footer
        class="bg-[#0b0704] border-t border-[#23140a] text-stone-400 text-sm pt-10 pb-20 sm:pb-12 px-4 sm:px-8 space-y-10">
        <div class="w-full grid grid-cols-1 md:grid-cols-2 lg:grid-cols-6 gap-8 border-b border-[#24150b] pb-10">
          <div class="space-y-3 lg:col-span-2">
            <div class="flex items-center gap-3"><a href="/" class="inline-block m-auto"><img
                  src="/assets/free-online-games-logo-C1EG2Cuk.webp" alt="Free Online Games" width="1254" height="1174"
                  class="w-auto max-w-[180px] m-auto object-contain rounded drop-shadow-[0_2px_6px_rgba(255,180,0,0.4)]"
                  loading="lazy" decoding="async" referrerpolicy="no-referrer"></a></div>
            <p class="text-[11px] text-stone-400 leading-relaxed"><span class="text-amber-400 font-bold">The premier
                free online gaming portal in the Philippines:</span> Experience verified free-to-play demo slots,
              high-adrenaline crash arcade titles, authentic Pinoy peryahan, live dealer baccarat, and competitive card
              tables with zero financial risk. <a href="/responsible-gaming"
                class="font-semibold text-amber-300 hover:text-amber-400 transition-colors">PAGCOR Responsible Gaming
                Information</a></p>
            <div
              class="flex items-center gap-2 text-[10px] text-emerald-400 bg-[#121c14] border border-emerald-600/30 p-2.5 rounded-lg">
              <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                class="lucide lucide-shield-alert w-4 h-4 shrink-0 text-emerald-400" aria-hidden="true">
                <path
                  d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z">
                </path>
                <path d="M12 8v4"></path>
                <path d="M12 16h.01"></path>
              </svg><span>SSL 256-Bit Encrypted &amp; RNG Certified Fair Play Standards</span></div>
          </div>
          <div class="space-y-3"><span class="text-sm font-extrabold text-white tracking-wider block">Game
              Categories</span>
            <ul class="space-y-2 text-[11px]">
              <li><a href="/slots" class="text-stone-400 hover:text-amber-300 transition-colors">Online Slot Games</a>
              </li>
              <li><a href="/arcade" class="text-stone-400 hover:text-amber-300 transition-colors">Arcade &amp; Crash
                  Games</a></li>
              <li><a href="/perya-games" class="text-stone-400 hover:text-amber-300 transition-colors">Pinoy Perya
                  Carnival</a></li>
              <li><a href="/live-casino" class="text-stone-400 hover:text-amber-300 transition-colors">Live Casino &amp;
                  Baccarat</a></li>
              <li><a href="/card-games" class="text-stone-400 hover:text-amber-300 transition-colors">Classic Card
                  Games</a></li>
              <li><a href="/sports-betting" class="text-stone-400 hover:text-amber-300 transition-colors">Sports Betting
                  Simulation</a></li>
            </ul>
          </div>
          <div class="space-y-3"><span class="text-sm font-extrabold text-white tracking-wider block">Player
              Features</span>
            <ul class="space-y-2 text-[11px]">
              <li><a href="/promos" class="text-stone-400 hover:text-amber-300 transition-colors">Promotions &amp;
                  Bonuses</a></li>
              <li><a href="/vip" class="text-stone-400 hover:text-amber-300 transition-colors">VIP Rewards Lounge</a>
              </li>
              <li><a href="/invite" class="text-stone-400 hover:text-amber-300 transition-colors">Invite &amp; Earn
                  Program</a></li>
              <li><a href="/payments" class="text-stone-400 hover:text-amber-300 transition-colors">Payment Options
                  &amp; GCash</a></li>
              <li><a href="/become-a-partner" class="text-stone-400 hover:text-amber-300 transition-colors">Become a
                  Partner</a></li>
              <li><a href="/app" class="text-stone-400 hover:text-amber-300 transition-colors">Official Mobile App</a>
              </li>
              <li><a href="/blog" class="text-stone-400 hover:text-amber-300 transition-colors">Strategy Guides &amp;
                  News</a></li>
              <li><a href="/contact" class="text-stone-400 hover:text-amber-300 transition-colors">24/7 Customer
                  Support</a></li>
            </ul>
          </div>
          <div class="space-y-3"><span class="text-sm font-extrabold text-white tracking-wider block"> Our
              Partners</span><a href="/playnow" class="block  transition-colors"><img
                src="/assets/images/bybet-logo.webp" alt="ByBet" width="180" height="72"
                class="h-12 w-full max-w-[180px] object-contain rounded-md" loading="lazy" decoding="async"></a></div>
          <div class="space-y-3"><span class="text-sm font-extrabold text-white tracking-wider block">About Free Casino
              Games</span>
            <ul class="space-y-2 text-[11px]">
              <li><a href="/about-us" class="text-stone-400 hover:text-amber-300 transition-colors">About Us</a></li>
              <li><a href="/responsible-gaming"
                  class="text-stone-400 hover:text-amber-300 transition-colors">Responsible Gaming</a></li>
              <li><a href="/terms-and-conditions" class="text-stone-400 hover:text-amber-300 transition-colors">Terms
                  &amp; Conditions</a></li>
              
            </ul>
          </div>
        </div>
        <div class="w-full space-y-3"><span
            class="text-[10px] font-black uppercase tracking-widest text-stone-500 block text-center">OFFICIAL GAME
            PROVIDERS &amp; GAMING PARTNERS</span>
          <div class="blog-provider-marquee" data-provider-marquee aria-label="Official game providers">
            <div class="blog-provider-marquee-track" data-provider-marquee-track></div>
          </div>
        </div>
        <div
          class="w-full flex flex-col sm:flex-row items-center justify-between gap-3 pt-6 border-t border-[#1d1108] text-[11px] text-stone-500">
          <p>Copyright © All Rights Reserved By Free Online Games Philippines</p>
          <div class="flex items-center gap-4"><a href="/" class="hover:text-stone-300 transition-colors">Main
              Lobby</a><a href="/contact" class="hover:text-stone-300 transition-colors">Contact Us</a><a href="/blog"
              class="hover:text-stone-300 transition-colors">Blog Articles</a></div>
        </div>
      </footer>
      <div
        class="fixed bottom-0 left-0 right-0 z-40 bg-[#160d07] border-t border-[#311f13] py-1.5 px-3 flex items-center justify-around sm:hidden shadow-2xl backdrop-blur-md">
        <a href="/" class="flex flex-col items-center gap-0.5 text-[10px] font-bold text-stone-400"><svg
            xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
            class="lucide lucide-house w-5 h-5" aria-hidden="true">
            <path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8"></path>
            <path
              d="M3 10a2 2 0 0 1 .709-1.528l7-6a2 2 0 0 1 2.582 0l7 6A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z">
            </path>
          </svg><span>Home</span></a>
        <a href="/promos/"
          class="flex flex-col items-center gap-0.5 text-[10px] font-bold text-stone-400 hover:text-amber-300"><svg
            xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
            class="lucide lucide-gift w-5 h-5 text-orange-400" aria-hidden="true">
            <rect x="3" y="8" width="18" height="4" rx="1"></rect>
            <path d="M12 8v13"></path>
            <path d="M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7"></path>
            <path d="M7.5 8a2.5 2.5 0 0 1 0-5A4.8 8 0 0 1 12 8a4.8 8 0 0 1 4.5-5 2.5 2.5 0 0 1 0 5"></path>
          </svg><span>Promos</span></a>
        <a href="/playnow" class="flex flex-col items-center gap-0.5 text-[10px] font-bold text-amber-400">
          <div
            class="p-1 rounded-full bg-gradient-to-tr from-amber-500 to-orange-600 text-stone-950 -mt-3 shadow-lg shadow-orange-950">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
              stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
              class="lucide lucide-wallet w-5 h-5" aria-hidden="true">
              <path
                d="M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2a1 1 0 0 0-1-1">
              </path>
              <path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"></path>
            </svg>
          </div><span>Play Now!</span>
        </a>
        <a href="/contact/"
          class="flex flex-col items-center gap-0.5 text-[10px] font-bold text-stone-400 hover:text-amber-300"><svg
            xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
            class="lucide lucide-message-square w-5 h-5 text-emerald-400" aria-hidden="true">
            <path
              d="M22 17a2 2 0 0 1-2 2H6.828a2 2 0 0 0-1.414.586l-2.202 2.202A.71.71 0 0 1 2 21.286V5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2z">
            </path>
          </svg><span>Support</span></a><a href="/become-a-partner/"
          class="flex flex-col items-center gap-0.5 text-[10px] font-bold text-stone-400 hover:text-amber-300"><svg
            xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
            class="lucide lucide-crown w-5 h-5 text-purple-400" aria-hidden="true">
            <path
              d="M11.562 3.266a.5.5 0 0 1 .876 0L15.39 8.87a1 1 0 0 0 1.516.294L21.183 5.5a.5.5 0 0 1 .798.519l-2.834 10.246a1 1 0 0 1-.956.734H5.81a1 1 0 0 1-.957-.734L2.02 6.02a.5.5 0 0 1 .798-.519l4.276 3.664a1 1 0 0 0 1.516-.294z">
            </path>
            <path d="M5 21h14"></path>
          </svg><span>Sponsors</span></a>
      </div>
      <div class="fixed bottom-20 right-4 sm:right-6 z-40 flex flex-col items-end"><button
          class="group relative flex items-center gap-2.5 bg-gradient-to-r from-amber-500 via-orange-500 to-red-600 hover:from-amber-400 hover:to-orange-400 text-stone-950 p-3 sm:px-4 sm:py-3 rounded-full shadow-2xl shadow-orange-950/80 border-2 border-amber-300 transition-all hover:scale-110 active:scale-95 cursor-pointer"
          aria-label="Open CS Support Chatbot"><span class="absolute -top-1 -right-1 flex h-4 w-4"><span
              class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span><span
              class="relative inline-flex rounded-full h-4 w-4 bg-emerald-500 border-2 border-stone-900"></span></span>
          <div class="relative"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
              fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
              class="lucide lucide-headphones w-6 h-6 text-stone-950 group-hover:rotate-12 transition-transform"
              aria-hidden="true">
              <path
                d="M3 14h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-7a9 9 0 0 1 18 0v7a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3">
              </path>
            </svg></div>
          <div class="hidden sm:flex flex-col items-start leading-tight text-left"><span
              class="text-[11px] font-black tracking-tight text-stone-950">24/7 CS LIVE</span><span
              class="text-[9px] font-bold text-stone-900/90 flex items-center gap-1"><svg
                xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                class="lucide lucide-sparkles w-2.5 h-2.5 text-amber-950" aria-hidden="true">
                <path
                  d="M11.017 2.814a1 1 0 0 1 1.966 0l1.051 5.558a2 2 0 0 0 1.594 1.594l5.558 1.051a1 1 0 0 1 0 1.966l-5.558 1.051a2 2 0 0 0-1.594 1.594l-1.051 5.558a1 1 0 0 1-1.966 0l-1.051-5.558a2 2 0 0 0-1.594-1.594l-5.558-1.051a1 1 0 0 1 0-1.966l5.558-1.051a2 2 0 0 0 1.594-1.594z">
                </path>
                <path d="M20 2v4"></path>
                <path d="M22 4h-4"></path>
                <circle cx="4" cy="20" r="2"></circle>
              </svg> AI Assistant</span></div>
        </button></div>
      <div class="fixed bottom-20 left-4 sm:left-6 z-40 flex flex-col items-start animate-float-bob"><button
          class="group relative flex items-center gap-2 bg-gradient-to-r from-amber-500 via-orange-500 to-red-600 hover:from-amber-400 hover:to-orange-400 text-stone-950 p-2.5 sm:px-3.5 sm:py-2.5 rounded-2xl shadow-2xl shadow-amber-950/90 border-2 border-amber-300 transition-transform hover:scale-110 active:scale-95 cursor-pointer"
          aria-label="Floating Lucky Slot Wheel"><span
            class="absolute -inset-1 rounded-2xl bg-gradient-to-r from-amber-400 to-orange-500 opacity-40 blur-sm group-hover:opacity-100 transition-opacity animate-pulse"></span><span
            class="absolute -top-3 left-1/2 -translate-x-1/2 bg-red-600 text-amber-200 text-[8px] sm:text-[9px] font-black px-2 py-0.5 rounded-full border border-amber-300 shadow whitespace-nowrap uppercase tracking-wider animate-bounce">🎰
            ₱240M JACKPOT</span>
          <div class="relative z-10 flex items-center gap-2">
            <div
              class="w-8 h-8 rounded-xl bg-stone-950/30 border border-amber-300/60 flex items-center justify-center text-lg shadow-inner">
              🎰</div>
            <div class="hidden sm:flex flex-col text-left leading-none"><span
                class="text-[11px] font-black text-stone-950 uppercase tracking-tight">LUCKY SPIN</span><span
                class="text-[9px] font-extrabold text-amber-950/90">FREE BONUS</span></div>
          </div>
        </button></div>
    </div>
  </div>
  <div id="blog-scroll-promo-modal"
    class="hidden fixed inset-0 z-50 items-center justify-center p-3 sm:p-4 bg-black/80 backdrop-blur-md animate-fadeIn overflow-y-auto"
    style="z-index: 9999;" role="dialog" aria-modal="true" aria-labelledby="blog-scroll-promo-title">
    <div
      class="relative w-full max-w-md max-h-[90vh] bg-gradient-to-b from-[#211208] via-[#1a0e06] to-[#0d0703] border-2 border-amber-500/60 rounded-2xl sm:rounded-3xl shadow-2xl shadow-orange-950/90 overflow-hidden flex flex-col transform transition-all"
      data-blog-scroll-promo-panel>
      <div class="absolute -top-12 -left-12 w-32 h-32 bg-amber-500/20 rounded-full blur-2xl pointer-events-none"></div>
      <div class="absolute -bottom-12 -right-12 w-32 h-32 bg-orange-600/20 rounded-full blur-2xl pointer-events-none">
      </div>
      <button type="button"
        class="absolute top-2.5 right-2.5 sm:top-3.5 sm:right-3.5 w-10 h-10 flex items-center justify-center rounded-full bg-[#2a1b10]/90 hover:bg-[#3a2618] text-stone-300 hover:text-white transition-colors z-20 cursor-pointer border border-amber-500/30"
        aria-label="Close modal" data-blog-scroll-promo-close>
        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
          stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
          class="lucide lucide-x w-5 h-5" aria-hidden="true">
          <path d="M18 6 6 18"></path>
          <path d="m6 6 12 12"></path>
        </svg>
      </button>
      <div class="p-4 sm:p-6 pt-5 sm:pt-7 text-center space-y-3.5 sm:space-y-4 overflow-y-auto">
        <div
          class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-gradient-to-r from-amber-500 to-orange-600 text-stone-950 font-black text-[11px] sm:text-sm uppercase tracking-wider shadow-lg shadow-orange-950/50">
          <span>Bonus Unlocked</span>
        </div>
        <div class="relative mx-auto w-16 h-16 sm:w-20 sm:h-20 flex items-center justify-center">
          <div
            class="absolute inset-0 bg-gradient-to-tr from-amber-500 to-orange-500 rounded-2xl rotate-6 opacity-30 animate-pulse">
          </div>
          <div
            class="relative w-16 h-16 sm:w-20 sm:h-20 rounded-2xl bg-gradient-to-b from-[#3a2010] to-[#201006] border-2 border-amber-400/80 flex items-center justify-center shadow-inner p-2.5">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
              stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
              class="lucide lucide-gift w-8 h-8 sm:w-10 sm:h-10 text-amber-400" aria-hidden="true">
              <rect x="3" y="8" width="18" height="4" rx="1"></rect>
              <path d="M12 8v13"></path>
              <path d="M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7"></path>
              <path d="M7.5 8a2.5 2.5 0 0 1 0-5A4.8 8 0 0 1 12 8a4.8 8 0 0 1 4.5-5 2.5 2.5 0 0 1 0 5"></path>
            </svg>
          </div>
          
        </div>
        <div class="space-y-1 sm:space-y-1.5">
          <h3 id="blog-scroll-promo-title"
            class="text-xl sm:text-2xl font-black text-white tracking-tight leading-tight">Exclusive ₱500 Special Bonus!
          </h3>
          <p class="text-[11px] sm:text-sm text-amber-200/90 leading-relaxed max-w-xs mx-auto">Thank you for reading
            this guide. Claim an instant ₱500 free Red Envelope bonus to start playing today.</p>
        </div>
        <div
          class="bg-[#120a05] border border-amber-500/40 rounded-xl sm:rounded-2xl p-3 sm:p-3.5 flex items-center justify-between text-left shadow-inner gap-2">
          <div class="flex items-center gap-2.5 sm:gap-3 min-w-0">
            <div
              class="w-9 h-9 sm:w-10 sm:h-10 rounded-xl bg-amber-500/20 border border-amber-400/30 flex items-center justify-center text-amber-400 shrink-0">
              ₱</div>
            <div class="min-w-0">
              <span class="text-[9px] sm:text-[10px] text-stone-400 font-medium block truncate">Welcome Red
                Envelope</span>
              <span class="text-sm sm:text-sm font-black text-amber-300 truncate block">₱500.00 Free Cash</span>
            </div>
          </div>
          <div class="text-right shrink-0">
            <span
              class="inline-block px-2 py-0.5 sm:py-1 rounded bg-emerald-500/20 border border-emerald-500/40 text-emerald-400 font-extrabold text-[9px] sm:text-[10px]">Instant</span>
          </div>
        </div>
        <div class="space-y-2 pt-1">
          <a href="/playnow"
            class="bg-gradient-to-r from-amber-500 via-orange-500 to-red-600 hover:from-amber-400 hover:to-orange-400 text-stone-950  w-full font-black py-3 sm:py-3.5 px-3 rounded-xl sm:rounded-2xl shadow-xl transition-all text-sm sm:text-sm flex items-center justify-center gap-2 transform hover:scale-[1.02] active:scale-[0.98] cursor-pointer inline-flex"
            data-blog-scroll-promo-claim>
            <span>CLAIM ₱500 BONUS NOW</span>
          </a>
          <button type="button"
            class="text-sm font-bold text-stone-400 hover:text-white transition-colors py-1 cursor-pointer"
            data-blog-scroll-promo-close>Maybe Later</button>
        </div>
      </div>
    </div>
  </div>
  <script>
    (() => {
      const playUrl = <?= json_encode(public_url('/playnow', $siteBaseUrl), JSON_UNESCAPED_SLASHES) ?>;
      const internalLinks = {
        'nav-item-home': <?= json_encode(public_url('/', $siteBaseUrl), JSON_UNESCAPED_SLASHES) ?>,
        'nav-item-slots': <?= json_encode(public_url('/slots/', $siteBaseUrl), JSON_UNESCAPED_SLASHES) ?>,
        'nav-item-arcade': <?= json_encode(public_url('/arcade/', $siteBaseUrl), JSON_UNESCAPED_SLASHES) ?>,
        'nav-item-perya': <?= json_encode(public_url('/perya-games/', $siteBaseUrl), JSON_UNESCAPED_SLASHES) ?>,
        'nav-item-live': <?= json_encode(public_url('/live-casino/', $siteBaseUrl), JSON_UNESCAPED_SLASHES) ?>,
        'nav-item-cards': <?= json_encode(public_url('/card-games/', $siteBaseUrl), JSON_UNESCAPED_SLASHES) ?>,
        'nav-item-fish': <?= json_encode(public_url('/fishing-games/', $siteBaseUrl), JSON_UNESCAPED_SLASHES) ?>,
        'nav-item-sports': <?= json_encode(public_url('/sports-betting/', $siteBaseUrl), JSON_UNESCAPED_SLASHES) ?>,
        'nav-item-invite': <?= json_encode(public_url('/invite/', $siteBaseUrl), JSON_UNESCAPED_SLASHES) ?>,
        'nav-item-vip': <?= json_encode(public_url('/vip/', $siteBaseUrl), JSON_UNESCAPED_SLASHES) ?>,
        'nav-item-payments': <?= json_encode(public_url('/payments/', $siteBaseUrl), JSON_UNESCAPED_SLASHES) ?>,
        'nav-item-partner': <?= json_encode(public_url('/become-a-partner/', $siteBaseUrl), JSON_UNESCAPED_SLASHES) ?>,
        'nav-item-blogs': <?= json_encode(public_url('/blog/', $siteBaseUrl), JSON_UNESCAPED_SLASHES) ?>
      };

      Object.entries(internalLinks).forEach(([id, url]) => {
        const button = document.getElementById(id);
        if (!button || button.tagName.toLowerCase() !== 'button') return;
        const link = document.createElement('a');
        Array.from(button.attributes).forEach((attribute) => link.setAttribute(attribute.name, attribute.value));
        link.href = url;
        link.removeAttribute('type');
        link.innerHTML = button.innerHTML;
        button.replaceWith(link);
      });

      const sidebar = document.querySelector('aside');
      const menuToggle = document.getElementById('btn-toggle-sidebar');
      const menuOverlay = document.querySelector('button[aria-label="Close menu"]');
      const closeMenu = () => {
        if (sidebar) sidebar.style.display = 'none';
        if (menuOverlay) menuOverlay.style.display = 'none';
      };
      const openMenu = () => {
        if (sidebar) sidebar.style.display = 'flex';
        if (menuOverlay) menuOverlay.style.display = 'block';
      };
      menuToggle?.addEventListener('click', openMenu);
      menuOverlay?.addEventListener('click', closeMenu);
      document.querySelector('aside button[title="Close Menu"]')?.addEventListener('click', closeMenu);
      const mobileSidebarQuery = window.matchMedia('(max-width: 639px)');
      const syncSidebarForViewport = () => mobileSidebarQuery.matches ? closeMenu() : openMenu();
      syncSidebarForViewport();
      mobileSidebarQuery.addEventListener?.('change', syncSidebarForViewport);
      document.querySelectorAll('aside button[aria-controls]').forEach((button) => {
        const target = document.getElementById(button.getAttribute('aria-controls') || '');
        if (!target) return;
        const chevron = button.querySelector('.lucide-chevron-right');
        button.addEventListener('click', () => {
          const isExpanded = button.getAttribute('aria-expanded') === 'true';
          button.setAttribute('aria-expanded', String(!isExpanded));
          target.hidden = isExpanded;
          chevron?.classList.toggle('rotate-90', !isExpanded);
        });
      });

      function go(url) {
        window.location.href = url;
      }

      function wireById(id, url) {
        const element = document.getElementById(id);
        if (!element) return;
        element.addEventListener('click', () => go(url));
      }

      Object.entries(internalLinks).forEach(([id, url]) => wireById(id, url));

      const buttonLinks = [
        { text: 'BACK TO ALL ARTICLES', url: <?= json_encode(public_url('/blog/', $siteBaseUrl), JSON_UNESCAPED_SLASHES) ?> },
        { text: 'BACK TO BLOGS', url: <?= json_encode(public_url('/blog/', $siteBaseUrl), JSON_UNESCAPED_SLASHES) ?> },
        { text: 'PLAY & CLAIM BONUS NOW', url: playUrl },
        { text: 'Simulan', url: playUrl },
        { text: 'App', url: playUrl },
        { text: '24/7 Live Support', url: playUrl },
        { text: 'Open CS Support Chatbot', url: playUrl, aria: true },
        { text: 'Floating Lucky Slot Wheel', url: playUrl, aria: true },
        { text: 'Deposito', url: playUrl },
        { text: 'Promosyon', url: playUrl },
        { text: 'Tulong', url: playUrl },
        { text: 'VIP', url: playUrl }
      ];

      document.querySelectorAll('button').forEach((button) => {
        if (button.hasAttribute('data-blog-reaction') || button.hasAttribute('aria-controls') || internalLinks[button.id] || button.id === 'btn-toggle-sidebar' || button.getAttribute('aria-label') === 'Close menu') {
          return;
        }
        const label = (button.getAttribute('aria-label') || button.textContent || '').replace(/\s+/g, ' ').trim();
        if (label.includes('Tahanan')) {
          button.addEventListener('click', () => go('/'));
          return;
        }
        const match = buttonLinks.find((item) => item.aria ? label === item.text : label.includes(item.text));
        if (!match) return;
        button.addEventListener('click', () => go(match.url));
      });

      document.querySelectorAll('.cursor-pointer').forEach((element) => {
        const label = (element.textContent || '').replace(/\s+/g, ' ').trim();
        if (label.includes('Claim Bonus') || label.includes('Follow Official Channel')) {
          element.addEventListener('click', () => go(playUrl));
        }
      });

      const scrollPromoModal = document.getElementById('blog-scroll-promo-modal');
      let scrollPromoShown = false;
      const closeScrollPromo = () => {
        if (!scrollPromoModal) return;
        scrollPromoModal.classList.add('hidden');
        scrollPromoModal.classList.remove('flex');
      };
      const openScrollPromo = () => {
        if (!scrollPromoModal || scrollPromoShown) return;
        scrollPromoShown = true;
        scrollPromoModal.classList.remove('hidden');
        scrollPromoModal.classList.add('flex');
      };
      let scrollPromoTicking = false;
      const checkScrollPromoPosition = () => {
        scrollPromoTicking = false;
        if (scrollPromoShown) return;
        const documentElement = document.documentElement;
        const scrollTop = window.scrollY || documentElement.scrollTop;
        const documentHeight = documentElement.scrollHeight;
        if (scrollTop + window.innerHeight < documentHeight - 64) return;
        openScrollPromo();
        window.removeEventListener('scroll', onScrollPromoScroll);
      };
      const onScrollPromoScroll = () => {
        if (scrollPromoShown || scrollPromoTicking) return;
        scrollPromoTicking = true;
        window.requestAnimationFrame(checkScrollPromoPosition);
      };
      scrollPromoModal?.addEventListener('click', (event) => {
        if (event.target === scrollPromoModal) closeScrollPromo();
      });
      scrollPromoModal?.querySelector('[data-blog-scroll-promo-panel]')?.addEventListener('click', (event) => event.stopPropagation());
      scrollPromoModal?.querySelectorAll('[data-blog-scroll-promo-close], [data-blog-scroll-promo-claim]').forEach((element) => {
        element.addEventListener('click', closeScrollPromo);
      });
      window.addEventListener('scroll', onScrollPromoScroll, { passive: true });

      const providerTrack = document.querySelector('[data-provider-marquee-track]');
      const renderProviders = (providers) => {
        if (!providerTrack || !Array.isArray(providers) || providers.length === 0) return;
        const items = providers
          .filter((provider) => provider && provider.name)
          .slice(0, 24);
        if (!items.length) return;
        const escapeHtml = (value) => String(value || '').replace(/[&<>"']/g, (character) => ({
          '&': '&amp;',
          '<': '&lt;',
          '>': '&gt;',
          '"': '&quot;',
          "'": '&#039;'
        })[character]);
        const providerMarkup = items.concat(items).map((provider) => {
          const name = escapeHtml(provider.name);
          const search = encodeURIComponent(provider.name);
          const image = provider.thumbnail ? `<img src="${escapeHtml(provider.thumbnail)}" alt="${name}" width="160" height="64" loading="lazy" decoding="async">` : '';
          return `<a class="blog-provider-item" href="<?= blog_h(public_url('/search', $siteBaseUrl)) ?>?q=${search}">${image}<span>${name}</span></a>`;
        }).join('');
        providerTrack.innerHTML = providerMarkup;
      };
      if (providerTrack) {
        fetch('/api/provider-list.php?count=24', {
          headers: { 'Accept': 'application/json', 'X-Requested-With': 'fetch' }
        })
          .then((response) => response.ok ? response.json() : null)
          .then((payload) => renderProviders(payload?.providers || []))
          .catch(() => { });
      }

      const shareUrl = encodeURIComponent(<?= json_encode($canonical, JSON_UNESCAPED_SLASHES) ?>);
      const shareTitle = encodeURIComponent(<?= json_encode($articleTitle, JSON_UNESCAPED_SLASHES) ?>);
      const shareLinks = {
        Facebook: `https://www.facebook.com/sharer/sharer.php?u=${shareUrl}`,
        Telegram: `https://t.me/share/url?url=${shareUrl}&text=${shareTitle}`,
        'X (Twitter)': `https://twitter.com/intent/tweet?url=${shareUrl}&text=${shareTitle}`,
        WhatsApp: `https://api.whatsapp.com/send?text=${shareTitle}%20${shareUrl}`
      };

      document.querySelectorAll('button').forEach((button) => {
        const label = (button.textContent || '').replace(/\s+/g, ' ').trim();
        if (shareLinks[label]) {
          button.addEventListener('click', () => window.open(shareLinks[label], '_blank', 'noopener,noreferrer'));
        }
        if (label === 'Copy Link') {
          button.addEventListener('click', async () => {
            try {
              await navigator.clipboard.writeText(<?= json_encode($canonical, JSON_UNESCAPED_SLASHES) ?>);
              button.querySelector('span:last-child').textContent = 'Copied';
            } catch (error) {
              go(<?= json_encode($canonical, JSON_UNESCAPED_SLASHES) ?>);
            }
          });
        }
      });
    })();
  </script>
  <script>
    (() => {
      const postId = <?= json_encode((string) $post['id'], JSON_UNESCAPED_SLASHES) ?>;
      const viewCount = document.getElementById('blog-view-count');
      const likeCount = document.getElementById('blog-like-count');
      const dislikeCount = document.getElementById('blog-dislike-count');

      function formatCount(value, label) {
        const count = Number.parseInt(String(value || '0'), 10) || 0;
        return count.toLocaleString() + ' ' + label;
      }

      function updateCounts(counts) {
        if (!counts) return;
        if (viewCount) viewCount.textContent = formatCount(counts.views, 'views');
        if (likeCount) likeCount.textContent = String(Number.parseInt(String(counts.likes || '0'), 10) || 0);
        if (dislikeCount) dislikeCount.textContent = String(Number.parseInt(String(counts.dislikes || '0'), 10) || 0);
      }

      document.querySelectorAll('[data-blog-reaction]').forEach((button) => {
        button.addEventListener('click', async () => {
          const action = button.getAttribute('data-blog-reaction') || '';
          button.disabled = true;
          try {
            const response = await fetch('/api/blog-engagement.php', {
              method: 'POST',
              headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'fetch'
              },
              body: JSON.stringify({ postId, action })
            });
            const result = await response.json();
            if (response.ok && result.ok) updateCounts(result.counts);
          } catch (error) {
            console.error('Blog reaction failed', error);
          } finally {
            button.disabled = false;
          }
        });
      });
    })();
  </script>


</body>

</html>
