# Continuous support chat

Customer chat uses the existing `/api/chat-session.php`, with token-authenticated actions for new conversations. Existing transcript-save clients continue working, but must adopt the new actions to receive live replies and AI fallback. Do not migrate an old session merely by knowing its filename; start a new protected session.

## Behavior

- Enabled keyword responses are checked first and answer immediately.
- Otherwise AI first answers after the oldest unanswered user message has waited 180 seconds, regardless of agent availability. Additional player messages do not restart the three-minute wait. After a successful AI reply, follow-ups have no added wait until a human replies. Generation, worker queue time, and error backoff still apply.
- The player must be online: at least one browser connection must have sent a heartbeat within 75 seconds. The supplied client marks hidden tabs offline. Agent availability has the same 75-second expiry and is controlled by the **Available for live support** checkbox on `/admin/live-chat.php`; a visible checked tab sends heartbeats every 25 seconds.
- A human reply clears the waiting state and ends the active AI conversation. A human reply, new player message, or disconnect during generation causes an obsolete AI answer to be discarded. Worker leases prevent duplicate generation across concurrent workers.
- The AI uses Ollama chat directly, with no web-search requests. Enabled automatic-response rules provide support knowledge (up to 20 KB) alongside the last 20 messages. Its display name is configured in **Settings → AI Chat Name** (default `Alex`, stored as `chat_ai_name` in `app_settings`). It does not announce an AI handoff or volunteer an AI introduction, but answers honestly if asked. It is instructed not to invent site policies, bonus offers, account information, or completed transactions.
- Complete messages are delivered; this does not stream individual model tokens. Delivery normally takes up to two seconds after a reply is saved, plus model generation/worker queue time. The relay polls the shared PHP API internally; browsers receive WebSocket snapshots. A worker processes one AI request at a time.
- If Ollama fails, the conversation stays waiting and retries use increasing delays from 60 seconds up to 15 minutes. No fabricated success reply is saved.

## Maintain support answers

In **Live Chat → Automatic Chat Replies** (`/admin/chat-auto-responses.php`), add enabled rules for registration, login, and other common questions. Use specific matching phrases and write the actual approved steps and URLs in the reply. Enabled rules also supply AI support information (up to the existing 20 KB limit); no model training is required. Matching phrases return the saved reply immediately, so include unresolved-issue guidance in those saved replies when appropriate. Avoid broad phrases that would repeatedly match troubleshooting follow-ups.

The AI is instructed to give short steps using documented information. When a user reports that the steps did not resolve the issue, it asks optionally for either an email address or a contact number, avoids asking again when one is already supplied, and summarizes the issue in a direct support voice. It does not volunteer AI identity or mention human agents/transfers; if asked directly about its identity, it answers honestly. Details stay in the chat transcript visible to human agents; this does not create a ticket, send email/SMS, or schedule a callback. Restart the AI worker after deploying prompt changes. Saved support-rule changes are read on subsequent AI jobs without a restart.

## Start the services

Run from the project root. Keep your existing PHP site running (locally on port 8083):

```sh
npm ci --prefix admin/scripts/chat-socket
```

Start the AI worker in one terminal:

```sh
php admin/scripts/chat-ai-worker.php
```

Start the WebSocket relay in another:

```sh
CHAT_ALLOWED_ORIGINS=http://localhost:8083 \
CHAT_BACKEND_URL=http://127.0.0.1:8083/api/chat-session.php \
node admin/scripts/chat-socket/server.mjs
```

Node.js 22+ is required. The listener defaults to `127.0.0.1:8084`; local frontend code can connect to `ws://localhost:8084/chat-ws`. If the browser resolves localhost only to IPv6, use `ws://127.0.0.1:8084/chat-ws` instead. Allowed origins match the **page's** origin, including its port, not the WebSocket URL. Add multiple exact origins as a comma-separated list when needed.

The worker loads existing `admin/.env` settings. Optional configuration:

| Variable | Default | Purpose |
| --- | --- | --- |
| `CHAT_AI_ENABLED` | `1` | Set `0` to disable AI fallback. |
| `CHAT_AI_MODEL` | `gemma4:31b-cloud` | Chat model override, independent of the enrichment model. |
| `OLLAMA_HOST` | `http://127.0.0.1:11434` | Existing Ollama server. |
| `OLLAMA_CHAT_URL` | `<OLLAMA_HOST>/api/chat` | Explicit chat endpoint; e.g. `https://ollama.com/api/chat` for direct cloud access. |
| `OLLAMA_API_KEY` | Existing configured key | Sent only from the server to Ollama; never send to the frontend. |
| `CHAT_STORAGE_DIR` | `admin/chats` | Shared private session/presence directory; PHP site and worker must use the same directory. |
| `CHAT_BACKEND_URL` | `http://127.0.0.1:8083/api/chat-session.php` | PHP endpoint used by the Node relay. |
| `CHAT_ALLOWED_ORIGINS` | Required | Comma-separated allowed browser origins; no wildcard. |
| `CHAT_WS_HOST` | `127.0.0.1` | Relay bind address. |
| `CHAT_WS_PORT` | `8084` | Relay port. |

The relay reads its variables from the process environment; it does not load `admin/.env`. Keep API requests same-origin, or route your frontend's API path through your web server. No permissive cross-origin API policy is added.

For production, run the PHP worker and Node relay as supervised services (aaPanel process manager or Supervisor) under a user with access to the site's private chat/storage files. Configure automatic restart and logs. Do not launch the worker from public web requests. `--once` processes one scan for testing or scheduled use, but a continuous worker is needed for prompt fallback. Starting the services and configuring your web server are deployment steps; they are not performed automatically by installing the code.

Use HTTPS/WSS. Example Nginx location inside the site's existing server block:

```nginx
location /chat-ws {
    proxy_pass http://127.0.0.1:8084;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_read_timeout 90s;
}
```

Set `CHAT_ALLOWED_ORIGINS=https://your-site.example` and an internal `CHAT_BACKEND_URL` that reaches the correct PHP virtual host. Keep the relay port private and preserve existing access restrictions on `admin/chats`, `admin/storage`, and `admin/scripts`.

## Frontend integration

Use the supplied public ES module `/chat-client.js`. It handles session creation/resumption in `sessionStorage`, 25-second presence heartbeats, WebSocket authentication and reconnection, and four-second HTTP polling when the socket is unavailable. It sends player messages through HTTP and receives live snapshots over WebSocket; direct WebSocket sends are also supported below.

Wire it to your existing chat layout:

```js
import { SupportChat, renderChatMessage } from '/chat-client.js';

const chat = new SupportChat({
  name: 'Player_VIP',
  // Local only; omit wsUrl in production when /chat-ws is reverse-proxied.
  wsUrl: 'ws://127.0.0.1:8084/chat-ws',
  onUpdate({ messages, status }) {
    const list = document.querySelector('#chat-messages');
    list.replaceChildren(...messages.map(message => {
      const row = document.createElement('div');
      const sender = message.sender === 'agent' ? 'Support agent'
        : message.type === 'ai' ? (message.name || status.supportName)
        : message.sender === 'bot' ? 'Automatic reply' : 'You';
      row.append(document.createTextNode(`${sender}: `));
      const body = document.createElement('div');
      renderChatMessage(body, message.text);
      row.append(body);
      return row;
    }));
    document.querySelector('#chat-status').textContent = status.aiPending
      ? `${status.supportName} is replying…`
      : status.agentOnline ? 'Support is online' : 'Support available';
  },
  onError(error) {
    document.querySelector('#chat-error').textContent = error.message;
  }
});

await chat.start();

document.querySelector('#chat-form').addEventListener('submit', async event => {
  event.preventDefault();
  const input = document.querySelector('#chat-input');
  const button = event.currentTarget.querySelector('button');
  button.disabled = true;
  const text = input.value;
  // Retain this ID and text in your UI if sending fails; reuse both on Retry.
  const messageId = crypto.randomUUID();
  try {
    await chat.send(text, messageId);
    input.value = '';
  } catch (error) {
    document.querySelector('#chat-error').textContent = error.message;
    // Your retry button can call chat.send(text, error.clientMessageId).
  } finally { button.disabled = false; }
});

// On chat-widget close or component unmount:
// chat.stop();
```

Supply the elements referenced above in your layout. Use `renderChatMessage(element, message.text)` for message bodies: it preserves line breaks, blank lines and spacing and creates validated HTTP(S) links, including `www.` addresses and Markdown links (site-relative paths are supported in Markdown links). HTML stays plain text. Use a `<textarea>` for multiline input; Enter inserts a new line and the submit button sends. Never assign message text to `innerHTML`. Each update is a full snapshot: replace/reconcile the transcript rather than appending every item again. `revision` prevents older snapshots from replacing newer message history. Do not store session tokens in URLs, logs, analytics, or shared caches. Session credentials expire after 30 days; the module starts a fresh session when expired.

Fetch suggestions from `GET /api/chat-auto-responses.php` and create up to five buttons using each `label`; call `chat.send(suggestion.message)` when clicked. Stop sending the old full-transcript save payload for these protected sessions. The server assigns sender, timestamp, and message IDs.

## HTTP contract

All actions use JSON POST to `/api/chat-session.php` with `Content-Type: application/json`. No admin cookies or CSRF are required for visitor actions; session token ownership is required after start.

| Field | Required | Default | Meaning |
| --- | --- | --- | --- |
| `action` | Yes | None | `start`, `send`, `poll`, `heartbeat`, or `disconnect`. |
| `name` | No; used on start | `Guest` | Display name, up to 120 characters. |
| `session_id` | Except start | None | Returned `sessionId`. |
| `session_token` | Except start | None | Returned secret `sessionToken`; only its hash is stored. |
| `client_id` | Send/heartbeat/disconnect | None | Per-tab ID, 8–80 letters, digits, hyphens or underscores (UUID works). |
| `message` | Send | None | Nonblank text, at most 5,000 bytes. |
| `client_message_id` | Send | None | Stable ID, same format as client_id. Reuse on retries with identical text. |
| `online` | No; heartbeat | `true` | Boolean; false removes this connection's presence. |
| `version` | No; poll | None | Previous version; unchanged responses contain `unchanged: true` and empty messages. |

Start: `{"action":"start","name":"Player_VIP"}`. Returns `ok`, `sessionId`, secret `sessionToken`, `revision`, `messages`, `version`, and `status`. Later actions return the same public view without `sessionToken`. Status includes `agentOnline`, `playerOnline`, `waitingSince` (Unix seconds or null), `aiPending`, and `aiEnabled`, plus `supportName` (configured display name). AI messages include `name`; render it with `textContent` instead of a hardcoded AI label. Do not clear your UI when `unchanged` is true.

Send: `{"action":"send","session_id":"...","session_token":"...","client_id":"browser-uuid","client_message_id":"message-uuid","message":"help me"}`. Only player text is accepted; passing `sender` cannot forge an agent message. History retains 200 messages, and deduplication covers retained messages. Protected sessions reject legacy snapshot writes even if their filename is known. Legacy sessions are not eligible for AI because they lack ownership tokens and presence.

Errors: 403 invalid/expired ownership, 422 invalid input, 429 rate limit (`Retry-After: 60`), 500 storage/service error. Starts are limited to 5/minute per direct request IP; sends to 20/minute per session; each other visitor action to 180/minute per session. Configure trusted proxy handling at your web server rather than forwarding arbitrary client IP headers.

Admin presence: form POST `admin_action=presence`, `client_id`, `online=1|0`, `csrf_token`. Requires existing session/JWT, `chat.reply`, and CSRF. Existing admin message-read and reply routes are unchanged. Admin replies clear pending AI work.

## WebSocket contract

Connect to `/chat-ws`; authenticate within five seconds by sending:

```json
{"type":"auth","sessionId":"...","sessionToken":"...","online":true}
```

- Receive `{"type":"snapshot", ...publicChatView}` whenever messages or status change.
- Send `{"type":"heartbeat","online":true}` every 25 seconds; send false when hidden. The connection is closed after 65 seconds without a client event, and stale presence expires after 75 seconds. Disconnect removes its presence. Up to three sockets are allowed per session.
- Optionally send `{"type":"message","text":"help me","clientMessageId":"stable-uuid"}`. A successful send returns a snapshot followed by `{"type":"ack","clientMessageId":"stable-uuid"}`. If the connection drops before ack, retry the same ID/text after reconnecting.
- Errors use `{"type":"error","error":"..."}`. Invalid ownership closes with code 1008. Maximum frame/message payload is 16 KB; clients are limited to 60 events/minute. The relay limits queued messages and disconnects slow readers.
- Authentication is sent in the first message, never in the URL. The relay forwards token-authorized calls to PHP; it cannot read another conversation without that conversation's token. All saved messages still live in the existing private chat files.

## Verification

```sh
php admin/scripts/test-chat-realtime.php
php admin/scripts/test-chat-auto-responses.php
php admin/scripts/test-admin-support.php
node admin/scripts/chat-socket/test.mjs
```

Tests use temporary databases/chat files and a local mock Ollama server. They cover session access, legacy-write rejection, origin checks, message retries, AI eligibility and races, retry delays, WebSocket AI/human delivery, and presence expiry. The HTTP/WebSocket test requires permission to bind localhost ports.

AI failures are silent to players: no error message is added to the transcript or returned by the chat API/WebSocket. Retry timestamps and failure counts remain internal. The worker appends timestamped, sanitized diagnostics (HTTP/cURL codes and a hashed session reference) to `CHAT_STORAGE_DIR/ai-errors.php` (default `admin/chats/ai-errors.php`). This is a log file with a PHP guard returning an empty 404 on HTTP access; it has owner-only permissions. Read it from the server filesystem and include it in server log rotation. Credentials, upstream response bodies and conversation text are never logged. Existing retry backoff continues. Restart the worker after deploying this change.

### Relevant support links

Each generated reply receives up to eight relevant links matched against the last three player messages: publicly visible blog titles/excerpts from the same source and visibility rules as `sitemap-blog.php`, plus same-site URLs from the root `sitemap-pages.xml` when present. Missing page sitemaps are skipped. Draft/future blogs, external domains, admin/API/upload paths, DTDs and XML entities are excluded. Blog excerpts are reference material, not model instructions. No remote page fetches occur. Replies use descriptive `[label](/path/)` links, rendered as safe clickable anchors by the existing chat renderer. No new endpoints, flags or database tables. Restart the running chat AI worker to load these code changes.
