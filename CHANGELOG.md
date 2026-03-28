# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.2.1] — 2026-03-28

### Added

- **Built-in analytics beacon** — `track.php` at the web root accepts `navigator.sendBeacon` POST requests with `{url, referrer, is404}` JSON; no cookies or third-party services used
- **`page_views` database table** (schema v13) — stores url, referrer, device_type, is_404, ip_hash, and timestamp; auto-migrates on boot
- **IP privacy** — client IP addresses are stored as HMAC-SHA256 hashes using a server-side salt; raw IPs are never persisted
- **Rate limiting** — PHP-level limit of 30 requests/minute per IP in `track.php`; Nginx `limit_req_zone` at 2 r/s with burst 20 in `location = /track.php`
- **Analytics dashboard** (`admin/analytics.php`) — Chart.js graphs for views/day, top pages, device breakdown, and referrers; 404 error table; 7/30/90-day range selector; owner opt-out URL shown
- **Chart.js 4.4.7** vendored locally at `admin/assets/chart.min.js` — no external CDN dependency
- **Beacon JS in public templates** — `templates/base.php` now includes a small inline script that fires `sendBeacon` on page load; visit `/?ti=exclude` to set a localStorage opt-out flag, `/?ti=include` to re-enable
- **404 tracking** — `templates/404.php` sets an `analyticsIs404` flag so 404 hits are recorded separately in the dashboard
- **Automatic data pruning** — `admin/bootstrap.php` deletes `page_views` rows older than 90 days on ~1% of admin requests
- **Nginx config for beacon** — `docker/nginx.conf` and `nginx.conf.example` both include a `location = /track.php` block with `limit_req`, `limit_except POST`, `client_max_body_size 4k`, and security headers

---

## [1.1.1] — 2025-xx-xx

### Changed

- Updated `league/commonmark` to v2.8.2

---

## [1.1.0] — 2025-xx-xx

### Added

- **Passkey (WebAuthn) authentication** — admin login now supports passkeys as an alternative to password + TOTP; manage passkeys from Admin → Account

---

## [1.0.0] — Initial release

### Added

- Static-output CMS with PHP/SQLite admin panel
- Markdown editor (EasyMDE) with GitHub-flavored Markdown, footnotes, and server-side syntax highlighting
- Posts and pages with draft, published, and scheduled statuses
- Date-based post URLs (`/YYYY/MM/DD/{slug}/`)
- Categories and tags taxonomy with archive pages
- Media library with drag-and-drop uploads
- Image galleries with masonry layout and lightbox
- Atom feed and JSON Feed 1.1
- Open Graph image generation (GD + FreeType)
- JSON-LD structured data (BlogPosting schema.org)
- Mastodon and Bluesky auto-syndication
- Incoming webmentions via webmention.io (client-side display)
- Outgoing webmentions CLI script (`bin/send-webmentions.php`)
- WordPress-compatible XML-RPC API (MarsEdit support)
- REST API with HTTP Basic Auth
- Client-side full-text search
- TOTP two-factor authentication
- Activity log and login attempt history
- Google Analytics GA4 integration (optional)
- Tinylytics integration (optional)
- Dark/light mode with system-preference detection
- Custom CSS via Settings panel
- Collapsible admin sidebar
- Docker local development setup
- Production Nginx configuration example with CSP headers and TLS
