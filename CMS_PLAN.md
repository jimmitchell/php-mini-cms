# PHP Flat-File CMS — Development Plan

## Overview

A lightweight, static-output CMS written in PHP, inspired by Kirby. Content is authored in Markdown through a secure admin panel and published as pre-rendered HTML files on disk. The server never runs PHP at page-browse time — only during admin operations and builds.

**Key characteristics:**

- PHP admin panel + build engine; pure HTML output for visitors
- SQLite for content metadata; Markdown source stored in the database
- Smart incremental rebuilds — only changed content re-renders
- Deployed on a Linux VPS (Digital Ocean / Hetzner); Nginx + PHP-FPM
- Single admin user (credentials in config file)
- Posts and static pages, both authored in Markdown
- Media uploads: images, video, audio
- Minimalistic one-column theme
- RSS/Atom feed, paginated post index, draft/scheduled posts

---

## Technology Choices

| Concern | Choice | Rationale |
|---|---|---|
| Language | PHP 8.3 | Current stable; ships in Ubuntu 24.04 PPA |
| Web server | Nginx 1.24+ | Efficient static file serving; PHP-FPM for admin |
| PHP process | PHP-FPM 8.3 | Standard Nginx/PHP integration |
| Database | SQLite 3 (via PDO) | Zero-config, file-based, no server needed |
| TLS | Let's Encrypt + Certbot | Free, auto-renewing certificates |
| Markdown | `league/commonmark` | CommonMark compliant, actively maintained |
| Admin editor | EasyMDE (SimpleMDE fork) | Browser-based Markdown editor, no build step |
| Dependency management | Composer | Standard for PHP |
| Templating | Plain PHP templates | No extra engine, easy to customise |
| CSS (admin) | Vanilla CSS | No framework, minimal footprint |
| CSS (theme) | Vanilla CSS | Output pages need zero JavaScript |

---

## Directory Structure

```
/var/www/cms/               ← document root on VPS (owned by deploy user, readable by www-data)
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
├── content/                ← BLOCKED: Nginx denies all access
│   └── media/              ← Uploaded files (images, video, audio)
│
├── data/                   ← BLOCKED: Nginx denies all access
│   └── cms.db              ← SQLite database
│
├── src/                    ← BLOCKED: Nginx denies all access
│   ├── Auth.php
│   ├── Builder.php
│   ├── Database.php
│   ├── Media.php
│   ├── Post.php
│   ├── Page.php
│   ├── Feed.php
│   └── Helpers.php
│
├── templates/              ← BLOCKED: Nginx denies all access
│   ├── base.php            ← Shared HTML shell
│   ├── post.php            ← Single post
│   ├── page.php            ← Static page
│   ├── index.php           ← Post listing / pagination
│   └── feed.php            ← RSS/Atom XML
│
├── vendor/                 ← BLOCKED: Nginx denies all access
│
├── media/                  ← PUBLIC — served via Nginx alias to content/media/
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
├── config.php              ← BLOCKED: Nginx denies access; site config + admin credentials
├── composer.json
└── composer.lock
```

> **Note on `media/`:** Uploaded files live in `content/media/` (blocked from web). Nginx's `alias` directive maps `/media/` requests directly to `content/media/` inside the server block — no symlinks or rewrites needed. This is cleaner and more explicit than an Apache Alias or RewriteRule fallback.

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

## URL Structure & Nginx Configuration

Generated output uses directory-based pretty URLs (e.g. `/posts/my-slug/`). Nginx's `index index.html` directive serves these automatically — no rewrites needed for static content.

All routing, security blocks, PHP-FPM proxying, and media aliasing live in the Nginx server block. There is no `.htaccess` file.

### `/etc/nginx/sites-available/cms`

```nginx
server {
    listen 80;
    server_name example.com www.example.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name example.com www.example.com;

    root /var/www/cms;
    index index.html;

    # TLS — managed by Certbot
    ssl_certificate     /etc/letsencrypt/live/example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/example.com/privkey.pem;
    include             /etc/letsencrypt/options-ssl-nginx.conf;
    ssl_dhparam         /etc/letsencrypt/ssl-dhparams.pem;

    # Security headers
    add_header X-Frame-Options           "SAMEORIGIN"   always;
    add_header X-Content-Type-Options    "nosniff"      always;
    add_header Referrer-Policy           "strict-origin-when-cross-origin" always;
    add_header Permissions-Policy        "geolocation=()" always;

    # ── Block sensitive directories ───────────────────────────────────────────
    location ~* ^/(src|data|content|templates|vendor)(/|$) {
        deny all;
        return 403;
    }
    location = /config.php {
        deny all;
        return 403;
    }

    # ── Media uploads (served from content/media/, not web root) ─────────────
    location /media/ {
        alias /var/www/cms/content/media/;
        expires 30d;
        add_header Cache-Control "public, immutable";
    }

    # ── Admin panel — PHP-FPM ─────────────────────────────────────────────────
    location /admin/ {
        try_files $uri $uri/ =404;
        location ~ \.php$ {
            include        fastcgi_params;
            fastcgi_pass   unix:/run/php/php8.3-fpm.sock;
            fastcgi_param  SCRIPT_FILENAME $document_root$fastcgi_script_name;
        }
    }

    # ── Static HTML output ────────────────────────────────────────────────────
    location / {
        try_files $uri $uri/ $uri/index.html =404;

        # Cache static output aggressively; admin rebuilds invalidate on write
        expires 1h;
        add_header Cache-Control "public";
    }

    # ── Feed ──────────────────────────────────────────────────────────────────
    location = /feed.xml {
        add_header Content-Type "application/atom+xml; charset=utf-8";
    }

    # Deny hidden files
    location ~ /\. {
        deny all;
    }
}
```

**Enable the site:**
```bash
ln -s /etc/nginx/sites-available/cms /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx
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
| File access | Nginx `deny all` location blocks on `src/`, `data/`, `content/`, `templates/`, `vendor/`, `config.php` |
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
- [ ] Nginx server block with security location blocks

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
- [ ] `content/media/` → public `media/` routing via Nginx `alias`

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
- [ ] Provision VPS (Ubuntu 24.04), install Nginx + PHP 8.3-FPM, Composer, Certbot
- [ ] Configure Nginx server block; test with `nginx -t`
- [ ] Obtain TLS certificate via Certbot (`certbot --nginx`)
- [ ] Set up UFW: allow 22 (SSH), 80, 443; deny everything else
- [ ] Configure fail2ban for SSH and Nginx auth failures
- [ ] Set filesystem permissions: `www-data` write access to `data/`, `content/media/`, and output dirs
- [ ] Confirm PHP `display_errors = Off` in `/etc/php/8.3/fpm/php.ini`
- [ ] Set up automated SQLite backups (daily `data/cms.db` copy to offsite or DO Spaces)
- [ ] Write a brief `INSTALL.md` (clone repo, `composer install`, set password hash, configure Nginx, visit `/admin/`)

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

## VPS Server Requirements & Setup

### Minimum Server Spec

| Resource | Minimum | Recommended |
|---|---|---|
| CPU | 1 vCPU | 1 vCPU |
| RAM | 512 MB | 1 GB |
| Disk | 10 GB SSD | 25 GB SSD |
| OS | Ubuntu 22.04 LTS | **Ubuntu 24.04 LTS** |

Both Digital Ocean's cheapest droplet ($6/mo, 1 vCPU / 1 GB RAM) and Hetzner's CX22 (€4/mo, 2 vCPU / 4 GB RAM) comfortably exceed the minimum. Hetzner is better value if you're in Europe.

---

### Server Software Stack

```
Ubuntu 24.04 LTS
├── Nginx 1.24+           (apt: nginx)
├── PHP 8.3-FPM           (apt: php8.3-fpm php8.3-cli php8.3-sqlite3
│                               php8.3-mbstring php8.3-fileinfo php8.3-curl)
├── Composer 2.x          (install via getcomposer.org installer)
├── Certbot               (snap: certbot --nginx plugin)
├── UFW                   (pre-installed; configure firewall rules)
└── fail2ban              (apt: fail2ban)
```

**PHP extensions required:**

| Extension | Package | Purpose |
|---|---|---|
| `pdo_sqlite` | `php8.3-sqlite3` | SQLite database |
| `fileinfo` | `php8.3-fileinfo` | Upload MIME validation |
| `mbstring` | `php8.3-mbstring` | Required by league/commonmark |
| `session` | built-in | Admin sessions |
| `hash` | built-in | Content-hash diffing |
| `json` | built-in | Config/settings |
| `openssl` | built-in | Session security |

---

### Key `php.ini` / PHP-FPM Pool Settings

Edit `/etc/php/8.3/fpm/php.ini` and the pool file at `/etc/php/8.3/fpm/pool.d/www.conf`:

```ini
; /etc/php/8.3/fpm/php.ini
upload_max_filesize = 50M
post_max_size       = 55M
max_execution_time  = 60
memory_limit        = 128M
display_errors      = Off
log_errors          = On
error_log           = /var/log/php8.3-fpm.log
```

```ini
; /etc/php/8.3/fpm/pool.d/www.conf  (relevant lines)
user  = www-data
group = www-data
listen = /run/php/php8.3-fpm.sock
```

---

### Filesystem Permissions

```bash
# Application files owned by a deploy user (not www-data)
chown -R deploy:www-data /var/www/cms

# Directories the web server must write to
chmod 775 /var/www/cms/data
chmod 775 /var/www/cms/content/media

# Output directories (Nginx/PHP-FPM writes generated HTML here)
chmod 775 /var/www/cms
find /var/www/cms/posts /var/www/cms/page -type d -exec chmod 775 {} \;

# Everything else: readable by www-data, not writable
find /var/www/cms/src /var/www/cms/templates /var/www/cms/vendor -type f -exec chmod 644 {} \;
```

---

### Firewall (UFW)

```bash
ufw default deny incoming
ufw default allow outgoing
ufw allow 22/tcp      # SSH
ufw allow 80/tcp      # HTTP (redirects to HTTPS)
ufw allow 443/tcp     # HTTPS
ufw enable
```

---

### TLS Certificate (Let's Encrypt)

```bash
snap install --classic certbot
ln -s /snap/bin/certbot /usr/bin/certbot
certbot --nginx -d example.com -d www.example.com
# Certbot auto-installs cert paths into the Nginx config and sets up auto-renewal
```

Auto-renewal is handled by a systemd timer installed by Certbot — no cron entry needed.

---

### fail2ban

Two jails to configure in `/etc/fail2ban/jail.local`:

```ini
[sshd]
enabled  = true
maxretry = 5
bantime  = 1h

[nginx-http-auth]
enabled  = true
```

The admin login rate-limiting (stored in SQLite) operates at the application layer as a complementary measure.

---

### Automated SQLite Backup

SQLite databases are a single file. A simple daily backup to a remote location:

```bash
# /etc/cron.daily/cms-backup
#!/bin/bash
DATE=$(date +%Y%m%d)
sqlite3 /var/www/cms/data/cms.db ".backup '/var/backups/cms/cms-$DATE.db'"
find /var/backups/cms -name "*.db" -mtime +30 -delete
```

For off-site backup, pipe to `rclone` (DO Spaces / Hetzner Object Storage) or use the VPS provider's automated snapshot feature.

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
