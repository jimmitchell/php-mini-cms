# PHP Flat-File CMS — Development Plan

## Overview

A lightweight, static-output CMS written in PHP, inspired by Kirby. Content is authored in Markdown through a secure admin panel and published as pre-rendered HTML files on disk. The server never runs PHP at page-browse time — only during admin operations and builds.

**Key characteristics:**

- PHP admin panel + build engine; pure HTML output for visitors
- SQLite for content metadata; Markdown source stored in the database
- Smart incremental rebuilds — only changed content re-renders
- Apache shared hosting compatible
- Single admin user (credentials in config file)
- Posts and static pages, both authored in Markdown
- Media uploads: images, video, audio
- Minimalistic one-column theme
- RSS/Atom feed, paginated post index, draft/scheduled posts

---

## Technology Choices

| Concern | Choice | Rationale |
|---|---|---|
| Language | PHP 8.1+ | Widely available on shared hosting |
| Database | SQLite 3 (via PDO) | Zero-config, file-based, no server needed |
| Markdown | `league/commonmark` | CommonMark compliant, actively maintained |
| Admin editor | EasyMDE (SimpleMDE fork) | Browser-based Markdown editor, no build step |
| Dependency management | Composer | Standard for PHP |
| Templating | Plain PHP templates | No extra engine, easy to customise |
| CSS (admin) | Vanilla CSS | No framework, minimal footprint |
| CSS (theme) | Vanilla CSS | Output pages need zero JavaScript |

---

## Directory Structure

```
project-root/               ← lives inside public_html (or equivalent)
│
├── admin/                  ← Admin panel (PHP, password-protected)
│   ├── index.php           ← Login page / dashboard redirect
│   ├── dashboard.php
│   ├── posts.php           ← Post list
│   ├── post-edit.php       ← Create / edit post
│   ├── pages.php           ← Static page list
│   ├── page-edit.php       ← Create / edit static page
│   ├── media.php           ← Media library & uploader
│   ├── settings.php        ← Site-wide settings
│   ├── build.php           ← Manual full-rebuild trigger
│   └── assets/
│       ├── admin.css
│       ├── admin.js
│       └── easymde.min.*   ← Markdown editor (vendored)
│
├── content/                ← BLOCKED from web (.htaccess)
│   └── media/              ← Uploaded files (images, video, audio)
│
├── data/                   ← BLOCKED from web (.htaccess)
│   └── cms.db              ← SQLite database
│
├── src/                    ← BLOCKED from web (.htaccess)
│   ├── Auth.php
│   ├── Builder.php
│   ├── Database.php
│   ├── Media.php
│   ├── Post.php
│   ├── Page.php
│   ├── Feed.php
│   └── Helpers.php
│
├── templates/              ← BLOCKED from web (.htaccess)
│   ├── base.php            ← Shared HTML shell
│   ├── post.php            ← Single post
│   ├── page.php            ← Static page
│   ├── index.php           ← Post listing / pagination
│   └── feed.php            ← RSS/Atom XML
│
├── vendor/                 ← BLOCKED from web (.htaccess)
│
├── media/                  ← PUBLIC symlink (or copy) into content/media/
│
├── posts/                  ← Generated: one subdir per post
│   └── {slug}/
│       └── index.html
│
├── {page-slug}/            ← Generated: one subdir per static page
│   └── index.html
│
├── page/                   ← Generated: paginated index
│   └── 2/
│       └── index.html
│
├── index.html              ← Generated: post listing page 1
├── feed.xml                ← Generated: RSS/Atom feed
│
├── config.php              ← BLOCKED from web; site config + admin credentials
├── composer.json
├── composer.lock
└── .htaccess               ← Routing + security rules
```

> **Note on `media/`:** Uploaded files live in `content/media/` (blocked). The public `media/` directory is either an Apache `Alias` directive or a symlink pointing there. This keeps uploads out of the blocked source tree while serving them publicly.

---

## Database Schema (SQLite)

### `posts`

```sql
CREATE TABLE posts (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    title        TEXT    NOT NULL,
    slug         TEXT    UNIQUE NOT NULL,
    content      TEXT    NOT NULL,          -- Markdown source
    excerpt      TEXT,                      -- Optional hand-written summary
    status       TEXT    NOT NULL DEFAULT 'draft',  -- draft | published | scheduled
    published_at DATETIME,                  -- Actual or scheduled publish time
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    built_at     DATETIME,                  -- Last time HTML was generated
    content_hash TEXT                       -- SHA-256 of rendered HTML; change detection
);
```

### `pages`

```sql
CREATE TABLE pages (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    title        TEXT    NOT NULL,
    slug         TEXT    UNIQUE NOT NULL,
    content      TEXT    NOT NULL,          -- Markdown source
    nav_order    INTEGER DEFAULT 0,         -- Position in navigation
    status       TEXT    NOT NULL DEFAULT 'draft',  -- draft | published
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    built_at     DATETIME,
    content_hash TEXT
);
```

### `media`

```sql
CREATE TABLE media (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    filename      TEXT    NOT NULL,         -- Stored filename (possibly renamed)
    original_name TEXT    NOT NULL,
    mime_type     TEXT    NOT NULL,
    size          INTEGER NOT NULL,         -- Bytes
    uploaded_at   DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

### `settings`

```sql
CREATE TABLE settings (
    key        TEXT PRIMARY KEY,
    value      TEXT,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
-- Example keys: site_title, site_description, site_url,
--               posts_per_page, feed_post_count, footer_text
```

### `login_attempts`

```sql
CREATE TABLE login_attempts (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    ip         TEXT    NOT NULL,
    attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    success    INTEGER DEFAULT 0
);
```

---

## Configuration File (`config.php`)

```php
<?php
return [
    'admin' => [
        'username'      => 'admin',
        'password_hash' => '',   // bcrypt hash generated at setup
        'session_name'  => 'cms_session',
        'session_lifetime' => 3600,         // seconds
    ],
    'paths' => [
        'data'      => __DIR__ . '/data',
        'content'   => __DIR__ . '/content',
        'output'    => __DIR__,             // Web root is project root
        'templates' => __DIR__ . '/templates',
    ],
    'security' => [
        'max_login_attempts' => 5,
        'lockout_minutes'    => 15,
    ],
];
```

Credentials are set once at setup via a small CLI script or by pasting a `password_hash()` output into the file directly.

---

## Core Classes

### `Database`
Thin PDO/SQLite wrapper. Handles:
- Connection and schema creation on first run
- Prepared statement helpers (`select`, `insert`, `update`, `delete`)
- Migration runner (version the schema with a `schema_version` key in `settings`)

### `Auth`
- `login(username, password)` — checks credentials, enforces rate limit, issues session
- `logout()`
- `check()` — redirects to login if session is invalid
- `csrfToken()` / `verifyCsrf(token)` — per-session CSRF tokens

### `Post` / `Page`
Active-record-style models:
- `findAll(status)`, `findBySlug(slug)`, `findById(id)`
- `save()`, `delete()`
- `needsRebuild()` — compares current `content_hash` to what would be rendered

### `Builder`
The rebuild engine. Core method: `build(scope)` where scope is one of:
- `post($id)` — render one post
- `page($id)` — render one static page
- `index()` — render all paginated index pages
- `feed()` — render RSS/Atom
- `all()` — full site rebuild

Internally: render template → hash output → compare to stored `content_hash` → write file only if changed → update `built_at` and `content_hash`.

### `Media`
- `upload(file)` — validates type/size, stores file, inserts DB record
- `delete(id)` — removes file and DB record
- `all()` — list media for the library UI
- Allowed MIME types: `image/jpeg`, `image/png`, `image/gif`, `image/webp`, `image/svg+xml`, `video/mp4`, `video/webm`, `audio/mpeg`, `audio/ogg`
- Max upload size configurable; default 50 MB

### `Feed`
Renders `feed.xml` (Atom 1.0) from the N most recently published posts.

### `Helpers`
- `slugify(title)` — URL-safe slug generation
- `truncate(html, length)` — post excerpt fallback
- `formatDate(datetime, format)` — date formatting for templates

---

## Smart Rebuild Logic

When a post or page is **published or updated**:

```
1. Render the item's HTML from current Markdown + template
2. Hash the rendered HTML
3. Compare to stored content_hash
4. If different:
     a. Write file to disk
     b. Update content_hash and built_at in DB
5. If post listing is affected (new post, post unpublished, slug changed):
     a. Rebuild all paginated index pages
     b. Rebuild feed.xml
   Else if only content changed:
     a. Rebuild feed.xml (excerpt or title may appear there)
     b. Skip index pages (order and count unchanged)
```

**Scheduled posts:** On every admin page load, a lightweight check queries:
```sql
SELECT id FROM posts
WHERE status = 'scheduled' AND published_at <= CURRENT_TIMESTAMP
```
Any due posts are flipped to `published` and their rebuild is triggered automatically.

**Manual rebuild:** The admin dashboard has a "Rebuild entire site" button that calls `Builder::build('all')`. Useful after theme changes.

---

## URL Structure & `.htaccess`

Generated output uses directory-based pretty URLs. Apache's `DirectoryIndex index.html` serves them automatically — no rewrites needed for static pages.

The `.htaccess` at the project root handles two concerns:

```apacheconf
# 1. Block sensitive directories from direct web access
RedirectMatch 403 ^/(src|data|content|templates|vendor|config\.php)(/|$)

# 2. Route /admin/* requests through PHP
#    (Apache serves .php files in admin/ normally; no special rewrite needed
#     unless the host strips PHP extensions — handled below)

# 3. Allow /media/* to serve from content/media/ via Alias (set in vhost or .htaccess)
#    If Alias isn't available, use a RewriteRule:
RewriteEngine On
RewriteRule ^media/(.*)$ content/media/$1 [L]

# 4. Canonical trailing slash for generated dirs
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME}/index.html -f
RewriteRule ^(.+[^/])$ /$1/ [R=301,L]
```

---

## Admin UI — Screens

### Login (`/admin/`)
- Username + password form
- Shows lockout message after N failed attempts
- CSRF token on form

### Dashboard (`/admin/dashboard.php`)
- Site stats: post count (published / draft / scheduled), page count, media count
- "Rebuild site" button
- List of scheduled posts due soon
- Link to each section

### Posts list (`/admin/posts.php`)
- Table: title, status badge, published date, actions (Edit / Delete / Preview)
- "New post" button
- Status filter tabs (All / Published / Draft / Scheduled)

### Post editor (`/admin/post-edit.php`)
- Fields: Title, Slug (auto-generated, editable), Excerpt (optional)
- EasyMDE Markdown editor (full width)
- Media insert helper: click a thumbnail in a sidebar panel to insert `![alt](url)` or `<video>` / `<audio>` at the cursor
- Publish controls:
  - **Save draft** — saves without triggering a build
  - **Publish now** — sets status=published, published_at=now, triggers build
  - **Schedule** — date/time picker, sets status=scheduled
  - **Unpublish** — reverts to draft, triggers index/feed rebuild
- Delete button (with confirm dialog)

### Pages list / Page editor
- Same pattern as posts but without publish scheduling
- Nav order field to control header link order

### Media library (`/admin/media.php`)
- Drag-and-drop upload zone + fallback file input
- Grid of thumbnails (images) or file icons (video/audio)
- Each item: filename, size, copy-URL button, delete button
- Accepted types enforced both client-side (accept attribute) and server-side

### Settings (`/admin/settings.php`)
- Site title, site description, site URL, footer text
- Posts per page (default: 10)
- Number of posts in RSS feed (default: 20)
- Save triggers a full index + feed rebuild

---

## Theme — Minimalistic One Column

**Goals:** readable typography, zero JS, fast load, works without web fonts.

```
┌────────────────────────────────────┐
│  Site Title          [Nav links]   │  ← header, max-width ~900px, centred
├────────────────────────────────────┤
│                                    │
│  Post Title                        │  ← article, max-width ~680px, centred
│  27 Feb 2026                       │
│                                    │
│  Body text body text body text...  │
│  body text body text body text...  │
│                                    │
│  [media embed]                     │
│                                    │
│  More body text...                 │
│                                    │
├────────────────────────────────────┤
│  © Site Name · RSS                 │  ← footer
└────────────────────────────────────┘
```

**Typography:**
- Font: system-ui, -apple-system, sans-serif
- Body: 18px / 1.7 line-height
- Max content width: 680px
- Colour scheme: near-black on white (`#1a1a1a` / `#ffffff`) with a light grey for meta
- Code blocks: monospace, subtle background, no JS syntax highlighting (CSS only via `<code>` class)

**Index page:** Reverse-chronological list of post titles + dates + optional excerpt. Pagination links at bottom.

**Templates produce valid HTML5** with proper `<meta charset>`, `<meta name="description">`, Open Graph tags (`og:title`, `og:description`, `og:url`), and a `<link rel="alternate" type="application/atom+xml">` pointing to `feed.xml`.

---

## Security Checklist

| Area | Measure |
|---|---|
| Authentication | bcrypt password hash in config; session-based auth |
| Session | Regenerate session ID on login; `HttpOnly` + `SameSite=Strict` cookie |
| CSRF | Token in every admin form; verified on every POST |
| Brute-force | IP-based lockout after N failures (stored in SQLite) |
| File access | `.htaccess` 403 on `src/`, `data/`, `content/`, `templates/`, `vendor/`, `config.php` |
| File upload | MIME type whitelist server-side; no executable extensions allowed; files stored outside web root in `content/media/` |
| SQL injection | PDO prepared statements throughout |
| XSS | All admin output passed through `htmlspecialchars()`; Markdown rendered to HTML then sanitised before output (league/commonmark's default escaping) |
| Path traversal | Media filenames sanitised with `basename()` before any filesystem operation |
| Error display | `display_errors = Off` in production; errors logged to file |

---

## Development Phases

### Phase 1 — Foundation
- [ ] Composer setup, directory scaffold, `config.php`
- [ ] `Database` class + schema creation + migration runner
- [ ] `Auth` class (login, session check, CSRF helpers)
- [ ] Admin login page + session guard middleware
- [ ] `.htaccess` security rules

### Phase 2 — Content Management
- [ ] `Post` and `Page` models
- [ ] `Helpers::slugify()`, `Helpers::formatDate()`
- [ ] Admin: posts list, post editor (save draft, delete)
- [ ] Admin: pages list, page editor
- [ ] EasyMDE integration in editor

### Phase 3 — Media
- [ ] `Media` class (upload, validate, delete, list)
- [ ] Admin: media library UI
- [ ] Media insert helper in post/page editor sidebar
- [ ] `content/media/` → public `media/` routing in `.htaccess`

### Phase 4 — Static Build System
- [ ] `Builder` class: render post, page, index, feed
- [ ] PHP templates: `base.php`, `post.php`, `page.php`, `index.php`, `feed.php`
- [ ] Content-hash diffing (skip unchanged files)
- [ ] Pagination logic in index build
- [ ] Build triggered on publish/unpublish/settings save
- [ ] `Feed` class (Atom 1.0 XML)

### Phase 5 — Publish Controls & Scheduling
- [ ] Publish now, save draft, schedule (date picker), unpublish
- [ ] Scheduled post check on admin page load
- [ ] Status badges and filter tabs on posts list

### Phase 6 — Theme
- [ ] `base.php` layout with header, nav, footer
- [ ] Single post template with Open Graph meta
- [ ] Static page template
- [ ] Index/listing template with pagination
- [ ] Responsive CSS (single column, system fonts)

### Phase 7 — Admin Polish & Settings
- [ ] Settings screen + DB-backed site config
- [ ] Dashboard with stats + "Rebuild site" button
- [ ] Login rate limiting using `login_attempts` table
- [ ] Error handling and user-facing flash messages
- [ ] Setup script to hash initial admin password

### Phase 8 — Hardening & Deployment
- [ ] Audit `.htaccess` on a real Apache host
- [ ] Confirm `content/media/` → `media/` routing works (Alias vs RewriteRule fallback)
- [ ] Test with PHP `display_errors = Off`
- [ ] Write a brief `INSTALL.md` (upload files, run `composer install`, set password hash, visit `/admin/`)

---

## Dependencies (`composer.json`)

```json
{
    "require": {
        "php": ">=8.1",
        "league/commonmark": "^2.4"
    }
}
```

EasyMDE is included as vendored static assets in `admin/assets/` (no npm build step required).

---

## Decisions Deferred / Out of Scope (v1)

- Multi-user accounts and roles
- Categories, tags, or any taxonomy
- Comments
- Search
- Image resizing / thumbnail generation (uploads served as-is)
- CDN integration
- Two-factor authentication
- Git-based content versioning
