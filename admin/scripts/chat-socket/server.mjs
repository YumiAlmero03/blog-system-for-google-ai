import http from 'node:http';
import { randomUUID } from 'node:crypto';
import { WebSocketServer, WebSocket } from 'ws';

// Keep this listener private; terminate TLS and proxy /chat-ws at the web server.
const backend = process.env.CHAT_BACKEND_URL || 'http://127.0.0.1:8083/api/chat-session.php';
const origins = new Set((process.env.CHAT_ALLOWED_ORIGINS || '').split(',').map(s => s.trim()).filter(Boolean));
if (!origins.size) throw new Error('Set CHAT_ALLOWED_ORIGINS to your frontend origin(s).');
const wss = new WebSocketServer({ noServer: true, maxPayload: 16384, perMessageDeflate: false });
const server = http.createServer((req, res) => { res.writeHead(404); res.end(); });
const sessions = new Map();

server.on('upgrade', (req, socket, head) => {
  if (req.url !== '/chat-ws' || !origins.has(req.headers.origin) || wss.clients.size >= 1000) {
    socket.end('HTTP/1.1 403 Forbidden\r\nConnection: close\r\n\r\n'); return;
  }
  wss.handleUpgrade(req, socket, head, ws => wss.emit('connection', ws));
});

wss.on('connection', ws => {
  const clientId = randomUUID();
  const pending = [];
  let credentials = null, version = '', busy = false, lastActivity = Date.now(), requests = [];
  const send = data => {
    if (ws.readyState !== WebSocket.OPEN) return;
    if (ws.bufferedAmount > 1048576) { ws.close(1013, 'Slow connection'); return; }
    ws.send(JSON.stringify(data));
  };
  const call = async (action, extra = {}) => {
    const response = await fetch(backend, {
      method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ ...credentials, ...extra, action, client_id: clientId }),
      signal: AbortSignal.timeout(10000), redirect: 'error',
    });
    const data = await response.json();
    if (!response.ok || !data.ok) {
      if (response.status === 403) ws.close(1008, 'Chat access denied');
      throw new Error(data.error || 'Chat is unavailable.');
    }
    return data;
  };
  const snapshot = data => {
    if (!data.unchanged) { version = data.version; send({ type: 'snapshot', ...data }); }
  };
  const authTimer = setTimeout(() => { if (!credentials) ws.close(1008, 'Authentication required'); }, 5000);
  ws.on('error', () => {});
  const drain = () => {
    if (busy || !pending.length || ws.readyState !== WebSocket.OPEN) return;
    const [raw, binary] = pending.shift(); handle(raw, binary);
  };
  ws.on('message', (raw, binary) => {
    if (pending.length >= 8) { ws.close(1008, 'Too many pending requests'); return; }
    pending.push([raw, binary]); drain();
  });
  async function handle(raw, binary) {
    const now = Date.now();
    requests = requests.filter(t => t > now - 60000);
    if (binary || requests.length >= 60) { ws.close(1008, 'Request limit exceeded'); return; }
    requests.push(now); lastActivity = now; busy = true;
    try {
      const input = JSON.parse(raw.toString());
      if (!credentials) {
        if (input.type !== 'auth' || typeof input.sessionId !== 'string' || typeof input.sessionToken !== 'string') throw new Error('Authenticate first.');
        credentials = { session_id: input.sessionId, session_token: input.sessionToken };
        const data = await call('poll');
        if (ws.readyState !== WebSocket.OPEN) return;
        if ((sessions.get(input.sessionId) || 0) >= 3) { credentials=null; ws.close(1008, 'Too many connections'); return; }
        sessions.set(input.sessionId, (sessions.get(input.sessionId) || 0) + 1);
        ws.registeredSession = input.sessionId;
        clearTimeout(authTimer);
        snapshot(data);
        snapshot(await call('heartbeat', { online: input.online !== false }));
      } else if (input.type === 'heartbeat') {
        snapshot(await call('heartbeat', { online: input.online !== false }));
      } else if (input.type === 'message') {
        const data = await call('send', { message: input.text, client_message_id: input.clientMessageId });
        snapshot(data);
        send({ type: 'ack', clientMessageId: input.clientMessageId });
      } else {
        throw new Error('Unknown event.');
      }
    } catch (error) {
      send({ type: 'error', error: error instanceof SyntaxError ? 'Invalid JSON.' : error.message });
      if (!ws.registeredSession) ws.close(1008, 'Authentication failed');
    } finally { busy = false; queueMicrotask(drain); }
  }
  const timer = setInterval(async () => {
    if (Date.now() - lastActivity > 65000) { ws.close(1001, 'Heartbeat expired'); return; }
    if (!ws.registeredSession || busy || ws.readyState !== WebSocket.OPEN) return;
    busy = true;
    try { snapshot(await call('poll', { version })); }
    catch (error) { send({ type: 'error', error: error.message }); }
    finally { busy = false; queueMicrotask(drain); }
  }, 2000);
  ws.on('close', () => {
    clearTimeout(authTimer); clearInterval(timer);
    if (ws.registeredSession) {
      const count = (sessions.get(ws.registeredSession) || 1) - 1;
      if (count) sessions.set(ws.registeredSession, count); else sessions.delete(ws.registeredSession);
      call('disconnect').catch(() => {});
    }
  });
});
server.listen(Number(process.env.CHAT_WS_PORT || 8084), process.env.CHAT_WS_HOST || '127.0.0.1', () => {
  console.log('Support chat WebSocket relay listening.');
});
for (const signal of ['SIGINT', 'SIGTERM']) process.on(signal, () => {
  for (const ws of wss.clients) ws.close(1001, 'Server restarting');
  server.close(() => process.exit(0));
  setTimeout(() => process.exit(0), 3000).unref();
});
