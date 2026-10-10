# Odds API

Management now lives in **Odds API → Sport / Competition** at `/admin/plugin/odds-api/sports/`.
See the [API layout builder guide](sports/README.md) for API URLs, provider settings, field discovery, nested loops, labels, columns, embedding, migration and deployment.

`/admin/plugin/odds-api/` is an authenticated compatibility redirect, not a second editor. Existing `/odds-api/?iframe=N` embeds use the shared saved-layout renderer. Provider credentials, regions, bookmakers and the master switch remain under `/admin/plugin/odds-api/settings.php`; each migrated layout retains its markets selection.

The legacy `/api/odds-api.php?action=sports|odds&iframe=N&sport=KEY` raw feed remains available for existing integrations. The iframe now uses `/api/sports-layout.php`. See [API.md](/admin/API.md) for both contracts.

## Sports Registry

Sports categories, tournaments, participants, logos, image uploads and provider enrichment live at `/admin/plugin/odds-api/registry/`, under **Odds API → Sports Registry** in the header. The existing `/sports/` folder remains the iframe layout editor. Former `/admin/settings/sports/` URLs redirect here with query strings and request methods preserved. Deploy the root `.htaccess` with this move.

Registry implementation: `includes/sports-registry.php`. Existing `sports-registry.sqlite` storage and upload paths are retained; no data migration is required.

Run synchronization from the project root:

```sh
php admin/plugin/odds-api/scripts/sync-odds-participants.php [--sport=KEY] [--category=SLUG] [--key=API_KEY]
php admin/plugin/odds-api/scripts/sync-api-sports-teams.php --sport=SLUG_OR_ID [--competition=ID] [--season=YEAR]
```

Update any cron jobs using the former `admin/scripts/` locations. Registry regression check: `php admin/scripts/test-sports-registry.php`.

## Free sports images

Open **Odds API → Sports Images** (`registry/images.php`) or **Enrich Logos** on a registry tournament. Preview or enrich up to five missing team/tournament logos per request, using SportsDataverse first, then Wikidata P154 → Wikimedia Commons. Existing/manual participant images are never replaced. Unmatched names remain unresolved; no fuzzy nickname matching is applied. The current registry, rather than a season subscription, supplies the list of entities.

SportsDataverse's manifest is cached privately for 24 hours. Matching uses exact normalized names and supported competition mappings; duplicate identities, historical ranges, wordmarks and unsupported image formats are skipped. Current means the latest captured eligible logo, not an independently verified rebrand date. Wikidata requires an exact English label/alias, matching P641 sport, and a single current P154 logo. Ambiguous or missing records stay unmatched. Commons photographs (P18) are not substituted for logos.

Validated PNG/JPEG/WebP files are stored in `/uploads/sports/`; Commons SVGs use raster thumbnails. HTTPS hosts are restricted, redirects disabled, downloads size/time limited. A provider HTTP 429 immediately stops the batch. Source failure can fall back to Commons. `sports_image_assets` in the existing registry database stores tournament logos, provenance, license/artist/credit information and attempt status; participant paths also update `participants` and legacy `teams`. Tournament logos are available to iframe sport-image resolution, with the existing category image fallback. The iframe links to public `/odds-api/?credits=1` for paginated image attribution.

Run from the project root:

```sh
php admin/plugin/odds-api/scripts/enrich-sports-images.php --tournament=basketball_nba --limit=5 --dry-run
php admin/plugin/odds-api/scripts/enrich-sports-images.php --tournament=basketball_nba --limit=5
php admin/plugin/odds-api/scripts/test-sports-images.php
```

`--tournament` accepts a registry ID or Odds sport key; omit for all enabled tournaments. `--limit` is 1–25 (default 5); each run stops between items after roughly 45 seconds. Re-run to process remaining items. `--dry-run` previews matches without assigning images or recording attempts (the source cache/schema can initialize). Unmatched items cool down for seven days, errors for one hour; `--retry` retries them immediately. Existing images are always skipped. `SPORTS_IMAGE_UPLOAD_DIR` overrides the physical image folder for tests; public paths remain `/uploads/sports/`.

Provider references: [SportsDataverse manifest](https://github.com/sportsdataverse/sdv-assets), [Wikidata logo property](https://www.wikidata.org/wiki/Property:P154), [Commons image information API](https://www.mediawiki.org/wiki/API:Imageinfo).

## Daily/background image job (aaPanel)

`daily-sports-images.php` repeatedly fills missing logos using SportsDataverse then Wikidata/Commons. It uses an exclusive storage lock, skips already attempted items within a run, and resumes the eligible queue next time. It does not refresh existing/manual logos or import new participants; continue using the registry participant sync for newly added teams.

Defaults: target **100 saved images**; at most **1,000 items**, **1,800 seconds**, **25 items per batch**, **1 second between batches**. Time limits are checked between items; an in-flight request can finish after the limit. HTTP 429 waits 30 minutes and retries automatically; waiting does not consume the active time budget. Logs go to stdout and private `sports-images-daily.log` beside `sports-registry.sqlite` (`APP_STORAGE_DIR` when configured), rotating at 10 MB with one retained archive. A concurrent job exits successfully with a skipped message. Provider failures exit 1; normal completion, item/time limits and overlap skips exit 0.

In **aaPanel → Cron → Add Task**, choose **Shell Script**, a **Daily** schedule (for example 03:00 server time), and the site's execution user (`www` where applicable). Replace the site directory and `82` with the installed PHP CLI version (PHP 8.1+ with cURL and PDO SQLite):

```sh
/www/server/php/82/bin/php '/www/wwwroot/YOUR_SITE/admin/plugin/odds-api/scripts/daily-sports-images.php' --max-items=1000 --max-seconds=1800
```

Cron already runs unattended; do not add `&` or `nohup` to the aaPanel task. Use **Execute** to test and **Log** to inspect output. [aaPanel Cron documentation](https://www.aapanel.com/docs/Function/Cron.html).

For a manual detached terminal run:

```sh
nohup /www/server/php/82/bin/php '/www/wwwroot/YOUR_SITE/admin/plugin/odds-api/scripts/daily-sports-images.php' --max-items=1000 --max-seconds=1800 > /tmp/sports-images-background.log 2>&1 < /dev/null &
```

Optional flags: `--max-images=100`, `--tournament=ID_OR_ODDS_KEY`, `--max-items=1..100000`, `--max-seconds=1..86400`, `--batch-size=1..25`, `--pause-ms=0..5000`, `--help`. The daily job respects the normal seven-day unmatched / one-hour error cooldowns. No scheduler is registered automatically by deploying these files.

Iframe and editor registry images use same-origin `/uploads/...` paths, including saved tournament logos. This works even before `SITE_BASE_URL` is configured. Missing registry logos remain empty instead of using team names as image URLs; explicit custom-image fallbacks remain supported. HTTPS custom image sources are permitted by the iframe's image CSP. Public registry helper callers retain absolute URLs by default; the iframe opts into local paths.

Checks:

```sh
php admin/plugin/odds-api/scripts/test-sports-images-daily.php
php admin/plugin/odds-api/scripts/test-sports-images.php
node admin/plugin/odds-api/scripts/test-iframe-images.cjs
```

The browser check requires Chrome (or `CHROME_BINARY`) and uses a temporary registry/server without contacting providers.

Daily image eligibility: both `sports_categories.enabled=1` and `tournaments.enabled=1` are required. The daily runner also ignores provider-inactive tournaments (`active=0`). Participants qualify only through eligible tournaments. `--tournament` does not bypass these filters; interactive enrichment behavior is unchanged.

Daily sports images HTTP 429 behavior: persist the 30-minute cooldown, keep the worker and lock alive, wait in 30-second intervals, and retry automatically. Existing cooldowns are awaited on startup. Waiting time is excluded from the active processing time budget. Repeated 429 responses repeat the wait; progress is retained. Default target is 100 successfully saved images (`--max-images=100`); skipped/unmatched/failed images do not count. Existing item/time safety limits still apply. No database changes.

## Default image

Upload a shared fallback in **Settings → Default fallback image**. Missing sports image blocks display this image; failed image loads switch to it once. The layout editor uses the same fallback. This changes display only: missing team logos remain eligible for enrichment, and actual/manual logos are not overwritten. Removing the setting restores the previous empty-image behavior.

Sports image queue: all enabled/active leagues remain in daily scope unless `--tournament` is supplied. Available exact SportsDataverse matches are processed before Wikidata searches, preventing alphabetical athlete records from delaying team logos. NBA/NCAA/NFL and other mapped preseason/championship-winner keys share their base league mapping; AHL/OHL/WHL/QMJHL/UFL mappings are included. Existing image paths and manual images are skipped. Logs show skipped-existing, skipped-manual and deferred-cooldown counts. New exact SDV matches can bypass a previous unmatched cooldown; uncertain identities are never guessed. Wikimedia requests are paced at least one second apart within a worker; the existing 30-minute HTTP 429 cooldown remains. No new flags or schema changes.

## Sports layout list endpoint

The public sports tree and saved layout list now live at `/odds-api/sports.php`. Use `/odds-api/sports.php?view=layouts` for the flat layout list, optionally `&sport=basketball_nba`. Methods, filters, permissions, CORS and response fields are unchanged. `/api/sports.php` remains a compatibility adapter. Implementation remains inside this plugin at `api/sports.php`; removing the plugin returns JSON 404 from either public route. Individual layout data remains at `/api/sports-layout.php?id=N`.
