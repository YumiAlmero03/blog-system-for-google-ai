(() => {
  const editor = document.getElementById('slot-editor');
  const toolbar = document.getElementById('slot-toolbar');
  const form = document.getElementById('slot-content-form');
  const feedback = document.getElementById('slot-feedback');
  toolbar.addEventListener('mousedown', event => { if (event.target.closest('button')) event.preventDefault(); });
  toolbar.addEventListener('click', event => {
    const button = event.target.closest('[data-command]'); if (!button) return;
    editor.focus();
    if (button.dataset.command === 'link') {
      const href = prompt('Link URL (https:// or /path/)');
      if (href && /^(https?:\/\/|\/(?!\/)|#)/i.test(href.trim())) document.execCommand('createLink',false,href.trim());
    } else window.ContentEditor.apply(button.dataset.command);
  });
  // Pasted/dropped active HTML must not execute inside an authenticated admin page.
  editor.addEventListener('paste', event => { event.preventDefault(); document.execCommand('insertText',false,event.clipboardData.getData('text/plain')); });
  editor.addEventListener('drop', event => event.preventDefault());
  form.addEventListener('submit', async event => {
    event.preventDefault(); const button = form.querySelector('[type=submit]'); button.disabled = true;
    feedback.textContent = 'Saving…';
    const data = new FormData(form); data.set('long_description',editor.innerHTML);
    try {
      const response = await fetch('/admin/slot-save.php',{method:'POST',headers:{Accept:'application/json'},body:data});
      const payload = await response.json();
      if (!response.ok || !payload.ok) throw new Error(payload.error || 'Unable to save content.');
      feedback.textContent = 'Content saved.';
    } catch (error) { feedback.textContent = error.message; }
    finally { button.disabled = false; }
  });
})();
