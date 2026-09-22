# Index Checker setup

Admin page: `/admin/index-checker/`. Opening it only reads saved data. Recheck buttons and selected-row actions queue work. SEO Settings also provides a super-user-only Google connection test. Admins and super users have access; editors do not.

## Configure Google access

1. Enable the Google Search Console API in your Google Cloud project.
2. Create a service account and grant its email access to the correct Search Console property (owner or full-user access as appropriate for URL inspection).
3. As a super user, open **SEO Settings → Google API / Search Console**, set the property, upload the service-account `.json`, then test the connection. Upload, replacement, removal and submission queue controls are super-user-only. Admins see only safe status/property/email fields.
4. Credentials are stored atomically with mode `0600` in `google-config.json`, inside a mode `0700` directory **outside the public site**. Set `GOOGLE_PRIVATE_DIR` to a private absolute directory writable by the web/cron account; its parent must exist. The default is a sibling `.google-private-<site-path-hash>` directory. Never publish this directory through another web-server mapping or include it in deployment archives.
5. Existing installations can alternatively keep these private environment values:

```text
GSC_SERVICE_ACCOUNT_FILE=/private/server/path/search-console-service-account.json
GSC_PROPERTY=sc-domain:freecasinogames.ph
```

Use the exact property registered in Search Console. URL-prefix properties are also supported, for example `[insert_domain_here]/` (trailing slash required). Saved settings take precedence; removing credentials also disables the legacy file fallback. If neither a saved property nor `GSC_PROPERTY` exists, the worker uses the existing SEO website URL, respecting `SITE_BASE_URL` precedence. The site origin is always the existing SEO website URL; sitemap discovery starts at its `/sitemap-index.xml`.

PHP needs PDO SQLite, DOM, cURL and OpenSSL. No Composer packages are required. Inspection and connection tests request `webmasters.readonly`; sitemap submission requests `webmasters`. Access tokens are held in process memory; key material and raw Google responses are not stored in SQLite.

## Schedule daily

From the server site directory, first run:

```sh
php admin/scripts/check-google-indexing.php
```

Example cron entry (replace the PHP binary, site directory and private log path with your server paths):

```cron
15 2 * * * cd /www/wwwroot/freecasinogames.ph && /usr/bin/php admin/scripts/check-google-indexing.php >> /private/logs/index-checker.log 2>&1
```

Cron reconciles current public content with its previous public-URL snapshot, submits the queued sitemap index, inspects due URLs, and independently processes IndexNow notifications without interactive login. Google failures do not stop IndexNow. Saving credentials/property queues submission; use **Queue Sitemap Submission** to resubmit later. Failed submissions remain queued. Normal game/blog URLs are never sent to the Google Indexing API.

Cron uses the server's timezone. This repository does not install a cron entry automatically. Use the same configuration/storage path as the website. Sitemap files must already be deployed and publicly reachable. Use the existing game sitemap generator after visibility changes; the blog sitemap is dynamic. No credentials have been provisioned by this implementation.

## Queue and limits

| Environment variable | Default | Meaning |
| --- | --- | --- |
| `INDEX_CHECK_BATCH` | 200 | Maximum due rows considered per run (up to 2,000). |
| `INDEX_CHECK_DAILY_LIMIT` | 1800 | Maximum attempts per configured property in a rolling 24 hours, capped at 2,000. |
| `INDEX_CHECK_MINUTE_LIMIT` | 60 | Maximum attempts per property in a rolling minute, capped at 600. |
| `INDEX_CHECK_INDEXED_DAYS` | 30 | Indexed-result interval. |
| `INDEX_CHECK_UNRESOLVED_DAYS` | 3 | Not Indexed interval. |
| `INDEX_CHECK_RETRY_HOURS` | 24 | Initial Unknown/Error delay; doubles up to seven days. |

Priority is never-checked URLs, manual rechecks, Not Indexed, Error/Unknown, then due Indexed URLs. Requests are also spaced by 1.1 seconds. Manual rechecks do not bypass persistent quota or cooldown. Requests are reserved before Google access, so interrupted/failed attempts conservatively consume local allowance. Other applications using the same Google property also consume Google's quota; lower the local budget accordingly.

HTTP 429 or quota errors stop the batch and set a persisted cooldown of at least 24 hours, honoring a longer Retry-After up to seven days. Authentication/permission errors stop further requests and persist a retry delay. Temporary inspection failures use per-URL exponential backoff. No tight retries occur. After fixing credentials, subsequent runs respect the recorded cooldown.

A filesystem lock prevents overlapping workers. A process killed mid-run may leave a `Running` history row; its lock releases and the next cron can continue. The attempt ledger still protects quota. Recent run summaries are retained for 90 runs; there is no full per-URL response/history archive.

## Sitemap and result behavior

- Discover nested same-origin XML sitemaps, including blog and numbered game sitemaps. Duplicate URLs are merged.
- Complete discovery must succeed before membership changes commit. A failed child fetch/parser leaves previous membership intact.
- Newly discovered URLs start Pending. Existing results survive synchronization. Removed URLs become inactive and consume no normal quota; a manual recheck can request an eligible removed URL.
- Current blog/game eligibility is checked in grouped database lookups to exclude stale sitemap entries for drafts, future posts, hidden/unprocessed/restricted games and unapproved providers.
- Admin/API/private/promo paths and query URLs are excluded. Before inspection, a public GET checks HTTP availability and robots/noindex directives. Those requests do not establish indexing status. Inactive/noindex URLs may be rediscovered by a later sitemap sync; their next eligibility check is delayed by the indexed interval to avoid starving the queue. Manual recheck can bring that eligibility check forward.
- Sitemap/page redirects are deliberately not followed; sitemap discovery fails safely on redirects, and redirected content URLs get a retryable error. Publish canonical same-origin URLs in the sitemap. Remote hosts must resolve to public IPv4 addresses; nonstandard ports are rejected.
- Google `PASS` maps to Indexed; `FAIL`/`NEUTRAL` to Not Indexed. Missing/unspecified/partial results map to Unknown. Request failures map to Error. Coverage/verdict, canonical URLs and crawl metadata remain visible as secondary fields; errors retain the last known Google fields.

This inspects Google's stored index information. It neither submits URLs for indexing nor performs Google's live inspection test.

## Verification

```sh
php admin/scripts/test-index-checker.php
```

Tests use temporary SQLite storage, sitemap fixtures, a temporary signing key, mocked Google transports and the existing isolated admin handler harness. They do not require production credentials or consume Google quota. Live credentials, property access and server cron execution must be verified after setup.

Official references: [URL Inspection request and authorization](https://developers.google.com/webmaster-tools/v1/urlInspection.index/inspect), [inspection result fields](https://developers.google.com/webmaster-tools/v1/urlInspection.index/UrlInspectionResult), [Google quotas](https://developers.google.com/webmaster-tools/limits), and [service-account OAuth](https://developers.google.com/identity/protocols/oauth2/service-account).

## IndexNow

Super users configure **SEO Settings → IndexNow**: enable/disable, save an existing 8–128 character alphanumeric/hyphen key, or generate a random key. The root `/{key}.txt` file contains only the key; replacement removes the previous matching file without overwriting unrelated files. The web process needs write permission to the public root. `INDEXNOW_PUBLIC_DIR` is a filesystem override for tests or deployments that map that directory to the same public root. Admins can view status; editors cannot manage these settings. The public SEO API exposes enabled/configured/status/key-location fields, not Google credentials.

**Test IndexNow** fetches the public key file and submits the site home URL. HTTP 200 means accepted; 202 means accepted with key verification pending, not indexing confirmation. Neither reports Google status. The shared helper uses `https://api.indexnow.org/indexnow` for Bing, Yandex and participating engines.

`indexing_public_urls` tracks canonical URLs that were publicly eligible. `indexing_notifications` deduplicates changes/removals by URL; `indexing_google_events` separately queues Google inspection changes. `indexing_jobs` holds sitemap work and IndexNow cooldowns. Content saves queue locally without contacting engines. Cron reconciliation also detects scheduled publication, imports, enrichment, and direct visibility changes. Never-public drafts, restricted games and private routes are excluded; a formerly public URL may be sent as a removal notification.

The existing cron sends at most 10,000 pending IndexNow URLs per batch. HTTP 200/202 clears only the submitted versions; concurrent edits remain queued. Failures retain notifications with exponential backoff (10 minutes to one day), honoring longer `Retry-After` values. HTTP 400/403/422 waits at least one day; saving a replacement key or enabling IndexNow resets the retry delay. A worker lock prevents overlapping IndexNow batches. A Google sitemap failure has its own one-hour-to-one-day backoff. No ordinary content is sent to Google's Indexing API.

Tests: `php admin/scripts/test-indexnow.php`, `php admin/scripts/test-google-credentials.php`, and `php admin/scripts/test-index-checker.php`. Remote engine responses are mocked.
