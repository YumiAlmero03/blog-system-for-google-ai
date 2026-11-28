# Index Checker setup

Admin page: `/admin/index-checker/`. Opening it only reads saved data. Recheck buttons and selected-row actions queue work. **Settings → SEO Settings** (`/admin/settings/seo/`) also provides super-user-only controls to configure credentials and test connections. Admins and super users can access `/admin/index-checker/`; editors do not.

For the actual browser-based GSC **Request indexing** workflow, see [the optional aaPanel browser worker](gsc-browser/README.md). It requires a separate Google browser login on the server, respects GSC's manual-request quota, and pauses for CAPTCHA/login/UI errors. The worker must pass a real server submission test before cron is enabled; it is not another Google API endpoint.

## Step-by-step setup guide

### 1. Google Cloud Console (`https://console.cloud.google.com/`)

1. Create a new Google Cloud project or select an existing one.
2. Open **APIs & Services → Library** and enable:
   - **Google Search Console API** (required for Search Console connection tests, Sitemap Submission, and URL Inspection).
   - **Web Search Indexing API** *(optional — only needed if you plan to enable Direct URL Submissions via Google Indexing API)*.
3. Open **IAM & Admin → Service Accounts → Create Service Account**, enter a name, and finish creating it (no project-level IAM role is required).
4. Copy the generated service-account email (for example `index-checker@your-project.iam.gserviceaccount.com`).
5. Open the service account's **Keys** tab → **Add Key → Create new key → JSON** to download your `.json` key file (must stay under 64 KB).

### 2. Google Search Console (`https://search.google.com/search-console`)

1. Select the property matching your live website:
   - Domain property: `sc-domain:yourdomain.com`
   - URL-prefix property: `https://yourdomain.com/` *(trailing slash required)*
2. Open **Settings → Users and permissions → Add user**.
3. Paste the service-account email copied above and set the permission level:
   - **Owner** — required for Google Indexing API direct URL submissions (`URL_UPDATED` / `URL_DELETED`) and supports all Search Console operations.
   - **Full** — minimum permission level for Search Console Sitemap Submission and URL Inspection.

### 3. Admin Panel Configuration (`/admin/settings/seo/`)

1. Sign in as a `super_user` and open **Settings → SEO Settings** (`/admin/settings/seo/`), then scroll to **Google API / Search Console & Indexing API**.
2. Enter the exact **Search Console Property** (`sc-domain:yourdomain.com` or `https://yourdomain.com/`).
3. Under **Service Account JSON**, select the downloaded `.json` key file.
4. *(Optional)* Check **Enable Google Indexing API (Direct URL Submit)** only if your pages use Google Indexing API–supported schema (`JobPosting` or `BroadcastEvent`) or your property is authorized for it.
5. Click **Save / Replace Credentials**. This stores the key privately and automatically queues sitemap submission for the next cron run.
6. Click **Test Google Connection** to verify OAuth2 authentication and Search Console property access (`Connected`).
7. If you enabled the Indexing API, click **Test Google Indexing API** to verify access (`Connected`).
8. Click **Queue Sitemap Submission** any time you want to re-queue `/sitemap-index.xml` for submission on the next cron run.

### 4. SQLite storage & environment variables (`admin/.env`)

- Google configuration and service-account credentials (`property`, `status`, `submission_pending`, `indexing_api_enabled`, `credentials`, `last_authenticated`, `removed`) are stored atomically inside SQLite (`admin/storage/blogs.sqlite` → `app_settings` table under `setting_key = 'google_config'`), avoiding filesystem directory/permission or `open_basedir` restrictions on aaPanel.
- **Automatic Legacy Migration**: If a legacy `google-config.json` file exists in `google_private_dir()` (resolved from `GOOGLE_PRIVATE_DIR`, `../.google-private-<site-path-hash>`, or `admin/storage/.google-private`), `google_state()` automatically imports it into `app_settings.google_config` and removes the legacy JSON file.
- `google_config` is strictly private: `/api/settings/seo.php` queries only its allowlisted public keys, and only `super_user` accounts can view/manage Google credentials.
- Optional environment variables in `admin/.env`:

| Variable | Default | Meaning |
| --- | --- | --- |
| `GOOGLE_INDEXING_ENABLED` | `false` *(or UI checkbox)* | Fallback toggle (`true`/`false`) for Google Indexing API direct URL notifications when not explicitly set via **SEO Settings** in SQLite. |
| `GOOGLE_INDEXING_AUTO_SUBMIT` | `true` *(or UI checkbox)* | Includes eligible `Not Indexed` and unsubmitted sitemap URLs (`blogs → pages → games`) when an admin manually runs an auto-submit batch in the Index Checker. |
| `GOOGLE_INDEXING_DAILY_LIMIT` | `200` | Rolling 24-hour request budget for Google Indexing API submissions. |
| `GOOGLE_INDEXING_BATCH` | `50` | Maximum Google Indexing API URL notifications published per manually triggered batch (1–200). |
| `GOOGLE_INDEXING_RESUBMIT_DAYS` | `7` | Minimum days before a manual auto-submit batch can re-submit a URL that remains `Not Indexed` (1–90). |
| `GSC_BROWSER_STATE_DIR` | *(empty)* | Optional absolute private path outside public webroot for read-only GSC browser worker ledger status in `/admin/index-checker/`. |
| `GOOGLE_PRIVATE_DIR` | *(auto)* | Legacy directory searched once when migrating an existing `google-config.json` into SQLite. |
| `GSC_SERVICE_ACCOUNT_FILE` | *(empty)* | Legacy path to a private service-account JSON file outside public web paths (or inside `admin/storage/`). |
| `GSC_PROPERTY` | *(empty)* | Legacy Search Console property override. |

> Saved UI settings in SQLite (`app_settings.google_config`) take precedence over `GOOGLE_INDEXING_ENABLED` and legacy `GSC_*` file fallback; removing credentials in the UI also disables legacy file fallback. If no saved or env property is set, the worker falls back to the configured SEO Website URL (`SITE_BASE_URL` takes precedence).

PHP needs PDO SQLite, DOM, cURL, and OpenSSL. No Composer packages are required. URL Inspection and connection tests request `https://www.googleapis.com/auth/webmasters.readonly`; sitemap submission requests `https://www.googleapis.com/auth/webmasters`; Indexing API requests `https://www.googleapis.com/auth/indexing`. Access tokens are held only in process memory; raw Google responses are never persisted.

## Schedule & CLI output reference

From the server site directory, test manually with:

```sh
php admin/scripts/check-google-indexing.php
```

Recommended aaPanel / crontab schedule (see also [`Cron_Setup.md`](/Cron_Setup.md)):

```cron
# Every 30 minutes (or once daily during off-peak hours)
*/30 * * * * cd /www/wwwroot/YOUR_SITE && /usr/bin/php admin/scripts/check-google-indexing.php >> /private/logs/index-checker.log 2>&1
```

Cron reconciles current public content with its previous public-URL snapshot (`indexing_public_urls`), submits queued sitemap index changes, inspects due URLs, and independently processes IndexNow notifications without interactive login. It does not publish per-URL notifications to the Google Indexing API; those remain available as manual actions in the Index Checker. Google failures do not stop IndexNow.

Cron uses the server's timezone. This repository does not install a cron entry automatically. Use the same configuration/storage path as the website. Sitemap files must already be deployed and publicly reachable; run `php admin/plugin/games/scripts/generate-game-sitemaps.php` after game visibility changes (`sitemap-blog.php` is dynamic). No credentials are provisioned by default.

### Understanding CLI output

```text
Google authentication succeeded.
Google sitemap submission HTTP 204
Index Checker: run started.
Google authentication succeeded.
Index Checker: run ended; processed 200; Complete.
Index Checker: Complete; sitemap URLs: 1024; inspected: 200.
IndexNow: 0 URL notification(s) accepted.
```

- **`Google authentication succeeded.`**: Your service-account JWT assertion succeeded and obtained an OAuth2 access token.
- **`Google sitemap submission HTTP 204`**: **Sitemap submission succeeded.** Google's Search Console Sitemaps API (`PUT /webmasters/v3/sites/{siteUrl}/sitemaps/{feedpath}`) returns `HTTP 204 No Content` on success. It is only sent when `submission_pending` is queued (after saving credentials, clicking **Queue Sitemap Submission**, or publishing/modifying public URLs).
- **`Index Checker: Complete; sitemap URLs: ...; inspected: 200.`**: Discovery and URL Inspection completed for the current batch (Search Console inspections still work independently of the Indexing API).
- **`Google Indexing publish URL_UPDATED HTTP 403 for ...`**: Only applies to manual use of the optional **Google Indexing API** (`indexing.googleapis.com`); the scheduled checker no longer sends these notifications. An HTTP `403` means one of three things:
  1. The service account was added to Search Console as **Full** rather than **Owner** (**Settings → Users and permissions**).
  2. The **Web Search Indexing API** is not enabled in Google Cloud Console.
  3. Google restricted Indexing API access for the property or URL type (Google's Indexing API is officially scoped to `JobPosting` and `BroadcastEvent` pages; ordinary blogs and game pages are expected to use **Sitemap Submission + URL Inspection + IndexNow**). To disable manual Indexing API calls while keeping Search Console sitemaps and URL Inspection active, set `GOOGLE_INDEXING_ENABLED="false"` in `admin/.env` or uncheck **Enable Google Indexing API** in **SEO Settings**.

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

## IndexNow

Super users configure **SEO Settings → IndexNow**: enable/disable, save an existing 8–128 character alphanumeric/hyphen key, or generate a random key. IndexNow settings (`indexnow_config`) are stored in SQLite (`blogs.sqlite` → `app_settings`). Saving also attempts to maintain the static `/{key}.txt` file in `admin/` (or `INDEXNOW_PUBLIC_DIR` if explicitly configured); on hosts where `admin/` is read-only for PHP-FPM, `.htaccess` falls back to `admin/indexnow-key.php` so `/{key}.txt` is served directly from SQLite. Admins can view status; editors cannot manage these settings. The public SEO API exposes enabled/configured/status/key-location fields, not Google credentials.

**Test IndexNow** fetches the public key file and submits the site home URL. HTTP 200 means accepted; 202 means accepted with key verification pending, not indexing confirmation. Neither reports Google status. The shared helper uses `https://api.indexnow.org/indexnow` for Bing, Yandex and participating engines.

`indexing_public_urls` tracks canonical URLs that were publicly eligible. `indexing_notifications` deduplicates changes/removals by URL; `indexing_google_events` separately queues Google inspection changes. `indexing_jobs` holds sitemap work and IndexNow cooldowns. Content saves queue locally without contacting engines. Cron reconciliation also detects scheduled publication, imports, enrichment, and direct visibility changes. Never-public drafts, restricted games and private routes are excluded; a formerly public URL may be sent as a removal notification.

The existing cron sends at most 10,000 pending IndexNow URLs per batch. HTTP 200/202 clears only the submitted versions; concurrent edits remain queued. Failures retain notifications with exponential backoff (10 minutes to one day), honoring longer `Retry-After` values. HTTP 400/403/422 waits at least one day; saving a replacement key or enabling IndexNow resets the retry delay. A worker lock prevents overlapping IndexNow batches. A Google sitemap failure has its own one-hour-to-one-day backoff.

### Index now steps
1. Go to [Bing Index now](https://www.bing.com/indexnow/getstarted#)
2. Download and Copy API Key
3. go to Bloggerist/admin/settings/seo/
4. insert key and click "Save IndexNow" 
5. upload the downloaded file to public file ex: examplesite.com/{key}.txt (make sure it is visible )
6. Click "Test IndexNow" make sure it works and wait for the verification pending 

## Google Indexing API (Direct URL Submissions)

Super users configure **SEO Settings → Google API / Search Console & Indexing API**:
1. Enable the **Web Search Indexing API** in your Google Cloud Console project.
2. Ensure the service account email is added as an **Owner** to your Google Search Console property (required by Google for Indexing API submissions).
3. Check **Enable Google Indexing API (Direct URL Submit)** in SEO Settings (and ensure `GOOGLE_INDEXING_ENABLED` is not forcing `false` in `admin/.env`) and click **Save / Replace Credentials**.
4. Click **Test Google Indexing API** to verify access.

### Behavior & Quotas
- **Queue & Manual Backfill**:
  - When blog posts or games are published, modified, or deleted, events are queued immediately in `google_indexing_queue`.
  - The scheduled checker cron does not process or publish this queue. When an admin manually runs **Run Auto-Submit Batch Now**, and **Include Not Indexed & unsubmitted URLs in manual batches** is enabled, eligible `Not Indexed` and unsubmitted (`Pending`) sitemap URLs are also queued — prioritizing **blogs → static pages → games** — while excluding `Indexed` URLs, drafts, hidden/restricted games, and `robots_state = 'DISALLOWED'` / `noindex` pages.
  - In manually triggered batches, `Not Indexed` URLs that were already submitted are eligible for re-submission after `GOOGLE_INDEXING_RESUBMIT_DAYS` (default `7` days), and failed submissions retry after 24 hours.
  - Manual batches publish up to `GOOGLE_INDEXING_BATCH` (default `50`) URLs (`URL_UPDATED` / `URL_DELETED`) to `https://indexing.googleapis.com/v3/urlNotifications:publish` and record timestamps/status in `index_checks`.
- **Manual Submission / On-Demand Batch**: In `/admin/index-checker/`, click **Run Auto-Submit Batch Now** to immediately process the next priority batch, or select up to 50 URLs and click **Submit selected to Google Indexing API** (or click **Submit to Google** on any individual row).
- **Quota Safeguard**: Google sets a default quota of 200 requests/day per Google Cloud project. The system records all attempts in `google_indexing_attempts` and clamps requests within a rolling 24-hour window using `GOOGLE_INDEXING_DAILY_LIMIT` (default: 200).
- **Rate-limiting & Errors**: HTTP 429 (`RESOURCE_EXHAUSTED`) or 403 halts processing immediately and initiates a 24-hour cooldown in `indexing_jobs` to protect project quota.

## Verification

```sh
php admin/test/test-google-credentials.php
php admin/test/test-index-checker.php
php admin/test/test-indexnow.php
```

Tests use temporary SQLite storage, sitemap fixtures, a temporary signing key, mocked Google transports, and the existing isolated admin handler harness. They do not require production credentials or consume Google quota. Live credentials, property access, and server cron execution must be verified after setup.

Official references: [URL Inspection request and authorization](https://developers.google.com/webmaster-tools/v1/urlInspection.index/inspect), [inspection result fields](https://developers.google.com/webmaster-tools/v1/urlInspection.index/UrlInspectionResult), [Google quotas](https://developers.google.com/webmaster-tools/limits), [Web Search Indexing API](https://developers.google.com/search/apis/indexing-api/v3/quickstart), and [service-account OAuth](https://developers.google.com/identity/protocols/oauth2/service-account).
