import assert from 'node:assert/strict';
import http from 'node:http';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { spawn } from 'node:child_process';
import { once } from 'node:events';
import { WebSocket } from 'ws';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'chat-realtime-http-'));
const children = [], sockets = [];
let browserClient;
let aiRequests = 0, lastPrompt;
const mock = http.createServer(async (req, res) => {
  let raw = ''; for await (const chunk of req) raw += chunk;
  const body = JSON.parse(raw); lastPrompt = body;
  assert.equal(req.url, '/api/chat'); assert.equal(body.stream, false); assert.equal(body.model, 'test-model');
  aiRequests++; res.setHeader('Content-Type', 'application/json');
  res.end(JSON.stringify({ message: { role: 'assistant', content: 'I am the AI support assistant. How can I help?' } }));
});
const freePort = async () => {
  const server = http.createServer(); server.listen(0, '127.0.0.1'); await once(server, 'listening');
  const port = server.address().port; await new Promise(r => server.close(r)); return port;
};
const delay = ms => new Promise(r => setTimeout(r, ms));
const run = (command, args, env) => new Promise((resolve, reject) => {
  const child = spawn(command, args, { cwd: root, env }); let output = '';
  child.stdout.on('data', d => output += d); child.stderr.on('data', d => output += d);
  child.on('error', reject); child.on('exit', code => code === 0 ? resolve(output) : reject(new Error(output)));
});
try {
  mock.listen(0, '127.0.0.1'); await once(mock, 'listening');
  const phpPort = await freePort(), wsPort = await freePort();
  const origin = `http://127.0.0.1:${phpPort}`, api = `${origin}/api/chat-session.php`;
  const env = { ...process.env, APP_STORAGE_DIR: `${dir}/storage`, CHAT_STORAGE_DIR: `${dir}/chats`,
    CHAT_AI_ENABLED: '1', CHAT_AI_MODEL: 'test-model', OLLAMA_API_KEY: 'test-key',
    OLLAMA_CHAT_URL: `http://127.0.0.1:${mock.address().port}/api/chat`, SITE_BASE_URL: origin,
    CHAT_BACKEND_URL: api, CHAT_ALLOWED_ORIGINS: origin, CHAT_WS_PORT: String(wsPort),
    ADMIN_USERNAME: 'chat-test-admin', ADMIN_JWT_SECRET: 'chat-test-secret-'.repeat(4) };
  env.ADMIN_PASSWORD_HASH = await run('php', ['-r', "echo password_hash('Chat test password!',PASSWORD_DEFAULT);"], env);
  fs.mkdirSync(`${dir}/sessions`);
  const launch = (command, args) => {
    const child = spawn(command, args, { cwd: root, env, stdio: 'ignore' }); children.push(child); return child;
  };
  launch('php', ['-d', `session.save_path=${dir}/sessions`, '-S', `127.0.0.1:${phpPort}`, '-t', root]);
  const relay = launch(process.execPath, ['admin/scripts/chat-socket/server.mjs']);
  let ready = false;
  for (let i = 0; i < 60; i++) {
    try { await fetch(api); await fetch(`http://127.0.0.1:${wsPort}`); ready = true; break; } catch { await delay(50); }
  }
  assert(ready, 'Servers start');
  const post = async body => {
    const response = await fetch(api, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
    return { status: response.status, data: await response.json() };
  };
  const { data: session } = await post({ action: 'start', name: 'Realtime Test' });
  assert(session.sessionToken); const auth = { session_id: session.sessionId, session_token: session.sessionToken, client_id: 'http-browser' };
  assert.equal((await post({ ...auth, action: 'poll', session_token: 'wrong' })).status, 403);
  assert.equal((await post({ session_id: session.sessionId, messages: [{ sender: 'agent', text: 'Forged' }] })).status, 403);
  assert.equal((await fetch(api + '?admin_action=presence', { method: 'POST', headers: { Accept: 'application/json' } })).status, 403);
  const admin = JSON.parse(await run('php', ['-d', `session.save_path=${dir}/sessions`, '-r', `
    require 'admin/includes/auth.php';
    $user=auth_authenticate_credentials('chat-test-admin','Chat test password!');
    auth_mark_authenticated($user);
    $claims=['aud'=>'admin','scope'=>'admin:access','sub'=>$user['id'],'user_id'=>$user['id'],
      'role'=>$user['role'],'ver'=>$user['auth_version'],'sid'=>$_SESSION['auth_binding'],
      'iat'=>time(),'nbf'=>time(),'exp'=>time()+900];
    echo json_encode(['cookie'=>session_name().'='.session_id().'; '.AUTH_ACCESS_COOKIE.'='.jwt_sign($claims,auth_jwt_secret()),'csrf'=>csrf_token()]);
  `], env));
  const presence = (online, csrf = admin.csrf) => fetch(api, { method: 'POST',
    headers: { Accept: 'application/json', Cookie: admin.cookie },
    body: new URLSearchParams({ admin_action: 'presence', client_id: 'test-agent-browser', online, csrf_token: csrf }) });
  assert.equal((await presence('1', 'wrong')).status, 403, 'Presence requires CSRF');
  assert.equal((await presence('1')).status, 200, 'Authenticated presence');
  assert.equal((await post({ ...auth, action: 'poll' })).data.status.agentOnline, true);
  await presence('0');
  const adminGet = async fields => {
    const response = await fetch(api + '?' + new URLSearchParams(fields), { headers: { Accept: 'application/json', Cookie: admin.cookie } });
    assert.equal(response.status,200); return response.json();
  };
  const playerQuery = { admin_action: 'player_presence', 'session_ids[]': session.sessionId };
  assert.equal((await adminGet(playerQuery)).players[session.sessionId],false);
  const rejected = new WebSocket(`ws://127.0.0.1:${wsPort}/chat-ws`, { origin: 'https://untrusted.test' });
  await new Promise(resolve => rejected.once('error', resolve));
  const ws = new WebSocket(`ws://127.0.0.1:${wsPort}/chat-ws`, { origin }); sockets.push(ws);
  const events = []; ws.on('message', raw => events.push(JSON.parse(raw.toString()))); await once(ws, 'open');
  ws.send(JSON.stringify({ type: 'auth', sessionId: session.sessionId, sessionToken: session.sessionToken }));
  const until = async predicate => { for (let i=0; i<120; i++) { if (predicate()) return; await delay(50); } throw new Error('Timed out waiting for chat event'); };
  await until(() => events.some(e => e.type === 'snapshot' && e.status.playerOnline));
  assert.equal((await adminGet(playerQuery)).players[session.sessionId],true);
  const adminMessages = await adminGet({ admin_action: 'messages', session_id: session.sessionId });
  const unchangedMessages = await adminGet({ admin_action: 'messages', session_id: session.sessionId, version: adminMessages.version });
  assert.equal(unchangedMessages.unchanged,true); assert.equal(unchangedMessages.playerOnline,true);
  ws.send(JSON.stringify({ type: 'message', text: 'Help with my account', clientMessageId: 'socket-message-1' }));
  await until(() => events.some(e => e.type === 'ack'));
  await run('php', ['admin/scripts/chat-ai-worker.php', '--once'], env);
  await until(() => events.some(e => e.messages?.some(m => m.type === 'ai')));
  assert.equal(aiRequests, 1); assert(lastPrompt.messages.some(m => m.role === 'user' && m.content === 'Help with my account'));
  await run('php', ['admin/scripts/chat-ai-worker.php', '--once'], env); assert.equal(aiRequests, 1);
  const retry = await post({ ...auth, action: 'send', message: 'Help with my account', client_message_id: 'socket-message-1' });
  assert.equal(retry.data.messages.length, 2);
  // Human messages use the same file and arrive through the socket without a visitor send.
  await run('php', ['-r', `require 'admin/includes/chat-realtime.php'; chat_admin_reply(${JSON.stringify(session.sessionId)},'A human agent is here.');`], env);
  await until(() => events.some(e => e.messages?.some(m => m.sender === 'agent')));
  ws.close(); await once(ws, 'close');
  await post({ ...auth, action: 'disconnect' }); await delay(100);
  const offline = await post({ ...auth, action: 'poll' }); assert.equal(offline.data.status.playerOnline, false);
  assert.equal((await adminGet(playerQuery)).players[session.sessionId],false);
  const invalid = new WebSocket(`ws://127.0.0.1:${wsPort}/chat-ws`, { origin }); sockets.push(invalid);
  await once(invalid, 'open'); invalid.send(JSON.stringify({ type: 'auth', sessionId: session.sessionId, sessionToken: 'wrong' }));
  const [code] = await once(invalid, 'close'); assert.equal(code, 1008);

  // Exercise the actual browser module with small DOM/storage shims and real HTTP/WS.
  const storage = new Map();
  globalThis.sessionStorage = { getItem: key => storage.get(key) ?? null, setItem: (key, value) => storage.set(key, value) };
  globalThis.document = new EventTarget(); document.hidden = false;
  globalThis.window = new EventTarget(); globalThis.location = { protocol: 'http:', host: `127.0.0.1:${phpPort}` };
  globalThis.WebSocket = class extends WebSocket { constructor(url) { super(url, { origin }); } };
  globalThis.navigator.sendBeacon = (url, body) => { fetch(url, { method: 'POST', body }).catch(() => {}); return true; };
  const { SupportChat } = await import(pathToFileURL(path.join(root, 'chat-client.js')));
  const updates = [];
  browserClient = new SupportChat({ apiUrl: api, wsUrl: `ws://127.0.0.1:${wsPort}/chat-ws`, onUpdate: data => updates.push(data) });
  await browserClient.start(); await until(() => browserClient.socketReady);
  await browserClient.send('Browser question', 'browser-message-1');
  await run('php', ['admin/scripts/chat-ai-worker.php', '--once'], env);
  await until(() => updates.some(e => e.messages.some(m => m.type === 'ai')));
  document.hidden = true; document.dispatchEvent(new Event('visibilitychange'));
  await until(() => updates.at(-1).status.playerOnline === false);
  document.hidden = false; document.dispatchEvent(new Event('visibilitychange'));
  await until(() => updates.at(-1).status.playerOnline === true);
  relay.kill(); await once(relay, 'exit');
  await run('php', ['-r', `require 'admin/includes/chat-realtime.php'; chat_admin_reply(${JSON.stringify(browserClient.session.sessionId)},'Polling fallback reply');`], env);
  await until(() => updates.some(e => e.messages.some(m => m.text === 'Polling fallback reply')));
  browserClient.stop();
  console.log('PASS: HTTP/WS authentication, admin presence and CSRF, origin checks, protected sessions, AI/human delivery, idempotence, actual browser client, visibility presence and HTTP fallback.');
} finally {
  browserClient?.stop();
  for (const ws of sockets) ws.terminate();
  await Promise.all(children.map(child => new Promise(resolve => {
    if (child.exitCode !== null) return resolve(); child.once('exit', resolve); child.kill();
  })));
  await new Promise(resolve => mock.close(resolve));
  fs.rmSync(dir, { recursive: true, force: true });
}
