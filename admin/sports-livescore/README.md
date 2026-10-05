# Sports storage and compatibility

The admin feature is now **Odds API**. See the [Odds API usage guide](../odds-api/README.md)
for settings, iframe blocks, embedding, public endpoints and deployment.

The administrator-only `/admin/sports-livescore/` page redirects to `/admin/odds-api/`.
The existing `includes/storage.php` helper opens `sports-livescore.sqlite` under
`APP_STORAGE_DIR` or `admin/storage`. The `matches` table remains unchanged.

The Odds API module adds an `iframes` table here for saved block layouts: ID,
title, markets, blocks JSON, created_at and updated_at. Each iframe has its own
field selection, labels, title/description roles and ordering. The previous singleton
layout migrates once from `odds-api.sqlite` to iframe 1. Provider credentials and
response cache stay in `odds-api.sqlite`; this sports database stores no API keys.
