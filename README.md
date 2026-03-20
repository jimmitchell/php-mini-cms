# PHP Mini CMS

A lightweight flat-file CMS with a PHP/SQLite admin panel and a fully static HTML output layer. Write posts and pages in Markdown, publish them, and the CMS generates clean static HTML that Nginx serves directly — no PHP in the request path for public visitors.

---

## Features

- **Static output** — generates plain HTML files; public pages need no PHP at serve time
- **Markdown editor** — EasyMDE with GitHub-flavored Markdown, footnotes, and server-side syntax highlighting (xcode-dark palette)
- **Posts & pages** — separate content types; pages can appear in site navigation
- **Date-based post URLs** — posts live at `/YYYY/MM/DD/{slug}/` for clean, chronological permalinks
- **Scheduling** — set a future publish date; posts promote automatically on next admin load
- **Categories & tags** — full taxonomy system; posts can belong to multiple categories and tags; archive pages generated at `/category/{slug}/` and `/tag/{slug}/`
- **Media library** — drag-and-drop uploads with MIME validation; images, video, and audio supported (50 MB limit)
- **Image galleries** — select multiple images in the post editor and insert a `[gallery]` shortcode; renders as a responsive masonry grid (3 columns desktop, 1 column mobile) with a looping lightbox
- **Atom feed** — generated automatically at `/feed.xml`
- **JSON Feed** — generated automatically at `/feed.json` (JSON Feed 1.1); linked in `<head>` for feed reader discovery
- **OG images** — auto-generated 1200×630 PNG per post (requires GD + FreeType)
- **JSON-LD structured data** — `BlogPosting` schema.org markup in every post's `<head>` for richer search results; author name configurable in Settings
- **Reading time** — estimated minutes-to-read displayed inline with the post date
- **Microformats2 (h-entry)** — posts and index items carry MF2 classes for IndieWeb parsers and readers
- **Mastodon & Bluesky** — optional auto-post on first publish; the URL of the remote post is stored and displayed as an "Also on:" link at the bottom of each post; per-post skip checkbox for each platform
- **Incoming webmentions** — display likes, reposts, and replies on posts via webmention.io; client-side fetch with avatar grid for reactions and threaded reply cards
- **Outgoing webmentions** — CLI script (`bin/send-webmentions.php`) discovers endpoints and sends pings for all external links in published posts; safe to schedule via cron
- **MarsEdit support** — full WordPress XML-RPC API at `/admin/xmlrpc.php`; write and publish from MarsEdit with post and page management
- **Google Analytics** — optional GA4 integration; add a measurement ID in Settings to inject the tracking script
- **Custom CSS** — paste override styles directly in Settings; injected as a `<style>` block on every public page after the main stylesheet
- **Dark / light mode** — system-preference aware with manual toggle; no flash on load
- **Search** — client-side full-text search of posts at `/search/`; no server-side PHP required
- **Favicon** — SVG favicon matching the site theme color
- **Collapsible admin sidebar** — sidebar collapses to icon-only mode to maximize editor space; preference stored in localStorage
- **Activity log** — every content and settings change is recorded with action, object, and IP; viewable in Admin → Logs
- **Two-factor authentication** — optional TOTP 2FA (Google Authenticator, Authy, 1Password, etc.); setup via Admin → Account; backup codes generated on enable
- **Single admin user** — bcrypt password, CSRF protection, IP-based rate limiting; password and 2FA managed from within the admin panel
- **Docker-ready** — one command to run locally

---

## Requirements

| Component | Version |
|-----------|---------|
| PHP | 8.1+ (8.3 recommended) |
| Extensions | `pdo_sqlite`, `mbstring`, `simplexml`, `gd` (with FreeType for OG images) |
| Nginx | 1.18+ |
| Composer | 2.x |
| SQLite | 3.x (bundled with PHP) |

> **Note:** `simplexml` is in the `php8.3-xml` package on Ubuntu/Debian — it is not automatically installed with `php8.3-fpm`. See [INSTALL.md](INSTALL.md).

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

Runtime settings are stored in the SQLite `settings` table and edited through **Admin → Settings**. The Settings page is organized into panels:

| Panel | Settings |
|-------|----------|
| Site identity | Title, author name, description, URL, footer text, timezone, locale |
| Content | Posts per page, feed post count |
| Mastodon | Handle, instance URL, access token |
| Bluesky | Profile URL, handle, app password |
| IndieWeb | webmention.io domain |
| Analytics | Tinylytics site ID, Google Analytics measurement ID |
| Custom CSS | Freeform CSS injected into every public page |

---

## Admin Panel

| Page | Path | Description |
|------|------|-------------|
| Login | `/admin/` | Two-step login: password then TOTP code (if 2FA is enabled) |
| Dashboard | `/admin/dashboard.php` | Stats, scheduled posts due soon, full site rebuild |
| Posts | `/admin/posts.php` | List with status filter tabs, title search, inline delete |
| Post editor | `/admin/post-edit.php` | Title, slug, Markdown editor, status, schedule date, categories, tags, image gallery insert |
| Pages | `/admin/pages.php` | List with inline delete |
| Page editor | `/admin/page-edit.php` | Same as post editor + nav order field |
| Categories | `/admin/categories.php` | Create, edit, and delete post categories |
| Tags | `/admin/tags.php` | Create, edit, bulk-add, and delete post tags |
| Media | `/admin/media.php` | Upload (drag-and-drop), library, copy URL to clipboard |
| Settings | `/admin/settings.php` | Site identity, content options, social/analytics credentials, custom CSS |
| Account | `/admin/account.php` | Change admin password; set up, manage, or disable TOTP 2FA |
| Logs | `/admin/login-log.php` | Login attempt history and admin activity log |
| XML-RPC API | `/admin/xmlrpc.php` | WordPress-compatible API for MarsEdit and similar clients |

### Security

- CSRF token on every form POST
- Passwords hashed with bcrypt (`PASSWORD_BCRYPT`)
- IP-based login rate limiting: 5 attempts → 15-minute lockout (applies to TOTP verification and XML-RPC auth too)
- Sessions: `HttpOnly`, `Secure`, `SameSite=Strict`
- Optional TOTP two-factor authentication (RFC 6238); backup codes generated on setup; rate-limited independently from the password step
- Nginx blocks direct access to `src/`, `templates/`, `data/`, `content/`, `vendor/`, and `config.php`
- Separate Content-Security-Policy headers for admin (allows `unsafe-inline` for EasyMDE) and public pages (strict)

---

## Content Management

### Posts

Posts have a **status** of `draft`, `published`, or `scheduled`. Saving a post as `published` immediately triggers a static build for that post. Scheduling sets a future `published_at` date; scheduled posts are promoted to `published` automatically the next time any admin page loads.

Each published post generates:
- `posts/YYYY/MM/DD/{slug}/index.html` — the post page, served at `/YYYY/MM/DD/{slug}/`
- `posts/YYYY/MM/DD/{slug}/og.png` — Open Graph image (if GD is available)

The paginated index (`index.html`, `page/2/index.html`, …) and `feed.xml` are rebuilt on publish and when settings change.

### Pages

Pages work the same as posts but without scheduling. The **nav order** field controls whether a page appears in the site header navigation and in what order (`0` = hidden from nav and sorted to the bottom of the pages list).

Published pages are output to `pages/{slug}/index.html` and served at `/{slug}/` via an Nginx named location fallback.

### Categories & Tags

Posts can be assigned to any number of **categories** and **tags** from the post editor. Categories are a hierarchical taxonomy; tags are flat.

Manage them in **Admin → Categories** and **Admin → Tags** (the Tags page has a bulk-add textarea for quickly creating multiple tags at once). When a post is published, the CMS rebuilds archive pages for every category and tag the post belongs to:

- `/category/{slug}/` → `category/{slug}/index.html`
- `/tag/{slug}/` → `tag/{slug}/index.html`

Categories and tags are displayed as styled pills in the post header on public post pages.

### Media

Files are uploaded to `content/media/` and served through a Nginx alias at `/media/`. Filenames are sanitized to `{stem}_{8hex}.{canonical_ext}`. Accepted MIME types: JPEG, PNG, GIF, WebP, SVG, MP4, WebM, MP3, OGG. Maximum size: 50 MB.

### Shortcode Embeds

The post editor toolbar includes embed buttons (YouTube, Vimeo, GitHub Gist, Mastodon, Instagram, X/Twitter, LinkedIn) that insert the appropriate shortcode at the cursor. You can also type shortcodes directly. Shortcodes must appear alone on their own line.

| Shortcode | Description |
|-----------|-------------|
| `[youtube id="dQw4w9WgXcQ"]` | YouTube video (privacy-enhanced via youtube-nocookie.com) |
| `[vimeo id="123456789"]` | Vimeo video (privacy-friendly with `dnt=1`) |
| `[gist url="https://gist.github.com/user/abc123"]` | GitHub Gist (optional: `file="foo.php"`) |
| `[mastodon url="https://mastodon.social/@user/123456789"]` | Mastodon post |
| `[instagram url="https://www.instagram.com/p/ABC123/"]` | Instagram post |
| `[tweet url="https://x.com/user/status/123456789"]` | X / Twitter post (also accepts `twitter.com` URLs) |
| `[linkedin urn="urn:li:share:1234567890"]` | LinkedIn post — get the URN from LinkedIn's "Embed this post" option |

**Notes:**
- YouTube and Vimeo render as responsive 16:9 iframes with no cookies (YouTube nocookie, Vimeo dnt=1).
- GitHub Gist uses the static `.pibb` render — no external JavaScript is loaded into your page.
- Mastodon uses each instance's native `/embed` URL — no external JavaScript needed.
- Instagram and X/Twitter inject their respective embed scripts (`embed.js`, `widgets.js`). If a page has multiple embeds of the same type, the script is deduplicated automatically. Both degrade gracefully to a plain link if the script fails to load.
- LinkedIn embeds require the post's URN, which you can find in the `<iframe src>` shown by LinkedIn's "Embed this post" feature.
- Shortcodes work in both posts and pages.

### Image Galleries

You can insert a masonry image gallery into any post directly from the post editor:

1. Open a post in **Admin → Post editor**
2. In the **Insert media** sidebar panel, click **Select for gallery**
3. Click two or more images to select them (selected images show a blue outline)
4. Click **Insert gallery (N images)** — a `[gallery ids="…"]` shortcode is inserted at the cursor position
5. Save or publish the post; the gallery renders automatically

The shortcode format is:

```
[gallery ids="8,5,1,6"]
```

IDs correspond to media library records and are stored in the order you selected them, which controls the left-to-right display order.

**Display:**
- Desktop (> 600 px): 3-column masonry grid with equal 8 px gutters
- Mobile (≤ 600 px): single-column stacked layout

**Lightbox:** clicking any gallery image opens a full-screen lightbox. Prev/Next buttons and ← → arrow keys navigate through the gallery with looping (wraps from last image back to first and vice versa). Click the backdrop or press Escape to close.

The gallery JavaScript (masonry layout + lightbox) is injected inline only on post pages that contain a gallery shortcode — pages without a gallery load no extra script.

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

## Two-Factor Authentication

TOTP-based 2FA can be enabled per account from **Admin → Account**.

**Setup:**
1. Click **Set up two-factor authentication**
2. Scan the QR code with your authenticator app (Google Authenticator, Authy, 1Password, etc.) or enter the manual key
3. Enter the 6-digit verification code to confirm
4. Save the 8 one-time backup codes that are displayed — they will not be shown again

**Logging in with 2FA enabled:**
1. Enter your username and password as usual
2. Enter the 6-digit code from your authenticator app (or a backup code)

**Managing 2FA:**
- Regenerate backup codes at any time from the Account page (requires password confirmation)
- Disable 2FA from the Account page (requires password confirmation)

TOTP verification attempts are rate-limited separately from the password step using the same thresholds (5 failures → 15-minute lockout per IP).

---

## Search

The CMS generates a `/search.json` file alongside every index rebuild. The search page at `/search/` fetches this file client-side and filters posts by title and excerpt — no server-side PHP or external service required.

A magnifying-glass icon in the site header links to the search page. Results display as post cards with title, date, and excerpt.

---

## Atom Feed

The feed is generated at `/feed.xml` and includes the most recent N posts (configurable in Settings, default 20). It is rebuilt whenever a post is published/unpublished or settings are saved.

---

## Open Graph Images

When PHP's GD extension is compiled with FreeType support, the CMS generates a 1200×630 PNG for each published post. The image includes the post title and site name rendered in Figtree. Images are cached by a hash of the title + site name; they regenerate only when either changes.

The font files at `fonts/og/` must be present. The Docker image includes FreeType.

---

## Mastodon & Bluesky Integration

### Mastodon

Set your handle (`@user@instance.social`), instance URL, and an API access token in **Settings → Mastodon**. The token only needs the `write:statuses` scope. When both fields are saved, new posts are automatically tooted on first publish. Individual posts have a **Skip Mastodon** checkbox to suppress tooting.

The handle also adds a `fediverse:creator` meta tag to every page and renders a Mastodon icon link in the footer.

### Bluesky

Set your Bluesky handle and an app password in **Settings → Bluesky**. New posts are automatically cross-posted on first publish. Individual posts have a **Skip Bluesky** checkbox.

Both platforms are independent — you can enable one, both, or neither.

When a post is syndicated, the URL of the Mastodon toot or Bluesky post is stored and displayed at the bottom of the public post page as a small "Also on: Mastodon / Bluesky" footer.

---

## Webmentions

### Incoming (webmention.io)

The CMS supports [webmention.io](https://webmention.io/) for receiving and displaying incoming webmentions (IndieWeb interactions from other sites). To enable:

1. Sign in to webmention.io with your site URL
2. Enter your domain (e.g. `example.com`) in **Admin → Settings → IndieWeb**

The CMS will:
- Add `<link rel="webmention">` and `<link rel="pingback">` tags to every page `<head>` so other sites can send webmentions to you
- Fetch and render incoming webmentions client-side on each post page

Webmentions are grouped by type:
- **Likes and reposts** — displayed as a compact avatar grid with reaction counts
- **Replies and mentions** — displayed as individual reply cards with author, date, and content

### Outgoing

The CMS can send webmention pings to every external URL linked from your published posts. This runs as a CLI script (not in the web request) to avoid timeouts:

```bash
php bin/send-webmentions.php           # send for posts updated since last run
php bin/send-webmentions.php --force   # re-send for all published posts
```

Add to cron for daily sending:

```
0 2 * * * /usr/bin/php /var/www/cms/bin/send-webmentions.php >> /var/www/cms/storage/webmentions.log 2>&1
```

The script is idempotent — it skips posts whose content has not changed since webmentions were last sent.

---

## MarsEdit Integration

The CMS exposes a WordPress-compatible XML-RPC API at `/admin/xmlrpc.php`. In MarsEdit:

1. **Add Blog** → choose **WordPress**
2. **Endpoint URL:** `https://example.com/admin/xmlrpc.php`
3. **Username / Password:** your admin credentials

MarsEdit will show both a **Posts** and a **Pages** section. All post and page CRUD operations, media uploads, and the media library work from MarsEdit. The endpoint also supports the MetaWeblog API (for clients that prefer it) at the same URL.

---

## Custom CSS

Paste any CSS into **Settings → Custom CSS** and save. The styles are injected as a `<style>` block at the end of every public page's `<head>`, after `theme.css`, so they naturally take precedence. Leave the field empty to inject nothing.

This is intended for small overrides (fonts, colors, spacing). For larger changes, edit `theme.css` directly.

---

## Activity Log

**Admin → Logs** shows two tables:

- **Activity log** — the last 200 content and settings actions (create, update, publish, unpublish, schedule, delete, upload, settings save, password change, site rebuild, 2FA enable/disable/regen), with timestamp, action, detail, and IP address
- **Login attempts** — the last 200 login attempts with timestamp, IP, and success/failure badge; includes TOTP verification attempts (prefixed with `totp:`)

Log entries older than 90 days are pruned automatically on a ~1% probabilistic cleanup triggered on each admin page load.

---

## Static Output Structure

```
/                           → index.html              (page 1 of post index)
/page/2/                    → page/2/index.html        (paginated index)
/YYYY/MM/DD/{slug}/         → posts/YYYY/MM/DD/{slug}/index.html
/{slug}/                    → pages/{slug}/index.html  (via Nginx @page fallback)
/category/{slug}/           → category/{slug}/index.html
/tag/{slug}/                → tag/{slug}/index.html
/search/                    → search/index.html        (client-side search page)
/search.json                → search index (title, excerpt, date, URL for all published posts)
/feed.xml                   → Atom 1.0 feed
/feed.json                  → JSON Feed 1.1
/media/{filename}           → content/media/ alias
/theme.css                  → public stylesheet
/fonts/                     → Inter web font files
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

The UI typeface is [Figtree](https://fonts.google.com/specimen/Figtree) and the prose body typeface is [Crimson Pro](https://fonts.google.com/specimen/Crimson+Pro) (both self-hosted variable WOFF2, OFL license). To add custom styles without editing `theme.css`, use **Settings → Custom CSS**.

---

## Project Structure

```
php-mini-cms/
├── admin/                  # Admin panel PHP pages
│   ├── assets/             # Admin CSS, JS, EasyMDE, Font Awesome
│   ├── partials/           # Shared nav partial
│   └── xmlrpc.php          # WordPress/MetaWeblog XML-RPC API endpoint
├── bin/
│   ├── setup.php           # CLI installer (password hash + DB init)
│   └── send-webmentions.php  # CLI: send outgoing webmention pings
├── content/
│   └── media/              # Uploaded files (not committed)
├── data/                   # SQLite database (not committed)
├── docker/                 # Docker-specific Nginx config, PHP ini, entrypoint
├── fonts/                  # Figtree + Crimson Pro WOFF2 files + OG image fonts (fonts/og/)
├── src/                    # PHP source classes (namespace CMS\)
│   ├── Auth.php            # Login, session, CSRF, rate limiting, TOTP 2FA
│   ├── Bluesky.php
│   ├── Builder.php
│   ├── Database.php
│   ├── Feed.php
│   ├── Helpers.php
│   ├── HighlightFencedCodeRenderer.php
│   ├── JsonFeed.php
│   ├── Mastodon.php
│   ├── Media.php
│   ├── OgImage.php
│   ├── Page.php
│   ├── Post.php
│   ├── Webmention.php
│   └── XmlRpc.php
├── templates/              # Public HTML templates
│   ├── base.php
│   ├── index.php
│   ├── page.php
│   ├── post.php
│   ├── search.php
│   └── taxonomy.php        # Category and tag archive pages
├── storage/                # Runtime logs (not committed; create on server)
├── config.php              # Credentials + paths (not committed)
├── composer.json
├── docker-compose.yml
├── Dockerfile
├── favicon.svg             # SVG favicon (blue rounded square matching theme)
├── nginx.conf.example      # Production Nginx template
├── theme.css               # Public stylesheet
└── INSTALL.md              # Full VPS deployment guide
```

---

## License

MIT
