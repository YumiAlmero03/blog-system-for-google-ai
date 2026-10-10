# Plugins

- **Games**: `games/` contains catalogue administration, providers, module settings, helpers and jobs. Open Games in the admin header.
- **Odds API**: `odds-api/` contains provider settings, sports registry and image management, synchronization scripts, sports iframe editing, services, APIs and iframe assets. Open Odds API or Odds API Settings in the admin header.

Remove either folder to uninstall that plugin. Stop Games import/enrichment/sitemap cron jobs before removing Games. Header links disappear automatically, public adapters return 404, and core blog/settings pages continue working. Existing game demo blocks show their unavailable state when Games is absent.

Storage is deliberately retained for reinstall: `games.sqlite`, `odds-api.sqlite` , `sports-registry.sqlite` and `sports-livescore.sqlite` remain under the existing storage directory or `APP_STORAGE_DIR`. Removing plugin code does not erase your catalogue, credentials or layouts. Back up storage separately.

Deploy the root `.htaccess` with the folders and adapters: it protects plugin internals and preserves legacy admin URLs and `/odds-api/` asset URLs. Existing public API paths and iframe IDs are unchanged.

Checks: `php admin/scripts/test-plugin-removal.php`, `php admin/scripts/test-sports-api.php`, `php admin/scripts/test-odds-api.php`, `php admin/scripts/test-game-storage.php`, `php admin/scripts/test-game-visibility.php` and `php admin/scripts/test-public-details.php`.

Public Games API implementations live in `admin/plugin/games/api/` (with canonical provider-list.php) and root `game-api/` (game.php, slot-list.php, provider-list.php adapter, game-play.php). Existing `/api/` and `/game-api/` URLs forward to the plugin endpoints and return JSON 404 when Games is uninstalled. Provider administration is located at `admin/plugin/games/providers/index.php` and `admin/plugin/games/providers.php` (with legacy shim at `admin/settings/providers/index.php`). The Games plugin supplies catalogue storage, provider settings, and shared helpers.


### Removed compatibility files (October 2026)

Game handlers now use `/game-api/{slot-list,provider-list,game,game-play}.php` and `/admin/plugin/games/` (`index.php`, `edit.php`, `save.php`). Removed `/api/` game forwarders and `/admin/slot*.php` are no longer supported. Sports layout reads use public `GET /odds-api/sports-layout.php?id=ID`; sports listings use `/odds-api/sports.php`. Methods, permissions, inputs and response shapes on surviving endpoints are unchanged. The public iframe and generated layout URLs use the canonical sports route.

Shared `public_detail_slug()` and `public_detail_url()` live in `admin/includes/public-detail.php`, which loads optional game helpers and retains the absent-plugin fallback. Core/bootstrap and game import/sitemap code no longer require the deleted `admin/includes/game-*.php` wrappers. No flags or database schema changes. Regression tests target surviving routes.
