// Shared basic commands; blog-specific blocks and link toolbox remain in the blog editor.
window.ContentEditor = {
  apply(command) {
    const commands = {h2:['formatBlock','h2'],h3:['formatBlock','h3'],bold:['bold'],italic:['italic'],ul:['insertUnorderedList'],ol:['insertOrderedList'],quote:['formatBlock','blockquote']};
    if (command === 'clear') {
      document.execCommand('removeFormat'); document.execCommand('formatBlock',false,'p'); return;
    }
    const entry = commands[command];
    if (entry) document.execCommand(entry[0],false,entry[1] || null);
  }
};
