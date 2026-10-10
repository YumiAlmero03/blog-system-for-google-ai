# Scripts

Utility scripts for maintaining the site, admin credentials, blog API access, static routes, assets, and game data.

Run commands from the project root unless noted otherwise:

```sh
cd /path/to/www
```

## Requirements

- PHP CLI for `.php` scripts.
- Node.js for `.cjs` scripts.
- Python 3 for `enrich-game-descriptions.py`.
- SQLite support in PHP/Python for scripts that read or update `admin/storage/blogs.sqlite`.
- A configured `.env` file when scripts need app settings or API credentials.

## Safety Notes

Some scripts modify files or database records:

- `sync-static-routes.php`, `sync-blog-routes.php`, `sync-game-routes.php`, and `build-static-routes.sh` write route `index.html` files.
- `apply-noindex.cjs` rewrites robots meta tags in `.html` and `.php` files.
- `minify-assets.cjs` rewrites minified CSS/JS assets.
- `import-slotslaunch-*.php` update game/provider tables in `admin/storage/blogs.sqlite`.
- `enrich-game-descriptions.*` update game descriptions, RTP, and volatility unless `--dry-run` is used.

Use `--dry-run` when available before running data imports or enrichment on production data.

## Script Reference

### `generate-llms.php`

Create or replace the public site-root `llms.txt` using the existing Ollama configuration. The script reads `sitemap.xml`; if no local file exists it uses local `sitemap-index.xml`, otherwise the configured site's `/sitemap.xml`. `--sitemap` explicitly selects a local XML file or an HTTP(S) sitemap URL. Sitemap indexes are followed recursively, duplicate URLs are removed, and only URLs on the configured site's origin are accepted. All sitemap URLs must be real absolute URLs: replace `[insert_domain_here]` and example/test hosts before a production run.

```sh
# Preview generated text without writing (still calls AI).
php admin/scripts/generate-llms.php --base-url=https://your-domain.com --dry-run

# Create/update www/llms.txt using the configured site and Ollama service.
php admin/scripts/generate-llms.php

# Explicit remote sitemap and optional model/output overrides.
php admin/scripts/generate-llms.php --sitemap=https://your-domain.com/sitemap.xml --base-url=https://your-domain.com --model=gemma4:31b-cloud --max-pages=40 --output=/path/to/www/llms.txt
```

Options: `--sitemap=PATH_OR_URL`, `--base-url=URL`, `--output=PATH.txt`, `--model=NAME`, `--max-pages=N` (1–100, default 40), `--dry-run`, `--help`. Relative paths resolve from the current working directory. Requires PHP CLI with cURL, DOM/libxml and mbstring. No Games or Odds API plugin is required.

Environment: `SITE_BASE_URL` (or stored SEO site URL), `OLLAMA_GENERATE_URL` (preferred over `OLLAMA_HOST` plus `/api/generate`), optional `OLLAMA_API_KEY`, and model priority `--model`, `LLMS_AI_MODEL`, `OLLAMA_MODEL`, then `gemma4:31b-cloud`. The normal `admin/.env` loader is reused. Local Ollama defaults to `http://127.0.0.1:11434`. The AI key is sent only to the configured AI endpoint, never to sitemap/page requests.

The generator scans up to 100 sitemap documents and 50,000 unique URLs, then fetches up to `--max-pages` pages, prioritizing short, shallow paths. This is a curated overview, not a copy of every sitemap entry. It excludes admin/API/login routes, query/fragment URLs, and pages with HTML noindex directives. Public titles, descriptions and short body excerpts are sent to the configured AI service. Navigation, scripts and forms are stripped. Model-generated page IDs are validated against the supplied pages; URLs are assembled by the script. No page or sitemap PHP is executed locally: dynamic sitemaps are fetched over HTTP. Remote redirects are not followed; use canonical URLs returning HTTP 200.

The generated Markdown uses the [llms.txt format](https://llmstxt.org/), with a site heading, summary and grouped links; AI generation uses the [Ollama generate API](https://docs.ollama.com/api/generate). Output replaces the file atomically only after successful fetching, generation and validation. Errors exit 1 and preserve any existing file; an empty/invalid sitemap does not erase it. An adjacent `.lock` file prevents overlapping write runs. `admin/llms.txt`, the existing legacy file, is not modified; the default output is `/llms.txt` at the public site root. No database writes occur.

Optional daily cron (adjust PHP, paths and server timezone; scheduling is not installed automatically):

```cron
15 3 * * * /usr/bin/php /path/to/www/admin/scripts/generate-llms.php >> /path/to/private-storage/llms-generation.log 2>&1
```

Regression checks: `php admin/scripts/test-generate-llms.php`. Fixtures cover sitemap recursion/cycles, deduplication, private/noindex exclusions, HTML extraction, AI IDs/format, first creation, replacement, dry run, failed-generation preservation, locking and unsafe/malformed XML. Tests make no live AI requests.

### Yoast readability checks and highlights

Publish Post shows only meaningful Yoast assessment results; empty/unscored results no longer appear as red “Yoast assessment completed” rows. Intermediate scores use orange indicators. Readability findings with matching passages have individual highlight toggles, plus an overall toggle. Highlights use the browser CSS Highlight API in Write, Preview and Split modes; they do not insert markup into the editor, saved content, or undo history. Source-only Markdown mode directs the user to a rendered mode. Whole-article checks without passage marks remain listed without a passage toggle.

Run `node admin/scripts/test-yoast-analysis.cjs` to test against the bundled Yoast engine in headless Chrome. Requires PHP CLI and an installed Chrome/Chromium browser; set `CHROME_BINARY` if needed (defaults to Google Chrome on macOS and `chromium` elsewhere). The test uses a temporary local server/profile and fixture content; no accounts, provider requests or production data are used.

### Continuous chat services

See [Chat integration](../CHAT-INTEGRATION.md) for the complete deployment and frontend guide.

```sh
npm ci --prefix admin/scripts/chat-socket
php admin/scripts/chat-ai-worker.php
```

In another terminal (local example):

```sh
CHAT_ALLOWED_ORIGINS=http://localhost:8083 CHAT_BACKEND_URL=http://127.0.0.1:8083/api/chat-session.php node admin/scripts/chat-socket/server.mjs
```

The PHP worker uses the existing Ollama host/key and defaults specifically to `gemma4:31b-cloud`, with `CHAT_AI_MODEL` as an optional override independent of `OLLAMA_MODEL`. `CHAT_AI_ENABLED=0` disables AI. Optional `--once` scans once; normal mode scans continuously every two seconds. The worker sends recent message text to the configured Ollama service and saves replies in existing private chat files. No web search is used. Node 22+ and the `ws` dependency are required for the relay; Node receives its configuration through process environment variables. Run both as supervised services in production and proxy `/chat-ws` over WSS.

Tests use temporary storage: `php admin/scripts/test-chat-realtime.php` and `node admin/scripts/chat-socket/test.mjs` (local mock AI; requires localhost listeners).

### `generate-password.php`

Generate a password hash for the admin login.

```sh
php admin/scripts/generate-password.php
*write your password*
*copy password and paste it to .env*
```

The output can be used as `ADMIN_PASSWORD_HASH` in `.env`.

### `generate-blog-api-token.php`

Generate a signed JWT for the public blog post list API.

```sh
php admin/scripts/generate-blog-api-token.php
php admin/scripts/generate-blog-api-token.php 604800
```

The optional argument is the token lifetime in seconds. Values are clamped between 300 seconds and 31,536,000 seconds.

### `sync-static-routes.php`

Create static `index.html` route files from the root `index.html` app shell.

```sh
php admin/scripts/sync-static-routes.php
```

It writes fixed routes like `app`, `blog`, `blogs`, `contact`, `invite`, `payments`, `support`, and `vip`. If `admin/storage/blogs.sqlite` exists, it also writes published game and blog routes.

### `sync-blog-routes.php` and `sync-game-routes.php`

Compatibility wrappers around `sync-static-routes.php`.

```sh
php admin/scripts/sync-blog-routes.php
php admin/scripts/sync-game-routes.php
```

### `build-static-routes.sh`

Shell wrapper around `sync-static-routes.php`.

```sh
admin/scripts/build-static-routes.sh
```

### `minify-assets.cjs`

Minify the main CSS and JavaScript files.

```sh
node admin/scripts/minify-assets.cjs
```

Writes:

- `assets/css/styles.min.css`
- `assets/js/main.min.js`
- `assets/js/blog.min.js`

### `apply-noindex.cjs`

Set robots meta tags to `index, follow` across `.html` and `.php` files, excluding `uploads`.

```sh
node admin/scripts/apply-noindex.cjs
```

Despite the filename, the current script writes:

```html
<meta name="robots" content="index, follow">
```

### `import-slotslaunch-providers.php`

Import or update game providers from the SlotsLaunch providers API, including validated local thumbnails. The original entrypoint and the sync command below share the same implementation.

```sh
php admin/scripts/import-slotslaunch-providers.php
php admin/scripts/import-slotslaunch-providers.php --page=2
php admin/scripts/import-slotslaunch-providers.php --url=https://example.test/providers.json
```

Environment variables:

- `SLOTSLAUNCH_API_TOKEN`, `SLOTSLAUNCH_TOKEN`, or `GAMES_API_TOKEN`
- `SLOTSLAUNCH_PROVIDERS_API_URL`
- `SLOTSLAUNCH_ORIGIN`

### `sync-providers.php`

Refresh provider metadata and download thumbnails into `uploads/providers`:

```sh
php admin/plugin/games/scripts/sync-providers.php
```

Uses the same token and API settings as `import-slotslaunch-providers.php`. Optional `--page=N` starts at that page and follows remaining pagination; `--url=...` overrides the provider API source. The Games module must be enabled. This is a CLI command, not a public HTTP write API.

The existing `game_providers.thumbnail` column stores `/uploads/providers/provider-<api-id>-<source-hash>.<extension>`. The public `/api/provider-list.php` returns that value in `thumbnail`. Valid cached files are reused, changed source URLs get new files, and a failed/missing image preserves the previous thumbnail. Failed downloads print the provider ID for retry on the next sync. Providers without an image remain empty. The sync updates metadata without changing provider approvals or game processing/publication flags and clears API caches after each page. Network downloads do not hold a database write transaction.

Image downloads reuse the game importer protections: public HTTP(S) addresses only, no redirects, 8 MB maximum, 40 megapixels maximum, and MIME/signature/dimension validation for JPEG, PNG, WebP, and supported AVIF. Files receive deterministic names and are moved into place only after validation. SVG/GIF sources are not accepted. Optional `PROVIDER_IMAGE_UPLOAD_DIR` overrides the physical upload directory for deployments/tests; public paths remain `/uploads/providers/...`.

The command exits with status 1 if any supplied thumbnail cannot be downloaded or validated, after saving the other providers; the final summary reports the failed image count. Missing/empty source URLs preserve the previous image and are not counted as download failures.

Source field priority is `thumb` (SlotsLaunch), then `thumbnail`, `image`, `logo`, and `icon` if the earlier field is absent or blank. This applies on both insert and update. PHP game imports also check the nested `provider.thumb` when creating/updating a provider; a missing nested image is left for provider sync and never replaced with the game's thumbnail.

Test with `php admin/scripts/test-provider-images.php` (temporary database/files; no live provider changes).

### `import-slotslaunch-games.php`

Import or update games from the SlotsLaunch games API.

Thumbnails are validated and saved under `/uploads/games/` with deterministic game-ID/source-hash filenames. Valid cached files are reused; changed source URLs produce new files without overwriting the previous image. Only thumbnails are downloaded. Public APIs use the shared absolute URL helper.

Downloads allow public HTTP(S) addresses, use 5-second connection/15-second total timeouts, and are limited to 8 MB and 40 megapixels. JPEG, PNG, WebP, and supported AVIF files must pass MIME/signature/dimension checks. Redirects are not followed; use a direct asset URL. Failed downloads log a sanitized source identity and reason, keep any previous valid local thumbnail, and set `done_processing=0`. A successful download does not mark the game processed; the existing completion workflow still applies. Re-encountering the game on import retries localization.

`GAME_IMAGE_UPLOAD_DIR` optionally overrides the physical games upload directory for isolated tests or deployments that map `/uploads/games/` to another directory; the default is the site's `uploads/games`. Tests: `php admin/scripts/test-game-images.php`.


```sh
php admin/scripts/import-slotslaunch-games.php
php admin/scripts/import-slotslaunch-games.php --all --page=1
php admin/scripts/import-slotslaunch-games.php --dry-run --limit=25
php admin/scripts/import-slotslaunch-games.php --page=2 --max-pages=5
php admin/scripts/import-slotslaunch-games.php --url=https://example.test/games.json
```

Options:

- `--dry-run`: fetch and count rows without changing the database.
- `--limit=N`: import or fetch at most `N` games, capped at 100; default 100.
- `--all`: remove the game-count limit and follow pagination; also works with `--dry-run`. Cannot be combined with `--limit`. Starting-page and maximum-page settings still apply (default maximum: 10,000 pages).
- `--page=N`: start from a specific API page.
- `--max-pages=N`: stop after at most `N` pages.
- `--url=URL`: override the default games endpoint.

Temporary network failures and HTTP 408/429/500/502/503/504 responses retry the same page four times, waiting 2, 4, 8, then 16 seconds. If fetching still fails, the importer stops with the page number to resume; previously committed pages remain saved. For example, after page 302 was saved, resume with `php admin/scripts/import-slotslaunch-games.php --all --page=303`. Keep any original `--url` setting when resuming.

Environment variables:

- `SLOTSLAUNCH_API_TOKEN`, `SLOTSLAUNCH_TOKEN`, or `GAMES_API_TOKEN`
- `SLOTSLAUNCH_GAMES_API_URL`
- `SLOTSLAUNCH_START_PAGE`
- `SLOTSLAUNCH_MAX_PAGES`
- `SLOTSLAUNCH_ORIGIN`

### `enrich-game-descriptions.php`

Generate or fill missing game RTP, volatility, short descriptions, and long descriptions using Ollama. Falls back to local generated copy if Ollama fails.

```sh
php admin/scripts/enrich-game-descriptions.php --dry-run --limit=10
php admin/scripts/enrich-game-descriptions.php --limit=50 --offset=100
php admin/scripts/enrich-game-descriptions.php --slug=game-slug
php admin/scripts/enrich-game-descriptions.php --fallback-only --limit=25
```

Options:

- `--limit=N`: number of games to process, from 1 to 500. Default: 25.
- `--offset=N`: database offset. Default: 0.
- `--timeout=N`: Ollama request timeout in seconds, from 30 to 3600. Default: 420.
- `--retries=N`: Ollama retry count, from 0 to 5. Default: 1.
- `--model=NAME`: Ollama model name.
- `--ollama-url=URL`: direct Ollama generate endpoint.
- `--slug=SLUG`: process one game slug.
- `--overwrite`: replace existing enrichment values.
- `--dry-run`: show what would happen without updating rows.
- `--skip-errors`: accepted by the script output/config, but current PHP flow falls back on generation failures.
- `--fallback-only`: skip Ollama and use local fallback copy.

Environment variables:

- `OLLAMA_HOST`
- `OLLAMA_GENERATE_URL`
- `OLLAMA_MODEL`

### `enrich-game-descriptions.py`

Python version of the game enrichment workflow.

Game selection (including `--all-games` and failed retries) uses the public slot API's exact SQL predicates, exported by `game-eligibility-sql.php` through PHP CLI. Only published, processed, viewable games from approved providers without PH restrictions are eligible. Skipped descriptions and rewrite statuses are preserved; batch summaries count eligible candidates and overlapping skip reasons before pagination. Use the current application database schema; failure to load the shared rules stops enrichment.

```sh
python3 admin/scripts/enrich-game-descriptions.py --dry-run --limit 10
python3 admin/scripts/enrich-game-descriptions.py --slug game-slug --overwrite
python3 admin/scripts/enrich-game-descriptions.py --fallback-only --once
```

Options include `--limit`, `--offset`, `--timeout`, `--retries`, `--model`, `--ollama-url`, `--slug`, `--overwrite`, `--dry-run`, `--skip-errors`, `--fallback-only`, `--once`, and `--verbose`.

Ollama web search HTTP 429 immediately stops the entire Python enrichment run with exit status 1, without retrying, sleeping, generating content or advancing to another game/batch. This also applies with `--skip-errors` and `--all-games`. The current game is marked `failed` unless `--dry-run` is active; previously saved games are retained. Other search errors keep their existing retry behavior.

## Generated Files

Do not edit these manually unless you are intentionally replacing generated output:

- Minified files written by `minify-assets.cjs`.
- Static route `index.html` files written by route sync scripts.
- `admin/scripts/__pycache__/` Python cache files.

## Sports synchronization

Sports sync utilities now live in `admin/plugin/odds-api/scripts/`: `sync-odds-participants.php` and `sync-api-sports-teams.php`. See the [Odds API guide](../plugin/odds-api/README.md#sports-registry) for commands. Update existing cron paths.

Free image enrichment: `php admin/plugin/odds-api/scripts/enrich-sports-images.php [--tournament=ID_OR_ODDS_KEY] [--limit=5] [--dry-run] [--retry]`. SportsDataverse first, Wikidata/Commons fallback. See [Free sports images](../plugin/odds-api/README.md#free-sports-images).

Daily/background sports logos: `php admin/plugin/odds-api/scripts/daily-sports-images.php [--tournament=ID_OR_ODDS_KEY] [--max-images=100] [--max-items=1000] [--max-seconds=1800] [--batch-size=25] [--pause-ms=1000]`. Exclusive lock, resumable batches, private rotating log, HTTP 429 wait-and-retry. See [aaPanel setup](../plugin/odds-api/README.md#dailybackground-image-job-aapanel).

Game description enrichment status now reports `remaining eligible` across the matching selection (before batch limit/offset) and `left to attempt in batch`. Successful saves reduce the remaining count; failures, skipped saves and dry runs do not. Counts are a selection snapshot for each attempt; no new flags or database changes. Successful progress lines also appear in `enrich-game-descriptions.log`.

`php admin/scripts/test-default-image.php` verifies shared default-image upload validation, blog fallback resolution, sports fallback payloads, replacement/removal and preservation of registry records. Uses isolated storage and removes test uploads.
