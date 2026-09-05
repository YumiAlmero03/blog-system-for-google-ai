## Rules

* Stack: PHP + HTML5 + SQLite.
* Make the smallest necessary change.
* Inspect only task-relevant files; expand only if needed.
* Do not modify or refactor unrelated code.
* Preserve existing HTML, classes, CSS variables, typography, grids, styling, and responsive behavior.
* Reuse existing components and patterns.
* Do not add dependencies unless necessary.
* Run only relevant checks/tests.
* Keep responses concise.

## Core Pages

* `*/index.html` files are the original Google AI-generated core/main pages.
* Treat them as the primary visual and structural reference.
* Preserve their layout, classes, styling, and responsive behavior when converting or extending them with PHP.
* Do not redesign these pages unless explicitly requested.

## Security

* Use PDO prepared statements.
* Escape untrusted output with `htmlspecialchars()`.
* Preserve authentication, validation, and access controls.

## Structure

* `/admin` — admin
* `/api` — API
* `/blog` — blog
* `/game` — games
* `/includes` — shared components/database
* `/scripts` — utilities
* `/chats` — chat/log data
* `/storage` — persistent data
* `/uploads` — media
* `*/index.html` — original Google AI core/main page templates
* `login*.php`, `logout.php` — authentication
* `sitemap-*.php` — XML sitemaps

## Python

`scripts/enrich-game-descriptions.py` is the only Python utility. Use it only for game catalog or description enrichment.

After implementation, report:

1. Files changed
2. Flags added
3. Tables/data affected
4. Test result