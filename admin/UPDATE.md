# Uploading an update

Build a ZIP locally, review its manifest, and upload it to the existing site through aaPanel or cPanel File Manager. This packages code; it does not deploy anything or replace production data.

## 1. Build the package

From the project directory, run:

```sh
sh admin/scripts/package-update.sh
```

Requires Git and PHP with ZipArchive. By default, the package contains tracked changes since `HEAD` plus non-ignored untracked files, using their current working copies. This includes all pending changes, not just the most recent feature.

If the server runs an older commit, use that deployed commit or branch as the baseline:

```sh
sh admin/scripts/package-update.sh <deployed-commit-or-branch>
```

The command creates three files in `admin/update-packages/`:

- `site-update-<UTC timestamp>-<suffix>.zip`: changed files, with paths relative to the site root.
- Matching `-manifest.txt`: baseline commit, creation time, file list, and SHA-256 hashes.
- Matching `-deletions.txt`: server paths that need manual removal; an empty file means no deletions.

The package excludes storage, uploads, chats, environment files, database files, private-key files, logs, archives, dependencies, and local agent/Git directories. Review the manifest for any other private files before uploading. Generated packages are ignored by Git. Renames appear as an upload plus a deletion.

## 2. Back up and upload

1. Back up the server files and production database using your hosting backup tools. Keep backups outside the public site directory. For a live SQLite database, use a SQLite-aware backup rather than copying only its main file while writes continue.
2. Review the manifest against the server version. Try the update on staging first.
3. Put the site into maintenance mode during extraction and database migration.
4. Upload the ZIP to the site root, where `admin/includes/`, `admin/`, and `api/` already exist. Extract there and allow the listed code files to be overwritten.
5. Review and apply any entries in the deletions report. Never delete unrelated server files.
6. Remove the uploaded ZIP and reports from the public server directory afterward.

Do not upload your local `admin/storage/blogs.sqlite`, `.env`, uploads, or customer/chat records.

## 3. Apply database migrations

This project runs its schema migrations through `blogs_pdo()`. There is no `admin/scripts/safe-db-update.php` and no schema-only dry-run command. After backing up and uploading, run this from the server site root:

```sh
php -r 'require "admin/includes/blog-storage.php"; blogs_pdo(); echo "Database initialization completed.\n";'
```

This **writes to the production database** using the server configuration. It can add tables/columns, backfill category assignments, and perform existing initialization work, including legacy blog migration and publishing due scheduled posts. Do not describe it as an add-columns-only operation. Test against a backup on staging when upgrading an older installation.

Then regenerate public game sitemaps:

```sh
php admin/scripts/generate-game-sitemaps.php
```

The web/PHP user must be able to write the sitemap files and API cache directory. Provider approval and game visibility saves also refresh sitemaps; a refresh failure appears in the admin response and can be retried by saving again.

## 4. Verify and finish

Check admin login, blog editing/Quick Edit, category filtering, tags, provider settings, slot editing, public APIs, and the sitemap index. Hidden games should return public 404s. Remove maintenance mode after checks pass.

If rollback is needed, restore the matching server code and database backup together. Restoring an older database discards writes made after that backup, so keep maintenance mode active until the update is verified.

## First update — 2026-09-16

The first package includes the current pending implementation:

- Blog tags with CRUD and multiple assignments.
- Hierarchical categories, stable category IDs, and parent-inclusive filtering/search.
- Category/tag output in blog APIs and public views.
- Provider approvals and per-game `is_viewable` controls.
- Shared public game eligibility and sitemap/provider-list filtering.
- The missing public game page handler, regression scripts, and updated documentation.

Database changes include category slugs/parents and blog category IDs, tag/relation tables, provider approval overrides, and game visibility. Existing provider approvals and game visibility default to enabled. No production deployment has been performed.

## Blog ZIP upload limits

The application accepts blog ZIP imports up to 100 MB. Configure PHP `upload_max_filesize` to at least 100M, and set `post_max_size` higher to allow multipart form overhead. The web-server request limit must be at least as large as `post_max_size`. Reload the affected services after changing their configuration.

## Admin directory layout

Application code, documentation, `.env`, uploads, and runtime storage now live under `admin/`. Run tools from the repository root with `php admin/scripts/<script>.php` or `bash admin/update.sh`. Update existing cron commands to `php admin/scripts/check-google-indexing.php`. Keep the root API, promo-code, sitemap, robots, `.htaccess`, and Git files in place.

Move existing runtime directories and `.env` with the application; deployment packages intentionally omit runtime data and credentials. The Apache root `.htaccess` preserves `/uploads/` and legacy login URLs while blocking private backend files. IndexNow key files live under `admin/` but remain verifiable at `/{key}.txt` through the root rewrite. If using Nginx, configure equivalent aliases/rewrite rules and deny direct access to `admin/includes`, `admin/scripts`, `admin/storage`, `admin/chats`, `admin/data`, `admin/update-packages`, and hidden files; Nginx does not read `.htaccess`.
