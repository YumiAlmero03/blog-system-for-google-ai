## Overview
Server-rendered PHP + HTML5 application using SQLite through PDO.

## Structure

```text
/
├── admin/
├── api/
├── blog/
├── game/
├── includes/
├── storage/
├── uploads/
├── scripts/
├── chats/
├── login.php
├── login-handler.php
├── logout.php
├── sitemap-blog.php
└── sitemap-games.php
```

## Modules

### `/admin`
Protected administrative pages.

### `/api`
PHP API endpoints.

### `/blog`
Public blog pages and blog rendering logic.

### `/game`
Public game pages and game rendering logic.

### `/includes`
Shared database connection, configuration, helpers, and reusable PHP logic.

### `/storage`
Persistent application storage.

### `/uploads`
Uploaded media and files.

### `/scripts`
Maintenance utilities.

Current Python utility:

`scripts/enrich-game-descriptions.py`

### `/chats`
Chat-related data and logs.

## Request Flow

```text
Browser
  ↓
PHP Page / API
  ↓
Shared Includes
  ↓
SQLite
```

## Authentication

```text
login.php
  ↓
login-handler.php
  ↓
Session
  ↓
Protected admin pages
```

## Blog

```text
/blog
  ↓
PHP blog logic
  ↓
SQLite
  ↓
Existing HTML template
```

Blog sitemap:

`sitemap-blog.php`

## Games

```text
/game
  ↓
PHP game logic
  ↓
SQLite
  ↓
Existing HTML template
```

Game sitemap:

`sitemap-games.php`

Optional enrichment utility:

`scripts/enrich-game-descriptions.py`