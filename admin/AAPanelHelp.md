# AAPanel Setup

1. create .env
to create hash password run `` php admin/scripts/generate-password.php `` input desired password then copy the hash generated to .env ADMIN_PASSWORD_HASH
2. to activate ai assistance get api key from [ollama settings](https://ollama.com/settings/keys) and add it to OLLAMA_API_KEY

##just scratch

| Script | Purpose |
|---|---|
| `generate-llms.php` | Generate/update `llms.txt` from sitemaps using AI |
| `generate-game-sitemaps.php` | Generate game sitemaps; forwards to Games plugin |
| `check-google-indexing.php` | Process Google indexing checks and IndexNow notifications |
| `generate-password.php` | Generate an administrator password hash |
| `generate-blog-api-token.php` | Generate a public blog API token |
| `sync-static-routes.php` | Generate static route files |
| `sync-blog-routes.php` | Compatibility wrapper for route generation |
| `sync-game-routes.php` | Compatibility wrapper for route generation |
| `build-static-routes.sh` | Shell wrapper for route generation |
| `minify-assets.cjs` | Minify CSS and JavaScript assets |
| `apply-noindex.cjs` | Update robots meta tags; currently writes **`index, follow`** |
| `chat-ai-worker.php` | Run the AI chat response worker |
| `package-update.php` | Build deployment update packages |
| `package-update.sh` | Shell wrapper for update packaging |
| `updater.py` | Build or install site updates |

| Path | Purpose |
|---|---|
| `admin/scripts/chat-socket/server.mjs` | Chat WebSocket relay |
| `admin/update.sh` | Site update and backup workflow |

| Script | Purpose |
|---|---|
| `import-slotslaunch-games.php` | Import games using PHP |
| `import-slotslaunch-games.py` | Import games using Python |
| `import-slotslaunch-providers.php` | Import providers and thumbnails |
| `sync-providers.php` | Refresh provider metadata and thumbnails |
| `enrich-game-descriptions.php` | Enrich game descriptions using PHP |
| `enrich-game-descriptions.py` | Enrich game descriptions using Python |
| `enrich-games-daily.py` | Run a daily enrichment batch |
| `generate-game-sitemaps.php` | Generate eligible-game sitemap files |
| `game-eligibility-sql.php` | Export game eligibility SQL rules |
| `runtime.php` | Report game database paths and enabled status |

| Area | Scripts |
|---|---|
| Admin and security | `test-admin-support.php`, `test-editor-security.php`, `test-layout.php` |
| Storage | `test-fresh-storage.php`, `test-game-storage.php` |
| Blogs and editors | `test-blog-buttons.php`, `test-blog-taxonomy.php`, `test-button-editor.cjs`, `test-custom-code-editor.cjs`, `test-writers.php` |
| Chat | `test-chat-auto-responses.php`, `test-chat-message.cjs`, `test-chat-realtime.php` |
| Games | `test-game-images.php`, `test-game-players.php`, `test-game-sitemaps.php`, `test-game-visibility.php` |
| Providers | `test-provider-images.php`, `test-provider-links.php` |
| SEO and indexing | `test-google-credentials.php`, `test-index-checker.php`, `test-indexnow.php`, `test-seo-settings.php` |
| AI sitemap summary | `test-generate-llms.php` |
| Odds and sports | `test-odds-api.php`, `test-sports-api.php` |
| Plugin removal | `test-plugin-removal.php` |
| Public content | `test-public-details.php` |
| Uploads | `test-upload-image.php` |

| Path | Purpose |
|---|---|
| `chat-client.js` | Public chat client |
| `chat-message.js` | Chat message handling |
| `admin/chat-auto-responses.js` | Automatic-response management |
| `admin/content-editor.js` | Shared content editor |
| `admin/live-chat.js` | Admin live chat |
| `admin/session-renew.js` | Admin session renewal |
| `admin/slot-editor.js` | Compatibility loader for the Games editor |
| `admin/table-pagination.js` | Shared admin table pagination |
| `admin/plugin/games/editor.js` | Game content editor |
| `admin/plugin/odds-api/editor.js` | Legacy odds iframe editor |
| `admin/plugin/odds-api/sports/editor.js` | Sports layout builder |
| `admin/plugin/odds-api/public/app.js` | Public odds iframe application |
| `admin/plugin/odds-api/public/render.js` | Public sports layout renderer |