// Render chat as text and validated links; never interpret message HTML.
export function renderChatMessage(element, value) {
  const text = String(value ?? '').replace(/\r\n?/g, '\n')
    .replace(/\[([^\]\n]+)\]\\\(([^\s<>]+)\\\)/g, (_, label, href) => `[${label}](${href.replace(/\\:/g, ':')})`);
  const document = element.ownerDocument;
  const fragment = document.createDocumentFragment();
  const urls = new Set();
  const pattern = /\[([^\]\n]+)\]\(([^\s<>]+)\)|\b(?:https?:\/\/|www\.)[^\s<>"']+/gi;
  let offset = 0;
  for (const match of text.matchAll(pattern)) {
    fragment.append(document.createTextNode(text.slice(offset, match.index)));
    const markdown = match[1] !== undefined;
    let href = markdown ? match[2] : match[0];
    let suffix = '';
    if (!markdown) {
      suffix = href.match(/[.,!?;:]+$/)?.[0] || '';
      href = href.slice(0, href.length - suffix.length);
      while (href.endsWith(')') && (href.match(/\)/g) || []).length > (href.match(/\(/g) || []).length) {
        href = href.slice(0, -1); suffix = ')' + suffix;
      }
    }
    const label = markdown ? match[1] : href;
    if (/^www\./i.test(href)) href = 'https://' + href;
    let valid = !/[\s\\\x00-\x1f\x7f]/.test(href) && (/^https?:\/\//i.test(href) || /^\/(?!\/)/.test(href));
    try {
      const url = new URL(href, 'https://chat.invalid');
      valid = valid && ['http:', 'https:'].includes(url.protocol) && !url.username && !url.password;
    } catch { valid = false; }
    if (valid) {
      const link = document.createElement('a');
      link.setAttribute('href', href);
      link.target = '_blank';
      link.rel = 'noopener noreferrer';
      link.textContent = label;
      link.style.cssText = 'color:inherit;text-decoration:underline;overflow-wrap:anywhere';
      fragment.append(link, document.createTextNode(suffix));
      urls.add(href);
    } else {
      fragment.append(document.createTextNode(match[0]));
    }
    offset = match.index + match[0].length;
  }
  fragment.append(document.createTextNode(text.slice(offset)));
  element.style.whiteSpace = 'pre-wrap';
  element.style.overflowWrap = 'anywhere';
  element.replaceChildren(fragment);
  if (document.defaultView?.fetch) {
    for (const href of [...urls].slice(0, 3)) addChatPreview(element, href);
  }
}

const chatPreviewCache = new Map();
function addChatPreview(element, href) {
  const document = element.ownerDocument;
  const url = new URL(href, document.baseURI).href;
  const card = document.createElement('a');
  card.href = url;
  card.target = '_blank';
  card.rel = 'noopener noreferrer';
  card.style.cssText = 'display:block;margin-top:10px;border:1px solid currentColor;border-radius:8px;overflow:hidden;text-decoration:none;color:inherit;white-space:normal;max-width:360px';
  card.hidden = true;
  element.append(card);
  if (!chatPreviewCache.has(url)) {
    if (chatPreviewCache.size >= 100) chatPreviewCache.delete(chatPreviewCache.keys().next().value);
    chatPreviewCache.set(url, document.defaultView.fetch('/api/chat-link-preview.php?url=' + encodeURIComponent(url), {
      credentials: 'omit', signal: AbortSignal.timeout(10000),
    }).then(response => response.ok ? response.json() : null).catch(() => null));
  }
  chatPreviewCache.get(url).then(data => {
    if (!data?.ok || !card.parentNode) return;
    if (data.image) {
      try {
        const imageUrl = new URL(data.image, url);
        if (!['http:', 'https:'].includes(imageUrl.protocol) || imageUrl.username || imageUrl.password) throw new Error();
        const image = document.createElement('img');
        image.src = imageUrl.href;
        image.alt = '';
        image.loading = 'lazy';
        image.referrerPolicy = 'no-referrer';
        image.style.cssText = 'display:block;width:100%;max-height:180px;object-fit:cover';
        image.onerror = () => image.remove();
        card.append(image);
      } catch { /* Keep text previews when an image URL is invalid. */ }
    }
    for (const [tag, text] of [['strong', data.title], ['span', data.description], ['small', new URL(url).hostname]]) {
      if (!text) continue;
      const child = document.createElement(tag);
      child.textContent = text;
      child.style.cssText = 'display:block;margin:8px 12px;overflow-wrap:anywhere';
      card.append(child);
    }
    card.hidden = false;
  });
}
