import { renderChatMessage } from '/chat-message.js';

(() => {
  const presence = document.getElementById('chat-presence');
  const available = document.getElementById('chat-available');
  const clientId = crypto.randomUUID();
  let presenceBusy = false;
  async function heartbeat() {
    if (!presence || presenceBusy) return;
    presenceBusy = true;
    try {
      const online = available.checked && !document.hidden;
      const response = await fetch('/api/chat-session.php', {
        method: 'POST', headers: { Accept: 'application/json' },
        body: new URLSearchParams({ admin_action: 'presence', client_id: clientId,
          online: online ? '1' : '0', csrf_token: presence.dataset.csrf }),
      });
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error('Presence unavailable; reconnect or sign in again.');
      document.getElementById('chat-presence-status').textContent = online ? 'Online' : 'Offline';
    } catch (error) { document.getElementById('chat-presence-status').textContent = error.message; }
    finally { presenceBusy = false; }
  }
  available?.addEventListener('change', heartbeat);
  document.addEventListener('visibilitychange', heartbeat);
  window.addEventListener('pagehide', () => {
    if (presence) navigator.sendBeacon('/api/chat-session.php', new URLSearchParams({ admin_action: 'presence',
      client_id: clientId, online: '0', csrf_token: presence.dataset.csrf }));
  });
  heartbeat(); setInterval(heartbeat,25000);
  const playerBadges = [...document.querySelectorAll('[data-player-presence]')];
  function updatePlayer(badge, online) {
    badge.dataset.online = online === true ? 'online' : online === false ? 'offline' : 'unknown';
    badge.textContent = online === true ? 'Online' : online === false ? 'Offline' : 'Unavailable';
    badge.title = online === null || online === undefined ? 'Player presence is unavailable for this session.' : 'Based on player heartbeats within the last 75 seconds.';
  }
  window.addEventListener('offline', () => playerBadges.forEach(badge => updatePlayer(badge, null)));
  const root = document.getElementById('conversation');
  if (!root) {
    let busy = false;
    async function pollPlayers() {
      if (busy || document.hidden || !playerBadges.length) return;
      busy = true;
      try {
        const query = new URLSearchParams({ admin_action: 'player_presence' });
        playerBadges.forEach(badge => query.append('session_ids[]', badge.dataset.playerPresence));
        const response = await fetch('/api/chat-session.php?' + query, { headers: { Accept: 'application/json' }, cache: 'no-store', signal: AbortSignal.timeout(10000) });
        const data = await response.json();
        if (!response.ok || !data.ok) throw new Error('Presence unavailable.');
        playerBadges.forEach(badge => updatePlayer(badge, data.players[badge.dataset.playerPresence]));
      } catch { playerBadges.forEach(badge => updatePlayer(badge, null)); }
      finally { busy = false; }
    }
    pollPlayers(); setInterval(pollPlayers,4000);
    document.addEventListener('visibilitychange',pollPlayers);
    window.addEventListener('online',pollPlayers);
    return;
  }
  const error = document.getElementById('chat-error');
  const form = document.getElementById('chat-reply');
  let version = '';
  let polling = false;
  async function poll() {
    if (polling || document.hidden) return;
    polling = true;
    try {
      const query = new URLSearchParams({admin_action: 'messages', session_id: root.dataset.chatId, version});
      const response = await fetch('/api/chat-session.php?' + query, {headers: {Accept: 'application/json'}, cache: 'no-store', signal: AbortSignal.timeout(10000)});
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.error || 'Unable to load messages.');
      playerBadges.forEach(badge => updatePlayer(badge, data.playerOnline));
      if (!data.unchanged) {
        const fragment = document.createDocumentFragment();
        for (const message of data.messages) {
          const row = document.createElement('div');
          row.className = 'support-message support-text';
          const timestamp = new Date(message.time || '');
          const displayTime = Number.isNaN(timestamp.getTime()) ? (message.time || '') : timestamp.toLocaleString();
          row.append(document.createTextNode(`${message.sender === 'agent' ? 'Admin' : message.type === 'ai' ? (message.name || 'Alex') : message.sender === 'bot' ? 'Bot' : 'Customer'} · ${displayTime}\n`));
          const body = document.createElement('div');
          renderChatMessage(body, message.text);
          row.append(body);
          fragment.append(row);
        }
        document.getElementById('chat-messages').replaceChildren(fragment);
        version = data.version;
      }
      error.textContent = '';
    } catch (e) { error.textContent = e.message; playerBadges.forEach(badge => updatePlayer(badge,null)); }
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
  document.addEventListener('visibilitychange',poll);
  window.addEventListener('online',poll);
})();
