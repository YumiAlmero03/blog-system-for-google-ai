# API reference

Source inventory reviewed on 2026-09-24. This documents the 14 public-facing PHP entrypoints under `/api/`, plus related authenticated admin handlers. It is not a claim that every route has been tested on your production server.

Use your site's origin as the base URL, for example `http://localhost:8082` locally. Configure `SITE_BASE_URL` for absolute public URLs.

## Documentation rule

Whenever an API is added, changed, renamed, or removed, update **both [AGENT.md](AGENT.md) and this API.md in the same change**. Include the route, methods, authentication/permissions, request fields, responses, and relevant behavior. When documentation files are added, renamed, moved, or removed, update the index in [README.md](README.md).

## Request conventions

- GET parameters belong in the query string.
- List endpoint POST requests use form fields, not JSON.
- Engagement, click tracking, public chat saving, and tickets accept JSON or form fields.
- Admin writes use the existing session/JWT cookies, route capabilities, and a valid `csrf_token`. A public blog token does not authorize admin operations.
- Responses normally contain `ok`; error responses generally contain `error`. Some unsupported-method handlers return an empty 405 response.
- Common statuses are 200 (success), 404 (unavailable detail), 405 (unsupported method), 422 (invalid input), and 500 (storage/service failure). Protected routes may also return authentication failures, 403, or 429.
- Successful write requests can change data or send email. Use read endpoints for routine availability checks.

## Endpoint inventory

| Endpoint | Methods | Access | Purpose |
| --- | --- | --- | --- |
| `/api/slot-list.php` | GET, POST | Public | Paginated eligible games of all supported types. |
| `/api/provider-list.php` | GET | Public | Providers and eligible game counts. |
| `/api/game.php` | GET | Public | One eligible game by slug. |
| `/api/blog-post-list.php` | GET, POST | Public | Published blog list. |
| `/api/blog-category-list.php` | GET, POST | Public | Blog category hierarchy and counts. |
| `/api/blog.php` | GET | Public | Published blog detail and rendered content. |
| `/api/blog-public-token.php` | GET, POST | Public | Short-lived public blog JWT. |
| `/api/blog-engagement.php` | POST | Public | Record a view, like, or dislike. |
| `/api/playnow-click.php` | POST | Public | Record a Play Now click. |
| `/api/chat-session.php` | POST; GET for admin messages | Public transcript save; authenticated admin actions | Save transcripts, read messages, or reply. |
| `/api/chat-auto-responses.php` | GET; POST for admin changes | Public suggestions; admin/super-user management | List up to five suggestions and manage unlimited automatic reply rules. |
| `/api/customer-ticket.php` | POST | Public | Save a support ticket and attempt email delivery. |
| `/api/settings/seo.php` | GET, POST | Public GET; admin/super-user POST | Read or update site SEO settings. |

## Games

Game API implementations live under `admin/games/api/`; continue using the stable `/api/` URLs above. Game data lives in `APP_STORAGE_DIR/games.sqlite`, defaulting to `admin/storage/games.sqlite`.

Public games require the Games module to be enabled, `published=1`, `done_processing=1`, `is_viewable=1`, an approved provider, a valid public slug, and no exact PH/PHILIPPINES restriction. Filters cannot bypass these rules.

Disabling **Games → Games Settings** makes game/provider lists empty and game details return 404. Provider sample mode cannot bypass the switch. Admin editing remains available.

### Game list

`GET` or form `POST /api/slot-list.php`

| Field | Meaning |
| --- | --- |
| `count` | Number of games, 1–100; default 24 normally, or 1 with `random=1`. |
| `random` | `1` or `true` selects random eligible games. |
| `page` | Page number; default 1. |
| `search` | Search name, slug, provider, or type. |
| `provider` | Provider slug. |
| `type`, `types` | Type slugs; arrays or comma-separated values. |
| `featured`, `progressive`, `upcoming` | Boolean filters: `1`, `0`, `true`, or `false`. |
| `megaways` | `1`/`true` enables the Megaways filter. |
| `published` | Defaults to 1. Setting 0 does not expose unpublished games. |
| `sort` | `name_asc`, `rtp_desc`, `release_desc`, or `updated_desc`; default is featured first, then newest updates. |

Returns `ok`, `slots`, `pagination` (`total`, `count`, `page`, `totalPages`), and `filters`. Each game includes identity, URLs, descriptions, provider/type objects, themes, statistics, flags, bet limits, paylines, and update time. Local list thumbnails use `/uploads/games/...`; resolve these against the API site's origin if your frontend is hosted elsewhere. Cache TTL: 60 seconds; `X-API-Cache` indicates HIT or MISS.

Random examples: `/api/slot-list.php?random=1` returns one random game by default; `/api/slot-list.php?random=1&count=5` returns up to five. Random mode preserves all eligibility and search/provider/type filters, selects without replacement within one response, ignores `page` and `sort`, and returns `filters.random=true` with `filters.sort="random"`. The response keeps the usual `slots` array and pagination structure; `page` and `totalPages` are 1, while `total` is the number of matching eligible games. Empty matches return `slots: []`. Results can repeat across requests. Random requests bypass the file cache and send `Cache-Control: no-store` and `X-API-Cache: BYPASS`. Normal listing defaults and caching remain unchanged. The flag also works with form POST.

### Providers

`GET /api/provider-list.php`

Optional `count` or `limit`: 1–1000. `sample` or `dev` enables sample rows when Games is enabled. Returns `ok`, `providers`, `total`, `count`, `limit`, and `sample`. Provider records include ID/slug, API ID, name, thumbnail, eligible game count, and update time. Cache TTL: 300 seconds.

Checked/approved providers from provider settings are included even when none of their games are currently eligible for public demos; those rows have `gameCount: 0` and retain their provider thumbnail. Unchecked providers stay excluded. Game counts still apply all publication, processing, visibility, slug, and PH restrictions. Disabling the Games module returns an empty list. Providers with games sort ahead of zero-count providers; ties sort by name. The optional count/limit is applied after sorting.

Provider images are returned in `providers[].thumbnail`. After syncing, this is a local public path such as `/uploads/providers/provider-123-<source-hash>.png`, suitable for an image `src` on the same site (prepend your API origin when using a different frontend origin). Providers without an available image return an empty string; previously stored remote URLs remain until successfully localized. Sample mode retains its placeholder images. Provider slugs from eligible games are preserved when they differ from the normalized provider name.

SlotsLaunch's `thumb` is the preferred source field when creating or updating providers, including nested provider objects in PHP game imports. If `thumb` is absent or blank, the importer checks `thumbnail`, `image`, `logo`, then `icon`. The public response field remains `thumbnail`.

Run `php admin/games/scripts/sync-providers.php` to refresh provider metadata from SlotsLaunch and download thumbnails to `uploads/providers`. It uses the existing provider importer and token configuration, follows provider API pagination, reuses valid downloaded files, preserves previous thumbnails on download failure, and clears API caches after each page. The command does not enable providers or change game publication/processing status. See [Scripts guide](scripts/README.md#sync-providersphp) for options.

### Game detail

`GET /api/game.php?slug=example-game`

Returns `{"ok":true,"game":{...}}` using the shared game fields. Detail page, iframe, and thumbnail URLs are formatted as absolute URLs using site configuration. Invalid, hidden, unfinished, restricted, or disabled games return 404. Response is not cached.

## Blogs

### Blog list and categories

`GET` or form `POST /api/blog-post-list.php`: optional `count` (1–100, default 10), `page` (default 1), and `category`. Category IDs, slugs, and legacy names are resolved by shared taxonomy helpers; a parent category includes descendants. Returns `ok`, `blogs`, and `pagination`. Only published content is listed; public writer fields do not include account credentials or permissions.

`GET` or `POST /api/blog-category-list.php`: no required fields. Returns `ok`, `categories`, and `total`, including category hierarchy and post counts.

### Blog detail and token

`GET /api/blog.php?slug=example-post`: returns `ok`, `blog`, and `faq_schema`; the blog includes `content_html`, writer details, category/tags, URLs, dates, and reading time. Invalid, draft, or future-scheduled content returns 404.

`GET` or `POST /api/blog-public-token.php`: no required fields. Returns `ok`, `token`, and `expiresIn` (300 seconds). The token has audience `blog-post-list` and scope `blog:read`. It requires a configured `BLOG_API_JWT_SECRET` of at least 32 characters. The current blog list does not require this token.

## Public write endpoints

| Endpoint | Required fields | Optional fields / result notes |
| --- | --- | --- |
| `/api/blog-engagement.php` | `postId`, `action` (`view`, `like`, `dislike`) | Updates engagement; invalid input returns 422 and unknown posts return 404. |
| `/api/playnow-click.php` | No mandatory context fields | Accepts `pageUrl`, `pagePath`, `pageTitle`, `referrer`, `targetUrl`, `buttonText`, `buttonClass`, `buttonId`, `buttonTag`, `buttonName`, `section`, `selector`, `location`; returns `totalClicks` and `locationClicks`. |
| `/api/chat-session.php` | Nonempty `messages` containing `text` | Message fields can include `sender`, `time`, `type`; optional `name`, `contact`, `page_url`, `session_id`. Returns `sessionId`, `path`, and `messageCount`. |
| `/api/customer-ticket.php` | `full_name`, `contact`, `topic`, `problem` | Optional `page_url`. Check `emailSent`: a saved ticket with `ok=true` does not necessarily mean email was delivered. |

### Public chat transcript request

For live customer conversations and AI fallback, use the token-authenticated `action` flow described in [Continuous chat](CHAT-INTEGRATION.md#http-contract). The transcript format below remains available for legacy sessions; protected sessions reject full-snapshot writes with 403.

Send `POST http://localhost:8083/api/chat-session.php` with `Content-Type: application/json` (replace the origin for other environments). Public transcript saves do not require admin authentication or CSRF.

```json
{
  "name": "Player_VIP",
  "messages": [
    { "sender": "user", "text": "help me" }
  ]
}
```

Use `name` for the visitor and `messages` (plural) for the transcript. The shape `{"message":"help me","context":{"user":"Player_VIP"}}` is unsupported: `message` and `context.user` are not read by the public save handler, so it returns HTTP 422 with `{"ok":false,"error":"No chat messages to save."}`.

**Top-level parameters**

Defaults below apply when a field is omitted or `null`.

| Parameter | Type | Required? | Default | Description |
| --- | --- | --- | --- | --- |
| `messages` | Array of message objects | Yes | `[]` (returns 422) | Full current transcript. At least one message must have nonblank `text`; only the last 200 entries are considered. |
| `name` | String | No | `"Guest"` | Visitor name, limited to 120 characters. Omission also sets an existing session's name to `Guest`; an explicitly blank value preserves its existing name. |
| `contact` | String | No | `""` | Contact details, limited to 160 characters. Blank or omitted values preserve an existing session's contact. |
| `page_url` | String | No | HTTP `Referer` header, otherwise `""` | Page associated with the chat, limited to 500 characters. |
| `session_id` | String | No for a new session; yes to update the same session | A new session ID is generated | Pass the exact `sessionId` returned by the previous save. Blank or invalid IDs also generate a new ID. |

**Fields inside each `messages` object**

| Parameter | Type | Required? | Default | Description |
| --- | --- | --- | --- | --- |
| `text` | String | Yes for the message to be saved | `""` (message is skipped) | Message content, limited to 5,000 characters. Blank messages are skipped; if none remain, the request returns 422. |
| `sender` | String | No | `"user"` | Supported values: `user`, `bot`, `agent`. Unrecognized values become `user`. |
| `time` | String | No | `""` | Message timestamp, limited to 60 characters. Use ISO 8601; the server does not automatically timestamp public messages. |
| `type` | String | No | `"chat"` | Message type, limited to 40 characters. |

String values are trimmed and whitespace is collapsed. Length limits use characters when PHP's `mb_substr` is available, otherwise bytes.

- A successful save returns `ok`, `sessionId`, `path`, `messageCount`, and `reply`. A matching automatic reply is saved as `bot` and returned in `reply`; otherwise `reply` is `null`. The returned `path` is a storage reference, not a public message-reading URL.
- For later saves, copy the returned `sessionId` into the request's `session_id` and send the full current transcript in `messages`, including earlier user/bot messages. Saves replace that history while preserving server-authored admin and automatic replies; they do not append a single new user message automatically. Storage retains up to 200 messages.
- Missing, empty, or unusable `messages` returns HTTP 422. A missing or invalid `session_id` creates a new session ID.

### Automatic chat suggestions and replies

Manage replies at `/admin/chat-auto-responses.php`, also linked from **Live Chat → Automatic replies**. Admins and super users can add, edit, disable, or delete rules. Configuration starts empty: enter your actual deposit and bonus instructions before enabling them. Rules are stored under `chat_auto_responses` in the existing SQLite `app_settings` table; no new tables or external AI service are used.

`GET /api/chat-auto-responses.php` is public, takes no parameters, and returns up to **5 enabled suggestions** for your support layout:

```json
{
  "ok": true,
  "suggestions": [
    { "id": "example-rule-id", "label": "Claim bonus", "message": "Claim bonus" }
  ]
}
```

Render each `label` as a button. On click, append its `message` to the transcript as a `user` message and POST the transcript to `/api/chat-session.php`. Display a non-null `reply` as a bot message and include it in later snapshots. Deduplicate displayed replies by `autoReplyId`: retrying the same user transcript returns the same saved reply. Keep original message timestamps unchanged and include a timestamp on each new user message.

There is no fixed limit on the number of matching rules. Each enabled rule matches its label or any configured phrase **anywhere inside** the latest message, ignoring case and repeated whitespace. For example, phrases `claim bonus` and `how to get bonus` can share one response; `please explain how to get bonus today` will match. The longest matching phrase wins; ties use rule creation order. Matching is literal phrase matching, not semantic AI matching. Only a transcript ending with `sender: "user"` triggers a reply.

Example save response (IDs and timestamps are illustrative):

```json
{
  "ok": true,
  "sessionId": "player-vip_2026-09-24_001.json",
  "path": "chats/player-vip_2026-09-24_001.json",
  "messageCount": 2,
  "reply": {
    "sender": "bot",
    "text": "Your configured bonus instructions",
    "time": "2026-09-24T12:00:00+00:00",
    "type": "auto_response",
    "autoReplyId": "example-reply-id",
    "ruleId": "example-rule-id"
  }
}
```

**Management API:** authenticated admin/super-user cookies are required. `GET /api/chat-auto-responses.php?admin_action=list` returns `{ "ok": true, "rules": [...] }`. Save/delete use form-encoded POST to the same endpoint with CSRF and return the updated rules. Unauthorized requests return 403, invalid input or a sixth enabled suggestion returns 422, unsupported methods/actions return 405, and storage failures return 500.

| Parameter | Type | Required? | Default | Description |
| --- | --- | --- | --- | --- |
| `admin_action` | String | Yes for management | None (public GET suggestions) | `list` for GET; `save` or `delete` for POST. |
| `csrf_token` | String | Yes for POST | None | Current admin session CSRF token. |
| `id` | String | For delete/update | New generated ID on save | Omit on creation; use a returned rule ID to edit or delete. Unknown IDs are rejected. |
| `label` | String | For save | None | Nonblank button label and matching phrase, up to 120 bytes. |
| `keywords` | String | For save | None | Nonblank matching phrases, one per line, up to 10,000 bytes per rule. Returned rules represent these as an array. |
| `response` | String | For save | None | Nonblank automatic response, up to 5,000 bytes. |
| `enabled` | `0` or `1` | No | `0` | Enable matching; disabled rules are excluded from suggestions. |
| `suggested` | `0` or `1` | No | `0` | Include in suggestions when enabled. At most five enabled rules may have this flag. |

Saves replace all editable fields of the selected rule; send the flags explicitly when editing. The admin add form checks Enabled by default. Suggestion ordering follows rule creation order.

### Continuous chat and AI fallback

Admin Live Chat shows a player Online/Offline indicator in the list and conversation. Authenticated `GET /api/chat-session.php?admin_action=player_presence&session_ids[]=...` (requires `chat.view`) accepts up to 25 session IDs and returns `{"ok":true,"players":{"<session-id>":true}}`. Values are true (heartbeat within 75 seconds), false (offline), or null (legacy/missing session with no presence tracking). Invalid IDs or more than 25 entries return 422. The admin `messages` action also returns nullable `playerOnline`, including when `unchanged` is true. Presence polling is read-only and never marks a player online. Both the list and conversation refresh every four seconds while visible. Green dots mean online, red dots mean offline, and gray means unavailable. Presence reads time out after ten seconds and refresh when the admin reconnects.

JSON POST `/api/chat-session.php` now accepts `action=start|send|poll|heartbeat|disconnect`. `start` issues a random `sessionId` and secret `sessionToken`; all other actions require `session_id` and `session_token`. Send uses `message`, stable `client_message_id`, and per-tab `client_id`; heartbeat/disconnect also require `client_id`. Heartbeat accepts boolean `online` (default true); poll accepts optional `version`. Responses return server-owned `messages`, monotonic message `revision`, `version`, and presence/AI `status`. No new public PHP endpoint or database table is introduced.

Enabled keyword rules answer first. Otherwise the Ollama worker answers immediately when agents are offline, or after 600 seconds without an answer while an agent is online, provided the player's heartbeat is fresh (75 seconds). Human replies cancel pending AI; stale generation results are discarded. Responses from AI use `sender=bot`, `type=ai`. New protected sessions retain 200 messages, require a session token, and reject legacy writes. AI is not enabled for legacy sessions without tokens/presence.

WebSocket `/chat-ws` delivers snapshots through the Node relay with token authentication, an exact origin allowlist, heartbeat expiry, and reconnect support. Run both `admin/scripts/chat-ai-worker.php` and `admin/scripts/chat-socket/server.mjs`. The supplied `/chat-client.js` handles visitor sessions, sockets, and HTTP polling fallback. See [Chat integration](CHAT-INTEGRATION.md) for field/default tables, frontend examples, environment variables, rate limits, deployment commands, and Nginx configuration.

## Authenticated handlers

These are application endpoints, not public management APIs. POSTs below require authentication, the appropriate capability, and CSRF.

Admin pages load an activity-aware heartbeat from `/admin/session-renew.js`. It calls `POST /admin/session-renew.php` once per minute while the last browser activity is within the 30-minute inactivity window. It renews only still-valid login tokens; an expired token requires sign-in. Heartbeats alone do not advance the session's last-activity time, and the eight-hour maximum remains. Suspended browsers may miss renewal; failures display a notice instead of navigating away from unsaved work.

| Endpoint | Methods | Permissions and request |
| --- | --- | --- |
| `/admin/session-renew.php` | POST | Requires `session.renew` (all authenticated admin/editor roles) and form `csrf_token`, `idle_seconds` (required integer 0–1800; no default). Returns `ok`, `csrfToken`, `idleTimeout` (1800), `absoluteExpiresAt` (Unix seconds). Invalid input returns 422; invalid CSRF or expired/invalid authentication returns 403. |
| `/api/settings/seo.php` | GET, POST | GET returns `ok` and public `settings`. POST requires admin/super-user, form settings fields and `csrf_token`; returns saved settings and rotated `csrfToken`. |
| `/admin/blog-category-list.php`, `/admin/blog-tag-list.php` | POST | Editors may read taxonomy lists/counts. |
| `/admin/blog-category-save.php`, `/admin/blog-tag-save.php` | POST | Admin/super-user; `action=save/delete`, `id`, `name`, `slug`; categories also accept `parent_id`. |
| `/admin/games/save.php` | POST | Requires `slots.edit`; form `id`, `name`, `short_description`, `long_description`, `rtp`, `volatility`, and optional `is_viewable`. Protected import/processing fields cannot be edited here. Returns `ok` and `message` or `error`. Legacy `/admin/slot-save.php` remains supported. |
| `/admin/games/index.php` | GET, POST | Authenticated game list; POST requires `slots.edit` for featured/visibility changes using `slot_id`, the selected action/value, and CSRF. HTML/form handler; legacy `/admin/slots.php` remains supported. |
| `/admin/games/providers.php` | GET, POST | Admin/super-user provider approval management. Form `provider_key`, `approved`; bulk `action=uncheck_all` uses JSON-encoded `provider_keys`. HTML/form handler; legacy `/admin/settings/providers/` remains supported. |
| `/admin/games/settings.php` | GET, POST | Admin/super-user; form `enabled=0/1` controls the global Games switch. Returns an HTML settings page. |
| `/api/chat-session.php?admin_action=messages` | GET | Requires `chat.view`; `session_id`, optional `version`. Returns current messages or unchanged state. |
| `/api/chat-session.php` with `admin_action=reply` | POST | Requires `chat.reply`; form `session_id`, `message` (up to 5,000 bytes), and `csrf_token`. |
| `/api/chat-session.php` with `admin_action=presence` | POST | Requires `chat.reply`; form `client_id`, `online=1|0` (default offline), and `csrf_token`. Returns `ok` and aggregate `agentOnline`. Presence expires after 75 seconds. |

Other authenticated admin page/form flows are described in [AGENT.md](AGENT.md). Import, enrichment, sitemap, and runtime scripts under `admin/games/scripts/` are CLI utilities, not HTTP APIs.

## Read examples

```sh
curl 'http://localhost:8082/api/slot-list.php?count=20&page=1'
curl 'http://localhost:8082/api/game.php?slug=starlight-princess'
curl 'http://localhost:8082/api/provider-list.php?count=20'
curl 'http://localhost:8082/api/blog-post-list.php?count=10&page=1'
curl 'http://localhost:8082/api/blog-category-list.php'
curl 'http://localhost:8082/api/settings/seo.php'
```

- AI chat failure handling: `/api/chat-session.php` realtime POST responses and WebSocket snapshots omit `status.aiRetryAt`; failure counts, upstream errors and retry details stay internal. No failure message is saved to the transcript; normal chat reads/sends continue and the worker retries with backoff. Sanitized diagnostics are written only to the protected `CHAT_STORAGE_DIR/ai-errors.php` log (default `admin/chats/ai-errors.php`), with timestamp, hashed session reference, operation and safe reason/HTTP/cURL codes. No new inputs, flags, permissions or database tables.

## Game player counter

`POST /api/game-play.php` accepts JSON or form fields. Call when the user starts the game demo, not when loading cards or reading game details. No authentication is required. Counts represent unique browsers per game since deployment, not verified people, concurrent players, bets, or historical activity. Reusing the same visitor ID is idempotent; clearing browser storage or using a different device creates another visitor. Client-reported analytics can be manipulated.

| Parameter | Required | Default | Meaning |
| --- | --- | --- | --- |
| `slug` | Yes | None | Publicly eligible game's exact slug. |
| `visitor_id` | Yes | None | Random browser identifier, 32–80 letters/digits/hyphens/underscores. Persist and reuse it in localStorage; do not send a username. |

Returns `{"ok":true,"counted":true,"uniquePlayers":1}`. `counted` is false for an already counted browser/game pair. Errors: 405 wrong method, 422 invalid fields, 404 unavailable game, 429 over 60 requests/minute per direct client IP, 500 storage failure. Responses use no-store. Only hashed visitor identifiers are retained. Counts appear in the existing paginated admin game list. No changes to game-list/detail response shapes.

Frontend: run this inside the demo launch handler; a tracking failure must not prevent launching the game:

```js
try {
  let visitor = localStorage.getItem('game-visitor-id');
  if (!visitor) {
    visitor = crypto.randomUUID();
    localStorage.setItem('game-visitor-id', visitor);
  }
  fetch('/api/game-play.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({slug: game.slug, visitor_id: visitor}),
    keepalive: true
  }).catch(() => {});
} catch (_) { /* Storage unavailable: continue launching the game. */ }
```

Blog editor: new/reset forms and omitted `status` in `/admin/blog-save.php` default to `draft`. Explicit publishing/scheduling remains available. Editing a published post shows **Update**; saving as published updates the same live record and clears API caches immediately. **Save as Draft** explicitly unpublishes it. Existing open frontend pages must fetch again to display saved edits; this is not browser autosave or push refresh.
