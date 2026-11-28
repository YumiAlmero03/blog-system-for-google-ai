# System Implementation Prompt Guide

Use this document when spinning up a newly created site or upgrading another copy of this CMS so all modules, APIs, security rules, media pipelines, integrations, cron jobs, and bug fixes from this system are implemented consistently.

---

## How to Use

1. Copy everything inside the **Master Implementation Prompt** code block below.
2. Paste it into your AI coding assistant inside the target repository (replace `<TARGET_DOMAIN>`, `<TARGET_TIMEZONE>`, `<TARGET_ADMIN_EMAIL>`, etc. where applicable).
3. After implementation, run the verification test suite and build a deployment package with `python3 admin/scripts/updater.py --build .` if deploying to aaPanel/Linux.

---

## Master Implementation Prompt

```markdown
You are implementing and bringing a newly created PHP 8.1+ / SQLite CMS codebase up to parity with the reference architecture described below. Follow every requirement strictly, reuse shared helpers instead of duplicating logic, keep secrets out of client-side and public endpoints, update `README.md`, `admin/AGENT.md`, and `admin/API.md` if any routes or maintained docs change, and verify every changed component with `php -l`, JS syntax checks, and the test suite.

---

### 1. Environment Configuration (`admin/.env` & `admin/.env.example`)
Ensure `admin/.env.example` documents every environment variable with clear comments, and configure `admin/.env` (never commit real secrets) with:

- **Admin Auth & JWTs**:
  - `ADMIN_USERNAME` (super_user username)
  - `ADMIN_PASSWORD_HASH` (bcrypt/argon2id hash via `password_hash()`)
  - `ADMIN_JWT_SECRET` (signing secret for admin sessions; falls back to `BLOG_API_JWT_SECRET`)
  - `BLOG_API_JWT_SECRET` (64-char hex recommended for `/api/blog-public-token.php`)
- **Application & URLs**:
  - `SITE_BASE_URL` (e.g. `https://example.com/` — canonical/sitemap/indexing origin, takes precedence over UI setting)
  - `FRONTEND_URL` (optional headless/frontend URL; falls back to `SITE_BASE_URL`)
  - `ADMIN_TIMEZONE` (e.g. `Asia/Manila` — used for blog scheduling and publish dates)
- **SlotsLaunch Integration (Server-Only)**:
  - `SLOTSLAUNCH_API_TOKEN` (API token for game/provider imports)
  - `SLOTSLAUNCH_API_KEY` (public site key included in signed iframe query string)
  - `SLOTSLAUNCH_API_SECRET` (HMAC secret; NEVER expose to client JS or frontend env)
  - `SLOTSLAUNCH_SITE_DOMAIN` (registered domain used in HMAC signature, without `www.`)
  - `SLOTSLAUNCH_IFRAME_TTL` (`60`–`86400`, default `3600`)
  - `SLOTSLAUNCH_ORIGIN` (registered website origin for SlotsLaunch API requests; falls back to `SLOTSLAUNCH_SITE_DOMAIN`)
- **Support, AI & Google Indexing**:
  - `TICKET_FROM_EMAIL` (sender email for support tickets)
  - `OPENROUTER_API_KEY` (plus optional `OPENROUTER_MODEL` defaulting to `nvidia/nemotron-3.5-super-vl:free`, `OPENROUTER_API_URL`, `CHAT_AI_MODEL`, `LLMS_AI_MODEL`, `OPENROUTER_WEB_SEARCH` for daily game enrichment, `llms.txt` generation, and AI chat worker)
  - `GOOGLE_PRIVATE_DIR` (directory outside web root, or inside protected `admin/storage/.google-private` on open_basedir hosts)
  - `GOOGLE_INDEXING_ENABLED` (`"false"` by default unless pages use supported Google Indexing API schema such as `JobPosting` or `BroadcastEvent`; Search Console sitemap submission & URL Inspection work independently)
  - `GOOGLE_INDEXING_DAILY_LIMIT` (default `"200"`)

---

### 2. Core Storage Architecture (`admin/includes/` & `admin/storage/`)
- Use SQLite via PDO in `admin/storage/` (overridable via `APP_STORAGE_DIR`):
  - `admin/storage/blogs.sqlite`: blogs, taxonomy (`categories`, `tags`, `blog_post_tags`), users, writers, SEO/site settings (`app_settings`), indexing queues (`indexing_public_urls`, `indexing_notifications`, `indexing_google_events`, `indexing_jobs`, `google_indexing_attempts`, `google_indexing_queue`).
  - `admin/storage/games.sqlite`: attached as `game_store` by shared DB connection helpers so blog demos and indexing can query games without duplicating tables (`games`, `game_providers`, `game_types`, `game_themes`, `game_theme_links`, `game_provider_settings`, `game_module_settings`).
  - `admin/storage/sports-livescore.sqlite` & `admin/storage/sports-registry.sqlite` & `admin/storage/odds-api.sqlite`: Odds API plugin layout builder, cache, credentials, and sports registry.
- Keep private directories (`admin/storage`, `admin/includes`, `admin/scripts`, `admin/test`, `admin/chats`, `admin/data`, `admin/update-packages`, `admin/plugin/*/includes`, `admin/plugin/*/scripts`) blocked from direct HTTP access in `.htaccess` and Nginx configs.
- Write uploaded media consistently to both `admin/uploads/...` and public `uploads/...` (e.g. `uploads/blogs/`, `uploads/games/`, `uploads/providers/`, `uploads/sports/`).

---

### 3. Blog System, Rich Editor, Markdown Renderer & WordPress Importer
1. **Shared Markdown & Block Renderer (`admin/includes/blog-renderer.php`)**:
   - Render Markdown and custom blocks (`:::button`, `:::table`, `:::custom-code`, `:::slot-demo`, `:::faq`, `:::quote`, `:::countdown`) through `blog_render_content()` / `blog_render_markdown()`, generating `content_html` and JSON-LD `faq_schema`.
   - In `blog_parse_faq_items()`, strip `****` and leading/trailing `*` wrappers from FAQ questions while preserving intentional internal Markdown emphasis.
   - Keep `:::custom-code` restricted to administrators (`admin` / `super_user`) on save in `admin/blog-save.php`.
   - Protect image markdown before link/emphasis replacements in `blog_render_inline_markdown()` so linked images `[![alt](src)](href){nofollow}` and standalone images `![alt](src)` render safely with `rel="noopener noreferrer"` (plus `nofollow` when requested).

2. **Blog Editor (`admin/blog-publish.php` & `admin/blogs.php`)**:
   - Load existing posts for editing via `/admin/blog-publish.php?id=<slug>` fetching `/admin/blog-edit.php` and hydrating via `populateEditorFromBlog()`.
   - **Critical JS Checks**:
     - Ensure `slotDemoSearchTimer` (and all timer/state variables) is declared only once in `DOMContentLoaded` so no compile-time `SyntaxError` blocks editor hydration.
     - Guard optional/nullable DOM references (`if (writerField)`, `cancelBtn`, `keyphraseSynonyms`, `seoScoreBadge`, `saveState`) in `populateEditorFromBlog()` so execution never aborts before `setEditorMarkdown()` and `setImage()`.
     - Strip `****` and leading/trailing `*` from FAQ question strings in `normalizeFaqItems()` and `nodeToMarkdown()` so plain `<input>` FAQ question fields do not show literal asterisks.
   - In `parseMarkdown()` in both `admin/blog-publish.php` and `admin/blogs.php`:
     - Normalize legacy broken linked-image syntax `[!alt(src)](href){nofollow}` into `[![alt](src)](href){nofollow}` before escaping/replacing.
     - Support both local `/uploads/blogs/...` paths and `https?://...` URLs in image and linked-image regexes so content images always display in WYSIWYG, Preview, and Yoast SEO checks.
   - Ensure Demo Blocks (`:::slot-demo`) support game search via `GET /admin/game-search.php?search=...` and dynamically fetch signed iframe URLs via `GET /api/slotlaunch-embed.php?id=<api_id>`.

3. **WordPress WXR Importer & Local Image Localization**:
   - **`admin/includes/blog-wordpress-import.php`**:
     - In `blog_wordpress_markdown()`:
       - Preserve inner brackets for linked images (`[![alt](src)](href){nofollow}`) instead of stripping `[` and `]` inside `<a>` tags.
       - Extract fallback image URLs from `src`, `data-src`, `data-lazy-src`, and the first URL in `srcset`.
       - Strip `<script>`, `<style>`, `<noscript>`, and `<template>` elements completely.
       - Escape `:::` sequences in `DOMText` and `<pre>` nodes as `: : :` so collapsed HTML cannot inject `:::custom-code` blocks.
       - Strip `****` and leading/trailing `*` from extracted FAQ question headings (`blog_clean_faq_question()` / `blog_convert_markdown_faqs()`).
     - In `blog_wordpress_manifest()`:
       - Populate `'images' => array_keys($remoteImages)` covering `_thumbnail_id` attachment URLs, `<wp:post_parent>` attachments, and content `src`/`srcset` URLs.
       - Fall back to the first `<wp:post_type>attachment</wp:post_type>` matching `<wp:post_parent>` when `_thumbnail_id` is absent.
     - Implement `blog_repair_image_markdown(string $markdown, callable $localize): array` to repair mangled `[!alt(url)](href){nofollow}` links, strip `****`/outer `*` on `Q:` FAQ lines, and localize remote image URLs cleanly while reporting per-URL download failures without aborting unaffected content.
   - **`admin/includes/blog-import-batches.php`**:
     - Implement `blog_import_localize_remote_image(string $url): string` (and delegate `blog_import_restore_remote_image()` to it) to download remote images via cURL, validate MIME type & dimensions via `validate_blog_image_file()`, save content-addressed files (`substr(hash_file('sha256', $tmp), 0, 32) . '.' . $ext`) into both `admin/uploads/blogs/` and `uploads/blogs/`, and return `/uploads/blogs/<filename>`.
   - **`admin/scripts/repair-wordpress-images.php`**:
     - Provide CLI recovery script supporting `--xml=/path/to/export.xml`, `--post=slug`, and `--dry-run` that backs up `blogs.sqlite`, localizes featured and content images, updates `blog_posts`, triggers indexing queue updates, and clears API cache.

4. **Scheduled Blog Publisher (`admin/scripts/publish-scheduled-blogs.php`)**:
   - Ensure due `scheduled` posts automatically publish based on `ADMIN_TIMEZONE`, clear API caches, and enqueue URLs in the indexing queue.

---

### 4. SlotsLaunch Signed Demo Iframes & Games Plugin (`admin/plugin/games/`, `game-api/`)
1. **HMAC-SHA256 Signing Client (`admin/includes/slotslaunch-client.php`)**:
   - Implement `SlotsLaunch\Client($apiKey, $apiSecret, $siteDomain, 'https://slotslaunch.com', $ttl)` with:
     - `iframeUrl(int $gameId, ?int $ttl = null): string` (`$exp = time() + $ttl`, query params `token`, `exp`, `sig`).
     - `signEmbed(int $gameId, int $exp): string` (`hash_hmac('sha256', "$gameId\n$exp\n$siteDomain", $apiSecret)` where `$siteDomain` strips `www.`).
     - `apiHeaders(string $method, string $path, ?int $timestamp = null): array` (`X-SL-Timestamp` & `X-SL-Signature`).
2. **Signed Embed Endpoint (`api/slotlaunch-embed.php`) & Admin Search (`admin/game-search.php`)**:
   - `GET /api/slotlaunch-embed.php?id=<gameId>` (or `?url=https://slotslaunch.com/iframe/<id>`): signs embed URL server-side using `admin/.env` credentials and returns `{"url": "..."}`.
   - `GET /admin/game-search.php?search=<term>&limit=8` (authenticated): searches eligible games (`published=1`, PH-allowed, visible) and returns normalized `slot_list_item()` payloads with signed `iframeUrl`.
   - Use `SlotsLaunch\Client` in both `slot_list_iframe_url()` (`admin/plugin/games/includes/game-public.php`) and `blog_slotlaunch_signed_iframe_url()` (`admin/includes/blog-renderer.php`).
3. **Public Game APIs & Eligibility**:
   - Serve public routes via `/game-api/slot-list.php`, `/game-api/provider-list.php`, `/game-api/game.php`, `/game-api/game-play.php` (keeping `/api/` adapters compatible and returning 404 when plugin unavailable).
   - Enforce `published=1`, `done_processing=1`, `is_viewable=1`, approved provider, valid slug, and PH restriction checks via `admin/plugin/games/includes/game-visibility.php` and `game-restrictions.php`.
   - Implement **Recheck PH Availability** (`admin/plugin/games/includes/ph-availability.php`, `recheck-ph.php`, `recheck-ph.js`) to batch-verify SlotsLaunch country restrictions (`100` games/batch) using signed API headers and rebuild game sitemaps (`admin/plugin/games/scripts/generate-game-sitemaps.php`).

---

### 5. Odds API, Sports Registry & Logo Enrichment (`admin/plugin/odds-api/`)
1. **Sports Registry & Logo Management**:
   - Shared logo form fields & live preview (`admin/plugin/odds-api/includes/logo-fields.php`, `admin/plugin/odds-api/registry/logo-editor.js`) for team and tournament editors (`registry/team-edit.php`, `registry/tournament-edit.php`, `registry/teams.php`, `registry/registration.php`), preserving manual uploads during auto-enrichment.
   - Automated free logo enrichment (`admin/plugin/odds-api/scripts/daily-sports-images.php`, `scripts/enrich-sports-images.php`) fetching from SportsDataverse and Wikimedia Commons with file locking and 429 rate-limit backoff.
2. **Generic Sports Layout Builder & Public Endpoints**:
   - Admin layout builder at `/admin/plugin/odds-api/sports/index.php` (`field|loop|button|link|text|image` node tree stored in `sports-livescore.sqlite`).
   - Public endpoints `/odds-api/sports-layout.php?id=N&sport=KEY` (backed by `admin/plugin/odds-api/api/sports-layout.php`) and `/odds-api/sports.php`, with SSRF-hardened fetching, host caching (`sport_api_cache`), and IP rate limiting.

---

### 6. Live Chat, AI Worker, Tickets & Global SEO
- **Live Chat (`api/chat-session.php`, `api/chat-auto-responses.php`, `api/chat-link-preview.php`, `admin/scripts/chat-ai-worker.php`, `admin/scripts/chat-socket/server.mjs`)**:
  - Support token-authenticated real-time chat (`start|send|poll|heartbeat|disconnect`), keyword auto-responses (up to 5 suggestions), 180s initial AI fallback timer, preserved whitespace/newlines, and WebSocket relay `/chat-ws`.
- **Google Search Console, Sitemap Submission & IndexNow (`admin/includes/google-credentials.php`, `admin/includes/seo-settings.php`, `admin/includes/public-head.php`, `admin/includes/indexnow.php`, `admin/indexnow-key.php`, `admin/includes/indexing-queue.php`, `admin/scripts/check-google-indexing.php`)**:
  - Store site SEO settings (`website_name`, `website_url`, `google_analytics_id`, `google_tag_id` such as `GTM-*` / `GT-*` / `G-*` / `AW-*`, `google_search_console_verification`, `default_seo_title`, `default_meta_description`, `default_og_image`) in `blogs.sqlite` → `app_settings` and expose them via `/api/settings/seo.php` and `public_head_html()`.
  - Store Google Search Console & Indexing API configuration and service-account credentials (`property`, `status`, `submission_pending`, `indexing_api_enabled`, `credentials`, `last_authenticated`, `removed`) in `blogs.sqlite` → `app_settings` (`google_config`), auto-migrating any legacy `google-config.json` from `GOOGLE_PRIVATE_DIR`. Keep `google_config` excluded from `/api/settings/seo.php` and restricted to `super_user`.
  - Store IndexNow settings (`indexnow_config`) in `blogs.sqlite` → `app_settings`, with `admin/indexnow-key.php` + `.htaccess` fallback serving `/{key}.txt` from SQLite if static file creation is restricted.
  - Automatically submit sitemaps to Search Console (`HTTP 204` expected), inspect up to daily quota (`200/day`), ping IndexNow for Bing/Yandex on content changes/removals, and only call Google Indexing API (`URL_UPDATED`/`URL_DELETED`) when enabled in SEO Settings or `GOOGLE_INDEXING_ENABLED="true"` (sites without `JobPosting`/`BroadcastEvent` schema receive `HTTP 403` from Google Indexing API and should leave it disabled).

---

### 7. Scheduled Cron Jobs (`Cron_Setup.md`)
Configure these recurring CLI jobs in aaPanel Cron or system crontab (all running from site root, no `&` or `nohup`):
1. **Every 1 minute**: `php admin/scripts/publish-scheduled-blogs.php` (Blog Scheduler — Critical)
2. **Every 30 minutes**: `php admin/scripts/check-google-indexing.php` (Sitemap submission, URL Inspection & IndexNow)
3. **Daily 03:00**: `php admin/plugin/odds-api/scripts/daily-sports-images.php` (Sports team/tournament logo enrichment)
4. **Daily 04:00**: `php admin/plugin/games/scripts/generate-game-sitemaps.php` (Game XML sitemaps & `sitemap-index.xml`)
5. **Daily 02:00 (Optional)**: `python3 admin/plugin/games/scripts/enrich-games-daily.py` (AI game descriptions via OpenRouter `nvidia/nemotron-3.5-super-vl:free`, max 20/run)
6. **Weekly Sun 05:00 (Optional)**: `php admin/plugin/odds-api/scripts/sync-odds-participants.php` (Sports participant rosters)

---

### 8. Production Packaging & Verification
1. **Deployment Packaging (`admin/scripts/updater.py`)**:
   - Build deployable update package: `python3 admin/scripts/updater.py --build .` (outputs `admin/update-packages/full/updater.py` and `site-update.zip`, excluding `.env`, SQLite DBs, uploads, chats, logs).
   - Validate on server before install: `python3 updater.py --check`, then install with `python3 updater.py` during maintenance mode.
2. **Mandatory Verification Before Completion**:
   - Run `php -l` on all modified PHP files.
   - Check inline JS syntax (`node --check`) in admin templates (`admin/blog-publish.php`, `admin/blogs.php`).
   - Run relevant test scripts in `admin/test/`:
     - `php admin/test/test-fresh-storage.php`
     - `php admin/test/test-blog-wordpress-import.php`
     - `php admin/test/test-blog-import-batches.php`
     - `php admin/test/test-blog-import-post.php`
     - `php admin/test/test-public-details.php`
     - `php admin/test/test-game-storage.php`
     - `php admin/test/test-game-visibility.php`
     - `php admin/test/test-game-ph-recheck.php`
     - `php admin/test/test-registry-logos.php`
     - `php admin/test/test-odds-api.php`
     - `php admin/test/test-sports-api.php`
     - `php admin/test/test-index-checker.php`
     - `php admin/test/test-indexnow.php`
     - `php admin/test/test-google-credentials.php`
     - `node admin/test/test-custom-code-editor.cjs`
```
