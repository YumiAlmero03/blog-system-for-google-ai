# API and admin guidance

- Check `INDEX.md` before searching documentation and read only task-relevant documents; when creating, renaming, moving, or deleting a maintained project `.md` file, update `INDEX.md` in the same change.
- Read `AGENT.md` for existing project conventions; reuse endpoints and storage helpers before adding APIs.
- Public details: `api/game.php?slug=…` and `api/blog.php?slug=…`; keep visibility checks in `includes/game-public.php` and `includes/public-detail.php` (processed/public slugs, exact PH restrictions, published/due blogs).
- Blog HTML must reuse `includes/blog-renderer.php` for Markdown and special blocks, shared with `blog/view.php`.
- Contacts use `api/customer-ticket.php` / `includes/customer-ticket-storage.php`; live chat uses `api/chat-session.php` / `includes/chat-session-storage.php`. Preserve their existing payloads and persistence.
- Authentication: `login-handler.php`, `includes/auth.php`, `includes/admin-permissions.php`; enforce session/JWT, CSRF, rate limits, and capabilities on the server.
- Users and writer profiles are managed by `admin/users.php`, `includes/admin-users.php`, and `includes/writers.php`; reuse these functions, not a second user/profile API.
- `super_user` has full access; only super users manage privileged accounts/roles. Admins manage editors and writer profiles; editors cannot manage users or roles.
- Blog writes use `admin/blog-save.php` and `includes/blog-storage.php`. Preserve omitted writer attribution; validate selected writer IDs and load list profiles in groups.
- Public writer data is limited to name, display-only `role_name`, an absolute profile image URL, bio, and validated HTTP(S) social links. List responses omit bio/socials; `role_name` never affects permissions. Never expose credentials, usernames, role/session data, or other private account fields.

- Global SEO/site settings use `includes/seo-settings.php` and `api/settings/seo.php` (public GET, admin POST); public heads use `includes/public-head.php`. Reuse these helpers instead of hardcoding Analytics, verification, site names, or default metadata. `SITE_BASE_URL` retains precedence over the saved site URL.

- Blog taxonomy uses `includes/blog-taxonomy.php`: stable category IDs/slugs with validated `parent_id`, `blog_posts.category_id`, and flat `tags`/`blog_post_tags`. Admin CRUD: `admin/blog-category-save.php` and `admin/blog-tag-save.php`; authenticated lookups: `admin/blog-category-list.php` and `admin/blog-tag-list.php`. Editors may assign existing taxonomy via `admin/blog-save.php`, but cannot manage it. Public blog APIs return category hierarchy and tags using grouped lookups.

- Category filtering is shared through `blog_category_filter_ids()` and `blogs_page()`: stable IDs, slugs, or legacy names resolve to IDs; root categories include all descendants, direct child selections stay exact. Admin category-name search also includes descendants. Counts and paginated rows use the same ID predicate.

- Public games must pass publication/processing, exact PH restrictions, provider approval, and `is_viewable` through `includes/game-visibility.php`. Provider overrides reuse existing game provider keys; absent overrides and existing games default to enabled. Provider settings are admin-only; slot visibility uses `slots.edit`. Visibility writes clear API caches and regenerate game sitemap chunks/index.

- Index Checker: `/admin/index-checker/` and `scripts/check-google-indexing.php` use XML sitemaps as the URL source. Search Console inspection results are private/admin-only; credentials must stay outside the public site and never enter the public SEO settings API.
