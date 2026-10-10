# Google AI Instructions
Use these for dynamic data for backend integration in production. but also use demo apis for development.

## APIs
| Endpoint | Methods | Access | Parameters | Purpose |
| --- | --- | --- | --- | --- |
| `/game-api/slot-list.php` | GET, POST | Public | Optional: `count`, `page`, `random`, `search`, `provider`, `type`, `types`, `featured`, `progressive`, `upcoming`, `megaways`, `published`, `sort`. | Paginated eligible games of all supported types. |
| `/game-api/provider-list.php` | GET | Public | Optional: `count` or `limit`; `sample` or `dev`. | Providers and eligible game counts. |
| `/game-api/game.php` | GET | Public | Required: `slug`. | One eligible game by slug. |
| `/api/blog-post-list.php` | GET, POST | Public | Optional: `count`, `page`, `category`. | Published blog list. |
| `/api/blog-category-list.php` | GET, POST | Public | None. | Blog category hierarchy and counts. |
| `/api/blog.php` | GET | Public | Required: `slug`. | Published blog detail and rendered content. |
| `/api/blog-public-token.php` | GET, POST | Public | None. | Short-lived public blog JWT. |
| `/api/blog-engagement.php` | POST | Public | Required: `postId`, `action` (`view`, `like`, `dislike`). | Record a view, like, or dislike. |
| `/api/playnow-click.php` | POST | Public | Optional: `pageUrl`, `pagePath`, `pageTitle`, `referrer`, `targetUrl`, `buttonText`, `buttonClass`, `buttonId`, `buttonTag`, `buttonName`, `section`, `selector`, `location`, `x`, `y`. | Record a Play Now click. |
| `/api/chat-session.php` | POST; GET for admin messages | Public transcript save; authenticated admin actions | Legacy save: required `messages[].text`; optional `name`, `contact`, `page_url`, `session_id`, `messages[].sender`, `messages[].time`, `messages[].type`. Realtime: `action`, plus action-specific `session_id`, `session_token`, `message`, `client_message_id`, `client_id`, `online`, `version`; see [chat contract](CHAT-INTEGRATION.md#http-contract). Admin: `admin_action`, action-specific `session_id`, `session_ids[]`, `message`, `client_id`, `online`, `version`; POST requires `csrf_token`. | Save transcripts, read messages, or reply. |
| `/api/chat-auto-responses.php` | GET; POST for admin changes | Public suggestions; admin/super-user management | Public GET: none. Admin GET: `admin_action=list`. Admin POST: `admin_action` (`save` or `delete`), `csrf_token`; save requires `label`, `keywords`, `response`, with optional `id`, `enabled`, `suggested`; delete requires `id`. | List up to five suggestions and manage unlimited automatic reply rules. |
| `/api/customer-ticket.php` | POST | Public | Required: `full_name`, `contact`, `topic`, `problem`. Optional: `page_url`. | Save a support ticket and attempt email delivery. |
| `/api/settings/seo.php` | GET, POST | Public GET; admin/super-user POST | GET: none. POST: required `csrf_token`; settings fields: `website_name`, `website_url`, `google_analytics_id`, `google_search_console_verification`, `default_seo_title`, `default_meta_description`, `default_og_image`. | Read or update site SEO settings. |



//if chat integration is integrated
-