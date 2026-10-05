import { renderChatMessage } from '/chat-message.js';

const reply = document.getElementById('automatic-reply');
const label = document.getElementById('reply-link-label');
const url = document.getElementById('reply-link-url');
const error = document.getElementById('reply-link-error');
const preview = () => renderChatMessage(document.getElementById('reply-preview'), reply.value);

document.getElementById('reply-insert-link').addEventListener('click', () => {
  error.textContent = '';
  let href = url.value.trim();
  if (/^www\./i.test(href)) href = 'https://' + href;
  try {
    const parsed = new URL(href, window.location.origin);
    if (!/^(https?:\/\/|\/(?!\/))/i.test(href) || /[\s<>"'\\\x00-\x1f\x7f]/.test(href)
      || !['http:', 'https:'].includes(parsed.protocol) || parsed.username || parsed.password) throw new Error();
  } catch {
    error.textContent = 'Enter a valid https:// or http:// URL, or a site path starting with /.';
    url.focus();
    return;
  }
  const start = reply.selectionStart;
  const end = reply.selectionEnd;
  const text = label.value.trim() || reply.value.slice(start, end) || href;
  if (/[\[\]\r\n]/.test(text)) {
    error.textContent = 'Use link text without brackets or line breaks.';
    label.focus();
    return;
  }
  href = href.replace(/\(/g, '%28').replace(/\)/g, '%29');
  const link = `[${text}](${href})`;
  if (reply.value.length - (end - start) + link.length > reply.maxLength) {
    error.textContent = 'This link would exceed the reply length limit. Shorten the reply first.';
    return;
  }
  reply.setRangeText(link, start, end, 'end');
  reply.dispatchEvent(new Event('input', { bubbles: true }));
  label.value = ''; url.value = '';
  reply.focus();
});

reply.addEventListener('input', preview);
preview();
document.querySelectorAll('[data-reply-preview]').forEach(element => renderChatMessage(element, element.textContent));
