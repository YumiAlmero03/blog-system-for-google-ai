// Render chat as text and validated links; never interpret message HTML.
export function renderChatMessage(element, value) {
  const text = String(value ?? '').replace(/\r\n?/g, '\n');
  const document = element.ownerDocument;
  const fragment = document.createDocumentFragment();
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
      fragment.append(link, document.createTextNode(suffix));
    } else {
      fragment.append(document.createTextNode(match[0]));
    }
    offset = match.index + match[0].length;
  }
  fragment.append(document.createTextNode(text.slice(offset)));
  element.style.whiteSpace = 'pre-wrap';
  element.style.overflowWrap = 'anywhere';
  element.replaceChildren(fragment);
}
