(() => {
  const editor = document.getElementById('slot-editor');
  const toolbar = document.getElementById('slot-toolbar');
  const form = document.getElementById('slot-content-form');
  const feedback = document.getElementById('slot-feedback');
  toolbar.addEventListener('mousedown', event => { if (event.target.closest('button')) event.preventDefault(); });
  toolbar.addEventListener('click', event => {
    const button = event.target.closest('[data-command]'); if (!button) return;
    editor.focus();
    if (button.dataset.command === 'table') {
      const input = prompt('Number of symbol rows (1–30)', '5');
      if (input === null) return;
      const rows = Number(input);
      if (!Number.isInteger(rows) || rows < 1 || rows > 30) {
        feedback.textContent = 'Enter a whole number from 1 to 30.';
        return;
      }
      editor.focus();
      const selection = window.getSelection();
      if (!selection.rangeCount || !editor.contains(selection.anchorNode) || !editor.contains(selection.focusNode)) {
        const range = document.createRange();
        range.selectNodeContents(editor); range.collapse(false);
        selection.removeAllRanges(); selection.addRange(range);
      }
      const row = '<tr><td>Symbol</td><td>Required combination</td><td>Check in-game paytable</td></tr>';
      document.execCommand('insertHTML', false,
        '<table><caption>Symbol payouts</caption><thead><tr><th>Symbol</th><th>Required combination</th><th>Payout and units</th></tr></thead><tbody>' +
        row.repeat(rows) + '</tbody></table><p><br></p>');
      feedback.textContent = 'Table inserted. Click a cell to edit its content.';
    } else if (button.dataset.command === 'link') {
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
      const response = await fetch('/admin/games/save.php',{method:'POST',headers:{Accept:'application/json'},body:data});
      const payload = await response.json();
      if (!response.ok || !payload.ok) throw new Error(payload.error || 'Unable to save content.');
      feedback.textContent = 'Content saved.';
    } catch (error) { feedback.textContent = error.message; }
    finally { button.disabled = false; }
  });
})();
