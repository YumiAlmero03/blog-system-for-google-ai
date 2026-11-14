// Public browser module: credentials stay in sessionStorage, never in WebSocket URLs.
export { renderChatMessage } from './chat-message.js';

export class SupportChat {
  constructor({ name = 'Guest', apiUrl = '/api/chat-session.php', wsUrl,
    onUpdate = () => {}, onError = () => {} } = {}) {
    this.name = name; this.apiUrl = apiUrl;
    this.wsUrl = wsUrl ?? `${location.protocol === 'https:' ? 'wss:' : 'ws:'}//${location.host}/chat-ws`;
    this.onUpdate = onUpdate; this.onError = onError;
    this.clientId = crypto.randomUUID(); this.storageKey = `support-chat:${apiUrl}`;
    try { this.session = JSON.parse(sessionStorage.getItem(this.storageKey) || 'null'); } catch { this.session = null; }
    this.visibility = () => this.heartbeat().catch(this.onError);
    this.pagehide = () => this.offline();
  }
  async request(action, fields = {}) {
    const response = await fetch(this.apiUrl, { method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action, session_id: this.session?.sessionId,
        session_token: this.session?.sessionToken, client_id: this.clientId, ...fields }),
      signal: AbortSignal.timeout(12000),
    });
    const data = await response.json();
    if (!response.ok || !data.ok) {
      const error = new Error(data.error || 'Chat unavailable.'); error.status = response.status; throw error;
    }
    return data;
  }
  update(data) {
    if (data.unchanged) return;
    if (this.currentSession === data.sessionId && (data.revision ?? 0) < (this.revision ?? 0)) return;
    this.currentSession = data.sessionId; this.revision = data.revision;
    this.version = data.version;
    if (data.incremental) {
      const messages = this.messages ?? [];
      const knownIds = new Set(messages.map(message => message.id ?? message.clientMessageId).filter(Boolean));
      this.messages = [...messages, ...(data.messages ?? []).filter(message => {
        const id = message.id ?? message.clientMessageId;
        if (!id) return true;
        if (knownIds.has(id)) return false;
        knownIds.add(id);
        return true;
      })].slice(-200);
    } else {
      this.messages = data.messages ?? [];
    }
    this.onUpdate({ ...data, messages: this.messages });
  }
  async start() {
    if (this.started) return;
    if (this.session) {
      try { this.update(await this.request('poll')); }
      catch (error) { if (error.status !== 403) throw error; this.session = null; }
    }
    if (!this.session) {
      const data = await this.request('start', { name: this.name });
      this.session = { sessionId: data.sessionId, sessionToken: data.sessionToken };
      sessionStorage.setItem(this.storageKey, JSON.stringify(this.session)); this.update(data);
    }
    this.started = true; this.stopped = false;
    try { await this.heartbeat(); }
    catch (error) { this.started = false; throw error; }
    if (this.stopped) return;
    this.connectSocket();
    document.addEventListener('visibilitychange', this.visibility);
    window.addEventListener('pagehide', this.pagehide);
    this.presenceTimer = setInterval(() => this.heartbeat().catch(this.onError), 25000);
    this.pollTimer = setInterval(async () => {
      if (this.socketReady || this.polling || this.stopped) return;
      this.polling = true;
      try { this.update(await this.request('poll', { version: this.version })); }
      catch (error) { this.onError(error); }
      finally { this.polling = false; }
    }, 4000);
  }
  connectSocket() {
    if (this.stopped) return;
    const ws = this.socket = new WebSocket(this.wsUrl);
    const timeout = setTimeout(() => { if (!this.socketReady) ws.close(); }, 12000);
    ws.onopen = () => ws.send(JSON.stringify({ type: 'auth', ...this.session, online: !document.hidden }));
    ws.onmessage = event => {
      let data; try { data = JSON.parse(event.data); } catch { return; }
      if (data.type === 'snapshot') { this.socketReady = true; clearTimeout(timeout); this.update(data); }
      else if (data.type === 'error') this.onError(new Error(data.error));
    };
    ws.onclose = () => {
      clearTimeout(timeout); this.socketReady = false;
      if (!this.stopped) this.reconnectTimer = setTimeout(() => this.connectSocket(),5000);
    };
    ws.onerror = () => {}; // HTTP polling continues while the relay is unavailable.
  }
  async heartbeat() {
    if (!this.session || this.stopped) return;
    // Clear the HTTP presence too when switching to a socket or hiding the tab.
    const online = !document.hidden;
    if (this.socketReady && this.socket.readyState === WebSocket.OPEN) {
      this.socket.send(JSON.stringify({ type: 'heartbeat', online }));
    }
    this.update(await this.request('heartbeat', { online: online && !this.socketReady }));
  }
  async send(text, clientMessageId = crypto.randomUUID()) {
    if (!this.started || this.stopped) throw new Error('Start the chat first.');
    try {
      const data = await this.request('send', { message: text, client_message_id: clientMessageId });
      this.update(data); return { clientMessageId, ...data };
    } catch (error) {
      error.clientMessageId = clientMessageId; // Reuse this ID when retrying a failed send.
      throw error;
    }
  }
  offline() {
    if (!this.session) return;
    this.socketReady = false; this.socket?.close();
    navigator.sendBeacon(this.apiUrl, new Blob([JSON.stringify({ action: 'disconnect',
      session_id: this.session.sessionId, session_token: this.session.sessionToken,
      client_id: this.clientId })], { type: 'application/json' }));
  }
  stop() {
    this.stopped = true; this.started = false;
    clearInterval(this.pollTimer); clearInterval(this.presenceTimer); clearTimeout(this.reconnectTimer);
    document.removeEventListener('visibilitychange', this.visibility);
    window.removeEventListener('pagehide', this.pagehide); this.offline();
  }
}
