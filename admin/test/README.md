# Tests

All automated test scripts live here. Run commands from the `www` directory:

```sh
php admin/test/test-blog-import-batches.php
php admin/test/test-blog-wordpress-import.php
node admin/test/test-blog-import-client.cjs
```

PHP tests require PHP CLI and the extensions used by the feature being tested (including SQLite, XML, ZIP, and GD for import tests). JavaScript tests use Node.js. Browser tests additionally require Chrome/Chromium and may accept `CHROME_BINARY`.

For the chat socket integration test, install the relay's dependencies first:

```sh
npm ci --prefix admin/scripts/chat-socket
node admin/test/test-chat-socket.mjs
```

Some integration tests start local HTTP servers. The test directory is blocked from web access by the site's Apache configuration; include `/admin/test/` in equivalent Nginx private-path rules.

Maintenance tools and production workers remain in `admin/scripts` and the plugin script directories.
