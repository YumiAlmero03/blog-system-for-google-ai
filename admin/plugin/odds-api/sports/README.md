# Sport / Competition API layouts

Open **Odds API → Sport / Competition** (`/admin/plugin/odds-api/sports/`). Admin and super-user accounts can manage layouts; editors cannot. The old Odds API and sports-livescore admin pages redirect here. Existing iframe IDs and `/odds-api/?iframe=N` embeds continue to work.

## Build a layout

1. Open an existing layout or choose **New layout**. Enter its name, a complete public HTTP(S) JSON API URL, enabled status and 1–4 columns.
2. Set the root path to `$` for a top-level record/list, or e.g. `$.data` for a wrapped list. Click **Test / Load API**. This previews disabled layouts too, without publishing them.
3. Add discovered fields or arrays. Adding a nested field automatically creates its enclosing loops. Discovery samples up to 100 items at each array level and merges available keys, including optional keys appearing in later records. Add custom paths for absent optional fields.
4. Select a block to edit its label, path, visibility, field/loop type, Title/Data/Description role, or text/decimal/date/datetime format. Blank fallback skips missing values; a configured fallback displays instead. Zero and false are retained.
5. Loops repeat their child blocks for each array item. Nested loops have their own 1–4 column setting. Fields can reference the current item or an enclosing record. Move blocks within their group with the drag handle or arrows. Remove and re-add fields as needed; undo/redo is available until reload.
6. Click **Test / Load API** again to preview changes, then **Save layout** to publish. Copy the embed code, adding the full HTTPS site address for other websites.

Paths start with `$` and are relative to each selected root record: `$.home_team`, `$.bookmakers[]`, `$.bookmakers[].markets[].outcomes[].price`. Property names containing punctuation use quoted notation, e.g. `$["competition.name"]`. Root paths cannot cross nested arrays; represent those arrays with loops. Changing a field to a loop requires an array path ending `[]` and child blocks. Invalid paths/scopes are rejected on preview/save.

Column counts apply to root records and to repeated items within each loop. Narrow screens/containers reduce the number automatically. Dates use the viewer's timezone; decimal formatting uses their locale. Values and labels render as text, never HTML. There is no template/expression execution.

## Existing Odds API layouts

Provider credentials, regions, bookmakers and the master on/off switch remain in Settings. Markets remain in the per-layout editor. Migrated layouts retain their sport selector and server-expanded `{sport}`, `{apiKey}`, `{regions}`, `{markets}`, `{bookmakers}` URL placeholders. The key placeholder is restricted to `api.the-odds-api.com`. The saved catalogue provides sport titles/groups. Both the provider master switch and the layout switch must be on.

For a new generic layout, enter the complete URL including its desired sport key and query parameters. Any credentials in that URL are private configuration visible only to administrators and stored in the protected SQLite database. Public output includes only selected values, not the saved URL or unselected response fields. Do not select secret response fields for public display.

## Storage, limits and deployment

The existing `sports-livescore.sqlite` `iframes` table keeps its IDs/title/markets/blocks/timestamps, with additive columns `api_url`, `enabled`, `root_path`, `columns_per_row`, `layout_config` and `catalog_url`. First use migrates old block selections, roles, labels and ordering into nested layout JSON. `matches` and the legacy source remain intact. New edits use `layout_config`; old `blocks` remain a compatibility snapshot, not a second editor.

`sport_api_cache` stores expiring JSON responses for 300 seconds and failure cooldowns for 60 seconds. URL hashes are cache keys; layout changes reuse the same response. Expired rows are purged on subsequent fetches. No sample-response table is created. Provider credentials remain in `odds-api.sqlite`; generic URL credentials are in `sports-livescore.sqlite`. Protect both databases and backups. Storage follows `APP_STORAGE_DIR`, falling back to `admin/storage`.

The server accepts public HTTP(S) URLs on ports 80/443, verifies TLS, blocks private/reserved literal and DNS addresses, pins validated DNS answers, disables redirects/proxies, limits bodies to 2 MB and uses 5-second connection/15-second transfer timeouts. Failures log a source hash, not credentials, URLs or response bodies. Discovery is bounded to 500 paths/depth 12; layouts to 120 blocks/depth 8; output to 200 root records and 100 items per nested loop with a global block budget. Truncation is reported publicly.

Keep `admin/storage`, `admin/includes`, module `includes` and admin scripts inaccessible over HTTP. Apache rules cover private directories; configure equivalent nginx denies when nginx does not process `.htaccess`. PHP needs PDO SQLite and cURL, and PHP 8.2+ for public-address validation.

Public `GET /api/sports-layout.php?id=N&sport=KEY` returns the saved projected layout. `sport` is optional for migrated catalogues. `/api/odds-api.php` remains the legacy raw Odds API endpoint; it does not expose the new generic layout contract. See [API documentation](/admin/API.md).

## Verification

Run `php admin/scripts/test-sports-api.php` and `php admin/scripts/test-odds-api.php` from the site root. Tests use isolated temporary storage and injected fixture transports; no real provider key or network is required. Browser integration additionally verifies authenticated Settings, discovery, nested insertion, native drag/drop, save/reload, escaped public output and mobile layout.
