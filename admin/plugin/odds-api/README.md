# Odds API

Management now lives in **Odds API → Sport / Competition** at `/admin/plugin/odds-api/sports/`.
See the [API layout builder guide](sports/README.md) for API URLs, provider settings, field discovery, nested loops, labels, columns, embedding, migration and deployment.

`/admin/plugin/odds-api/` is an authenticated compatibility redirect, not a second editor. Existing `/odds-api/?iframe=N` embeds use the shared saved-layout renderer. Provider credentials, regions, bookmakers and the master switch remain under `/admin/plugin/odds-api/settings.php`; each migrated layout retains its markets selection.

The legacy `/api/odds-api.php?action=sports|odds&iframe=N&sport=KEY` raw feed remains available for existing integrations. The iframe now uses `/api/sports-layout.php`. See [API.md](/admin/API.md) for both contracts.
