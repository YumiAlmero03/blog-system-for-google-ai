# Sports storage and compatibility

Sport / Competition management lives under [Odds API](../sports/README.md).
The administrator-only `/admin/plugin/odds-api/livescore/` redirects to `/admin/plugin/odds-api/sports/`.

`includes/storage.php` opens `sports-livescore.sqlite` under `APP_STORAGE_DIR` or `admin/storage`. Its `matches` table is unchanged. The existing `iframes` table now stores API URLs, enabled state, root path, column counts and nested layout JSON alongside preserved IDs and legacy blocks. `sport_api_cache` contains expiring API responses. URLs may contain credentials: keep this database and backups private.
