# PHP Mini CMS

A lightweight flat-file CMS with a PHP/SQLite admin panel and a fully static HTML output layer. Write posts and pages in Markdown, publish them, and the CMS generates clean static HTML that Nginx serves directly — no PHP in the request path for public visitors.

---

## Features

- **Static output** — generates plain HTML files; public pages need no PHP at serve time
- **Markdown editor** — EasyMDE with GitHub-flavored Markdown, footnotes, and server-side syntax highlighting (xcode-dark palette)
- **Posts & pages** — separate content types; pages can appear in site navigation
- **Scheduling** — set a future publish date; posts promote automatically on next admin load
- **Media library** — drag-and-drop uploads with MIME validation; images, video, and audio supported (50 MB limit)
- **Atom feed** — generated automatically at `/feed.xml`
- **OG images** — auto-generated 1200×630 PNG per post (requires GD + FreeType)
- **Mastodon integration** — optional auto-toot on first publish
- **Dark / light mode** — system-preference aware with manual toggle; no flash on load
- **Single admin user** — bcrypt password, CSRF protection, IP-based rate limiting
- **Docker-ready** — one command to run locally

---

## Requirements

| Component | Version |
|-----------|---------|
| PHP | 8.1+ (8.3 recommended) |
| Extensions | `pdo_sqlite`, `mbstring`, `gd` (with FreeType for OG images) |
| Nginx | 1.18+ |
| Composer | 2.x |
| SQLite | 3.x (bundled with PHP) |

---

## Quick Start (Docker)

```bash
git clone https://github.com/yourname/php-mini-cms.git
cd php-mini-cms

# Start PHP-FPM + Nginx
docker compose up --build

# In a second terminal: create your admin password and initialize the DB
docker compose exec php php bin/setup.php
```

Visit **http://localhost:8080/admin/** and log in.

The setup script prompts for a username and password, writes both to `config.php`, and seeds the SQLite database. Generated HTML, uploaded media, and the database are written to the project directory (not inside the container), so they persist across restarts.

---

## Production Deployment

See [INSTALL.md](INSTALL.md) for the full VPS guide (Ubuntu 22.04, Nginx, PHP-FPM, Let's Encrypt, UFW, and daily SQLite backups). The short version:

```bash
# Clone and install dependencies
git clone https://github.com/yourname/php-mini-cms.git /var/www/cms
cd /var/www/cms
composer install --no-dev --optimize-autoloader

# Initialize (prompts for password, seeds DB)
php bin/setup.php

# Permissions
chown -R deploy:www-data .
chmod -R 775 data/ content/media/

# Nginx
cp nginx.conf.example /etc/nginx/sites-available/cms
# Edit domain and PHP-FPM socket path, then:
ln -s /etc/nginx/sites-available/cms /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx

# TLS (optional but recommended)
certbot --nginx -d example.com
```

---

## Configuration

`config.php` lives at the project root and is blocked by Nginx. It is **not** committed to version control. It is created (or updated) by `bin/setup.php`.

```php
return [
    'admin' => [
        'username'         => 'admin',
        'password_hash'    => '$2y$10$...',  // set by bin/setup.php
        'session_name'     => 'cms_session',
        'session_lifetime' => 3600,
    ],
    'paths' => [
        'data'      => __DIR__ . '/data',
        'content'   => __DIR__ . '/content',
        'output'    => __DIR__,              // web root
        'templates' => __DIR__ . '/templates',
    ],
    'security' => [
        'max_login_attempts' => 5,
        'lockout_minutes'    => 15,
    ],
];
```

Runtime settings (site title, description, URL, footer text, pagination, Mastodon credentials, etc.) are stored in the SQLite `settings` table and edited through **Admin → Settings**.

---

## Admin Panel

| Page | Path | Description |
|------|------|-------------|
| Login | `/admin/` | Single-user login with rate limiting |
| Dashboard | `/admin/dashboard.php` | Stats, scheduled posts due soon, full site rebuild |
| Posts | `/admin/posts.php` | List with status filter tabs, inline delete |
| Post editor | `/admin/post-edit.php` | Title, slug, Markdown editor, status, schedule date |
| Pages | `/admin/pages.php` | List with inline delete |
| Page editor | `/admin/page-edit.php` | Same as post editor + nav order field |
| Media | `/admin/media.php` | Upload (drag-and-drop), library, copy URL to clipboard |
| Settings | `/admin/settings.php` | Site identity, content options, Mastodon credentials |

### Security

- CSRF token on every form POST
- Passwords hashed with bcrypt (`PASSWORD_BCRYPT`)
- IP-based login rate limiting: 5 attempts → 15-minute lockout
- Sessions: `HttpOnly`, `Secure`, `SameSite=Strict`
- Nginx blocks direct access to `src/`, `templates/`, `data/`, `content/`, `vendor/`, and `config.php`
- Separate Content-Security-Policy headers for admin (allows `unsafe-inline` for EasyMDE) and public pages (strict)

---

## Content Management

### Posts

Posts have a **status** of `draft`, `published`, or `scheduled`. Saving a post as `published` immediately triggers a static build for that post. Scheduling sets a future `published_at` date; scheduled posts are promoted to `published` automatically the next time any admin page loads.

Each published post generates:
- `posts/{slug}/index.html` — the post page
- `posts/{slug}/og.png` — Open Graph image (if GD is available)

The paginated index (`index.html`, `page/2/index.html`, …) and `feed.xml` are rebuilt on publish and when settings change.

### Pages

Pages work the same as posts but without scheduling. The **nav order** field controls whether a page appears in the site header navigation and in what order (`0` = hidden).

Published pages are output to `pages/{slug}/index.html` and served at `/{slug}/` via an Nginx named location fallback.

### Media

Files are uploaded to `content/media/` and served through a Nginx alias at `/media/`. Filenames are sanitized to `{stem}_{8hex}.{canonical_ext}`. Accepted MIME types: JPEG, PNG, GIF, WebP, SVG, MP4, WebM, MP3, OGG. Maximum size: 50 MB.

---

## Markdown

The Markdown renderer uses [league/commonmark](https://commonmark.thephpleague.com/) with:

- CommonMark + GitHub-flavored Markdown (tables, strikethrough, task lists)
- Footnotes
- Server-side syntax highlighting via [scrivo/highlight.php](https://github.com/scrivo/highlight.php) using the **xcode-dark** color palette
- Raw HTML pass-through (trusted admin only — allows embedding `<video>` and `<audio>`)

Fenced code blocks with a language tag are highlighted automatically:

````markdown
```php
echo "Hello, world!";
```
````

Code blocks without a language tag receive auto-detection and fall back to plain output if detection fails.

---

## Atom Feed

The feed is generated at `/feed.xml` and includes the most recent N posts (configurable in Settings, default 20). It is rebuilt whenever a post is published/unpublished or settings are saved.

---

## Open Graph Images

When PHP's GD extension is compiled with FreeType support, the CMS generates a 1200×630 PNG for each published post. The image includes the post title and site name rendered in Inter. Images are cached by a hash of the title + site name; they regenerate only when either changes.

The font files at `fonts/` must be present. The Docker image includes FreeType.

---

## Mastodon Integration

Set your handle (`@user@instance.social`), instance URL, and an API access token in **Settings → Mastodon**. The token only needs the `write:statuses` scope. When both fields are saved, new posts are automatically tooted on first publish. Individual posts have a **Skip Mastodon** checkbox to suppress tooting.

The handle also adds a `fediverse:creator` meta tag to every page and renders a Mastodon icon link in the footer.

---

## Static Output Structure

```
/                       → index.html          (page 1 of post index)
/page/2/                → page/2/index.html   (paginated index)
/posts/{slug}/          → posts/{slug}/index.html
/pages/{slug}/          → pages/{slug}/index.html  (via Nginx @page fallback)
/feed.xml               → Atom 1.0 feed
/media/{filename}       → content/media/ alias
/theme.css              → public stylesheet
/fonts/                 → Inter web font files
```

Stale pagination pages and unpublished post/page files are removed automatically on rebuild.

---

## Theme & Styling

The public theme is a single file, `theme.css`, with no build step. It uses CSS custom properties for all colors:

| Variable | Light | Dark |
|----------|-------|------|
| `--color-text` | `#1a1a1a` | `#e5e7eb` |
| `--color-muted` | `#6b7280` | `#9ca3af` |
| `--color-border` | `#e5e7eb` | `#374151` |
| `--color-bg` | `#ffffff` | `#181818` |
| `--color-code-bg` | `#f3f4f6` | `#2a2a2a` |
| `--color-link` | `#2563eb` | `#60a5fa` |

Dark mode activates automatically when the system preference is `dark`. The toggle button in the header overrides this and persists the choice in `localStorage`. An inline script in `<head>` applies the stored preference before the stylesheet loads, preventing any flash of the wrong color scheme.

The body typeface is [Inter](https://rsms.me/inter/) (self-hosted WOFF2, OFL license).

---

## Project Structure

```
php-mini-cms/
├── admin/                  # Admin panel PHP pages
│   ├── assets/             # Admin CSS, JS, EasyMDE, Font Awesome
│   └── partials/           # Shared nav partial
├── bin/
│   └── setup.php           # CLI installer (password hash + DB init)
├── content/
│   └── media/              # Uploaded files (not committed)
├── data/                   # SQLite database (not committed)
├── docker/                 # Docker-specific Nginx config, PHP ini, entrypoint
├── fonts/                  # Inter WOFF2 files + OG image font
├── src/                    # PHP source classes (namespace CMS\)
│   ├── Auth.php
│   ├── Builder.php
│   ├── Database.php
│   ├── Feed.php
│   ├── Helpers.php
│   ├── HighlightFencedCodeRenderer.php
│   ├── Mastodon.php
│   ├── Media.php
│   ├── OgImage.php
│   ├── Page.php
│   └── Post.php
├── templates/              # Public HTML templates
│   ├── base.php
│   ├── index.php
│   ├── page.php
│   └── post.php
├── config.php              # Credentials + paths (not committed)
├── composer.json
├── docker-compose.yml
├── Dockerfile
├── nginx.conf.example      # Production Nginx template
├── theme.css               # Public stylesheet
└── INSTALL.md              # Full VPS deployment guide
```

---

## License

MIT
