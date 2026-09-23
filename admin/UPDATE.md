# Update using two files

Upload only **`updater.py`** and **`site-update.zip`**. The ZIP contains the complete current application, so you do not need the server's last Git commit or intermediate update packages. The server does not need Git.

## 1. Create the two files on your computer

From this project's root folder:

```sh
python3 admin/scripts/updater.py --build .
```

Find both files in **`admin/update-packages/full/`**. Always upload the matching pair from the same build. Rebuild after making more changes.

## 2. Upload both files

In **aaPanel → Files**, open the website root containing `admin` and `api`. Upload `updater.py` and `site-update.zip` there. **Do not extract the ZIP yourself.**

Keep the site's existing `.env`, SQLite database, uploads, and chats. These are excluded from the package. The full package includes tracked application dependencies and current non-ignored new files; review new files before building to avoid packaging private material.

## 3. Check the update

In the aaPanel terminal, replace `YOUR_SITE_FOLDER` and run:

```sh
cd /www/wwwroot/YOUR_SITE_FOLDER
python3 updater.py --check
```

Requires **Python 3.10+** and **PHP 8.1+ with PDO SQLite**. If PHP is not on the command path, add its actual location, for example `--php /www/server/php/84/bin/php`, to both the check and installation commands.

## 4. Install

Enable website maintenance mode and pause cron jobs first so files and database records are not being changed during the update. Then run:

```sh
python3 updater.py
```

The updater checks the matching ZIP and file hashes, backs up affected code and both SQLite databases when present, validates PHP syntax, installs the full application, removes obsolete application paths recorded in repository history, runs existing database migrations, clears API caches, and regenerates game sitemaps. Unrelated server files are left alone.

Backups are created in **`.site-update-backups` beside the website folder**, outside the site root. The command prints the exact location. If that parent directory is not writable, use a writable private directory outside the website:

```sh
python3 updater.py --backup-dir /path/outside/website/backups
```

Run as the site's deployment user with permission to update code, storage, and sitemaps. The updater does not automatically enable maintenance mode or configure Nginx.

## 5. Finish

Check admin login, slot editing, `/api/slot-list.php`, and your sitemaps. Then resume the site and cron jobs. Delete the uploaded `updater.py` and `site-update.zip` from the public website folder. Keep the backup privately.

If the updater reports a failure, **keep maintenance enabled**. It prints the backup location; `restore-info.json` records the original database location and newly added files. Restore the backed-up code and both databases together before reopening the site. It does not automatically roll back migrations or undo unrelated writes.

## Updating a much older installation

You can skip releases when the server already uses **`admin/includes/blog-storage.php`** and the current `admin/` layout. Existing database migration helpers apply missing schema changes; test very old databases on a staging copy first. A full package cannot guarantee compatibility with every historical schema.

If the server uses an older root-level backend layout, migrate its runtime data and `.env` to the correct locations first. The updater stops rather than guesses which database to use. Preserve the server's `APP_STORAGE_DIR` setting when configured.

For Nginx, retain rules blocking private paths such as `admin/storage`, `admin/includes`, `admin/scripts`, `admin/chats`, `admin/data`, `admin/update-packages`, and hidden files. Nginx does not read `.htaccess`.

## Games module database update

This release moves game code into `admin/games/` and the catalogue into `admin/storage/games.sqlite` (or the configured `APP_STORAGE_DIR`). During database initialization, existing game records move automatically from `blogs.sqlite` with a pre-migration SQLite backup. Keep maintenance mode enabled and pause cron jobs until migration finishes. Do not upload local database files.

The updater now backs up both databases. If rolling back the first split migration to older code, restore the old `blogs.sqlite` backup and remove the newly created `games.sqlite` and its sidecar files while all writers are stopped; `restore-info.json` records whether the game database existed before the update. For later rollbacks, restore both matching database backups.

In Nginx, add private-path rules for `admin/games/includes` and `admin/games/scripts` alongside the existing private folders. Apache receives matching rules in this release.

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
