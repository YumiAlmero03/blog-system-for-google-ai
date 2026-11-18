(() => {
  const previews = new WeakMap();
  function preview(editor) {
    const image = editor.querySelector('.registry-logo-preview');
    const empty = editor.querySelector('.registry-logo-empty');
    const file = editor.querySelector('.registry-logo-file')?.files?.[0];
    const path = editor.querySelector('.registry-logo-url')?.value.trim() || '';
    if (!image || !empty) return;
    const previous = previews.get(editor);
    if (previous) URL.revokeObjectURL(previous);
    previews.delete(editor);
    let source = '';
    if (file && ['image/jpeg', 'image/png', 'image/webp', 'image/avif'].includes(file.type)) {
      source = URL.createObjectURL(file);
      previews.set(editor, source);
    } else if (/^https:\/\//.test(path) || /^\/uploads\//.test(path)) {
      source = path;
    }
    image.hidden = !source;
    empty.hidden = !!source;
    if (source) image.src = source;
    else image.removeAttribute('src');
  }
  document.addEventListener('input', event => {
    if (event.target.matches('.registry-logo-url')) preview(event.target.closest('.registry-logo-editor'));
  });
  document.addEventListener('change', event => {
    if (event.target.matches('.registry-logo-file')) preview(event.target.closest('.registry-logo-editor'));
  });
})();
