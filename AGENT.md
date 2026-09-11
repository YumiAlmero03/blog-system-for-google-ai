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

## Current APIs

Base URL: `https://freecasinogames.ph`. Inventory checked on 2026-09-11 against all nine PHP endpoints in `/api`.

Verification below means local PHP handler execution, using a temporary SQLite database copy and temporary storage for writes. It does not establish production HTTP availability. All nine files passed `php -l`. No real engagement, click, chat, or ticket records were changed; no email was sent.

| Endpoint | Methods | Purpose and inputs | Local verification |
| --- | --- | --- | --- |
| `/api/slot-list.php` | GET, POST | Paginated games. `count` (1–100, default 24), `page`, `search`, `provider` (slug), `type`/`types`, `featured`, `progressive`, `megaways`, `upcoming`, `published` (default 1), `sort`. Returns `slots`, `pagination`, and `filters`. Requires `done_processing = 1`; excludes exact PH/PHILIPPINES restriction tokens through `includes/game-restrictions.php`. | Both methods returned status 200 and `ok: true`. |
| `/api/provider-list.php` | GET | Providers and game counts. Optional `count` or `limit` (1–1000); `sample` or `dev` enables sample data. Returns `providers`, `total`, `count`, `limit`, and `sample`. | Real database mode returned status 200 and `ok: true`. |
| `/api/blog-category-list.php` | GET, POST | Public blog categories. No required input. Returns category data for blog navigation. | Both methods returned status 200 and `ok: true`. |
| `/api/blog-post-list.php` | GET, POST | Published blog listing. `count` (1–100, default 10), `page`, optional `category`. Returns `blogs` and `pagination`. | Both methods returned status 200 and `ok: true`. |
| `/api/blog-public-token.php` | GET, POST | Issues a public `blog:read` JWT with audience `blog-post-list`, `token`, and `expiresIn: 300`. Requires `BLOG_API_JWT_SECRET` of at least 32 characters. The current blog listing handler does not require this token. | Both methods returned status 200 and `ok: true`; token values were not logged. |
| `/api/blog-engagement.php` | POST | Records engagement. Required `postId` (blog ID), `action` (`view`, `like`, or `dislike`). Updates blog engagement storage. | A `view` on a copied published blog returned status 200 and `ok: true`. |
| `/api/playnow-click.php` | POST | Records button click analytics. Optional context includes `pageUrl`, `pagePath`, `pageTitle`, `referrer`, `targetUrl`, `buttonText`, `buttonClass`, `buttonId`, `buttonName`, `buttonTag`, `section`, `selector`, and `location`. | Isolated click returned status 200 and `ok: true`. |
| `/api/chat-session.php` | POST | Saves chat transcripts. Requires nonempty `messages` with `text`; each message can include `sender` (`user`, `bot`, `agent`), `time`, and `type`. Optional `name`, `contact`, `page_url`, and `session_id`. Returns `sessionId`, `path`, and `messageCount`. | Valid payload returned `ok: true` and saved one message in temporary storage; empty messages returned 422. |
| `/api/customer-ticket.php` | POST | Saves a support ticket and attempts email delivery. Required `full_name`, `contact`, `topic`, `problem`; optional `page_url`. Uses configured SMTP or mail fallback. Check `emailSent`: `ok: true` alone does not confirm delivery. | Validation only: missing fields returned 422. Successful submission and email delivery were not tested. |

GET inputs use query parameters. POST inputs for the list endpoints use form fields (`application/x-www-form-urlencoded`), not JSON. Engagement, click, chat, and ticket endpoints accept JSON or form fields. The token endpoint needs no request fields. These handlers contain no login requirement; this does not verify deployment-level access rules.

Example read requests:

```text
https://freecasinogames.ph/api/slot-list.php?count=24&page=1
https://freecasinogames.ph/api/provider-list.php?count=20
https://freecasinogames.ph/api/blog-category-list.php
https://freecasinogames.ph/api/blog-post-list.php?count=10&page=1
```

Do not use successful requests to write endpoints as production health checks: they can create records, change analytics, or send email. Provider counts are not a guarantee of PH-eligible games; use the slot listing's restriction filtering for game discovery.

### Contact and live-chat modules

Added two public APIs alongside the inventory above (11 public PHP API files in total). The legacy `/api/customer-ticket.php` and `/api/chat-session.php` retain their existing behavior; their JSON archives are not imported into these new modules.

- `POST /api/contact-form.php`: JSON or form fields `email`, `subject`, `message`; optional `name`, `source_page`. Returns 201 after saving. Limits: 254-byte email, 120-byte name, 200-byte subject, 10,000-byte message, 1,000-byte source; three attempts per IP per minute. Admin list/detail/status/delete: `/admin/contacts.php`.
- `/api/live-chat.php`: `POST` JSON or form with `action=start` and optional `name` returns 201 with `chat_id` and a secret `token`. No customer login is required. Treat the token as a credential; keep it out of URLs, logs, and HTML. Retain it privately on the client for the conversation.
- Send with `POST /api/live-chat.php`, body `action=send`, `chat_id`, `message`, and header `Authorization: Bearer <token>`. Message maximum: 4,000 bytes. Closed conversations reject messages with 409.
- Poll `GET /api/live-chat.php?action=messages&chat_id=<id>&after_id=<last_id>` with the same bearer header. Start at zero, advance to the returned `last_id`, and drain `has_more` batches (up to 100 messages each). Otherwise poll approximately every four seconds while visible. Render message strings with `textContent`, never `innerHTML`.
- Acknowledge displayed admin messages with `POST` body `action=read`, `chat_id`, `through_id` and the bearer header. Another chat's token cannot authorize this request or message reads/writes.
- Chat limits per IP/minute: five starts, 30 sends, 120 fetches, 120 read acknowledgments. Public payloads are capped at 20,000 bytes. Rate limits use the existing file-backed helper and `REMOTE_ADDR`.
- Admin list and conversation: `/admin/live-chat.php`; authenticated polling/reply/read/status API: `/admin/live-chat-api.php`. Admin POSTs require the existing session CSRF token. List order puts open chats first, then latest activity; conversation polling is incremental. Read actions acknowledge only messages through the displayed cursor.
- `includes/support-storage.php` initializes `contact_submissions`, `live_chat_sessions`, and `live_chat_messages` plus indexes using the existing `blogs_pdo()` connection. Contact statuses: `new`, `read`, `resolved`; chat statuses: `open`, `closed`. Messages are separate persisted rows.
- Optional contact notifications reuse `includes/smtp-mailer.php`. Set `CONTACT_RECIPIENT_EMAIL` (falls back to `TICKET_RECIPIENT_EMAIL`) and `SMTP_FROM_EMAIL` (falls back to `TICKET_FROM_EMAIL`), plus existing `SMTP_*` transport settings. Without SMTP/recipient configuration, submissions are saved without email. Notification failures are logged without credentials or message content and never roll back saved submissions. No production email is sent by the tests.
- Regression command: `php scripts/test-support.php` uses temporary storage and verifies persistence, statuses, ownership, polling, read state, limits, and SMTP failure retention. Isolated localhost HTTP tests also verified auth/CSRF, contact admin rendering, both chat participants, and successful delivery to a local mock SMTP server. Production SMTP delivery and public frontend integration are not verified; the contact/chat frontend files are absent from this checkout.
