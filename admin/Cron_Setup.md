# Cron Setup Guide (aaPanel & Linux)

This guide covers all automated background tasks and cron jobs for the website, including scheduled blog publishing, search engine indexing, daily sports logo enrichment, and game sitemaps.

---

## 1. Quick Reference & Server Paths

Before configuring your cron jobs, replace the placeholders with your server's actual values:

| Variable | aaPanel Default Path | Description |
| :--- | :--- | :--- |
| **PHP CLI** | `/www/server/php/82/bin/php` | Installed PHP CLI binary (PHP 8.1, 8.2, or 8.3) |
| **Python 3** | `/usr/bin/python3` | Python 3 binary (for AI game descriptions) |
| **Site Root** | `/www/wwwroot/YOUR_SITE` | Absolute root directory of your website |
| **Run As User** | `www` | Recommended user in aaPanel / Linux |

> **Find your PHP path on the server:**
> ```bash
> which php
> # or check aaPanel PHP directory:
> ls -d /www/server/php/*/bin/php
> ```

---

## 2. Recommended Schedule Summary

| Task | Frequency | aaPanel Cycle | Script Path |
| :--- | :--- | :--- | :--- |
| **1. Blog Scheduler** *(Critical)* | Every 1 minute | **N Minute** (`1 Minute`) | `admin/scripts/publish-scheduled-blogs.php` |
| **2. Google Indexing & IndexNow** | Every 30 minutes | **N Minute** (`30 Minute`) | `admin/scripts/check-google-indexing.php` |
| **3. Sports Logo Enrichment** | Daily at 03:00 AM | **Daily** (`03:00`) | `admin/plugin/odds-api/scripts/daily-sports-images.php` |
| **4. Game Sitemaps Generation** | Daily at 04:00 AM | **Daily** (`04:00`) | `admin/plugin/games/scripts/generate-game-sitemaps.php` |
| **5. AI Game Descriptions** *(Optional)* | Daily at 02:00 AM | **Daily** (`02:00`) | `admin/plugin/games/scripts/enrich-games-daily.py` |
| **6. Sports Participants Sync** *(Optional)* | Weekly (Sun 05:00 AM) | **Weekly** (`Sunday 05:00`) | `admin/plugin/odds-api/scripts/sync-odds-participants.php` |

---

## 3. aaPanel Step-by-Step Configuration

1. Log in to your **aaPanel Dashboard**.
2. Click **Cron** in the left navigation menu.
3. For each task below, fill in the fields and click **Add Task**.

---

### Task 1: Blog Scheduler *(Auto-Publish Scheduled Posts)*
* **Purpose**: Checks for scheduled blog posts whose publish time has arrived, changes status to published, clears the API cache, and queues Google Indexing pings.
* **Type of Task**: `Shell Script`
* **Name of Task**: `[Site] Blog Scheduler`
* **Execution cycle**: `N Minute` → `1` (Every 1 minute)
* **Script Content**:
  ```bash
  /www/server/php/82/bin/php '/www/wwwroot/YOUR_SITE/admin/scripts/publish-scheduled-blogs.php'
  ```

---

### Task 2: Search Engine Indexing & IndexNow
* **Purpose**: Reconciles published blogs & games, submits sitemaps to Google Search Console, inspects due URLs via Search Console URL Inspection, **automatically queues and submits eligible `Not Indexed` & unsubmitted sitemap URLs (blogs → pages → games) to the Google Indexing API** within the 200/day rolling quota (50/run by default, re-submitting still-`Not Indexed` URLs every 7 days), and pings IndexNow (Bing/Yandex).
* **Type of Task**: `Shell Script`
* **Name of Task**: `[Site] Search Engine Indexing`
* **Execution cycle**: `N Minute` → `30` (Every 30 minutes)
* **Script Content**:
  ```bash
  /www/server/php/82/bin/php '/www/wwwroot/YOUR_SITE/admin/scripts/check-google-indexing.php'
  ```
* **Optional Separate Browser Worker**: If you later decide to also automate Google Search Console's browser **Request indexing** button (not required for API + IndexNow cron above), see `admin/scripts/gsc-browser/README.md` for its separate non-root cron setup.

---

### Task 3: Daily Sports Images / Logo Enrichment
* **Purpose**: Automatically discovers and downloads missing sports team and tournament logos from SportsDataverse and Wikimedia Commons into `/uploads/sports/`. Includes built-in process locking and HTTP 429 rate-limit backoff.
* **Type of Task**: `Shell Script`
* **Name of Task**: `[Site] Enrich Sports Logos`
* **Execution cycle**: `Daily` → `03:00`
* **Script Content**:
  ```bash
  /www/server/php/82/bin/php '/www/wwwroot/YOUR_SITE/admin/plugin/odds-api/scripts/daily-sports-images.php'
  ```
  *(Optional: To enrich only one tournament, append `--tournament=ID_OR_SLUG`, e.g. `--tournament=basketball_nba`)*

---

### Task 4: Game Sitemaps Generation
* **Purpose**: Re-generates XML sitemaps for all published slot games and updates `sitemap-index.xml`.
* **Type of Task**: `Shell Script`
* **Name of Task**: `[Site] Generate Game Sitemaps`
* **Execution cycle**: `Daily` → `04:00`
* **Script Content**:
  ```bash
  /www/server/php/82/bin/php '/www/wwwroot/YOUR_SITE/admin/plugin/games/scripts/generate-game-sitemaps.php'
  ```

---

### Task 5: AI Game Descriptions *(Optional)*
* **Purpose**: Uses Ollama (configured in `admin/.env`) to write AI descriptions for newly imported games so they qualify for `done_processing=1` public listing.
* **Type of Task**: `Shell Script`
* **Name of Task**: `[Site] AI Game Descriptions`
* **Execution cycle**: `Daily` → `02:00`
* **Script Content**:
  ```bash
  /usr/bin/python3 '/www/wwwroot/YOUR_SITE/admin/plugin/games/scripts/enrich-games-daily.py'
  ```

---

### Task 6: Sports Participants & Rosters Sync *(Optional)*
* **Purpose**: Pulls updated team rosters and participant names from The Odds API for all active tournaments.
* **Type of Task**: `Shell Script`
* **Name of Task**: `[Site] Sync Sports Participants`
* **Execution cycle**: `Weekly` → `Sunday` → `05:00`
* **Script Content**:
  ```bash
  /www/server/php/82/bin/php '/www/wwwroot/YOUR_SITE/admin/plugin/odds-api/scripts/sync-odds-participants.php'
  ```

---

## 4. Alternative: Standard Linux Crontab (`crontab -e`)

If configuring via SSH / Linux terminal directly:

```bash
# Open crontab for www user
crontab -u www -e
```

Paste the following entries (remember to update `/www/server/php/82/bin/php` and `/www/wwwroot/YOUR_SITE`):

```crontab
# 1. Blog Scheduler - runs every minute
* * * * * /www/server/php/82/bin/php '/www/wwwroot/YOUR_SITE/admin/scripts/publish-scheduled-blogs.php' >> /www/wwwroot/YOUR_SITE/admin/storage/blog-scheduler.log 2>&1

# 2. Google Indexing & IndexNow - runs every 30 minutes
*/30 * * * * /www/server/php/82/bin/php '/www/wwwroot/YOUR_SITE/admin/scripts/check-google-indexing.php' >> /www/wwwroot/YOUR_SITE/admin/storage/indexing.log 2>&1

# 3. Sports Logo Enrichment - runs daily at 03:00 AM
0 3 * * * /www/server/php/82/bin/php '/www/wwwroot/YOUR_SITE/admin/plugin/odds-api/scripts/daily-sports-images.php' >> /www/wwwroot/YOUR_SITE/admin/storage/sports-images-daily.log 2>&1

# 4. Game Sitemaps - runs daily at 04:00 AM
0 4 * * * /www/server/php/82/bin/php '/www/wwwroot/YOUR_SITE/admin/plugin/games/scripts/generate-game-sitemaps.php' >> /www/wwwroot/YOUR_SITE/admin/storage/sitemaps.log 2>&1

# 5. Optional: AI Game Descriptions - runs daily at 02:00 AM
0 2 * * * /usr/bin/python3 '/www/wwwroot/YOUR_SITE/admin/plugin/games/scripts/enrich-games-daily.py' >> /www/wwwroot/YOUR_SITE/admin/storage/enrich-games-daily.log 2>&1

# 6. Optional: Sync Sports Participants - runs Sundays at 05:00 AM
0 5 * * 0 /www/server/php/82/bin/php '/www/wwwroot/YOUR_SITE/admin/plugin/odds-api/scripts/sync-odds-participants.php' >> /www/wwwroot/YOUR_SITE/admin/storage/sports-sync.log 2>&1
```

---

## 5. Verification & Testing

### Testing in aaPanel
1. In **Cron**, locate the task in the list.
2. Click **Execute** to run the job immediately.
3. Click **Log** to view standard output and confirm successful execution.

### Expected Log Output Examples

* **Blog Scheduler**:
  ```
  Blog scheduler [2026-11-17 18:00:00 PST]: published 1 due post(s), 2 upcoming scheduled post(s).
    - example-post-slug (scheduled for 2026-11-18 10:00:00 PST)
  ```

* **Sports Logo Enrichment**:
  ```
  {"status":"complete","enriched":5,"sportsdataverse":4,"wikimedia":1,"unmatched":0,"remaining":20}
  ```

* **Game Sitemaps**:
  ```
  Generated sitemap-games-1.xml (1000 URLs)
  Generated sitemap-index.xml
  ```

---

## 6. Permissions & Troubleshooting

* **File & Directory Permissions**:
  The `admin/storage/` and `uploads/` directories must be writable by the `www` user:
  ```bash
  chown -R www:www /www/wwwroot/YOUR_SITE/admin/storage/
  chmod -R 775 /www/wwwroot/YOUR_SITE/admin/storage/
  chown -R www:www /www/wwwroot/YOUR_SITE/uploads/
  chmod -R 775 /www/wwwroot/YOUR_SITE/uploads/
  ```

* **No Background Flags in Cron**:
  Do not append `&` or wrap commands in `nohup` inside aaPanel tasks. aaPanel handles background daemon execution automatically.

* **Stuck Lock Files**:
  Long-running scripts write lock files to `admin/storage/*.lock` (e.g. `sports-images-daily.lock`). They release automatically when finished or expired. If a server reboot interrupts a job, verify the process is dead before removing stale `.lock` files.
