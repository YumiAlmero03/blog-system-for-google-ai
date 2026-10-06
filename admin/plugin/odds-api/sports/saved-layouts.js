(() => {
  for (const row of document.querySelectorAll('.saved-layout-embed')) {
    const input = row.querySelector('input');
    const button = row.querySelector('[data-copy-iframe]');
    const status = row.querySelector('[role="status"]');
    const iframe = document.createElement('iframe');
    iframe.src = new URL(`/odds-api/?iframe=${row.dataset.iframeId}`, window.location.origin).href;
    iframe.title = row.dataset.iframeTitle;
    iframe.width = '100%';
    iframe.height = '650';
    iframe.style.border = '0';
    iframe.loading = 'lazy';
    input.value = iframe.outerHTML;
    input.addEventListener('click', () => input.select());

    button.addEventListener('click', async () => {
      button.disabled = true;
      status.textContent = '';
      try {
        if (!navigator.clipboard?.writeText) throw new Error('Clipboard unavailable');
        await navigator.clipboard.writeText(input.value);
        status.textContent = 'Copied!';
      } catch {
        // Keep copying usable on HTTP and when clipboard permission is denied.
        input.focus();
        input.select();
        let copied = false;
        try { copied = document.execCommand('copy'); } catch { /* Manual copy remains available. */ }
        status.textContent = copied ? 'Copied!' : 'Select and copy the iframe code above.';
      } finally {
        button.disabled = false;
      }
    });
  }
})();
