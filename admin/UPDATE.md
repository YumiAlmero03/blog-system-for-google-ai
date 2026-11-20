# Step-by-step production update

This project deploys updates with a generated ZIP and updater pair. The server does not need Git, and the ZIP must be installed by `updater.py`; do not extract it manually.

## Before starting

1. Finish and test the changes locally.
2. Confirm the server uses the current layout, including `admin/includes/blog-storage.php`.
3. Make sure the server has Python 3.10+, PHP 8.1+, and PHP's `pdo_sqlite` extension.
4. Schedule a maintenance window. You will need to enable maintenance mode and pause aaPanel cron jobs before installation.

## 1. Build the update package

From this project's root folder:

```sh
python3 admin/scripts/updater.py --build .
```

The command creates a matching pair in **`admin/update-packages/full/`**: `updater.py` and `site-update.zip`. Always upload both files from the same build. Rebuild the pair after any further code changes. The package excludes `.env` files, SQLite databases, uploads, chats, logs, generated sitemap XML files, `robots.txt`, and other runtime/private data. PHP sitemap endpoints and sitemap-generation scripts remain part of the application package.

## 2. Upload the two files in aaPanel

1. Open **aaPanel → Files**.
2. Open the website root, the folder containing `admin/` and `api/`.
3. Upload `updater.py` and `site-update.zip` into that root folder.
4. Do not upload local `.env` files, SQLite databases, or uploads.
5. Do not extract `site-update.zip` yourself.

Keep the site's existing `.env`, SQLite database, uploads, and chats. These are excluded from the package. The full package includes tracked application dependencies and current non-ignored new files; review new files before building to avoid packaging private material.

## 3. Validate without changing anything

In the aaPanel terminal, replace `YOUR_SITE_FOLDER` and run:

```sh
cd /www/wwwroot/YOUR_SITE_FOLDER
python3 updater.py --check
```

The check verifies the matching pair, package contents, database location, PHP version, and SQLite support. It does not change files or database records. If PHP is not on the command path, add `--php /www/server/php/84/bin/php` to both commands.

## 4. Prepare the site

1. Enable website maintenance mode.
2. Pause aaPanel cron jobs and workers that write to the application or databases.
3. Confirm no imports, blog saves, game jobs, or scheduled publishing tasks are running.

## 5. Install

After completing the preparation steps, run:

```sh
python3 updater.py
```

The updater checks hashes, backs up affected code/configuration and both SQLite databases, validates PHP syntax, installs the application, removes obsolete backend paths, runs database initialization/migrations, clears API caches, and regenerates game sitemaps. Historical frontend deletions are excluded: the server's `index.html`, `blog/`, `game/`, `games/`, `assets/` and other separately deployed frontend paths are preserved. Old packages that list frontend deletions are rejected by the current updater; rebuild and upload the matching pair.

Installed code files are readable by the web server (at least `0644`), and application directories are traversable (`0755`), including with a restrictive deployment umask. Existing executable bits are retained. When run as root, replacement files keep their existing owner/group and new directories/files inherit the nearest application directory's owner/group. Private `.env`, storage and upload permissions are not changed.

If an earlier update removed the homepage and the site now returns 403, restore the frontend build (especially `index.html` and its matching assets) from the deployment or the private update backup's `files/` directory. This backend package does not contain the frontend build and cannot recreate it. Retain the server's frontend routing configuration.

Backups are created in **`.site-update-backups` beside the website folder**, outside the site root. The command prints the exact location. If that parent directory is not writable, use a writable private directory outside the website:

```sh
python3 updater.py --backup-dir /path/outside/website/backups
```

Run as the site's deployment user with permission to update code, storage, and sitemaps. The updater does not enable maintenance mode or configure Nginx.

## 6. New database columns and schema changes

New columns are applied automatically only when the release includes idempotent migration code. The database bootstrap checks the existing schema and adds missing columns/tables defined by the release; the updater triggers that bootstrap after installing the new files.

For every schema change:

1. Include the migration/schema code in the same update package as the code that uses the new column.
2. Never replace the server database with a local SQLite file.
3. Keep maintenance mode and database-writing jobs paused during the first initialization.
4. Watch the installer output for migration errors.
5. Test the feature that uses the new column after installation.

Test schema changes against a copy of production first. The updater cannot infer an arbitrary schema change that has no migration code, and SQLite constraint/type changes may require special migration handling.

## 7. Verify and finish

Check admin login, blog editing/publishing, slot editing, `/game-api/slot-list.php`, the feature using new columns, public pages, APIs, and sitemaps. Then disable maintenance mode and resume cron jobs/workers. Delete the uploaded `updater.py` and `site-update.zip`; keep the private backup until the release is trusted.

## If the update fails

Keep maintenance enabled and do not resume writers. The updater prints the backup directory and writes `restore-info.json` there. Restore the backed-up application files and matching SQLite databases together before reopening the site. It does not automatically roll back migrations or unrelated writes.

## Updating a much older installation

You can skip releases when the server already uses **`admin/includes/blog-storage.php`** and the current `admin/` layout. Existing database migration helpers apply missing schema changes; test very old databases on a staging copy first. A full package cannot guarantee compatibility with every historical schema.

If the server uses an older root-level backend layout, migrate its runtime data and `.env` to the correct locations first. The updater stops rather than guesses which database to use. Preserve the server's `APP_STORAGE_DIR` setting when configured.

For Nginx, retain rules blocking private paths such as `admin/storage`, `admin/includes`, `admin/scripts`, `admin/test`, `admin/chats`, `admin/data`, `admin/update-packages`, and hidden files. Nginx does not read `.htaccess`.

## Games module database update

This release moves game code into `admin/plugin/games/` and the catalogue into `admin/storage/games.sqlite` (or the configured `APP_STORAGE_DIR`). During database initialization, existing game records move automatically from `blogs.sqlite` with a pre-migration SQLite backup. Keep maintenance mode enabled and pause cron jobs until migration finishes. Do not upload local database files.

The updater now backs up both databases. If rolling back the first split migration to older code, restore the old `blogs.sqlite` backup and remove the newly created `games.sqlite` and its sidecar files while all writers are stopped; `restore-info.json` records whether the game database existed before the update. For later rollbacks, restore both matching database backups.

In Nginx, add private-path rules for `admin/plugin/games/includes` and `admin/plugin/games/scripts` alongside the existing private folders. Apache receives matching rules in this release.

After deployment, open **Games → Games Settings** to enable/disable all games. Existing API links and cron commands still work through compatibility entrypoints.

## Daily game job — configure once

Keep these settings in the server's `admin/.env`:

```dotenv
OLLAMA_API_KEY=your_actual_api_key
OLLAMA_MODEL=gemma4:31b-cloud
OLLAMA_HOST=http://127.0.0.1:11434
```

Ollama must be accessible at that address on the server. In **aaPanel → Cron**, create one **Shell Script** task scheduled **Daily at 02:00**:

```sh
cd /www/wwwroot/YOUR_SITE_FOLDER && /usr/bin/python3 admin/scripts/enrich-games-daily.py
```

Replace the paths if needed and set the server timezone to **Asia/Manila**. Edit an existing job instead of creating duplicates. Each run attempts up to 20 eligible games with `done_processing=0`; successful saves set it to `1`, so later runs skip them. Results are in `admin/storage/enrich-games-daily.log`.
