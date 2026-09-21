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

Import or update game providers from the SlotsLaunch providers API.

```sh
php admin/scripts/import-slotslaunch-providers.php
php admin/scripts/import-slotslaunch-providers.php --page=2
php admin/scripts/import-slotslaunch-providers.php --url=https://example.test/providers.json
```

Environment variables:

- `SLOTSLAUNCH_API_TOKEN`, `SLOTSLAUNCH_TOKEN`, or `GAMES_API_TOKEN`
- `SLOTSLAUNCH_PROVIDERS_API_URL`
- `SLOTSLAUNCH_ORIGIN`

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

## Generated Files

Do not edit these manually unless you are intentionally replacing generated output:

- Minified files written by `minify-assets.cjs`.
- Static route `index.html` files written by route sync scripts.
- `admin/scripts/__pycache__/` Python cache files.
