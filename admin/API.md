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

Response outputs below list the returned field names and nested structures. Every value uses `[...]` as a placeholder (including strings, numbers, booleans, arrays of scalar values, and nullable values); these are schema illustrations, not executable JSON. Arrays of objects show one item schema and can contain zero or more items. Nullable objects such as `category`, `parent`, `reply`, `countdown`, and `faq_schema` may be absent in value (`null`). Conditional response variants are listed separately. `<session-id>` denotes a dynamic object key. Unless otherwise noted, JSON errors use `{"ok": [...], "error": [...]}`; some unsupported-method responses have no body.

| Endpoint | Methods | Access | Parameters | Response output | Purpose |
| --- | --- | --- | --- | --- | --- |
| `/game-api/slot-list.php` | GET, POST | Public | Optional: `count`, `page`, `random`, `search`, `provider`, `type`, `types`, `featured`, `progressive`, `upcoming`, `megaways`, `published`, `sort`. | `{"ok": [...], "slots": [{"id": [...], "apiId": [...], "name": [...], "slug": [...], "gameUrl": [...], "iframeUrl": [...], "thumbnail": [...], "shortDescription": [...], "longDescription": [...], "provider": {"name": [...], "slug": [...]}, "type": {"name": [...], "slug": [...]}, "themes": [...], "rtp": [...], "volatility": [...], "featured": [...], "progressive": [...], "published": [...], "upcoming": [...], "release": [...], "paylines": [...], "minBet": [...], "maxBet": [...], "maxWinPerSpin": [...], "updatedAt": [...]}], "pagination": {"total": [...], "count": [...], "page": [...], "totalPages": [...]}, "filters": {"search": [...], "provider": [...], "types": [...], "featured": [...], "progressive": [...], "megaways": [...], "upcoming": [...], "published": [...], "sort": [...], "random": [...]}}` | Paginated eligible games of all supported types. |
| `/game-api/provider-list.php` | GET | Public | Optional: `count` or `limit`; `sample` or `dev`. | `{"ok": [...], "providers": [{"id": [...], "apiId": [...], "name": [...], "slug": [...], "thumbnail": [...], "gameCount": [...], "updatedAt": [...]}], "total": [...], "count": [...], "limit": [...], "sample": [...]}` | Providers and eligible game counts. |
| `/game-api/game.php` | GET | Public | Required: `slug`. | `{"ok": [...], "game": {"id": [...], "apiId": [...], "name": [...], "slug": [...], "gameUrl": [...], "iframeUrl": [...], "thumbnail": [...], "shortDescription": [...], "longDescription": [...], "provider": {"name": [...], "slug": [...]}, "type": {"name": [...], "slug": [...]}, "themes": [...], "rtp": [...], "volatility": [...], "featured": [...], "progressive": [...], "published": [...], "upcoming": [...], "release": [...], "paylines": [...], "minBet": [...], "maxBet": [...], "maxWinPerSpin": [...], "updatedAt": [...]}}` | One eligible game by slug. |
| `/api/blog-post-list.php` | GET, POST | Public | Optional: `count`, `page`, `category`. | `{"ok": [...], "blogs": [{"id": [...], "slug": [...], "title": [...], "writer": {"name": [...], "role_name": [...], "profile_image": [...]}, "seoTitle": [...], "category": {"name": [...], "slug": [...], "parent_name": [...]}, "tags": [{"name": [...], "slug": [...]}], "author": [...], "excerpt": [...], "featuredImage": [...], "status": [...], "date": [...], "publishedAt": [...], "scheduledAt": [...], "startsAt": [...], "endsAt": [...], "isPublic": [...], "createdAt": [...], "updatedAt": [...], "views": [...], "likes": [...], "dislikes": [...], "readTime": [...]}], "pagination": {"total": [...], "count": [...], "page": [...], "category": [...], "totalPages": [...]}}` | Published blog list. |
| `/api/blog-category-list.php` | GET, POST | Public | None. | `{"ok": [...], "categories": [{"id": [...], "name": [...], "slug": [...], "parent_id": [...], "parent_name": [...], "description": [...], "postCount": [...]}], "total": [...]}` | Blog category hierarchy and counts. |
| `/api/blog.php` | GET | Public | Required: `slug`. | `{"ok": [...], "blog": {"id": [...], "seoTitle": [...], "focusKeyphrase": [...], "content": [...], "categoryId": [...], "categoryName": [...], "categoryLabel": [...], "tagIds": [...], "writerId": [...], "status": [...], "isPublic": [...], "date": [...], "scheduledAt": [...], "createdAt": [...], "views": [...], "likes": [...], "dislikes": [...], "countdownEnabled": [...], "countdownTarget": [...], "countdownTitle": [...], "countdownEndText": [...], "title": [...], "slug": [...], "category": {"name": [...], "slug": [...], "parent": {"name": [...], "slug": [...]}}, "tags": [{"name": [...], "slug": [...]}], "author": [...], "writer": {"name": [...], "role_name": [...], "profile_image": [...], "bio": [...], "socials": [{"platform": [...], "url": [...], "label": [...]}]}, "about_author": {"name": [...], "role_name": [...], "profile_image": [...], "bio": [...], "socials": [{"platform": [...], "url": [...], "label": [...]}]}, "excerpt": [...], "content_html": [...], "countdown": {"enabled": [...], "target": [...], "title": [...], "end_text": [...], "html": [...]}, "countdown_html": [...], "featuredImage": [...], "blogUrl": [...], "publishedAt": [...], "updatedAt": [...], "readTime": [...]}, "countdown": {"enabled": [...], "target": [...], "title": [...], "end_text": [...], "html": [...]}, "countdown_html": [...], "faq_schema": {"@context": [...], "@type": [...], "mainEntity": [{"@type": [...], "name": [...], "acceptedAnswer": {"@type": [...], "text": [...]}}]}}` | Published blog detail and rendered content. |
| `/api/blog-public-token.php` | GET, POST | Public | None. | `{"ok": [...], "token": [...], "expiresIn": [...]}` | Short-lived public blog JWT. |
| `/api/blog-engagement.php` | POST | Public | Required: `postId`, `action` (`view`, `like`, `dislike`). | `{"ok": [...], "ignored": [...], "counts": {"views": [...], "likes": [...], "dislikes": [...]}}`<br>Error: `{"ok": [...], "error": [...], "counts": {"views": [...], "likes": [...], "dislikes": [...]}}`; storage errors omit `counts`. | Record a view, like, or dislike. |
| `/api/playnow-click.php` | POST | Public | Optional: `pageUrl`, `pagePath`, `pageTitle`, `referrer`, `targetUrl`, `buttonText`, `buttonClass`, `buttonId`, `buttonTag`, `buttonName`, `section`, `selector`, `location`, `x`, `y`. | `{"ok": [...], "totalClicks": [...], "locationClicks": [...]}` | Record a Play Now click. |
| `/api/chat-session.php` | POST; GET for admin messages | Public transcript save; authenticated admin actions | Legacy save: required `messages[].text`; optional `name`, `contact`, `page_url`, `session_id`, `messages[].sender`, `messages[].time`, `messages[].type`. Realtime: `action`, plus action-specific `session_id`, `session_token`, `message`, `client_message_id`, `client_id`, `online`, `version`; see [chat contract](CHAT-INTEGRATION.md#http-contract). Admin: `admin_action`, action-specific `session_id`, `session_ids[]`, `message`, `client_id`, `online`, `version`; POST requires `csrf_token`. | Legacy save: `{"ok": [...], "sessionId": [...], "path": [...], "messageCount": [...], "reply": {"sender": [...], "text": [...], "time": [...], "type": [...], "autoReplyId": [...], "ruleId": [...]}}`<br>Realtime: `{"ok": [...], "sessionId": [...], "revision": [...], "messages": [{"sender": [...], "text": [...], "time": [...], "type": [...], "id": [...], "name": [...], "clientMessageId": [...], "adminReplyId": [...], "autoReplyId": [...], "ruleId": [...]}], "status": {"agentOnline": [...], "playerOnline": [...], "waitingSince": [...], "aiPending": [...], "aiEnabled": [...], "supportName": [...]}, "version": [...]}`<br>Start additionally returns `{"sessionToken": [...]}`. Unchanged poll additionally returns `{"unchanged": [...]}` and an empty `messages` array.<br>Admin messages: `{"ok": [...], "version": [...], "unchanged": [...], "playerOnline": [...], "messages": [{"sender": [...], "text": [...], "time": [...], "type": [...], "id": [...], "name": [...], "clientMessageId": [...], "adminReplyId": [...], "autoReplyId": [...], "ruleId": [...]}]}`<br>Admin reply: `{"ok": [...]}`<br>Admin presence: `{"ok": [...], "agentOnline": [...]}`<br>Player presence: `{"ok": [...], "players": {"<session-id>": [...]}}`. Message IDs, `name`, `clientMessageId`, `adminReplyId`, `autoReplyId`, and `ruleId` are conditional on sender/message type. | Save transcripts, read messages, or reply. |
| `/api/chat-auto-responses.php` | GET; POST for admin changes | Public suggestions; admin/super-user management | Public GET: none. Admin GET: `admin_action=list`. Admin POST: `admin_action` (`save` or `delete`), `csrf_token`; save requires `label`, `keywords`, `response`, with optional `id`, `enabled`, `suggested`; delete requires `id`. | Public GET: `{"ok": [...], "suggestions": [{"id": [...], "label": [...], "message": [...]}]}`<br>Admin list/save/delete: `{"ok": [...], "rules": [{"id": [...], "label": [...], "response": [...], "keywords": [...], "suggested": [...], "enabled": [...]}]}` | List up to five suggestions and manage unlimited automatic reply rules. |
| `/api/customer-ticket.php` | POST | Public | Required: `full_name`, `contact`, `topic`, `problem`. Optional: `page_url`. | Delivered: `{"ok": [...], "emailSent": [...], "mailer": [...]}`<br>Delivery unavailable: `{"ok": [...], "emailSent": [...], "warning": [...]}` | Save a support ticket and attempt email delivery. |
| `/api/settings/seo.php` | GET, POST | Public GET; admin/super-user POST | GET: none. POST: required `csrf_token`; settings fields: `website_name`, `website_url`, `google_analytics_id`, `google_search_console_verification`, `default_seo_title`, `default_meta_description`, `default_og_image`. | GET: `{"ok": [...], "settings": {"website_name": [...], "website_url": [...], "google_analytics_id": [...], "google_search_console_verification": [...], "default_seo_title": [...], "default_meta_description": [...], "default_og_image": [...], "indexnow_enabled": [...], "indexnow_configured": [...], "indexnow_key_location": [...], "indexnow_status": [...]}}`<br>POST: `{"ok": [...], "settings": {"website_name": [...], "website_url": [...], "google_analytics_id": [...], "google_search_console_verification": [...], "default_seo_title": [...], "default_meta_description": [...], "default_og_image": [...], "indexnow_enabled": [...], "indexnow_configured": [...], "indexnow_key_location": [...], "indexnow_status": [...]}, "csrfToken": [...]}` | Read or update site SEO settings. |

## Games

Game API implementations live under `admin/plugin/games/api/` (canonical provider-list.php) and `game-api/`; use the `/game-api/` URLs above (the previous `/api/` paths remain compatible, forwarding to the plugin implementation and returning 404 if the plugin is uninstalled). Game data lives in `APP_STORAGE_DIR/games.sqlite`, defaulting to `admin/storage/games.sqlite`.

Public games require the Games module to be enabled, `published=1`, `done_processing=1`, `is_viewable=1`, an approved provider, a valid public slug, and no exact PH/PHILIPPINES restriction. Filters cannot bypass these rules.

Disabling **Games → Games Settings** makes game/provider lists empty and game details return 404. Provider sample mode cannot bypass the switch. Admin editing remains available.

### Game list

`GET` or form `POST /game-api/slot-list.php`

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

Random examples: `/game-api/slot-list.php?random=1` returns one random game by default; `/game-api/slot-list.php?random=1&count=5` returns up to five. Random mode preserves all eligibility and search/provider/type filters, selects without replacement within one response, ignores `page` and `sort`, and returns `filters.random=true` with `filters.sort="random"`. The response keeps the usual `slots` array and pagination structure; `page` and `totalPages` are 1, while `total` is the number of matching eligible games. Empty matches return `slots: []`. Results can repeat across requests. Random requests bypass the file cache and send `Cache-Control: no-store` and `X-API-Cache: BYPASS`. Normal listing defaults and caching remain unchanged. The flag also works with form POST.

### Providers

`GET /game-api/provider-list.php`

Optional `count` or `limit`: 1–1000. `sample` or `dev` enables sample rows when Games is enabled. Returns `ok`, `providers`, `total`, `count`, `limit`, and `sample`. Provider records include ID/slug, API ID, name, thumbnail, eligible game count, and update time. Cache TTL: 300 seconds.

Checked/approved providers from provider settings are included even when none of their games are currently eligible for public demos; those rows have `gameCount: 0` and retain their provider thumbnail. Unchecked providers stay excluded. Game counts still apply all publication, processing, visibility, slug, and PH restrictions. Disabling the Games module returns an empty list. Providers with games sort ahead of zero-count providers; ties sort by name. The optional count/limit is applied after sorting.

Provider images are returned in `providers[].thumbnail`. After syncing, this is a local public path such as `/uploads/providers/provider-123-<source-hash>.png`, suitable for an image `src` on the same site (prepend your API origin when using a different frontend origin). Providers without an available image return an empty string; previously stored remote URLs remain until successfully localized. Sample mode retains its placeholder images. Provider slugs from eligible games are preserved when they differ from the normalized provider name.

SlotsLaunch's `thumb` is the preferred source field when creating or updating providers, including nested provider objects in PHP game imports. If `thumb` is absent or blank, the importer checks `thumbnail`, `image`, `logo`, then `icon`. The public response field remains `thumbnail`.

Run `php admin/plugin/games/scripts/sync-providers.php` to refresh provider metadata from SlotsLaunch and download thumbnails to `uploads/providers`. It uses the existing provider importer and token configuration, follows provider API pagination, reuses valid downloaded files, preserves previous thumbnails on download failure, and clears API caches after each page. The command does not enable providers or change game publication/processing status. See [Scripts guide](scripts/README.md#sync-providersphp) for options.

### Game detail

`GET /game-api/game.php?slug=example-game`

Returns `{"ok":true,"game":{...}}` using the shared game fields. Detail page, iframe, and thumbnail URLs are formatted as absolute URLs using site configuration. Invalid, hidden, unfinished, restricted, or disabled games return 404. Response is not cached.

## Blogs

### Blog list and categories

`GET` or form `POST /api/blog-post-list.php`: optional `count` (1–100, default 10), `page` (default 1), and `category`. Category IDs, slugs, and legacy names are resolved by shared taxonomy helpers; a parent category includes descendants. Returns `ok`, `blogs`, and `pagination`. Only published content is listed; public writer fields do not include account credentials or permissions.

`GET` or `POST /api/blog-category-list.php`: no required fields. Returns `ok`, `categories`, and `total`, including category hierarchy and post counts.

### Blog detail and token

`GET /api/blog.php?slug=example-post`: returns `ok`, `blog`, and `faq_schema`; the blog includes `content_html`, writer details, category/tags, URLs, dates, and reading time. Invalid, draft, or future-scheduled content returns 404.

`content_html` omits generated formatting newlines between blocks and compacts the bundled stylesheet. Visible Markdown line breaks still use `<br>`; preformatted code and trusted custom-code line breaks are preserved and may still appear as `\n` in JSON. No request or response fields change.

Rendered tables fill the content width and wrap cell text, with horizontal overflow contained in the table wrapper. Demo blocks use a responsive player frame with a non-heading title and internal toolbar (View Game, Play for Real, Fullscreen/Exit fullscreen); fullscreen includes the toolbar. The external game's iframe contents are unchanged. FAQs use native, initially collapsed `details`/`summary` controls; all answers remain in `faq_schema`. The response fields, inputs and public access are unchanged.

`blog.about_author` contains the selected writer's full public profile: `name` (written name, falling back to display name), `role_name`, `profile_image`, `bio`, and `socials` (ordered objects with `platform`, `url`, and `label`). It reflects the current profile selected by the post's `writer_id`; `blog.writer` retains the same fields for compatibility. Without a selected writer, the legacy author name (or `Editorial Team`) is used with empty profile fields and an empty social list. Account credentials and administrative fields are excluded. Access remains public; no new request parameters are required.

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

Enabled keyword rules answer first. Otherwise the Ollama worker waits 180 seconds for the initial unanswered turn regardless of agent availability, provided the player's heartbeat is fresh (75 seconds). After a successful AI reply, follow-ups have no added wait until a human replies; generation, worker queue time and error backoff still apply. Human replies cancel pending AI; stale generation results are discarded. Responses from AI use `sender=bot`, `type=ai`, and `name` (configured display name); status includes `supportName`. Set the name in admin Settings → AI Chat Name (default `Alex`, existing `app_settings.chat_ai_name` key). No AI handoff notice is added. New protected sessions retain 200 messages, require a session token, and reject legacy writes. AI is not enabled for legacy sessions without tokens/presence.

WebSocket `/chat-ws` delivers snapshots through the Node relay with token authentication, an exact origin allowlist, heartbeat expiry, and reconnect support. Run both `admin/scripts/chat-ai-worker.php` and `admin/scripts/chat-socket/server.mjs`. The supplied `/chat-client.js` handles visitor sessions, sockets, and HTTP polling fallback. See [Chat integration](CHAT-INTEGRATION.md) for field/default tables, frontend examples, environment variables, rate limits, deployment commands, and Nginx configuration.

## Authenticated handlers

These are application endpoints, not public management APIs. POSTs below require authentication, the appropriate capability, and CSRF.

Admin pages load an activity-aware heartbeat from `/admin/session-renew.js`. It calls `POST /admin/session-renew.php` once per minute while the last browser activity is within the three-hour inactivity window. It renews only still-valid login tokens; an expired token requires sign-in. Heartbeats alone do not advance the session's last-activity time, and the eight-hour maximum remains. Suspended browsers may miss renewal; failures display a notice instead of navigating away from unsaved work.

| Endpoint | Methods | Permissions and request |
| --- | --- | --- |
| `/admin/session-renew.php` | POST | Requires `session.renew` (all authenticated admin/editor roles) and form `csrf_token`, `idle_seconds` (required integer 0–10800; no default). Returns `ok`, `csrfToken`, `idleTimeout` (10800), `absoluteExpiresAt` (Unix seconds). Invalid input returns 422; invalid CSRF or expired/invalid authentication returns 403. |
| `/api/settings/seo.php` | GET, POST | GET returns `ok` and public `settings`. POST requires admin/super-user, form settings fields and `csrf_token`; returns saved settings and rotated `csrfToken`. |
| `/admin/blog-category-list.php`, `/admin/blog-tag-list.php` | POST | Editors may read taxonomy lists/counts. |
| `/admin/blog-category-save.php`, `/admin/blog-tag-save.php` | POST | Admin/super-user; `action=save/delete`, `id`, `name`, `slug`; categories also accept `parent_id`. |
| `/admin/plugin/games/save.php` | POST | Requires `slots.edit`; form `id`, `name`, `short_description`, `long_description`, `rtp`, `volatility`, and optional `is_viewable`. Protected import/processing fields cannot be edited here. Returns `ok` and `message` or `error`. Legacy `/admin/slot-save.php` remains supported. |
| `/admin/plugin/games/index.php` | GET, POST | Authenticated game list; POST requires `slots.edit` for featured/visibility changes using `slot_id`, the selected action/value, and CSRF. HTML/form handler; legacy `/admin/slots.php` remains supported. |
| `/admin/plugin/games/providers.php` | GET, POST | Admin/super-user provider approval management. Form `provider_key`, `approved`; bulk `action=uncheck_all` uses JSON-encoded `provider_keys`. HTML/form handler; legacy `/admin/settings/providers/` remains supported. |
| `/admin/plugin/games/settings.php` | GET, POST | Admin/super-user; form `enabled=0/1` controls the global Games switch. Returns an HTML settings page. |
| `/api/chat-session.php?admin_action=messages` | GET | Requires `chat.view`; `session_id`, optional `version`. Returns current messages or unchanged state. |
| `/api/chat-session.php` with `admin_action=reply` | POST | Requires `chat.reply`; form `session_id`, `message` (up to 5,000 bytes), and `csrf_token`. |
| `/api/chat-session.php` with `admin_action=presence` | POST | Requires `chat.reply`; form `client_id`, `online=1|0` (default offline), and `csrf_token`. Returns `ok` and aggregate `agentOnline`. Presence expires after 75 seconds. |

Other authenticated admin page/form flows are described in [AGENT.md](AGENT.md). Import, enrichment, sitemap, and runtime scripts under `admin/plugin/games/scripts/` are CLI utilities, not HTTP APIs.

## Read examples

```sh
curl 'http://localhost:8082/game-api/slot-list.php?count=20&page=1'
curl 'http://localhost:8082/game-api/game.php?slug=starlight-princess'
curl 'http://localhost:8082/game-api/provider-list.php?count=20'
curl 'http://localhost:8082/api/blog-post-list.php?count=10&page=1'
curl 'http://localhost:8082/api/blog-category-list.php'
curl 'http://localhost:8082/api/settings/seo.php'
```

- AI chat failure handling: `/api/chat-session.php` realtime POST responses and WebSocket snapshots omit `status.aiRetryAt`; failure counts, upstream errors and retry details stay internal. No failure message is saved to the transcript; normal chat reads/sends continue and the worker retries with backoff. Sanitized diagnostics are written only to the protected `CHAT_STORAGE_DIR/ai-errors.php` log (default `admin/chats/ai-errors.php`), with timestamp, hashed session reference, operation and safe reason/HTTP/cURL codes. No new inputs, flags, permissions or database tables.

## Game player counter

`POST /game-api/game-play.php` accepts JSON or form fields. Call when the user starts the game demo, not when loading cards or reading game details. No authentication is required. Counts represent unique browsers per game since deployment, not verified people, concurrent players, bets, or historical activity. Reusing the same visitor ID is idempotent; clearing browser storage or using a different device creates another visitor. Client-reported analytics can be manipulated.

| Parameter | Required | Default | Meaning |
| --- | --- | --- | --- |
| `slug` | Yes | None | Publicly eligible game's exact slug. |
| `visitor_id` | Yes | None | Random browser identifier, 32–80 letters/digits/hyphens/underscores. Persist and reuse it in localStorage; do not send a username. |

Returns `{"ok":true,"counted":true,"uniquePlayers":1}`. `counted` is false for an already counted browser/game pair. Errors: 405 wrong method, 422 invalid fields, 404 unavailable game, 429 over 60 requests/minute per direct client IP, 500 storage failure. Responses use no-store. Only hashed visitor identifiers are retained. Counts appear in the existing paginated admin game list. The detail response also exposes `uniquePlayers`; the list response is unchanged.

Frontend: run this inside the demo launch handler; a tracking failure must not prevent launching the game:

```js
try {
  let visitor = localStorage.getItem('game-visitor-id');
  if (!visitor) {
    visitor = crypto.randomUUID();
    localStorage.setItem('game-visitor-id', visitor);
  }
  fetch('/game-api/game-play.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({slug: game.slug, visitor_id: visitor}),
    keepalive: true
  }).catch(() => {});
} catch (_) { /* Storage unavailable: continue launching the game. */ }
```

Blog editor: new/reset forms and omitted `status` in `/admin/blog-save.php` default to `draft`. Explicit publishing/scheduling remains available. Editing a published post shows **Update**; saving as published updates the same live record and clears API caches immediately. **Save as Draft** explicitly unpublishes it. Existing open frontend pages must fetch again to display saved edits; this is not browser autosave or push refresh.

Chat message formatting: `POST /api/chat-session.php` preserves internal spaces, line breaks and blank lines in legacy transcript `messages[].text`, realtime `action=send` message, and authenticated `admin_action=reply` message. CRLF/CR normalize to LF; outer whitespace is trimmed, unsafe control characters removed, and the existing 5,000-character limit remains. AI and saved automatic replies use the same normalization. GET admin messages and realtime snapshots return plain text; permissions, CSRF, tokens and response fields are unchanged. Live Chat uses `/chat-message.js` for safe clickable HTTP(S)/Markdown links; the public `/chat-client.js` re-exports `renderChatMessage` for widget integration. No new flags or tables. Previously collapsed whitespace cannot be restored.

### Sport / Competition API layout builder

- Admin/super-user GET `/admin/plugin/odds-api/sports/` edits iframe 1; `?id=N` selects a saved layout, `?new=1` creates a draft. The paginated list and builder live under Settings. `/admin/plugin/odds-api/` and `/admin/plugin/odds-api/livescore/` authenticate and redirect; the standalone navigation/editor is removed. Editors are denied server-side.
- Form POST `/admin/plugin/odds-api/sports/` requires session/JWT/CSRF. Inputs: `action=save|preview`, `id` (0 creates), `name` (required, 200 bytes), `api_url` (required HTTP(S), max4096), `enabled=0|1`, `root_path` (default `$`), `columns_per_row=1|2|3|4`, `layout_config` (JSON list). Migrated Odds layouts also accept `markets`, `bookmakers` and optional preview `sport`. Save returns 303 to the saved ID; preview returns `{ok,fields,preview}` with discovered paths/types and projected values. Errors: 403 auth/CSRF, 404 missing config, 422 validation, 429 shared admin rate limits, 503 storage/source unavailable. Preview can fetch while disabled, without saving configuration.
- Nodes use unique `id`, `type=field|loop|button|link|text|image`, JSON-style `path`, editable `label`, `visible`. Fields add `role=data|title|description`, `format=text|decimal|date|datetime`, `fallback` (blank skips missing), optional `url`. Loops add `columns=1..4`, `direction=grid|row`, `children`. Buttons/links add `url` (fixed or dynamic `{$.id}`), `style=primary|gold|brand|secondary|outline`, `target=_blank|_self`. Text blocks add `text`, `role=data|title|description|badge`. Image blocks add `url`, `alt`, `width`, `height`. Nesting/order are persisted directly in JSON; no hardcoded bookmaker renderer. Paths begin `$`, object members use dots or quoted brackets, arrays use `[]`; selected root records become `$` for field resolution. Maximum120 blocks/depth8.
- Public GET `/api/sports-layout.php?id=N&sport=KEY` (ID defaults1; sport optional) returns `{ok,enabled,title,columns,records,sports,sport,updatedAt,truncated}`. Records contain resolved field values, buttons, links, images, or nested loop items, never API URLs or unused raw fields. Disabled configurations return empty records without fetching. Error JSON `{ok:false,error}` uses 405 wrong method, 422 bad input/path, 404 missing layout, 429 60/minute/direct-IP cap, 503 source/storage failure. Public `/odds-api/?iframe=N&sport=KEY` embeds consume this endpoint and shared renderer with luxury dark theme styling; labels/values are text. Responsive grids and flex rows adapt on narrow screens.
- Additive migration in existing `sports-livescore.sqlite.iframes`: `api_url`, `enabled`, `root_path`, `columns_per_row`, `layout_config`, `catalog_url`, `item_limit`, `bookmakers`; preserves IDs, title, markets, old blocks/timestamps and `matches`. Existing fields/labels/roles/order migrate once to nested loops. `sport_api_cache(cache_key,payload,fetched_at,expires_at)` holds only expiring source responses. Provider credentials stay in `odds-api.sqlite`; generic API URLs may contain credentials, so protect both databases. No new environment flags.
- Generic fetching validates public HTTP(S) URLs/ports80/443, DNS addresses and pinned resolution; blocks private/reserved networks, redirects and proxies; verifies TLS; caps response2MB with connect5s/transfer15s timeouts. Hashed source cache uses300s successes/60s failures and locked fills; expired entries purge during fetch. Logs contain only a source hash. Discovery unions up to100 sampled items/array, max500 paths/depth12. Projection caps200 root records,100 items/loop and20000 blocks. Missing optional values skip safely; zero/false survive.
- Migrated Odds layouts retain saved provider URL/catalogue templates and sport metadata; provider Settings still accepts `action=save_odds_api`, `enabled`, `apiKey` (blank retains), `regions`, optional `clear_api_key=1` (requires disabled). Both global and per-layout switches gate migrated public output. Markets and bookmakers remain per layout. Saved `{apiKey}` and `{bookmakers}` expansions are supported.
- Compatibility: public GET `/api/odds-api.php?action=sports|odds&iframe=N&sport=KEY` keeps its legacy `{ok,enabled,data,updatedAt,layout}` raw feed and legacy block snapshot for existing integrations; the generic builder/iframe use `/api/sports-layout.php`. Legacy feed caches remain sports3600s/odds300s/failures60s. Do not use the legacy feed as the generic layout API.
- Public GET `/odds-api/sports.php` (legacy `/api/sports.php` remains an adapter, along with compatibility query `/api/sports-layout.php?action=sports`) returns the full Sports Registry tree grouped by sports category, then tournament, with all associated generated iframe layouts: `{ok, categories, layouts, total_categories, total_tournaments, total_layouts}`. Query filters: `?category=SLUG|ID`, `?sport=SPORT_KEY`, `?only_active=1`, `?view=layouts` (or `?layouts_only=1`), `?view=categories` (or `?tree_only=1`). Each category lists its enabled tournaments (`tournaments[]`), and each tournament includes its generated iframe layouts (`layouts[]`). The root `layouts[]` lists saved iframe layouts belonging to the same eligible tournaments, with their category and tournament details, embed URL (`/odds-api/?iframe=N`), iframe embed HTML code, and data API URL (`/api/sports-layout.php?id=N`).
- Guide: [Sport / Competition layouts](plugin/odds-api/sports/README.md). Tests: `php admin/scripts/test-sports-api.php`, `php admin/scripts/test-odds-api.php`.

### Optional plugins

Games lives in `admin/plugin/games/`; Odds API (provider settings, API services, sports editor, saved-layout storage helper and public renderer) lives in `admin/plugin/odds-api/`. Both appear in the admin header only when installed. Provider credentials are managed at `/admin/plugin/odds-api/settings.php` (GET/POST, admin/super-user, CSRF on POST; enabled/apiKey/clear_api_key/regions fields; bookmakers is managed in the per-layout editor). General Settings no longer loads either plugin's settings.

Public API methods, inputs and payloads remain unchanged: game list/detail/provider APIs and POST `/game-api/game-play.php` delegate to the Games plugin; GET `/api/odds-api.php` and GET `/api/sports-layout.php` delegate to Odds API. Missing plugins return JSON 404 `Plugin unavailable.` at these public adapters. Existing `/odds-api/?iframe=N` embeds remain supported. Apache rewrites retain old `/admin/games/`, `/admin/odds-api/`, `/admin/settings/sports/` and `/admin/sports-livescore/` bookmarks and old public asset URLs. Plugin includes/scripts and Markdown documentation remain blocked over HTTP.

Remove the corresponding plugin folder to uninstall its code; stop its scheduled jobs first. Databases stay in the existing shared storage directory (or `APP_STORAGE_DIR`) for reinstall/backup. No flags, tables, migrations or stored records are added or deleted by this reorganization. Main blog/settings bootstrap and indexing tolerate an absent Games plugin. See [plugin guide](/admin/plugin/README.md).

- Blog image links: public `GET /api/blog.php?slug=...` preserves linked images in `blog.content_html` as `<a href="..."><img ...></a>`, including `rel="noopener noreferrer nofollow"` when the saved Markdown link has `{nofollow}`. Root-relative and absolute HTTP(S) image URLs are supported; relative image/link URLs become absolute as usual. Images without links stay unlinked and follow links do not acquire nofollow. Image attributes are protected from subsequent Markdown formatting; unsafe URL schemes remain unsupported. Methods, permissions, response keys and stored data are unchanged. Regression: `php admin/scripts/test-public-details.php` exercises the API output.

### Root Games API folder

The four public game endpoints now live in root `game-api/`: GET/form POST `slot-list.php`, GET `provider-list.php`, GET `game.php`, and POST `game-play.php`. Inputs, JSON outputs, public eligibility, rate limits and access permissions are unchanged. `/api/slot-list.php`, `/api/provider-list.php`, `/api/game.php` and `/api/game-play.php` are compatibility wrappers; POST bodies and query parameters pass through without HTTP redirects. The API depends on the Games plugin for its catalogue/helpers and returns JSON 404 `Plugin unavailable.` if the plugin is removed. No flags, tables or stored records change.

### Sports image enrichment

- Admin/super-user GET `/admin/plugin/odds-api/registry/images.php?tournament=ID` shows previews, source/attribution records and unresolved matches. Authenticated form POST requires CSRF, `action=preview|enrich`, optional `retry=1`; each batch considers up to five missing images and returns HTML results. Sources: SportsDataverse followed by Wikidata P154 / Commons; no provider key required. Manual and existing images are preserved.
- CLI: `php admin/plugin/odds-api/scripts/enrich-sports-images.php [--tournament=ID_OR_ODDS_KEY] [--limit=5] [--dry-run] [--retry]`. HTTP access to scripts remains blocked. Tests: `php admin/plugin/odds-api/scripts/test-sports-images.php`.
- New `sports_image_assets` table in `sports-registry.sqlite` stores status, source and attribution; participant image paths update existing tables. Images live at `/uploads/sports/`. Saved tournament logos take precedence over category fallback in existing iframe image resolution; public JSON fields are unchanged.
- Public GET `/odds-api/?credits=1&credits_page=1` returns HTML image attribution, 50 entries/page, with source/license links and no credentials. The iframe links to it. See [Odds API image guide](plugin/odds-api/README.md#free-sports-images).

- Daily image runner: `admin/plugin/odds-api/scripts/daily-sports-images.php` (CLI-only), flags `--tournament`, `--max-items=1000`, `--max-seconds=1800`, `--batch-size=25`, `--pause-ms=1000`. Exclusive storage lock and private rotating log; no scheduler is installed automatically. Uses existing image tables and preserves manual/existing logos. See the [aaPanel job guide](plugin/odds-api/README.md#dailybackground-image-job-aapanel).
- Iframe image fix: existing `/api/sports-layout.php` image blocks now return same-origin local registry paths so a placeholder or different canonical site URL cannot prevent rendering. Missing registry images return an empty URL rather than a team/sport name. The editor uses the primary participant registry and saved tournament logos; iframe CSP permits HTTPS custom images. No new response fields/tables. Tests: `test-sports-images.php`, `test-sports-images-daily.php` and `test-iframe-images.cjs` under the Odds API plugin's scripts folder.

### Shared default image

`/admin/settings.php` accepts admin/super-user multipart POST with CSRF and `action=save_default_image`, file `default_image` (PNG/JPEG/WebP/AVIF, 10 MB maximum, validated dimensions), or `action=clear_default_image`. Returns HTML status. Uses existing `app_settings.default_image`; no new tables. Uploads are stored under `/uploads/blogs/site-default-…` and mirrored in admin uploads for existing routing. Saving/clearing invalidates blog API caches.

Blog list/detail `featuredImage` resolves blank/legacy defaults and missing local blog files to the configured image; real images and stored blog content remain unchanged. Blog editor previews and the Games admin missing-thumbnail display use the same fallback. Sports iframe/editor image blocks use the configured fallback for absent images, and expose `fallback_url` for a one-time browser load-error fallback. It never writes defaults into sports registry records or marks images enriched. Removing the setting restores the built-in blog default and prior empty sports fallback. Tests: `php admin/scripts/test-default-image.php` and the Odds API iframe browser regression.

Blog detail metadata: public `GET /api/blog.php?slug=...` additionally returns `blog.id`, `seoTitle` (falls back to title), `focusKeyphrase`, original `content`, `categoryId`, `categoryName`, `categoryLabel`, `tagIds`, `writerId`, `status`, `isPublic`, `date`, `scheduledAt`, `createdAt`, integer `views`/`likes`/`dislikes`, and `countdownEnabled`/`countdownTarget`/`countdownTitle`/`countdownEndText`. Existing rendered HTML, public writer profile, taxonomy, timestamps, countdown and FAQ fields remain available. Draft/future posts still return 404. Admin input helpers and private user records are excluded. No new parameters, permissions or database changes.

Admin authentication uses a three-hour inactivity window and three-hour access tokens, extended by authenticated requests within the unchanged eight-hour absolute login limit. PHP session storage and its cookie are retained for eight hours. Token signatures, session binding, account revocation and CSRF checks remain required; already expired tokens require a fresh login. No new request fields or database changes.

Sports-list visibility: `GET /odds-api/sports.php` and legacy `/api/sports.php` always require both category and tournament `enabled=1`, including flat `view=layouts` output. `all` and `include_disabled` no longer bypass public visibility. Layouts with no registered tournament are omitted. Category ID/slug, sport key, optional provider-active filter, empty-category option and response views remain supported; all lists and totals use the same eligible set. Registry toggle changes apply on the next request (`Cache-Control: no-store`); no hardcoded league list. Public listings require enabled layouts as well. No database changes.

Sports listing additionally requires at least one enabled saved layout for each tournament. Enabled tournaments without layouts are omitted; tournament/category totals reflect this. Categories with no eligible tournaments are hidden by default (`include_empty=1` may still return an empty category). Public listings require enabled layouts as well. No response fields or database changes.

Sports-list layout `created_at`/`updated_at` fields normalize both Unix seconds and legacy SQLite UTC datetime strings to integer Unix timestamps. Previously, casting a datetime string returned only its year, which could break consumer change detection. No schema or response-key changes.

`/odds-api/sports.php` (and its compatibility routes) respects layout-page status as well as registry status: category ON + tournament ON + layout ON are all required. Switching off the last enabled layout removes its tournament and, by default, an empty category. Saved records remain intact; toggles take effect on the next request.

### Sports layout download and import

Admin/super-user `GET /admin/plugin/odds-api/sports/?action=download&id=ID|all` downloads a version-1 `sports-layouts` JSON file with layout titles, sport keys, API URL templates, root paths, columns, item limits, blocks, markets and bookmakers. Runtime data, installation IDs and provider settings are excluded; common credential query parameters are replaced with `{apiKey}`. Each saved row has Download; Download all exports all saved layouts.

Authenticated multipart `POST` to the same page requires CSRF, `action=import` and `layout_file` (JSON, maximum 5 MB, 1–1000 layouts). Validation completes before writes; insertion is transactional. Imports always create new IDs switched OFF, preserving existing records. Success redirects to the first imported layout; invalid files return HTML errors (422). Review imported layouts, configure destination provider credentials and register the matching tournaments before enabling. Uses the existing `iframes` table; no schema changes. Regression: `php admin/scripts/test-sports-api.php`.

Local Docker Nginx supports `/promo-api/*` as an internal alias for `/api/*` on the same project. Methods, parameters, authentication and response formats are those of the destination handler. No forwarding to sibling deployments or database changes.


Automatic replies are limited to once per rule per chat session for matching phrases. Sending an exact enabled suggested-chat label (ignoring case and repeated whitespace) takes priority and can repeat that reply. Used rule IDs persist in session JSON as `autoReplyRuleIds`, including after the 200-message history is trimmed; existing sessions recover usage from saved automatic replies. Retries remain idempotent. Repeated keywords leave realtime chat pending for the existing AI fallback and its availability/timing rules; legacy transcript saves return `reply: null` without starting AI. Endpoint methods, permissions, inputs and response fields are unchanged; no new tables or configuration flags.


### Removed compatibility files (October 2026)

Game handlers now use `/game-api/{slot-list,provider-list,game,game-play}.php` and `/admin/plugin/games/` (`index.php`, `edit.php`, `save.php`). Removed `/api/` game forwarders and `/admin/slot*.php` are no longer supported. Sports layout reads use public `GET /odds-api/sports-layout.php?id=ID`; sports listings use `/odds-api/sports.php`. Methods, permissions, inputs and response shapes on surviving endpoints are unchanged. The public iframe and generated layout URLs use the canonical sports route.

Shared `public_detail_slug()` and `public_detail_url()` live in `admin/includes/public-detail.php`, which loads optional game helpers and retains the absent-plugin fallback. Core/bootstrap and game import/sitemap code no longer require the deleted `admin/includes/game-*.php` wrappers. No flags or database schema changes. Regression tests target surviving routes.


### Game inner-page detail

Use public `GET /game-api/game.php?slug=example-game` as the game equivalent of `/api/blog.php`. No authentication is required. It returns `{ "ok": true, "game": { ... } }` and retains all existing fields. Errors remain 404 for missing/invalid/ineligible games, 405 for non-GET methods, and 500 for storage failures. Responses are `no-store`. PH restrictions, provider approval, global enablement, publication, processing and visibility are enforced. Removed `/api/game.php` is not recreated.

Additional `game` fields:

- `content_html`: sanitized long description ready to render; raw `longDescription` remains available. Plain-text newlines are preserved. Render `content_html`, not unsanitized raw content.
- `provider.id`, `type.id`: nullable local catalogue IDs.
- `megaways`, `bonusBuy`, `landBased`, `autoplay`, `quickspin`, `tumblingReels`, `increasingMultipliers`: booleans.
- `reels`, `maxExposure`, `orientation`: saved strings; empty means unspecified.
- `currencies`, `languages`, `markets`: arrays; `createdAt`: Unix timestamp (0 if unavailable).
- `seo`: `title` from the game name, `description` from the plain short description, `canonicalUrl`, and `image`. These are derived, not separately stored SEO overrides.
- `uniquePlayers`: count from this site's `game_players` table; `playerCountMetric: "unique_browsers_on_this_site"`. Call existing `POST /game-api/game-play.php` with `slug` and a stable `visitor_id` when the visitor actually starts the demo. Reading details never increments it.
- `slotsLaunchPlays`: `{ "count": 1234, "metric": "plays", "source": "slotslaunch", "scope": "network", "syncedAt": 1791580800, "stale": false }`. `count` is null before sync or when a game is absent from the rankings. `syncedAt` is the local snapshot timestamp, not the provider's measurement period; `stale` becomes true after 24 hours. SlotsLaunch documents `times_played`, not unique users or a guaranteed all-time period. Never label this value as unique users.

Source: [SlotsLaunch Rankings API](https://docs.slotslaunch.com/article/12-api-endpoints). Counts come from the network rankings without a `host` filter. Public requests only read local data; they never call SlotsLaunch.

Refresh rankings from the `www` directory with:

```sh
php admin/plugin/games/scripts/sync-slotslaunch-rankings.php
```

Uses existing `SLOTSLAUNCH_API_TOKEN` (or existing token aliases), `SLOTSLAUNCH_ORIGIN` (registered website origin, falling back to a valid `SITE_BASE_URL`), and optional `SLOTSLAUNCH_API_SECRET` for signed requests. Keep the secret server-side. Schedule daily using your existing task runner. No scheduler is installed. Validated responses atomically replace `APP_STORAGE_DIR/slotslaunch-rankings.json`; fetch/validation failures preserve the previous snapshot. No new database tables or columns. Tests: `php admin/scripts/test-game-detail.php` and `php admin/scripts/test-game-players.php`.
