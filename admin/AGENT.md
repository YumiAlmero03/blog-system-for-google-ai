## Rules

* Stack: PHP + HTML5 + SQLite.
* Make the smallest necessary change.
* Read [README.md](README.md) for the documentation index; update it whenever maintained documentation is added, renamed, moved, or removed.
* Whenever an API is added, changed, renamed, or removed, update both this `AGENT.md` and [API.md](API.md) in the same change. Record methods, permissions, inputs, outputs, compatibility paths, and relevant behavior.
* Inspect only task-relevant files; expand only if needed.
* Do not modify or refactor unrelated code.
* Preserve existing HTML, classes, CSS variables, typography, grids, styling, and responsive behavior.
* Reuse existing components and patterns.
* Do not add dependencies unless necessary.
* Run only relevant checks/tests.
* Keep responses concise.
* Admin data tables must have pagination. Preserve existing server/API pagination; for otherwise unpaginated tables use `data-paginate` and shared `/admin/table-pagination.js` (25/50/100 rows, page jump, previous/next, totals). The admin header loads it. Provider search filters all loaded rows before pagination, and bulk provider actions retain their existing scope. Use `AdminTables.setRows(table, rows, escapedRowRenderer)` for dynamic reports so only the current page is rendered. Content-editor tables are authored content, not admin record lists.
* `/game-api/provider-list.php` includes approved providers represented in provider settings even with no publicly eligible demos (`gameCount: 0`). Preserve thumbnails and approval filtering; only the count applies all public game eligibility checks. The global Games switch still hides the whole list. Do not hide an approved provider solely because its game count is zero.
* Update this md and any md related if you have created new api, script or any related to mds

## Core Pages

* `*/index.html` files are the original Google AI-generated core/main pages.
* Treat them as the primary visual and structural reference.
* Preserve their layout, classes, styling, and responsive behavior when converting or extending them with PHP.
* Do not redesign these pages unless explicitly requested.

## Security

* Use PDO prepared statements.
* Escape untrusted output with `htmlspecialchars()`.
* Preserve authentication, validation, and access controls.

## Structure

* `/admin` — admin
* `/api` — API
* `/blog` — blog
* `/game` — games
* `/includes` — shared components/database
* `/scripts` — utilities
* `/chats` — chat/log data
* `/storage` — persistent data
* `/uploads` — media
* `*/index.html` — original Google AI core/main page templates
* `login*.php`, `admin/logout.php` — authentication
* `sitemap-*.php` — XML sitemaps

## Python

`admin/scripts/enrich-game-descriptions.py` is the only Python utility. Use it only for game catalog or description enrichment.

After implementation, report:

1. Files changed
2. Flags added
3. Tables/data affected
4. Test result

## Current APIs

`/game-api/slot-list.php` supports GET/form POST `random=1` (or `true`) with optional `count` (1–100, default 1 in random mode). It selects eligible games without replacement per response, retains other filters, ignores `page`/`sort`, and bypasses caching (`no-store`, `X-API-Cache: BYPASS`). The usual response structure remains: `slots`, pagination with page/totalPages 1 and total matching games, and `filters.random=true` / `filters.sort="random"`. Without random mode, the default remains 24. See [API.md](API.md) for examples.


Base URL: your configured site origin. The current source inventory contains 14 public-facing PHP entrypoints under `/api/`. See [API.md](API.md) for request fields, response formats, examples, and authenticated handlers. Update both documents whenever an API changes; this inventory does not certify production availability.

| Endpoint | Methods | Access | Purpose |
| --- | --- | --- | --- |
| `/game-api/slot-list.php` | GET, POST | Public | Paginated eligible games of all supported types. |
| `/game-api/provider-list.php` | GET | Public | Providers and eligible game counts. |
| `/game-api/game.php` | GET | Public | One eligible game by slug. |
| `/api/blog-post-list.php` | GET, POST | Public | Published blog list. |
| `/api/blog-category-list.php` | GET, POST | Public | Blog category hierarchy and counts. |
| `/api/blog.php` | GET | Public | Published blog detail and rendered content. |
| `/api/blog-public-token.php` | GET, POST | Public | Short-lived public blog JWT. |
| `/api/blog-engagement.php` | POST | Public | Record a view, like, or dislike. |
| `/api/playnow-click.php` | POST | Public | Record a Play Now click. |
| `/api/chat-session.php` | POST; GET for admin messages | Public transcript save; authenticated admin actions | Save transcripts, read messages, or reply. |
| `/api/chat-auto-responses.php` | GET; admin POST | Public suggestions; admin/super-user management | Up to five enabled suggestions and unlimited keyword rules. |
| `/api/customer-ticket.php` | POST | Public | Save a support ticket and attempt email delivery. |
| `/api/settings/seo.php` | GET, POST | Public GET; admin/super-user POST | Read or update site SEO settings. |

Game routes remain `/game-api/slot-list.php`, `/game-api/provider-list.php`, and `/game-api/game.php`, with implementations in `game-api/` and catalogue data in `games.sqlite`. Public eligibility includes the global Games switch, publication, processing, provider approval, visibility, slug validity, and PH restrictions. Disabling Games returns empty game/provider lists (including sample mode) and 404 game details. Local game-list thumbnails use `/uploads/games/...`.

Game admin handlers are `/admin/plugin/games/save.php` (POST, `slots.edit`, form fields and CSRF), `/admin/plugin/games/index.php` (GET list; POST featured/visibility with `slots.edit`), `/admin/plugin/games/providers.php` (admin/super-user provider approvals), and `/admin/plugin/games/settings.php` (admin/super-user `enabled=0/1` switch). The latter page handlers return HTML. Old slot/provider URLs remain compatibility entrypoints. All admin writes require CSRF. CLI import/enrichment/runtime scripts are not HTTP APIs.

List POSTs use form fields, not JSON. Public engagement/click/chat/ticket writes accept JSON or form data and may change records or send email. SEO settings GET is public; POST is admin-only. Chat `admin_action=messages` uses authenticated GET with `chat.view`; `admin_action=reply` uses authenticated form POST with `chat.reply` and CSRF.

### Contact and chat admin pages

- Player presence indicators appear in the Live Chat list and conversation view. Reuse the existing 75-second player heartbeat: legacy/untracked sessions display Unavailable. `GET /api/chat-session.php?admin_action=player_presence&session_ids[]=...` requires `chat.view`, accepts at most 25 IDs, and returns a nullable boolean per session in `players`. Admin message polls include `playerOnline` even for unchanged transcripts. List and detail polling run every four seconds while visible; green dots mean online, red dots offline, and gray unavailable. Reads time out after ten seconds; errors clear stale online indicators and reconnecting refreshes presence. These reads do not extend presence.

- Provider source field: prefer SlotsLaunch `thumb` over `thumbnail`, `image`, `logo`, and `icon` via shared `provider_image_from_payload()`. Both provider sync upserts and provider creation/update during PHP game import localize the nested provider image to `/uploads/providers/`; missing or failed images preserve existing thumbnails. Do not use the game's own `thumb` as its provider logo. Public provider responses retain the `thumbnail` key.

- Provider thumbnails: `php admin/plugin/games/scripts/sync-providers.php` reuses `import-slotslaunch-providers.php` to refresh all provider pages and download validated images to `uploads/providers`. Shared `admin/plugin/games/includes/provider-images.php` reuses game image validation and public-address-only downloading, deterministic source-hash names, and existing-file reuse. Failures keep the previous thumbnail; provider approval and game flags are unchanged. `/game-api/provider-list.php` keeps its `thumbnail` field and returns local `/uploads/providers/...` paths after sync, preserving actual provider slugs and avoiding duplicate empty-image rows. API caches clear after imported pages. No schema changes. `PROVIDER_IMAGE_UPLOAD_DIR` optionally overrides the physical directory. Regression: `php admin/scripts/test-provider-images.php`.

- Public chat saves use `POST /api/chat-session.php` with JSON or form fields: `name` and a nonempty `messages` array containing `text` (for example, `{"name":"Player_VIP","messages":[{"sender":"user","text":"help me"}]}`). The public handler does not read singular `message` or `context.user`; that shape alone returns 422 `No chat messages to save.` Public saves require no admin authentication or CSRF and return `ok`, `sessionId`, `path`, `messageCount`, and nullable `reply`. Subsequent saves send the returned `sessionId` as `session_id` with the full transcript; server-authored admin and automatic replies are preserved, and up to 200 messages are retained. See [API.md](API.md#public-chat-transcript-request) for the request contract.
- Automatic replies: `/admin/chat-auto-responses.php` (admin/super-user only, linked from Live Chat) edits unlimited rules in existing `app_settings.chat_auto_responses`. Public `GET /api/chat-auto-responses.php` returns up to five enabled suggestions (`id`, `label`, `message`). Authenticated admin `GET ?admin_action=list` returns full rules; form POST `admin_action=save/delete` requires CSRF. Saves accept optional `id`, required `label`, newline-separated `keywords`, `response`, and optional `enabled`/`suggested` flags defaulting to 0; delete requires `id`. Both return updated rules. Server validation enforces five enabled suggestions. Matching the latest user message uses case-insensitive literal containment, longest phrase first, with creation-order ties. Replies use sender `bot`, type `auto_response`, `autoReplyId`, and `ruleId`; identical user-transcript retries reuse the saved reply. Configuration starts empty; no business instructions are seeded. Regression: `php admin/scripts/test-chat-auto-responses.php`.

- `/admin/contacts.php` reads the existing customer-ticket API's `customer-tickets.json` archive using its shared storage path. It shows retained submissions (up to 500), details, search and 25-row pagination. No invented statuses, read actions or deletion: the current ticket API supports submission only. Missing email/status/updated-time fields are shown as not recorded.
- `/admin/live-chat.php` reads existing `/admin/chats/*.json` sessions, with search, pagination and message history. No additional database tables or public endpoints are used.
- Existing `/api/chat-session.php` now supports authenticated admin-only `GET ?admin_action=messages&session_id=...&version=...` and form-encoded `POST admin_action=reply&session_id=...&message=...&csrf_token=...`. Replies use the existing `agent` sender and are saved in the same session file. Public transcript-save requests keep their existing response fields; locked updates preserve admin replies across later customer snapshots.
- Admin polling every four seconds uses a content version to avoid transferring unchanged history. The availability switch sends authenticated CSRF-protected `admin_action=presence` every 25 seconds while visible; presence expires after 75 seconds. Replies still use the existing admin endpoint and clear pending AI work.
- Continuous chat: JSON POST actions `start`, `send`, `poll`, `heartbeat`, `disconnect` on `/api/chat-session.php` use random visitor tokens (hashes only in storage, 30-day expiry), per-tab presence, server-authored messages and idempotent client message IDs. Protected sessions reject legacy snapshot writes; old unprotected transcripts retain their existing behavior and cannot trigger AI. No new tables. `chat-realtime.php` and `chat-ai.php` share locked session storage, leases, revision checks and backoff. Worker `php admin/scripts/chat-ai-worker.php` uses existing Ollama configuration (fallback model `gemma4:31b-cloud`), a 180-second initial wait regardless of agent availability, only for online players. Successful AI replies enable follow-ups without an added wait until a human replies. Admin Settings saves `chat_ai_name` in existing `app_settings` (default `Alex`); public status exposes `supportName` and AI messages include `name`. No AI handoff notice is posted. Keyword rules take precedence. Stale AI replies are discarded after a human reply, new user turn, or disconnect.
- WebSocket relay: Node 22+ with locked `ws` dependency under `admin/scripts/chat-socket`; listener defaults to loopback 8084, exact configured origin allowlist, visitor-token authentication and bounded queues/payloads. It polls PHP internally and sends full message/status snapshots. `/chat-client.js` is the browser integration module with reconnect/HTTP fallback. Services must run persistently behind a WSS reverse proxy in production. See [CHAT-INTEGRATION.md](CHAT-INTEGRATION.md) for setup, fields, limits, frontend use and tests. Tests: `php admin/scripts/test-chat-realtime.php` and `node admin/scripts/chat-socket/test.mjs`.
- Shared helpers: `admin/includes/customer-ticket-storage.php`, `admin/includes/chat-session-storage.php`, `admin/includes/admin-support-data.php`. No replacement APIs or support database schema. The earlier parallel implementation has been removed; no stored data or tables were deleted.

Regression check: `php admin/scripts/test-admin-support.php`. Isolated HTTP tests cover unchanged ticket submissions, retained chat snapshots, admin replies, search/pagination, version polling, auth/CSRF and escaping. Chat snapshots retain the existing 200-message limit.

### Public detail APIs

- Blog `content_html` removes generated block-separator newlines and compacts bundled CSS whitespace. Preserve author code/preformatted newlines and Markdown `<br>` breaks. Public GET access, inputs and JSON fields are unchanged. Covered by `test-public-details.php`.

- Blog rendering: tables fill available content width with wrapping and contained overflow. Responsive demo frames include a non-heading title and controls inside the player wrapper, which enters/exits fullscreen as a unit; third-party iframe documents are unchanged. FAQs use native collapsed `details`/`summary` accordions while preserving full FAQ schema. Public GET inputs, permissions and response fields remain unchanged. Check `php admin/scripts/test-public-details.php`.

- Public blog detail includes `blog.about_author`, the selected writer's current full public profile (`name`, `role_name`, `profile_image`, `bio`, and ordered `socials` with `platform`, `url`, `label`). Existing `blog.writer` remains compatible. Selection uses stored `writer_id`; missing attribution falls back to the legacy author/Editorial Team with empty profile fields. No account credentials or administrative fields are exposed. Public GET method, slug input, permissions and paths are unchanged; no new flags or tables. Regression: `php admin/scripts/test-writers.php`.

- `GET /game-api/game.php?slug=<canonical-slug>` returns `ok` and the existing public slot field format under `game`. It requires `published=1`, `done_processing=1`, an exact valid slug, the shared PH exclusion rule, provider approval and `is_viewable=1`. Page, thumbnail and iframe URLs are absolute. Invalid/unavailable slugs return JSON 404.
- `GET /api/blog.php?slug=<canonical-slug>` returns `ok`, a public `blog` with `content_html`, `featuredImage`, `blogUrl`, `publishedAt`, `updatedAt`, `readTime`, and separate `faq_schema`. Drafts/future scheduled posts return 404. Existing database initialization still publishes due scheduled posts; stored content is not rewritten.
- `admin/includes/blog-renderer.php` is extracted from the former PHP blog view in Git history and shared by the API and the new thin `blog/view.php`. It renders Markdown and button/table/custom-code/slot-demo/faq/quote blocks. Normal content is escaped; custom-code retains the existing trusted-admin HTML/CSS/JS behavior. FAQ schema uses rendered FAQ text without truncation. Demo blocks resolve the selected public game and exclude PH-restricted/unprocessed games.
- `admin/includes/game-public.php` shares the existing slot serializer and demo URL helper with `slot-list.php`; `admin/includes/public-detail.php` shares detail lookup/JSON behavior. No dependencies added. `php admin/scripts/test-public-details.php` runs isolated rendering/visibility regression checks; localhost HTTP tests also verified 404s and exact view/API content parity.

### Editor accounts, authentication and slot content

- Existing environment administrator credentials (`ADMIN_USERNAME`, `ADMIN_PASSWORD_HASH`) remain the administrator account. `/admin/users.php` is administrator-only and manages editor usernames, display names, password resets, active status and last login. Editor passwords use `password_hash()`/`password_verify()` and require 12–72 bytes. No email field was added because the existing authentication system has no user email model.
- `admin/includes/admin-users.php` creates `admin_users` through `blogs_pdo()`. Its database constraint allows only the `editor` role; it does not duplicate or migrate the environment administrator. Every account edit increments `auth_version`, immediately revoking existing sessions. No accounts or passwords are seeded in production.
- Existing PHP sessions, CSRF tokens, regeneration, 30-minute inactivity timeout and eight-hour maximum lifetime remain. Login also issues a 15-minute HS256 JWT using the existing `admin/includes/jwt.php` helper. `ADMIN_JWT_SECRET` is preferred; the existing `BLOG_API_JWT_SECRET` is the fallback (minimum 32 bytes). Use a strong randomly generated environment secret; never commit it.
- The `admin_access` cookie is HttpOnly, SameSite=Strict, and Secure on HTTPS using the existing HTTPS/proxy detection. JWTs contain user ID, role, version, issued/expiry times, audience/scope and a random session binding. Every protected request requires both the JWT and the PHP session, revalidates the signature/expiry and checks current editor active/version state. An admin session also checks the current administrator password configuration fingerprint. Public blog tokens cannot authorize admin access.
- A still-valid access token can be renewed during an authenticated request when under five minutes remain, within the original session lifetime. Missing/expired tokens require login; there are no refresh tokens or browser localStorage credentials. Existing pre-JWT sessions need to sign in again after deploying this change.
- Shared admin header loads `session-renew.js`: at most one CSRF-protected POST per minute to `/admin/session-renew.php` while browser activity is within 30 minutes. The `session.renew` capability is available to admin/editor roles. Form `idle_seconds` (required 0–1800) reports the age of the last browser interaction; renewal preserves the previous server activity timestamp unless newer user activity is reported. Token signatures/account checks, 30-minute inactivity, and eight-hour absolute expiry remain enforced. Expired tokens are never revived. Network/session failures show an inline notice without redirecting away from unsaved work. Background Live Chat polling retains its preexisting activity behavior.
- `admin/includes/admin-permissions.php` provides the shared route/capability map used automatically by `require_auth()`, plus `require_admin()` and `require_capability()`. Unlisted routes default to administrator-only. Editors see only Blogs, Slots, Contacts and Live Chat navigation. Blog edit/save/publish/schedule/delete, category lookup and image uploads are allowed child actions. Users, settings, trackers, storage health, standalone SEO/category management, and import/export remain administrator-only.
- Editor blog saves preserve existing administrator-authored custom-code blocks but cannot change/add executable custom code. Block URLs must be HTTP(S) or site-relative to prevent script execution inside another user's editor preview. Ordinary formatting, FAQ/table/button/quote/demo blocks and existing publishing/scheduling remain available.
- Existing login limiting remains five failures per normalized username/IP in 15 minutes with generic errors and successful-login reset. Authenticated writes share a 30/minute/user budget, with a separate 30/minute/user chat-send budget. General reads and chat polling have separate 120/minute/user budgets. Limits reuse `admin/includes/api-rate-limit.php`; blocked requests return 429 with `Retry-After`. File-lock/storage failures fail closed.
- `/admin/slots.php` links to `/admin/slot-edit.php?id=...`. Formatted HTML is retained in `long_description`; shared `admin/content-editor.js` and `.css` reuse the blog editor's basic toolbar commands/styles. Script/event attributes and unsafe URLs are removed server-side, and pasted active HTML is not executed in the editor.
- `POST /admin/slot-save.php` accepts form fields `id`, `csrf_token`, `name`, `short_description`, `long_description`, `rtp`, `volatility`, and optional `is_viewable` only. It requires `slots.edit`, authentication, JWT and CSRF. Unknown/protected fields are rejected. It updates `updated_at` and invalidates existing API caches using the shared helper. IDs, slug, provider/sync fields, processing state, restrictions and publication state remain protected. Featured status retains its existing Slots-list action.
- Contacts and chat reuse their existing APIs/storage; unsupported read/close actions were not invented. No separate audit subsystem or `updated_by` column was added because no such schema exists. Editor accounts keep creation/update/last-login timestamps.
- Regression: `php admin/scripts/test-editor-security.php`. Isolated localhost HTTP tests cover real login/user forms, JWT tampering/expiry/session binding, role navigation/direct 403s, disabled/reset accounts, 429 limits, blog publishing/scheduling, legacy contacts/chat and slot editing/protected fields. All test identities and storage are temporary.

### Provider approval and game visibility

- `/admin/settings/providers/` lists existing game providers with approval overrides in `game_provider_settings` (provider slug key, or normalized name fallback; no copied provider names). Missing overrides default to approved, including new imports. Existing/new games default to `is_viewable=1`; imports preserve an existing visibility choice.
- Admins/super users manage approvals; `slots.edit` permits game visibility changes in the list/editor. Writes keep CSRF/rate limits, clear API caches and regenerate numbered game sitemaps/index. `GAME_SITEMAP_OUTPUT_DIR` can override the sitemap output directory (used by isolated tests); the default is the site root. A refresh failure is reported and can be retried by saving again.
- Shared eligibility: `admin/includes/game-visibility.php`. Tests: `php admin/scripts/test-game-visibility.php` and `php admin/scripts/test-game-sitemaps.php`.

- AI chat failure handling: `/api/chat-session.php` realtime POST responses and WebSocket snapshots omit `status.aiRetryAt`; failure counts, upstream errors and retry details stay internal. No failure message is saved to the transcript; normal chat reads/sends continue and the worker retries with backoff. Sanitized diagnostics are written only to the protected `CHAT_STORAGE_DIR/ai-errors.php` log (default `admin/chats/ai-errors.php`), with timestamp, hashed session reference, operation and safe reason/HTTP/cURL codes. No new inputs, flags, permissions or database tables.

- Game player analytics: public JSON/form POST `/game-api/game-play.php` requires `slug` and a persistent random `visitor_id` (32–80 letters/digits/hyphens/underscores; no defaults). Enforce public game eligibility and 60/minute direct-IP limit. Returns `ok`, `counted`, `uniquePlayers`; 405/422/404/429/500 errors. Unique `(game_id, visitor_hash)` pairs live in the new `game_players` table in games.sqlite. Repeat requests are idempotent; no raw visitor IDs/IPs in that table. Admin game cards show per-game unique browser totals within existing pagination. Frontend must call on actual demo launch; see API.md. No new environment flags.
- Blog publishing: new/reset forms and omitted save status default to draft; existing published edits show Update and remain live when saved as published. Explicit Save as Draft still unpublishes. Successful upserts clear blog API caches; public detail reads reflect the saved content on the next fetch. Regression: `php admin/scripts/test-game-players.php` covers counters, visibility, draft default, live edits and cache invalidation.

Chat message formatting: `POST /api/chat-session.php` preserves internal spaces, line breaks and blank lines in legacy transcript `messages[].text`, realtime `action=send` message, and authenticated `admin_action=reply` message. CRLF/CR normalize to LF; outer whitespace is trimmed, unsafe control characters removed, and the existing 5,000-character limit remains. AI and saved automatic replies use the same normalization. GET admin messages and realtime snapshots return plain text; permissions, CSRF, tokens and response fields are unchanged. Live Chat uses `/chat-message.js` for safe clickable HTTP(S)/Markdown links; the public `/chat-client.js` re-exports `renderChatMessage` for widget integration. No new flags or tables. Previously collapsed whitespace cannot be restored.

### Sport / Competition API layout builder

- Admin/super-user GET `/admin/plugin/odds-api/sports/` edits iframe 1; `?id=N` selects a saved layout, `?new=1` creates a draft. The paginated list and builder live under Settings. `/admin/plugin/odds-api/` and `/admin/plugin/odds-api/livescore/` authenticate and redirect; the standalone navigation/editor is removed. Editors are denied server-side.
- Form POST `/admin/plugin/odds-api/sports/` requires session/JWT/CSRF. Inputs: `action=save|preview`, `id` (0 creates), `name` (required, 200 bytes), `api_url` (required HTTP(S), max4096), `enabled=0|1`, `root_path` (default `$`), `columns_per_row=1|2|3|4`, `layout_config` (JSON list). Migrated Odds layouts also accept `markets` and optional preview `sport`. Save returns 303 to the saved ID; preview returns `{ok,fields,preview}` with discovered paths/types and projected values. Errors: 403 auth/CSRF, 404 missing config, 422 validation, 429 shared admin rate limits, 503 storage/source unavailable. Preview can fetch while disabled, without saving configuration.
- Nodes use unique `id`, `type=field|loop`, JSON-style `path`, editable `label`, `visible`. Fields add `role=data|title|description`, `format=text|decimal|date|datetime`, `fallback` (blank skips missing). Loops add `columns=1..4`, `children`. Nesting/order are persisted directly in JSON; no hardcoded bookmaker renderer. Paths begin `$`, object members use dots or quoted brackets, arrays use `[]`; selected root records become `$` for field resolution. Maximum120 blocks/depth8.
- Public GET `/api/sports-layout.php?id=N&sport=KEY` (ID defaults1; sport optional) returns `{ok,enabled,title,columns,records,sports,sport,updatedAt,truncated}`. Records contain resolved field values or nested loop items, never API URLs or unused raw fields. Disabled configurations return empty records without fetching. Error JSON `{ok:false,error}` uses 405 wrong method, 422 bad input/path, 404 missing layout, 429 60/minute/direct-IP cap, 503 source/storage failure. Public `/odds-api/?iframe=N&sport=KEY` embeds consume this endpoint and shared renderer; labels/values are text. Responsive grids reduce columns on narrow screens.
- Additive migration in existing `sports-livescore.sqlite.iframes`: `api_url`, `enabled`, `root_path`, `columns_per_row`, `layout_config`, `catalog_url`; preserves IDs, title, markets, old blocks/timestamps and `matches`. Existing fields/labels/roles/order migrate once to nested loops. `sport_api_cache(cache_key,payload,fetched_at,expires_at)` holds only expiring source responses. Provider credentials stay in `odds-api.sqlite`; generic API URLs may contain credentials, so protect both databases. No new environment flags.
- Generic fetching validates public HTTP(S) URLs/ports80/443, DNS addresses and pinned resolution; blocks private/reserved networks, redirects and proxies; verifies TLS; caps response2MB with connect5s/transfer15s timeouts. Hashed source cache uses300s successes/60s failures and locked fills; expired entries purge during fetch. Logs contain only a source hash. Discovery unions up to100 sampled items/array, max500 paths/depth12. Projection caps200 root records,100 items/loop and20000 blocks. Missing optional values skip safely; zero/false survive.
- Migrated Odds layouts retain saved provider URL/catalogue templates and sport metadata; provider Settings still accepts `action=save_odds_api`, `enabled`, `apiKey` (blank retains), `regions`, `bookmakers`, optional `clear_api_key=1` (requires disabled). Both global and per-layout switches gate migrated public output. Markets remain per layout. Saved `{apiKey}` expansion is restricted to the provider host.
- Compatibility: public GET `/api/odds-api.php?action=sports|odds&iframe=N&sport=KEY` keeps its legacy `{ok,enabled,data,updatedAt,layout}` raw feed and legacy block snapshot for existing integrations; the generic builder/iframe use `/api/sports-layout.php`. Legacy feed caches remain sports3600s/odds300s/failures60s. Do not use the legacy feed as the generic layout API.
- Guide: [Sport / Competition layouts](plugin/odds-api/sports/README.md). Tests: `php admin/scripts/test-sports-api.php`, `php admin/scripts/test-odds-api.php`.

### Blog countdown, odds iframe redesign and SEO checker enhancements

- Blog Post Countdown (optional): `blog_posts` table includes `countdown_enabled` (INT 0/1), `countdown_target` (TEXT), `countdown_title` (TEXT), and `countdown_end_text` (TEXT). Fully optional; defaults to disabled and empty. `admin/blog-publish.php` provides an optional countdown toggle and datetime-local picker in the publishing sidebar, as well as a toolbar countdown button for content markdown blocks. When enabled with a valid target date, public details output includes a countdown banner in HTML and countdown metadata.
- Odds Iframe & Sports Layout: `/odds-api/style.css` and `/odds-api/layout.css` copy the website's dark luxury casino/sportsbook design (`#080502`, crimson `--brand: #a62f3d`, gold/amber accents `#fbbf24`/`#f59e0b`, glassmorphic cards, gold odds price chips). Loops support `direction: 'grid'|'row'`. Layout builder and `/odds-api/render.js` support node types `field`, `loop`, `button`, `link`, `text`, `image`, `container`. Container blocks support flexible horizontal rows or columns (`direction: 'row'|'column'`). Button/link URLs support fixed URLs and dynamic interpolation like `{$.id}` or `{token}`. Layout supports `item_limit` and Title Section vs Loop Section separation, matching the visual wireframe builder.
- SEO Checker Error-First Ordering: `/admin/seo-checker-v2.php` sorts table rows so errors appear first (Links: broken/redirects before 200 OK; Schemas: invalid/missing schema before valid; Pages: HTTP errors and missing title/description/canonical before clean pages). `/admin/index-checker/index.php` adds `sort=errors` option to sort error and unindexed URLs first.

### Optional plugins

Games lives in `admin/plugin/games/`; Odds API (provider settings, API services, sports editor, saved-layout storage helper and public renderer) lives in `admin/plugin/odds-api/`. Both appear in the admin header only when installed. Provider credentials are managed at `/admin/plugin/odds-api/settings.php` (GET/POST, admin/super-user, CSRF on POST; existing enabled/apiKey/clear_api_key/regions/bookmakers fields). General Settings no longer loads either plugin's settings.

Public API methods, inputs and payloads remain unchanged: game list/detail/provider APIs and POST `/game-api/game-play.php` delegate to the Games plugin; GET `/api/odds-api.php` and GET `/api/sports-layout.php` delegate to Odds API. Missing plugins return JSON 404 `Plugin unavailable.` at these public adapters. Existing `/odds-api/?iframe=N` embeds remain supported. Apache rewrites retain old `/admin/games/`, `/admin/odds-api/`, `/admin/plugin/odds-api/registry/` and `/admin/sports-livescore/` bookmarks and old public asset URLs. Plugin includes/scripts and Markdown documentation remain blocked over HTTP.

Remove the corresponding plugin folder to uninstall its code; stop its scheduled jobs first. Databases stay in the existing shared storage directory (or `APP_STORAGE_DIR`) for reinstall/backup. No flags, tables, migrations or stored records are added or deleted by this reorganization. Main blog/settings bootstrap and indexing tolerate an absent Games plugin. See [plugin guide](/admin/plugin/README.md).

- Blog image links: public `GET /api/blog.php?slug=...` preserves linked images in `blog.content_html` as `<a href="..."><img ...></a>`, including `rel="noopener noreferrer nofollow"` when the saved Markdown link has `{nofollow}`. Root-relative and absolute HTTP(S) image URLs are supported; relative image/link URLs become absolute as usual. Images without links stay unlinked and follow links do not acquire nofollow. Image attributes are protected from subsequent Markdown formatting; unsafe URL schemes remain unsupported. Methods, permissions, response keys and stored data are unchanged. Regression: `php admin/scripts/test-public-details.php` exercises the API output.

### Root Games API folder

The four public game endpoints now live in root `game-api/`: GET/form POST `slot-list.php`, GET `provider-list.php`, GET `game.php`, and POST `game-play.php`. Inputs, JSON outputs, public eligibility, rate limits and access permissions are unchanged. `/api/slot-list.php`, `/api/provider-list.php`, `/api/game.php` and `/api/game-play.php` are compatibility wrappers; POST bodies and query parameters pass through without HTTP redirects. The API depends on the Games plugin for its catalogue/helpers and returns JSON 404 `Plugin unavailable.` if the plugin is removed. No flags, tables or stored records change.

### Sports Registry & Odds API Primary Architecture

- Architecture: **The Odds API** is the primary source of truth for sports categories, tournaments (sport keys), and participants. **SportsDataverse**, then **Wikidata/Commons**, enrich missing logos. **API-Sports** remains an optional legacy image service.
- Location: `/admin/plugin/odds-api/registry/` (Categories, Tournaments, Participants tabs; Sync Sports, Sync All Participants, Enrich Logos), `/admin/plugin/odds-api/registry/edit.php` (Category editor & manual image upload), `/admin/plugin/odds-api/registry/registration.php` (Tournaments & participant sync), `/admin/plugin/odds-api/registry/teams.php` (Central participant directory & filters), and `/admin/plugin/odds-api/registry/team-edit.php` (Participant editor & manual logo override).
- Database: `storage/sports-registry.sqlite`. Tables: `sports_categories`, `tournaments`, `participants`, `participant_tournaments`, `sport_settings`. Legacy tables (`sports`, `competitions`, `teams`, `team_competitions`) are preserved and mirrored for backward compatibility.
- Image Keys: Category image reference is strictly `{slug}_sports` (e.g. `basketball_sports`, `soccer_sports`). Participant image reference is `{participant-slug}_{category-slug}` (e.g. `detroit-pistons_basketball`).
- Primary Sports & Tournament Sync: `/v4/sports/?apiKey={apiKey}` imports available competitions, grouped by the API `group` field into `sports_categories` and `tournaments` (`odds_sport_key`). Admin enabled toggles and uploaded category images are preserved.
- Primary Participant Sync: `/v4/sports/{sport}/participants?apiKey={apiKey}` imports participants into the normalized `participants` table. Participants competing across tournaments are deduplicated and linked via `participant_tournaments`.
- Bulk Sync Runner: `php admin/plugin/odds-api/scripts/sync-odds-participants.php [--sport=<key>] [--category=<slug>]`. Batch processing is resilient; failures in one tournament log an error and continue processing the rest of the batch.
- Optional API-Sports Image Enrichment: `sports_registry_enrich_participant_images()` matches existing registered participants by name/category and downloads club badges locally to `/uploads/teams/`. It **never** creates new sports, tournaments, or participants. Manual images (`image_source = 'manual'`) are permanently preserved.
- Odds API Layout Integration: `/admin/plugin/odds-api/sports/` layout projections resolve sport images via `sports_registry_resolve_sport_image()` and team/participant images via `sports_registry_match_participant()`. All outputs use absolute local URLs (`public_url(...)`) and keep provider API keys strictly server-side.
- Test Suite: `php admin/scripts/test-sports-registry.php` (14-point Odds API Sports Registry verification suite).

### Sports image enrichment

- Admin/super-user GET `/admin/plugin/odds-api/registry/images.php?tournament=ID` shows previews, source/attribution records and unresolved matches. Authenticated form POST requires CSRF, `action=preview|enrich`, optional `retry=1`; each batch considers up to five missing images and returns HTML results. Sources: SportsDataverse followed by Wikidata P154 / Commons; no provider key required. Manual and existing images are preserved.
- CLI: `php admin/plugin/odds-api/scripts/enrich-sports-images.php [--tournament=ID_OR_ODDS_KEY] [--limit=5] [--dry-run] [--retry]`. HTTP access to scripts remains blocked. Tests: `php admin/plugin/odds-api/scripts/test-sports-images.php`.
- New `sports_image_assets` table in `sports-registry.sqlite` stores status, source and attribution; participant image paths update existing tables. Images live at `/uploads/sports/`. Saved tournament logos take precedence over category fallback in existing iframe image resolution; public JSON fields are unchanged.
- Public GET `/odds-api/?credits=1&credits_page=1` returns HTML image attribution, 50 entries/page, with source/license links and no credentials. The iframe links to it. See [Odds API image guide](plugin/odds-api/README.md#free-sports-images).

- Daily image runner: `admin/plugin/odds-api/scripts/daily-sports-images.php` (CLI-only), flags `--tournament`, `--max-items=1000`, `--max-seconds=1800`, `--batch-size=25`, `--pause-ms=1000`. Exclusive storage lock and private rotating log; no scheduler is installed automatically. Uses existing image tables and preserves manual/existing logos. See the [aaPanel job guide](plugin/odds-api/README.md#dailybackground-image-job-aapanel).
- Iframe image fix: existing `/api/sports-layout.php` image blocks now return same-origin local registry paths so a placeholder or different canonical site URL cannot prevent rendering. Missing registry images return an empty URL rather than a team/sport name. The editor uses the primary participant registry and saved tournament logos; iframe CSP permits HTTPS custom images. No new response fields/tables. Tests: `test-sports-images.php`, `test-sports-images-daily.php` and `test-iframe-images.cjs` under the Odds API plugin's scripts folder.

Daily image eligibility: both `sports_categories.enabled=1` and `tournaments.enabled=1` are required. The daily runner also ignores provider-inactive tournaments (`active=0`). Participants qualify only through eligible tournaments. `--tournament` does not bypass these filters; interactive enrichment behavior is unchanged.

Daily sports images HTTP 429 cooldown: the runner stops and persists a 30-minute pause in private `sports-images-cooldown.json` beside its log. Every rerun checks this before provider requests and reports `retry_at` (UTC) and `wait_seconds`; rerunning does not extend the pause. The next invocation after expiry resumes eligible items. It does not sleep or automatically restart after 30 minutes; cron runs at its configured schedule. Another HTTP 429 starts a fresh cooldown. No new flags or database tables.
