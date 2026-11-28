# GSC browser submission worker for aaPanel

This optional worker uses the real Search Console **Inspect URL → Request indexing** browser interface. It is separate from the Google Indexing API and the existing PHP inspection/sitemap cron. It never treats an API response or "Page can be indexed" as a successful submission. Success requires visible **Indexing requested** confirmation; Google still decides whether and when to index the URL.

Google has no public API for this button. This is an unofficial browser integration: Google can change its interface, reject automated sign-in, expire the session, show CAPTCHA, or impose a quota. This code has automated logic/fixture tests, but must pass a first submission in your server's authenticated browser before you enable cron. It cannot promise permanent unattended operation.

## Server requirements

- Node.js 22+ and a supported Linux distribution for Playwright. PHP CLI with PDO SQLite and permission to read the live blog/game databases.
- A dedicated **non-root** Linux user for both browser login and cron. Do not disable Chromium's sandbox to run as root.
- An existing secured interactive desktop session on the server for initial Google sign-in and occasional reauthentication/CAPTCHA. An aaPanel terminal alone and `xvfb-run` alone do not provide an interactive screen. If the server has no desktop, arrange one with your administrator before proceeding; do not expose a public VNC port.
- Google account access to the exact Search Console property. The service-account JSON used by the PHP worker cannot log into this browser.
- A private directory **outside every public webroot**, owned by the worker user with mode `0700`. It holds cookies capable of accessing your Google account. Do not upload it, commit it, or copy a personal Chrome profile into it. Prefer a Google account limited to the relevant properties.

No new application environment variables are required. Use absolute Node/PHP paths in aaPanel because its cron PATH can differ from the shell.

## Install and sign in once

Replace `/www/wwwroot/YOUR_SITE`, the binary paths, and `https://YOUR_DOMAIN/` below with the real production paths/property. Run these commands **on the aaPanel server**, as the worker user. Installing Linux browser dependencies may require an administrator; run browser commands and login as the worker user, not root.

```sh
cd /www/wwwroot/YOUR_SITE/admin/scripts/gsc-browser
npm ci
npx playwright install --with-deps chromium

# Administrator creates this once, assigning it to the worker's actual user/group:
# install -d -m 0700 -o WORKER_USER -g WORKER_GROUP /var/lib/gsc-browser/YOUR_SITE

node cli.mjs login \
  --state-dir /var/lib/gsc-browser/YOUR_SITE \
  --property https://YOUR_DOMAIN/
```

Run `login` from the server's interactive desktop terminal. Complete Google sign-in yourself, select/check the property, and press Enter in the terminal only when the property opens. The dedicated profile is reused on later runs. If Google refuses this browser's sign-in, stop here; this worker does not circumvent that restriction. `--channel chrome` can use an installed Google Chrome instead; use the same channel for login and every subsequent run.

## Validate the queue, then test one real request

`--db` reads the production database without changing its data. It exports current sitemap members that are not marked Indexed, rechecks publication/game eligibility, skips recorded URLs before limiting the batch, and validates every URL against the explicit public site and property. It never translates localhost URLs into production URLs. Blog posts have priority over regular pages and games.

```sh
node cli.mjs run --dry-run \
  --state-dir /var/lib/gsc-browser/YOUR_SITE \
  --property https://YOUR_DOMAIN/ --site https://YOUR_DOMAIN \
  --db /www/wwwroot/YOUR_SITE/admin/storage/blogs.sqlite \
  --php /usr/bin/php --limit 1

# Real request: keep the server's browser visible for the first test.
node cli.mjs run --headed \
  --state-dir /var/lib/gsc-browser/YOUR_SITE \
  --property https://YOUR_DOMAIN/ --site https://YOUR_DOMAIN \
  --db /www/wwwroot/YOUR_SITE/admin/storage/blogs.sqlite \
  --php /usr/bin/php --limit 1

node cli.mjs status \
  --state-dir /var/lib/gsc-browser/YOUR_SITE \
  --property https://YOUR_DOMAIN/
```

Only enable cron after the visible server browser shows **Indexing requested** and `status` records `confirmed`. A `url_mismatch`, `ui_timeout`, or `unexpected_ui` pause means the UI adapter needs checking on that server; do not treat it as success. The worker uses English UI labels and checks that the URL in the rendered inspection matches the queued URL before clicking.

You can use `--type blog` to initially limit requests to posts. Domain properties use the exact string, for example `--property sc-domain:YOUR_DOMAIN`, plus the real `--site https://YOUR_DOMAIN` origin. `--queue /private/queue.json` is an alternative to `--db`; its format is `{"version":1,"site":"https://YOUR_DOMAIN","urls":["https://YOUR_DOMAIN/blog/post/"]}`.

## aaPanel cron

Create a Shell Script task every 30 minutes, **as the same non-root user used for login**. Keep the existing `check-google-indexing.php` task for sitemap discovery and status inspection. Set aaPanel's task user explicitly, or have an administrator configure a user crontab. Do not run the browser under aaPanel's default root account.

Use the full paths to the Node and PHP versions actually installed on your server:

```sh
/ABSOLUTE/PATH/TO/node /www/wwwroot/YOUR_SITE/admin/scripts/gsc-browser/cli.mjs run \
  --state-dir /var/lib/gsc-browser/YOUR_SITE \
  --property https://YOUR_DOMAIN/ --site https://YOUR_DOMAIN \
  --db /www/wwwroot/YOUR_SITE/admin/storage/blogs.sqlite \
  --php /ABSOLUTE/PATH/TO/php --limit 5
```

Default `--limit 5` permits at most five clicks per property in a rolling 24 hours across cron runs. It is a conservative **local budget**, not Google's published allowance. GSC's manual-request quota is independent of the Indexing API's 200/day. Manual submissions or another runner can consume Google's quota too. A quota response pauses for at least 24 hours. Headless runs reuse the private signed-in profile; if headless access is rejected or challenged, stop and inspect in the secured desktop session rather than adding bypass flags.

Each run inspects at most 20 URLs and starts no further URL after ten minutes, even when all inspected URLs were already indexed. An inspection already in progress may take up to three additional minutes. Remaining URLs stay eligible for subsequent cron runs.

## Pauses, deduplication and recovery

- `confirmed`: GSC showed **Indexing requested**, which is not a claim that the page is indexed.
- `indexed`: GSC said the URL is already on Google; no request was made.
- `already_requested` or `not_indexable`: no additional request is made.
- `request_started` / `uncertain`: the request may already have reached Google. These URLs are excluded from all later automatic submissions, including after `resume`.
- Login, CAPTCHA, property-access problems, quota, unexpected UI, and timeouts stop the batch. Non-quota pauses stay paused across cron runs until reviewed.

Inspect `status`, resolve the problem using `login` or a headed run, then clear the pause:

```sh
node cli.mjs resume --state-dir /var/lib/gsc-browser/YOUR_SITE --property https://YOUR_DOMAIN/
```

`resume` only clears the pause; it does not reset the ledger or resubmit uncertain URLs. Each URL is processed once. If you intentionally need to request a URL again after edits, review its result manually rather than deleting the whole history. Nothing writes browser outcomes into the existing API submission/status fields; browser results are in private `ledger-*.json` files.

A single `worker.lock` prevents concurrent login/cron access to the profile. After a killed process, first verify that its PID is no longer running and Chromium has closed, then remove **only that exact stale lock file**. Do not delete the profile or ledger to resolve a lock. Preserve the private state across deployments; wiping it can cause duplicate requests.

## Tests

```sh
node --test admin/scripts/gsc-browser/worker.test.mjs
php admin/test/test-gsc-browser-export.php
```

These tests use mocks and temporary SQLite fixtures. They do not sign into Google or submit production URLs.

References: [GSC manual request workflow and quota](https://support.google.com/webmasters/answer/9012289), [Search Console API reference](https://developers.google.com/webmaster-tools/v1/api_reference_index), [Playwright persistent profiles](https://playwright.dev/docs/api/class-browsertype#browser-type-launch-persistent-context), [private authentication state](https://playwright.dev/docs/auth).
