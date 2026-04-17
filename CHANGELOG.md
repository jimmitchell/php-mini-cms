# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.2.18] — 2026-04-17

### Changed

- **Typography — Inter** — replaced Figtree (sans-serif) and Crimson Pro (serif) with [Inter](https://rsms.me/inter/) as the sole typeface; Inter is self-hosted as a variable font (`Inter-Variable.woff2` / `Inter-Variable-Italic.woff2`, OFL license) covering weight 100–900; prose content switches from serif to sans-serif at `1.1rem`
- **OG image font** — `src/OgImage.php` updated to use `Inter-Regular.ttf` / `Inter-Bold.ttf` for server-side PNG generation; existing OG images regenerate automatically on next build

---

## [1.2.17] — 2026-04-16

### Security

- **Custom CSS XSS fix** — `</style>` escape in `templates/base.php` changed from case-sensitive `str_replace` to `str_ireplace`; previously a payload using uppercase `</STYLE>` bypassed the filter and could break out of the style block on every public page

---

## [1.2.16] — 2026-04-16

### Security

- **API CORS restricted** — `Access-Control-Allow-Origin: *` replaced with an origin-matched header derived from the configured `site_url`; falls back to `*` only when `site_url` is unset (initial setup); `Vary: Origin` added alongside; native app clients (iOS, Xcode simulator) are unaffected as they do not send `Origin` headers
- **CSP `img-src` broadened** — changed from `https://avatars.webmention.io` to `https:` across all Nginx configs so external images embedded in post content (Markdown or raw HTML) are not silently blocked by the policy
- **Nginx `/fonts/` location hardened** — added explicit CSP (`default-src 'none'; font-src 'self'`) and security headers to the `/fonts/` location in `docker/nginx.conf`, syncing it with `nginx.conf.example`

---

## [1.2.12] — 2026-04-14

### Fixed

- **Post date slug** — posts published in the late evening (in negative-UTC-offset timezones) no longer get a slug one day ahead; `datePath()` now converts the stored UTC timestamp to the configured site timezone before extracting the `YYYY/MM/DD` path segment

---

## [1.2.11] — 2026-04-12

### Fixed

- **Code copy button** — button now turns green immediately on click and stays green (with white checkmark) regardless of mouse position until the 2-second reset; previously the green state was only visible after mousing away from the button

---

## [1.2.10] — 2026-04-12

### Added

- **WordPress XML export** — new Admin → Export page downloads all posts (published, and optionally drafts/scheduled) as a WXR 1.2 file; includes categories, tags, and post content rendered to HTML; importable into any WordPress site via Tools → Import

---

## [1.2.4] — 2026-03-30

### Changed

- **Analytics** — 404 errors now sorted by most recent first; default date range changed from 30 to 7 days; "Top pages" and "Device types" panels stack vertically on mobile; "Last seen" column is right-aligned

---

## [1.2.3] — 2026-03-29

### Changed

- **Code simplification** — migration loop, settings helpers, query patterns, slug generation, syndication logic, and build calls consolidated for clarity and consistency
- **Standardised output escaping** — `dashboard.php` and `analytics.php` now use `Helpers::e()` consistently with the rest of the admin
- **page-edit delete handler** — moved before save block so delete action is reachable (was previously unreachable)

### Fixed

- **XSS** — `$post->status` and `$page->status` now escaped with `Helpers::e()` in badge output
- **Session cookie `secure` flag** — now also set when behind a TLS-terminating reverse proxy via `HTTP_X_FORWARDED_PROTO`
- **WebAuthn rpId** — derived from canonical `site_url` setting instead of attacker-controllable `HTTP_HOST` header
- **Migration seed SQL injection** — settings seed now uses a prepared statement instead of string interpolation
- **DNS rebinding (Mastodon SSRF)** — hostname resolved immediately before curl connects and pinned via `CURLOPT_RESOLVE`

---

## [1.2.2] — 2026-03-28

### Fixed

- **Analytics timezone** — daily chart grouping, chart axis labels, and 404 "last seen" timestamps now respect the timezone configured in Settings instead of always using UTC

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
