(() => {
  const root = document.getElementById('conversation');
  if (!root) return;
  const error = document.getElementById('chat-error');
  const form = document.getElementById('chat-reply');
  let version = '';
  let polling = false;
  async function poll() {
    if (polling || document.hidden) return;
    polling = true;
    try {
      const query = new URLSearchParams({admin_action: 'messages', session_id: root.dataset.chatId, version});
      const response = await fetch('/api/chat-session.php?' + query, {headers: {Accept: 'application/json'}});
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.error || 'Unable to load messages.');
      if (!data.unchanged) {
        const fragment = document.createDocumentFragment();
        for (const message of data.messages) {
          const row = document.createElement('div');
          row.className = 'support-message support-text';
          const timestamp = new Date(message.time || '');
          const displayTime = Number.isNaN(timestamp.getTime()) ? (message.time || '') : timestamp.toLocaleString();
          row.textContent = `${message.sender === 'agent' ? 'Admin' : message.sender === 'bot' ? 'Bot' : 'Customer'} · ${displayTime}\n${message.text || ''}`;
          fragment.append(row);
        }
        document.getElementById('chat-messages').replaceChildren(fragment);
        version = data.version;
      }
      error.textContent = '';
    } catch (e) { error.textContent = e.message; }
    finally { polling = false; }
  }
  form.addEventListener('submit', async event => {
    event.preventDefault();
    const button = form.querySelector('button'); button.disabled = true;
    try {
      const response = await fetch('/api/chat-session.php', {
        method: 'POST', headers: {Accept: 'application/json'},
        body: new URLSearchParams({admin_action: 'reply', session_id: root.dataset.chatId, csrf_token: root.dataset.csrf, message: form.elements.message.value})
      });
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.error || 'Unable to save reply.');
      form.reset(); await poll();
    } catch (e) { error.textContent = e.message; }
    finally { button.disabled = false; }
  });
  poll(); setInterval(poll,4000);
})();
