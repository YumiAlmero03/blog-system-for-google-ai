<?php
declare(strict_types=1);

// Convert WordPress article HTML to the editor's Markdown, without executing HTML.
function blog_wordpress_markdown(string $html): string
{
    if (trim($html) === '') return '';
    $doc = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    try {
        $doc->loadHTML('<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>', LIBXML_NONET);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
    $render = static function (DOMNode $node) use (&$render): string {
        if ($node instanceof DOMText) return preg_replace('/\s+/u', ' ', $node->textContent) ?? '';
        if (!$node instanceof DOMElement) return '';
        $tag = strtolower($node->tagName);
        if ($node->getAttribute('data-faq-block') === '1') {
            return "\n\n" . trim($node->textContent) . "\n\n";
        }
        if ($tag === 'table') {
            $rows = [];
            $hasHeadings = false;
            $trElements = $node->getElementsByTagName('tr');
            $rowIndex = 0;
            foreach ($trElements as $tr) {
                $cells = [];
                $isHeaderRow = false;
                foreach ($tr->childNodes as $cell) {
                    if (!$cell instanceof DOMElement) continue;
                    $cellTag = strtolower($cell->tagName);
                    if (!in_array($cellTag, ['td', 'th'], true)) continue;
                    if ($cellTag === 'th') $isHeaderRow = true;
                    $cellContent = '';
                    foreach ($cell->childNodes as $child) $cellContent .= $render($child);
                    $cleanCell = trim(preg_replace('/\s+/u', ' ', $cellContent) ?? '');
                    $cleanCell = str_replace('|', '\\|', $cleanCell);
                    $cells[] = $cleanCell;
                }
                if ($cells) {
                    if ($rowIndex === 0 && ($isHeaderRow || strtolower($tr->parentNode?->nodeName ?? '') === 'thead')) {
                        $hasHeadings = true;
                    }
                    $rows[] = '| ' . implode(' | ', $cells) . ' |';
                    $rowIndex++;
                }
            }
            if ($rows === []) return '';
            return "\n\n:::table\n" . ($hasHeadings ? "headings: true\n" : "") . implode("\n", $rows) . "\n:::\n\n";
        }
        $text = '';
        foreach ($node->childNodes as $child) $text .= $render($child);
        $url = static function (string $value): string {
            $value = trim($value);
            return preg_match('#^(https?://|/(?!/))[^\s<>]+$#i', $value) ? str_replace(['(', ')'], ['%28', '%29'], $value) : '';
        };
        if ($tag === 'a') {
            $href = $url($node->getAttribute('href'));
            return $href !== '' ? '[' . str_replace(['[', ']'], '', trim($text)) . '](' . $href . ')' . (in_array('nofollow', explode(' ', $node->getAttribute('rel')), true) ? '{nofollow}' : '') : $text;
        }
        if ($tag === 'img') {
            $src = $url($node->getAttribute('src'));
            return $src !== '' ? "\n\n![" . str_replace(['[', ']'], '', $node->getAttribute('alt')) . '](' . $src . ")\n\n" : '';
        }
        if (preg_match('/^h([1-6])$/', $tag, $match)) return "\n\n" . str_repeat('#', (int) $match[1]) . ' ' . trim($text) . "\n\n";
        if (in_array($tag, ['strong', 'b'], true)) return '**' . trim($text) . '**';
        if (in_array($tag, ['em', 'i'], true)) return '*' . trim($text) . '*';
        if ($tag === 'br') return "\n";
        if ($tag === 'li') return "\n" . ($node->parentNode?->nodeName === 'ol' ? '1. ' : '- ') . trim($text);
        if ($tag === 'blockquote') return "\n\n> " . str_replace("\n", "\n> ", trim($text)) . "\n\n";
        if ($tag === 'pre') return "\n\n```\n" . $node->textContent . "\n```\n\n";
        if ($tag === 'code') return '`' . trim($text) . '`';
        if (in_array($tag, ['td', 'th'], true)) return trim($text) . ' | ';
        if (in_array($tag, ['p', 'div', 'section', 'figure', 'ul', 'ol'], true)) return "\n\n" . trim($text) . "\n\n";
        return $text;
    };

    // Detect and convert FAQ structures in DOM before rendering
    $xpath = new DOMXPath($doc);

    // 1. Heading-based FAQ sections: <h1>-<h6> with "faq" / "frequently asked questions"
    $headings = iterator_to_array($xpath->query('//h1 | //h2 | //h3 | //h4 | //h5 | //h6'));
    foreach ($headings as $h) {
        if (!preg_match('/\b(faqs?|frequently asked questions)\b/i', trim($h->textContent))) continue;
        $level = (int) substr($h->nodeName, 1);
        $curr = $h->nextSibling;
        $items = [];
        $currentQ = null;
        $currentA = [];
        $nodesToRemove = [];

        while ($curr) {
            $next = $curr->nextSibling;
            if ($curr instanceof DOMElement) {
                $tag = strtolower($curr->tagName);
                if (preg_match('/^h([1-6])$/', $tag, $m)) {
                    $currLevel = (int) $m[1];
                    if ($currLevel <= $level) {
                        break; // Stop at next heading of same or higher hierarchy
                    }
                    if ($currentQ !== null) {
                        $items[] = ['q' => $currentQ, 'a' => trim(implode(' ', $currentA))];
                        $currentA = [];
                    }
                    $qText = trim($render($curr));
                    $qText = preg_replace('/^#+\s*/', '', $qText);
                    $qText = preg_replace('/^(?:Q|Question)\s*:\s*/i', '', $qText);
                    $currentQ = $qText;
                    $nodesToRemove[] = $curr;
                } else if ($tag === 'details') {
                    $summary = $curr->getElementsByTagName('summary')->item(0);
                    $qText = $summary ? trim($render($summary)) : '';
                    $aText = '';
                    foreach ($curr->childNodes as $c) {
                        if ($c !== $summary) $aText .= ' ' . trim($render($c));
                    }
                    if ($currentQ !== null) {
                        $items[] = ['q' => $currentQ, 'a' => trim(implode(' ', $currentA))];
                        $currentQ = null;
                        $currentA = [];
                    }
                    if ($qText !== '') {
                        $items[] = ['q' => $qText, 'a' => trim($aText)];
                    }
                    $nodesToRemove[] = $curr;
                } else if ($tag === 'dl') {
                    $dlQ = null;
                    $dlA = [];
                    foreach ($curr->childNodes as $dlChild) {
                        if (!$dlChild instanceof DOMElement) continue;
                        $dlTag = strtolower($dlChild->tagName);
                        if ($dlTag === 'dt') {
                            if ($dlQ !== null) {
                                $items[] = ['q' => $dlQ, 'a' => trim(implode(' ', $dlA))];
                                $dlA = [];
                            }
                            $dlQ = trim($render($dlChild));
                        } else if ($dlTag === 'dd') {
                            $dlA[] = trim($render($dlChild));
                        }
                    }
                    if ($dlQ !== null) {
                        $items[] = ['q' => $dlQ, 'a' => trim(implode(' ', $dlA))];
                    }
                    $nodesToRemove[] = $curr;
                } else {
                    $strong = $curr->getElementsByTagName('strong')->item(0) ?? $curr->getElementsByTagName('b')->item(0);
                    $isStrongQuestion = false;
                    if ($strong && ($curr->childNodes->length === 1 || preg_match('/\?\s*$/', trim($strong->textContent)))) {
                        $textBefore = '';
                        foreach ($curr->childNodes as $cn) {
                            if ($cn === $strong) break;
                            $textBefore .= trim($cn->textContent);
                        }
                        if ($textBefore === '') {
                            $isStrongQuestion = true;
                        }
                    }

                    if ($isStrongQuestion) {
                        if ($currentQ !== null) {
                            $items[] = ['q' => $currentQ, 'a' => trim(implode(' ', $currentA))];
                            $currentA = [];
                        }
                        $qText = trim($render($strong));
                        $qText = preg_replace('/^\*\*|\*\*$/', '', $qText);
                        $qText = preg_replace('/^(?:Q|Question)\s*:\s*/i', '', $qText);
                        $currentQ = $qText;
                        $nodesToRemove[] = $curr;
                    } else if ($currentQ !== null) {
                        $currentA[] = trim($render($curr));
                        $nodesToRemove[] = $curr;
                    }
                }
            }
            $curr = $next;
        }
        if ($currentQ !== null) {
            $items[] = ['q' => $currentQ, 'a' => trim(implode(' ', $currentA))];
        }

        if ($items) {
            $faqLines = [];
            foreach ($items as $item) {
                $q = trim(preg_replace('/\s+/u', ' ', $item['q']) ?? '');
                $a = trim(preg_replace('/\s+/u', ' ', $item['a']) ?? '');
                $a = preg_replace('/^(?:A|Answer)\s*:\s*/i', '', $a);
                if ($q !== '' || $a !== '') {
                    $faqLines[] = 'Q: ' . $q . "\nA: " . $a;
                }
            }
            if ($faqLines) {
                $faqBlock = ":::faq\n" . implode("\n\n", $faqLines) . "\n:::";
                $placeholder = $doc->createElement('div');
                $placeholder->setAttribute('data-faq-block', '1');
                $placeholder->textContent = $faqBlock;
                $h->parentNode->insertBefore($placeholder, $h->nextSibling);
                foreach ($nodesToRemove as $remNode) {
                    if ($remNode->parentNode) {
                        $remNode->parentNode->removeChild($remNode);
                    }
                }
            }
        }
    }

    // 2. Schema.org FAQPage containers, Yoast/RankMath FAQ blocks
    $faqContainers = iterator_to_array($xpath->query('//*[contains(@itemtype, "FAQPage") or contains(@class, "wp-block-yoast-faq-block") or contains(@class, "wp-block-rank-math-faq-block") or contains(@class, "schema-faq-section")]'));
    foreach ($faqContainers as $container) {
        if (!$container->parentNode) continue;
        $qElements = iterator_to_array($xpath->query('.//*[@itemprop="name" or contains(@class, "schema-faq-question") or contains(@class, "rank-math-question")]', $container));
        $items = [];
        foreach ($qElements as $qEl) {
            $parentItem = $qEl->parentNode;
            $aEl = $xpath->query('.//*[@itemprop="text" or @itemprop="acceptedAnswer" or contains(@class, "schema-faq-answer") or contains(@class, "rank-math-answer")]', $parentItem)->item(0);
            $q = trim($render($qEl));
            $a = $aEl ? trim($render($aEl)) : '';
            $q = preg_replace('/^#+\s*/', '', $q);
            $q = trim(preg_replace('/^(?:Q|Question)\s*:\s*/i', '', $q));
            $a = trim(preg_replace('/^(?:A|Answer)\s*:\s*/i', '', $a));
            if ($q !== '' || $a !== '') {
                $items[] = 'Q: ' . $q . "\nA: " . $a;
            }
        }
        if ($items) {
            $placeholder = $doc->createElement('div');
            $placeholder->setAttribute('data-faq-block', '1');
            $placeholder->textContent = ":::faq\n" . implode("\n\n", $items) . "\n:::";
            $container->parentNode->insertBefore($placeholder, $container);
            $container->parentNode->removeChild($container);
        }
    }

    // 3. Standalone details elements
    $detailsList = iterator_to_array($doc->getElementsByTagName('details'));
    foreach ($detailsList as $d) {
        if (!$d->parentNode || $d->getAttribute('data-faq-block') === '1') continue;
        $group = [$d];
        $next = $d->nextSibling;
        while ($next) {
            if ($next instanceof DOMElement && strtolower($next->tagName) === 'details') {
                $group[] = $next;
                $next = $next->nextSibling;
            } else if ($next instanceof DOMText && trim($next->textContent) === '') {
                $next = $next->nextSibling;
            } else {
                break;
            }
        }
        $items = [];
        foreach ($group as $itemNode) {
            $summary = $itemNode->getElementsByTagName('summary')->item(0);
            $q = $summary ? trim($render($summary)) : '';
            $a = '';
            foreach ($itemNode->childNodes as $c) {
                if ($c !== $summary) $a .= ' ' . trim($render($c));
            }
            $q = trim(preg_replace('/\s+/u', ' ', $q) ?? '');
            $a = trim(preg_replace('/\s+/u', ' ', $a) ?? '');
            $a = preg_replace('/^(?:A|Answer)\s*:\s*/i', '', $a);
            if ($q !== '' || $a !== '') {
                $items[] = 'Q: ' . $q . "\nA: " . $a;
            }
        }
        if ($items) {
            $placeholder = $doc->createElement('div');
            $placeholder->setAttribute('data-faq-block', '1');
            $placeholder->textContent = ":::faq\n" . implode("\n\n", $items) . "\n:::";
            $d->parentNode->insertBefore($placeholder, $d);
            foreach ($group as $rem) {
                if ($rem->parentNode) $rem->parentNode->removeChild($rem);
            }
        }
    }

    $result = $render($doc->getElementsByTagName('body')->item(0));
    // Disallow executable custom-code blocks in imported content.
    $result = preg_replace('/^:::custom-code\b/im', ': : :custom-code', $result) ?? $result;
    return trim(preg_replace('/\n{3,}/', "\n\n", $result) ?? $result);
}

// Convert Markdown FAQ sections (e.g. ## FAQs followed by ### Question/answer lines) to native :::faq blocks.
function blog_convert_markdown_faqs(string $markdown): string
{
    if (!preg_match('/\b(faqs?|frequently asked questions)\b/i', $markdown)) {
        return $markdown;
    }
    return preg_replace_callback('/((?:^|\n)#{1,4}\s+(?:faqs?|frequently asked questions)\b[^\n]*\n)([\s\S]*?)(?=(?:\n#{1,2}\s+[^\n]+|$))/i', static function (array $m): string {
        $heading = $m[1];
        $body = $m[2];
        if (str_contains($body, ':::faq')) {
            return $m[0];
        }
        $qRegex = '/(?:^|\n)#{3,5}\s+([^\n]+)\n+([\s\S]*?)(?=(?:\n#{3,5}\s+[^\n]+|$))/';
        $matches = [];
        if (!preg_match_all($qRegex, $body, $matches, PREG_SET_ORDER)) {
            return $m[0];
        }
        $items = [];
        foreach ($matches as $match) {
            $q = trim(preg_replace('/^#+\s*/', '', $match[1]));
            $q = trim(preg_replace('/^(?:Q|Question)\s*:\s*/i', '', $q));
            $a = trim(preg_replace('/\s+/u', ' ', $match[2]) ?? '');
            $a = trim(preg_replace('/^(?:A|Answer)\s*:\s*/i', '', $a));
            if ($q !== '' || $a !== '') {
                $items[] = "Q: " . $q . "\nA: " . $a;
            }
        }
        if (!$items) {
            return $m[0];
        }
        return $heading . "\n:::faq\n" . implode("\n\n", $items) . "\n:::\n\n";
    }, $markdown) ?? $markdown;
}

function blog_wordpress_manifest(string $xml, bool $deferContent = false): array
{
    if (!function_exists('simplexml_load_string') || !class_exists('DOMDocument')) throw new RuntimeException('WordPress import requires the PHP XML extension.');
    if (preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) throw new InvalidArgumentException('XML document types and entities are not supported.');
    $previous = libxml_use_internal_errors(true);
    try {
        $root = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
    if ($root === false) throw new InvalidArgumentException('Invalid WordPress XML export.');
    $namespaces = $root->getDocNamespaces(true);
    $wp = $namespaces['wp'] ?? '';
    if ($root->getName() !== 'rss' || !preg_match('#^http://wordpress.org/export/1\.[012]/$#', $wp) || !isset($root->channel) || trim((string) $root->channel->children($wp)->wxr_version) === '') throw new InvalidArgumentException('Select a WordPress WXR XML export.');
    $authors = $images = $blogs = [];
    $skipped = 0;
    foreach ($root->channel->children($wp)->author as $author) $authors[(string) $author->author_login] = (string) $author->author_display_name;
    foreach ($root->channel->item as $item) {
        $data = $item->children($wp);
        if ((string) $data->post_type === 'attachment') $images[(string) $data->post_id] = (string) $data->attachment_url;
    }
    foreach ($root->channel->item as $item) {
        $data = $item->children($wp);
        $status = (string) $data->status;
        if ((string) $data->post_type !== 'post' || !in_array($status, ['publish', 'draft', 'pending', 'future', 'private'], true)) { $skipped++; continue; }
        $meta = [];
        foreach ($data->postmeta as $entry) $meta[(string) $entry->meta_key] = (string) $entry->meta_value;
        $categories = $tags = [];
        foreach ($item->category as $category) {
            if ((string) $category['domain'] === 'category') $categories[] = (string) $category;
            if ((string) $category['domain'] === 'post_tag') $tags[] = (string) $category;
        }
        $date = static function (string $value): ?int { return $value !== '' && !str_starts_with($value, '0000-') ? (strtotime($value . ' UTC') ?: null) : null; };
        $published = $date((string) $data->post_date_gmt) ?? $date((string) $data->post_date);
        $title = trim((string) $item->title);
        $slug = normalize_slug((string) $data->post_name) ?: normalize_slug($title);
        $creator = (string) $item->children('http://purl.org/dc/elements/1.1/')->creator;
        $rawContent = (string) $item->children('http://purl.org/rss/1.0/modules/content/')->encoded;
        $content = $deferContent ? '' : blog_wordpress_markdown($rawContent);
        $excerpt = trim(strip_tags((string) $item->children($namespaces['excerpt'] ?? $wp . 'excerpt/')->encoded));
        $blogs[] = [
            'id' => $slug, 'slug' => $slug, 'title' => $title,
            ...($deferContent ? ['wordpressHtml' => $rawContent] : []),
            'content' => $content, 'author' => $authors[$creator] ?? $creator,
            'category' => $categories[0] ?? '', 'wordpressTags' => array_slice($tags, 0, 100),
            'excerpt' => $meta['_yoast_wpseo_metadesc'] ?? ($excerpt ?: mb_substr(strip_tags((string) $item->children('http://purl.org/rss/1.0/modules/content/')->encoded), 0, 360)),
            'seoTitle' => !empty($meta['_yoast_wpseo_title']) && !str_contains($meta['_yoast_wpseo_title'], '%%') ? $meta['_yoast_wpseo_title'] : $title,
            'focusKeyphrase' => $meta['_yoast_wpseo_focuskw'] ?? '',
            'featuredImage' => $images[$meta['_thumbnail_id'] ?? ''] ?? '',
            'status' => trim((string) $data->post_password) !== '' ? 'draft' : match ($status) { 'publish' => 'published', 'future' => 'scheduled', default => 'draft' },
            'publishedAt' => $status === 'publish' ? $published : null,
            'scheduledAt' => $status === 'future' ? $published : null,
            'createdAt' => $published ?? time(), 'updatedAt' => $date((string) $data->post_modified_gmt) ?? $published ?? time(),
        ];
    }
    if (!$blogs) throw new InvalidArgumentException('The WordPress export contains no importable blog posts.');
    return ['format' => 'wordpress-wxr', 'blogs' => $blogs, 'skipped' => $skipped];
}
