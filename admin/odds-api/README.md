# Odds API and iframe block editor

This module displays sports and bookmaker odds from The Odds API v4. It replaces
Sports Livescore in the admin navigation; the old `/admin/sports-livescore/` page
redirects to `/admin/odds-api/`. It does not fetch scores.

## Provider settings

Open **Settings → Odds API** (`/admin/settings.php#odds-api`). Administrator or
super-user access is required.

- **apiKey**: save your provider key. Leave blank to retain it. To remove it,
  turn off the module and select Remove saved key in the same save. The key is
  never returned in public responses, iframe HTML, or embed URLs.
- **regions**: comma-separated `us`, `us2`, `uk`, `au`, `eu` (default `us`).
- **bookmakers**: optional provider keys, such as `onexbet`. These take priority
  over regions when provided.
- **Enable Odds API**: defaults off. Enabling requires a key. When off, all
  iframes show unavailable on their next refresh; no provider requests are made.

Markets belong to each iframe, not general Settings. Saved provider settings apply
to all iframes. Changing provider settings clears shared cached data.

## Build an iframe

Open **Odds API** and choose an existing iframe or **New iframe**.

The editor uses a block canvas, an Add block panel, and a Block settings inspector:

1. Enter the iframe's title and markets (`h2h`, `spreads`, `totals`, `outrights`,
   comma-separated). Select markets supported by your sports.
2. Click a data block in the canvas to select it.
3. Change **Display label** to rename the field's visible heading, without changing
   the source data. For example, rename `home_team` to “Home side”.
4. Select **Display as**:
   - **Data**: ordinary field value or table cell.
   - **Title**: the value is displayed as a heading.
   - **Description**: the value is displayed as paragraph text.
5. Use × or **Remove block** to omit a field from this layout. Removed fields appear
   in **Add block**, so they can be restored. Undo/Redo also restores changes.
6. Drag blocks to reorder them. Arrow buttons support keyboard use; drag handles
   also support touch. Event blocks move within event details; repeated outcome
   columns move within their own group to preserve their data relationships.
7. Preview changes, then **Save iframe**. Title, label, role and order changes preview
   immediately; markets take effect on save. At least one block is required.

All 20 fields are available by default: event ID, sport key/title, start time, home
and away teams; sports-list key/title/group/description/active/has_outrights;
bookmaker key/title/update; market key/update; outcome name/price/point.
Missing values show —, zero stays zero, and boolean values show Yes/No. Dates use
visitor local time. Multiple blocks may have the Title or Description role.
For repeated bookmaker/outcome fields, the role applies inside each table cell.

Removing a block hides its **display**; it does not delete provider data or remove
that field from the JSON feed. Every iframe has its own title, markets, block
selection, labels, roles and order. Saved changes affect only that iframe's embeds.
The Saved iframes table provides edit/view links and pagination.

## Embed

After saving, copy the generated embed code. For example:

```html
<iframe src="/odds-api/?iframe=2" title="Basketball odds"
        width="100%" height="650" style="border:0" loading="lazy"></iframe>
```

For another website, use this module's full HTTPS URL:

```html
<iframe src="https://YOUR-SITE.example/odds-api/?iframe=2&sport=basketball_nba"
        title="Basketball odds" width="100%" height="650"
        style="border:0" loading="lazy"></iframe>
```

Replace the hostname, iframe ID and initial sport. `sport` sets the initial
selection; visitors can still switch sports. Never include an API key in the URL.
Paste the iframe into a blog custom-code block's HTML tab if needed.
Old embeds without `iframe` continue to display **iframe 1**.

## Storage and migration

- **`sports-livescore.sqlite` → `iframes`** stores named layouts with columns `id`,
  `title`, `markets`, `blocks` (JSON), `created_at`, `updated_at` (UTC Unix seconds).
  This is the existing sports SQLite database; its `matches` table is preserved.
- **`odds-api.sqlite`** retains provider `settings` (including the secret API key)
  and shared `cache`. Its old `iframe_settings` table is retained as a migration
  source but is no longer edited or used for active layouts.
- On first use, the existing iframe title, markets and ordered fields are copied
  to sports `iframes.id=1`. Migration is idempotent; subsequent reads never
  overwrite a customized iframe. Existing fields migrate with the Data role.
- New iframe records are created only when saving New iframe. They get distinct
  IDs and embed URLs. No credentials are stored in the sports layout table.

Both databases use `APP_STORAGE_DIR`, or `admin/storage` by default, with private
file permissions. Back up both. No blog/game tables or new environment flags are
introduced. Keep storage inaccessible over HTTP.

## Local API and admin handlers

| URL | Method/access | Purpose |
| --- | --- | --- |
| `/admin/odds-api/?id=2` | GET, admin/super user | Edit a saved iframe; omitted ID defaults to 1 |
| `/admin/odds-api/?new=1` | GET, admin/super user | Unsaved new layout |
| `/admin/odds-api/` | POST, admin/super user + CSRF | `action=save_iframe`, `id` (0 creates), `title`, `markets`, `blocks` JSON |
| `/admin/settings.php` | POST, admin + CSRF | `action=save_odds_api`, `enabled`, `apiKey`, `regions`, `bookmakers`, optional `clear_api_key=1` |
| `/odds-api/?iframe=2&sport=basketball_nba` | GET, public | Embeddable display |
| `/api/odds-api.php?action=sports&iframe=2` | GET, public | Active sports list plus this iframe's layout |
| `/api/odds-api.php?action=odds&sport=basketball_nba&iframe=2` | GET, public | Odds for an active sport, using this iframe's markets |

Save body `blocks` is a JSON object with `event_fields` and `outcome_fields` arrays:

```json
{
  "event_fields": [
    {"field": "home_team", "label": "Home side", "role": "title"},
    {"field": "sport.description", "label": "League", "role": "description"}
  ],
  "outcome_fields": [
    {"field": "outcome.price", "label": "Price", "role": "data"}
  ]
}
```

Only known fields in the correct group are accepted; duplicates, unknown roles,
empty layouts and invalid labels are rejected. Title is limited to 200 bytes,
block labels to 120 bytes. Old ordered `event_fields[]` / `outcome_fields[]` posts
remain supported when `blocks` is absent, using default labels and Data roles.
POST save success redirects with 303 to the saved ID; validation is 422, unknown
ID 404, CSRF failure 403, and storage error 500.

Public results use `{ok,enabled,data,updatedAt,layout}`. `layout` includes ID, title,
markets, blocks, derived ordered field arrays, labels, and creation/update times.
When disabled, `data:[]` and `updatedAt:null`; layout still returns without provider
calls. Sports entries contain key, title, group, description, active, has_outrights.
Odds contain event identity/start/teams, nested bookmaker markets/outcomes, and
`sport` metadata from the sports list. Outcome points may be null. Keys and upstream
errors are never exposed. Error status codes: 405 wrong method, 422 invalid inputs,
404 unknown iframe, 429 rate limit, 503 provider/storage failure. Public callers
cannot override credentials, markets, regions or bookmakers.

## Provider requests and caching

Server-only calls use `https://api.the-odds-api.com/v4/sports/` and
`/v4/sports/{sport}/odds/`. Odds calls include apiKey, saved regions, this iframe's
markets, optional bookmakers, `oddsFormat=decimal`, and `dateFormat=iso`.

Sports are cached one hour; odds five minutes. Compatible iframes share cached
provider responses. Block/label/title edits do not consume credits. Market changes
select the corresponding cache entry or fetch a new one; they never reuse odds
from a different market configuration. Provider failures back off for 60 seconds;
concurrent cache fills use a lock. The visible iframe checks every minute; Refresh
respects the same shared cache. No worker/cron is required.

Sports-list calls do not consume credits. Odds costs depend on markets/regions;
specified bookmakers take priority, with groups of up to ten counting as one
region. See the [official v4 guide](https://the-odds-api.com/liveapi/guides/v4/)
and [bookmaker keys](https://the-odds-api.com/sports-odds-data/bookmaker-apis.html).

## Deployment and troubleshooting

PHP needs PDO SQLite, cURL and outbound HTTPS. Apache uses repository `.htaccess`
protections. Nginx must explicitly protect private files (before a generic PHP
location):

```nginx
location ^~ /admin/storage/ { deny all; }
location ^~ /admin/odds-api/includes/ { deny all; }
location ^~ /admin/sports-livescore/includes/ { deny all; }
location ~ ^/admin/(odds-api|sports-livescore)/.*\.md$ { deny all; }
```

Keep the existing PHP handler and `index index.php index.html;`. The physical iframe
directory needs no pretty-URL rewrite. Do not apply restrictive frame headers to
`/odds-api/` when embedding on other domains; keep protections on admin pages.

- Unavailable/off: enable the module with a valid key.
- Provider error: check key, quota, markets/bookmakers and outbound HTTPS; retry
  after the 60-second cooldown. No credential-bearing URLs are logged.
- No odds: choose another active sport or supported market.
- Missing field: add its block back; the provider may also omit its value.
- Iframe not found: copy the URL from Saved iframes after saving the new layout.
- Blocked embed: check parent `frame-src`, frame headers and HTTPS.

Run `php admin/scripts/test-odds-api.php` for isolated fixture tests of migration,
settings, caching, field removal, roles, labels and independent layouts. No provider
credits are consumed by the tests.
