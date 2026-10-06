# Plugins

- **Games**: `games/` contains catalogue administration, providers, module settings, API implementations, helpers and jobs. Open Games in the admin header.
- **Odds API**: `odds-api/` contains provider settings, sports iframe editing, services, APIs and iframe assets. Open Odds API or Odds API Settings in the admin header.

Remove either folder to uninstall that plugin. Stop Games import/enrichment/sitemap cron jobs before removing Games. Header links disappear automatically, public adapters return 404, and core blog/settings pages continue working. Existing game demo blocks show their unavailable state when Games is absent.

Storage is deliberately retained for reinstall: `games.sqlite`, `odds-api.sqlite` and `sports-livescore.sqlite` remain under the existing storage directory or `APP_STORAGE_DIR`. Removing plugin code does not erase your catalogue, credentials or layouts. Back up storage separately.

Deploy the root `.htaccess` with the folders and adapters: it protects plugin internals and preserves legacy admin URLs and `/odds-api/` asset URLs. Existing public API paths and iframe IDs are unchanged.

Checks: `php admin/scripts/test-plugin-removal.php`, `php admin/scripts/test-sports-api.php`, `php admin/scripts/test-odds-api.php`, `php admin/scripts/test-game-storage.php`, `php admin/scripts/test-game-visibility.php` and `php admin/scripts/test-public-details.php`.
