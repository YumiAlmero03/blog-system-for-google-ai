<?php
declare(strict_types=1);
require_once __DIR__ . '/blog-storage.php';

/** Keep HTML storage, but allow only formatting and safe navigation, never active content. */
function slot_content_html(string $html): string
{
    if (strlen($html)>200000 || !preg_match('//u',$html)) throw new InvalidArgumentException('Long description is invalid or too long.');
    $doc = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    try { $doc->loadHTML('<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>',LIBXML_NONET); }
    finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
    $render = static function(DOMNode $node) use (&$render): string {
        if ($node instanceof DOMText) return htmlspecialchars($node->textContent,ENT_QUOTES | ENT_SUBSTITUTE,'UTF-8');
        if (!($node instanceof DOMElement)) return '';
        $tag = strtolower($node->tagName);
        if (in_array($tag,['script','style','iframe','object','embed','svg','math','template','form','input','button'],true)) return '';
        $children = '';
        foreach ($node->childNodes as $child) $children .= $render($child);
        if (!in_array($tag,['p','div','h2','h3','strong','b','em','i','a','ul','ol','li','br','blockquote'],true)) return $children;
        if ($tag === 'div') $tag = 'p';
        if ($tag === 'br') return '<br>';
        $attributes = '';
        if ($tag === 'a') {
            $href = trim($node->getAttribute('href'));
            if (!preg_match('~^(?:https?://[^\s<>]+|/(?!/)[^\s<>]*|\#[^\s<>]*)$~i',$href)) return $children;
            $attributes = ' href="' . htmlspecialchars($href,ENT_QUOTES | ENT_SUBSTITUTE,'UTF-8') . '" rel="noopener noreferrer"';
        }
        return '<' . $tag . $attributes . '>' . $children . '</' . $tag . '>';
    };
    $body = $doc->getElementsByTagName('body')->item(0);
    $result = ''; if ($body) foreach ($body->childNodes as $node) $result .= $render($node);
    return $result;
}

function slot_content_save(array $input): void
{
    $allowed = ['id','csrf_token','name','short_description','long_description','rtp','volatility'];
    if (array_diff(array_keys($input),$allowed)) throw new InvalidArgumentException('Unsupported or protected field.');
    $id = filter_var($input['id'] ?? null,FILTER_VALIDATE_INT);
    if (!$id || $id<1) throw new InvalidArgumentException('Invalid game.');
    $fields = [];
    foreach (['name'=>200,'short_description'=>4000,'long_description'=>200000,'volatility'=>120] as $key=>$max) {
        if (!isset($input[$key]) || !is_string($input[$key]) || strlen($input[$key])>$max || !preg_match('//u',$input[$key])) throw new InvalidArgumentException('Invalid ' . $key . '.');
        $fields[$key] = trim($key === 'long_description' ? $input[$key] : strip_tags($input[$key]));
    }
    if ($fields['name'] === '') throw new InvalidArgumentException('Game name is required.');
    $rtp = $input['rtp'] ?? '';
    if (!is_string($rtp) || ($rtp !== '' && (!is_numeric($rtp) || !is_finite((float)$rtp) || (float)$rtp<0 || (float)$rtp>100))) throw new InvalidArgumentException('RTP must be between 0 and 100.');
    $fields['long_description'] = slot_content_html($fields['long_description']);
    $pdo = blogs_pdo();
    $stmt = $pdo->prepare('UPDATE games SET name=:name,short_description=:short_description,long_description=:long_description,rtp=:rtp,volatility=:volatility,updated_at=:updated_at WHERE id=:id');
    $stmt->execute($fields+['rtp'=>$rtp === '' ? null : (float)$rtp,'updated_at'=>time(),'id'=>$id]);
    if (!$stmt->rowCount()) throw new InvalidArgumentException('Game not found.');
    // Slot list caches use database mtime, but WAL writes may leave that unchanged.
    blog_clear_api_cache();
}
