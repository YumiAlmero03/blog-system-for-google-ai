(() => {
  const root = document.getElementById('conversation');
  if (!root) return;
  const error = document.getElementById('chat-error');
  const status = document.getElementById('chat-status');
  const toggle = document.getElementById('chat-toggle');
  const form = document.getElementById('chat-reply');
  let cursor = 0;
  let polling = false;
  async function request(fields) {
    const response = await fetch('/admin/live-chat-api.php', {
      method: 'POST', headers: {Accept: 'application/json'},
      body: new URLSearchParams({chat_id: root.dataset.chatId, csrf_token: root.dataset.csrf, ...fields})
    });
    const data = await response.json();
    if (!response.ok || !data.ok) throw new Error(data.error || 'Request failed.');
    return data;
  }
  async function poll() {
    if (polling || document.hidden) return;
    polling = true;
    try {
      let more;
      do {
        const query = new URLSearchParams({chat_id: root.dataset.chatId, after_id: String(cursor)});
        const response = await fetch('/admin/live-chat-api.php?' + query, {headers: {Accept: 'application/json'}});
        const data = await response.json();
        if (!response.ok || !data.ok) throw new Error(data.error || 'Unable to load messages.');
        for (const message of data.messages) {
          if (Number(message.id) <= cursor) continue;
          const row = document.createElement('div');
          row.className = 'support-message support-text';
          row.textContent = `${message.sender === 'admin' ? 'Admin' : 'Customer'} · ${new Date(message.created_at * 1000).toLocaleString()}\n${message.message}`;
          document.getElementById('chat-messages').append(row);
          cursor = Number(message.id);
        }
        status.textContent = data.status;
        toggle.textContent = data.status === 'open' ? 'Close chat' : 'Reopen chat';
        form.querySelector('button').disabled = data.status !== 'open';
        more = data.has_more;
      } while (more && !document.hidden);
      error.textContent = '';
    } catch (e) { error.textContent = e.message; }
    finally { polling = false; }
  }
  form.addEventListener('submit', async event => {
    event.preventDefault();
    const button = form.querySelector('button');
    button.disabled = true;
    try {
      await request({action: 'reply', message: form.elements.message.value});
      form.reset();
      await poll();
    } catch (e) { error.textContent = e.message; }
    finally { button.disabled = status.textContent !== 'open'; }
  });
  document.getElementById('chat-read').addEventListener('click', async () => {
    try { await request({action: 'read', through_id: String(cursor)}); error.textContent = ''; }
    catch (e) { error.textContent = e.message; }
  });
  toggle.addEventListener('click', async () => {
    toggle.disabled = true;
    try { await request({action: 'status', status: status.textContent === 'open' ? 'closed' : 'open'}); await poll(); }
    catch (e) { error.textContent = e.message; }
    finally { toggle.disabled = false; }
  });
  poll();
  setInterval(poll, 4000);
})();
