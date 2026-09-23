# API reference

Source inventory reviewed on 2026-09-23. This documents the 12 public-facing PHP entrypoints under `/api/`, plus related authenticated admin handlers. It is not a claim that every route has been tested on your production server.

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
| `count` | Page size, 1–100; default 24. |
| `page` | Page number; default 1. |
| `search` | Search name, slug, provider, or type. |
| `provider` | Provider slug. |
| `type`, `types` | Type slugs; arrays or comma-separated values. |
| `featured`, `progressive`, `upcoming` | Boolean filters: `1`, `0`, `true`, or `false`. |
| `megaways` | `1`/`true` enables the Megaways filter. |
| `published` | Defaults to 1. Setting 0 does not expose unpublished games. |
| `sort` | `name_asc`, `rtp_desc`, `release_desc`, or `updated_desc`; default is featured first, then newest updates. |

Returns `ok`, `slots`, `pagination` (`total`, `count`, `page`, `totalPages`), and `filters`. Each game includes identity, URLs, descriptions, provider/type objects, themes, statistics, flags, bet limits, paylines, and update time. Local list thumbnails use `/uploads/games/...`; resolve these against the API site's origin if your frontend is hosted elsewhere. Cache TTL: 60 seconds; `X-API-Cache` indicates HIT or MISS.

### Providers

`GET /api/provider-list.php`

Optional `count` or `limit`: 1–1000. `sample` or `dev` enables sample rows when Games is enabled. Returns `ok`, `providers`, `total`, `count`, `limit`, and `sample`. Provider records include ID/slug, API ID, name, thumbnail, eligible game count, and update time. Cache TTL: 300 seconds.

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

## Authenticated handlers

These are application endpoints, not public management APIs. POSTs below require authentication, the appropriate capability, and CSRF.

| Endpoint | Methods | Permissions and request |
| --- | --- | --- |
| `/api/settings/seo.php` | GET, POST | GET returns `ok` and public `settings`. POST requires admin/super-user, form settings fields and `csrf_token`; returns saved settings and rotated `csrfToken`. |
| `/admin/blog-category-list.php`, `/admin/blog-tag-list.php` | POST | Editors may read taxonomy lists/counts. |
| `/admin/blog-category-save.php`, `/admin/blog-tag-save.php` | POST | Admin/super-user; `action=save/delete`, `id`, `name`, `slug`; categories also accept `parent_id`. |
| `/admin/games/save.php` | POST | Requires `slots.edit`; form `id`, `name`, `short_description`, `long_description`, `rtp`, `volatility`, and optional `is_viewable`. Protected import/processing fields cannot be edited here. Returns `ok` and `message` or `error`. Legacy `/admin/slot-save.php` remains supported. |
| `/admin/games/index.php` | GET, POST | Authenticated game list; POST requires `slots.edit` for featured/visibility changes using `slot_id`, the selected action/value, and CSRF. HTML/form handler; legacy `/admin/slots.php` remains supported. |
| `/admin/games/providers.php` | GET, POST | Admin/super-user provider approval management. Form `provider_key`, `approved`; bulk `action=uncheck_all` uses JSON-encoded `provider_keys`. HTML/form handler; legacy `/admin/settings/providers/` remains supported. |
| `/admin/games/settings.php` | GET, POST | Admin/super-user; form `enabled=0/1` controls the global Games switch. Returns an HTML settings page. |
| `/api/chat-session.php?admin_action=messages` | GET | Requires `chat.view`; `session_id`, optional `version`. Returns current messages or unchanged state. |
| `/api/chat-session.php` with `admin_action=reply` | POST | Requires `chat.reply`; form `session_id`, `message` (up to 5,000 bytes), and `csrf_token`. |

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
