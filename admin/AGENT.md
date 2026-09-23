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

Base URL: your configured site origin. The current source inventory contains 12 public-facing PHP entrypoints under `/api/`. See [API.md](API.md) for request fields, response formats, examples, and authenticated handlers. Update both documents whenever an API changes; this inventory does not certify production availability.

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

Game routes remain `/api/slot-list.php`, `/api/provider-list.php`, and `/api/game.php`, with implementations in `admin/games/api/` and catalogue data in `games.sqlite`. Public eligibility includes the global Games switch, publication, processing, provider approval, visibility, slug validity, and PH restrictions. Disabling Games returns empty game/provider lists (including sample mode) and 404 game details. Local game-list thumbnails use `/uploads/games/...`.

Game admin handlers are `/admin/games/save.php` (POST, `slots.edit`, form fields and CSRF), `/admin/games/index.php` (GET list; POST featured/visibility with `slots.edit`), `/admin/games/providers.php` (admin/super-user provider approvals), and `/admin/games/settings.php` (admin/super-user `enabled=0/1` switch). The latter page handlers return HTML. Old slot/provider URLs remain compatibility entrypoints. All admin writes require CSRF. CLI import/enrichment/runtime scripts are not HTTP APIs.

List POSTs use form fields, not JSON. Public engagement/click/chat/ticket writes accept JSON or form data and may change records or send email. SEO settings GET is public; POST is admin-only. Chat `admin_action=messages` uses authenticated GET with `chat.view`; `admin_action=reply` uses authenticated form POST with `chat.reply` and CSRF.

### Contact and chat admin pages

- `/admin/contacts.php` reads the existing customer-ticket API's `customer-tickets.json` archive using its shared storage path. It shows retained submissions (up to 500), details, search and 25-row pagination. No invented statuses, read actions or deletion: the current ticket API supports submission only. Missing email/status/updated-time fields are shown as not recorded.
- `/admin/live-chat.php` reads existing `/admin/chats/*.json` sessions, with search, pagination and message history. No additional database tables or public endpoints are used.
- Existing `/api/chat-session.php` now supports authenticated admin-only `GET ?admin_action=messages&session_id=...&version=...` and form-encoded `POST admin_action=reply&session_id=...&message=...&csrf_token=...`. Replies use the existing `agent` sender and are saved in the same session file. Public transcript-save requests keep their existing response fields; locked updates preserve admin replies across later customer snapshots.
- Admin polling every four seconds uses a content version to avoid transferring unchanged history. The existing snapshot format has no stable message cursor, unread state or close/reopen workflow, so those are not fabricated. Public chat remains a transcript-saving API; customer-side delivery/polling is not present in the current frontend.
- Shared helpers: `admin/includes/customer-ticket-storage.php`, `admin/includes/chat-session-storage.php`, `admin/includes/admin-support-data.php`. No replacement APIs or support database schema. The earlier parallel implementation has been removed; no stored data or tables were deleted.

Regression check: `php admin/scripts/test-admin-support.php`. Isolated HTTP tests cover unchanged ticket submissions, retained chat snapshots, admin replies, search/pagination, version polling, auth/CSRF and escaping. Chat snapshots retain the existing 200-message limit.

### Public detail APIs

- `GET /api/game.php?slug=<canonical-slug>` returns `ok` and the existing public slot field format under `game`. It requires `published=1`, `done_processing=1`, an exact valid slug, the shared PH exclusion rule, provider approval and `is_viewable=1`. Page, thumbnail and iframe URLs are absolute. Invalid/unavailable slugs return JSON 404.
- `GET /api/blog.php?slug=<canonical-slug>` returns `ok`, a public `blog` with `content_html`, `featuredImage`, `blogUrl`, `publishedAt`, `updatedAt`, `readTime`, and separate `faq_schema`. Drafts/future scheduled posts return 404. Existing database initialization still publishes due scheduled posts; stored content is not rewritten.
- `admin/includes/blog-renderer.php` is extracted from the former PHP blog view in Git history and shared by the API and the new thin `blog/view.php`. It renders Markdown and button/table/custom-code/slot-demo/faq/quote blocks. Normal content is escaped; custom-code retains the existing trusted-admin HTML/CSS/JS behavior. FAQ schema uses rendered FAQ text without truncation. Demo blocks resolve the selected public game and exclude PH-restricted/unprocessed games.
- `admin/includes/game-public.php` shares the existing slot serializer and demo URL helper with `slot-list.php`; `admin/includes/public-detail.php` shares detail lookup/JSON behavior. No dependencies added. `php admin/scripts/test-public-details.php` runs isolated rendering/visibility regression checks; localhost HTTP tests also verified 404s and exact view/API content parity.

### Editor accounts, authentication and slot content

- Existing environment administrator credentials (`ADMIN_USERNAME`, `ADMIN_PASSWORD_HASH`) remain the administrator account. `/admin/users.php` is administrator-only and manages editor usernames, display names, password resets, active status and last login. Editor passwords use `password_hash()`/`password_verify()` and require 12–72 bytes. No email field was added because the existing authentication system has no user email model.
- `admin/includes/admin-users.php` creates `admin_users` through `blogs_pdo()`. Its database constraint allows only the `editor` role; it does not duplicate or migrate the environment administrator. Every account edit increments `auth_version`, immediately revoking existing sessions. No accounts or passwords are seeded in production.
- Existing PHP sessions, CSRF tokens, regeneration, 30-minute inactivity timeout and eight-hour maximum lifetime remain. Login also issues a 15-minute HS256 JWT using the existing `admin/includes/jwt.php` helper. `ADMIN_JWT_SECRET` is preferred; the existing `BLOG_API_JWT_SECRET` is the fallback (minimum 32 bytes). Use a strong randomly generated environment secret; never commit it.
- The `admin_access` cookie is HttpOnly, SameSite=Strict, and Secure on HTTPS using the existing HTTPS/proxy detection. JWTs contain user ID, role, version, issued/expiry times, audience/scope and a random session binding. Every protected request requires both the JWT and the PHP session, revalidates the signature/expiry and checks current editor active/version state. An admin session also checks the current administrator password configuration fingerprint. Public blog tokens cannot authorize admin access.
- A still-valid access token can be renewed during an authenticated request when under five minutes remain, within the original session lifetime. Missing/expired tokens require login; there are no refresh tokens or browser localStorage credentials. Existing pre-JWT sessions need to sign in again after deploying this change.
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
