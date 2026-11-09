# AAPanel Setup

1. create .env
to create hash password run `` php admin/scripts/generate-password.php `` input desired password then copy the hash generated to .env ADMIN_PASSWORD_HASH
2. to activate ai assistance get api key from [ollama settings](https://ollama.com/settings/keys) and add it to OLLAMA_API_KEY

## settimg up chat in aapane'
### step 1:
install chat socket from npm
```
cd /www/wwwroot/yourdomain.com

npm ci --prefix admin/scripts/chat-socket
```
test it
```
CHAT_WS_HOST=127.0.0.1 \
CHAT_WS_PORT=8084 \
CHAT_ALLOWED_ORIGINS=https://yourdomain.com \
CHAT_BACKEND_URL=https://yourdomain.com/api/chat-session.php \
node admin/scripts/chat-socket/server.mjs
```

### Step 2
Website → Your Domain → Config. then add the code below

### step 3 
keep open websocket 27/7
```
location = /chat-ws {
    proxy_pass http://127.0.0.1:8084;

    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;

    proxy_read_timeout 3600s;
    proxy_send_timeout 3600s;
    proxy_buffering off;
}
```

this will open wss://yourdomain.com/chat-ws. add this to chat ws url

Activate the worker
```
sudo systemctl daemon-reload
sudo systemctl enable --now blog-chat-ws
sudo systemctl status blog-chat-ws
```

### integrate ai worker
sudo nano /etc/systemd/system/chat-ai-worker.service
```
[Unit]
Description=PHP Chat AI Worker
After=network.target

[Service]
Type=simple
User=www
Group=www

WorkingDirectory=/www/wwwroot/yourdomain.com

ExecStart=/www/server/php/83/bin/php /www/wwwroot/yourdomain.com/admin/scripts/chat-ai-worker.php

Restart=always
RestartSec=5

StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
```

Activate worker
```
sudo systemctl daemon-reload
sudo systemctl enable --now chat-ai-worker
```

## just scratch

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

Test scripts live in `admin/test`.

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

use this for starting
``
python3 admin/plugin/games/scripts/enrich-game-descriptions.py \
  --all-games \
  --include-unprocessed \
  --model=gemma4:31b-cloud \
  --skip-errors \
  --verbose
  ``
## Daily sports images

In **Cron → Add Task**, select **Shell Script**, **Daily** (e.g. 03:00 server time), and the website user. Replace `82` and `YOUR_SITE` with your installed PHP version and website directory:

```sh
/www/server/php/82/bin/php '/www/wwwroot/YOUR_SITE/admin/plugin/odds-api/scripts/daily-sports-images.php'
```

Requires PHP 8.1+, cURL and PDO SQLite. No `nohup` or `&` is needed in cron. The runner prevents overlaps, fills missing logos, retains manual/existing images, waits 30 minutes and retries on HTTP 429, and records results in aaPanel's task output plus private `sports-images-daily.log` in the app storage directory. Use **Execute** and **Log** to verify the task. See the [daily/background image guide](plugin/odds-api/README.md#dailybackground-image-job-aapanel) for detached terminal runs, limits and tournament filters.

Daily sports images HTTP 429 behavior: persist the 30-minute cooldown, keep the worker and lock alive, wait in 30-second intervals, and retry automatically. Existing cooldowns are awaited on startup. Waiting time is excluded from the active processing time budget. Repeated 429 responses repeat the wait; progress is retained. Default runs scan all eligible missing tournament and participant images in batches of 100, with no saved-image target or total item/time cap. Completed saved/unmatched results are persisted per entity in private APP_STORAGE_DIR/sports-images-checklist.json and skipped on subsequent daily runs; existing and manual images are preserved. Failed requests and rate-limited items remain retryable. Optional item/time limits still apply when explicitly set. No database changes.
