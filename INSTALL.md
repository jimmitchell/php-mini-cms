# Installation — PHP Mini CMS

## Requirements (VPS / production)

| Component | Minimum version |
|---|---|
| OS | Ubuntu 22.04 LTS (24.04 recommended) |
| PHP | 8.1 (8.3 recommended) |
| PHP extensions | `pdo_sqlite`, `fileinfo`, `mbstring`, `simplexml`, `gd` (with FreeType), `curl`, `intl` |
| Web server | Nginx 1.18+ |
| PHP process manager | PHP-FPM |
| Composer | 2.x |

---

## 1 — System packages and PHP extensions

```bash
apt-get install -y \
  nginx \
  php8.3-fpm \
  php8.3-cli \
  php8.3-sqlite3 \
  php8.3-mbstring \
  php8.3-fileinfo \
  php8.3-xml \
  php8.3-gd \
  php8.3-curl \
  php8.3-intl \
  libfreetype6-dev \
  git \
  sqlite3 \
  unzip \
  curl
systemctl restart php8.3-fpm
```

> **`php8.3-xml` is required** — it provides the `SimpleXML` extension used by the XML-RPC API (`/admin/xmlrpc.php`). It is a separate package from `php8.3-fpm` and will not be pulled in automatically.

---

## 2 — Clone and install dependencies

```bash
git clone https://github.com/your-org/php-mini-cms /var/www/cms
cd /var/www/cms
composer install --no-dev --optimize-autoloader
```

---

## 3 — Run the setup script

```bash
php bin/setup.php
```

The script will:
- Prompt for an admin username and write it into `config.php`
- Prompt for an admin password and write its bcrypt hash into `config.php`
- Create the `data/` directory and initialize the SQLite database
- Optionally set the site title and URL

---

## 4 — Filesystem permissions

```bash
# Application files owned by deploy user, group www-data
chown -R deploy:www-data /var/www/cms

# Directories the web server must write to
chmod 775 /var/www/cms/data
chmod 775 /var/www/cms/content/media

# Web root writable so the Builder can write generated HTML
chmod 775 /var/www/cms

# Storage directory for CLI script logs (e.g. webmention send log)
mkdir -p /var/www/cms/storage
chmod 775 /var/www/cms/storage

# If generated HTML files already exist (e.g. copied from dev), make them group-writable
# so PHP-FPM (www-data) can overwrite them on rebuild
chmod -R g+w /var/www/cms/posts
chmod -R g+w /var/www/cms/pages
chmod g+w /var/www/cms/index.html /var/www/cms/feed.xml 2>/dev/null || true
```

---

## 5 — PHP-FPM settings

Edit `/etc/php/8.3/fpm/php.ini`:

```ini
upload_max_filesize = 50M
post_max_size       = 55M
max_execution_time  = 60
memory_limit        = 128M
display_errors      = Off
log_errors          = On
error_log           = /var/log/php8.3-fpm.log
```

Restart FPM: `systemctl restart php8.3-fpm`

Also set `umask` in the PHP-FPM pool config so generated files are group-writable (required for the web process to overwrite static HTML on rebuild):

```bash
# Edit /etc/php/8.3/fpm/pool.d/www.conf
# Add or update:
umask = 002
```

```bash
systemctl restart php8.3-fpm
```

---

## 6 — Nginx configuration

Install the site config:

```bash
cp /var/www/cms/nginx.conf.example /etc/nginx/sites-available/cms
# Edit server_name and paths as needed
ln -s /etc/nginx/sites-available/cms /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx
```

---

## 7 — TLS certificate (Let's Encrypt)

```bash
snap install --classic certbot
ln -s /snap/bin/certbot /usr/bin/certbot
certbot --nginx -d example.com -d www.example.com
```

Certbot auto-installs the certificate paths and sets up renewal.

---

## 8 — Firewall

```bash
ufw default deny incoming
ufw default allow outgoing
ufw allow 22/tcp   # SSH
ufw allow 80/tcp   # HTTP → redirects to HTTPS
ufw allow 443/tcp  # HTTPS
ufw enable
```

---

## 9 — First login

Visit `https://example.com/admin/` and log in with the credentials set during setup.

Click **Settings** to enter your site URL, then **Rebuild entire site** on the dashboard to generate the initial HTML output.

---

## 10 — MarsEdit setup (optional)

The CMS exposes a WordPress-compatible XML-RPC API. To connect MarsEdit:

1. **Add Blog** → choose **WordPress**
2. **Endpoint URL:** `https://example.com/admin/xmlrpc.php`
3. **Username / Password:** your admin credentials

MarsEdit will show **Posts** and **Pages** sections. All CRUD operations and media uploads work from the client. The same endpoint also supports the MetaWeblog API for other clients.

---

## Outgoing webmentions (cron)

The CMS can send webmention pings to external URLs linked from your published posts. Because endpoint discovery and pinging can take time, this runs as a CLI script rather than a web request:

```bash
php /var/www/cms/bin/send-webmentions.php           # send for posts updated since last run
php /var/www/cms/bin/send-webmentions.php --force   # re-send for all published posts
```

Add to your crontab (`crontab -e`) to run daily at 02:00:

```
0 2 * * * /usr/bin/php /var/www/cms/bin/send-webmentions.php >> /var/www/cms/storage/webmentions.log 2>&1
```

Verify the PHP binary path first: `which php`

The script exits with code `0` on success and `1` if any pings failed (useful for monitoring).

---

## Automated SQLite backup

```bash
# /etc/cron.daily/cms-backup
#!/bin/bash
DATE=$(date +%Y%m%d)
mkdir -p /var/backups/cms
sqlite3 /var/www/cms/data/cms.db ".backup '/var/backups/cms/cms-${DATE}.db'"
find /var/backups/cms -name "*.db" -mtime +30 -delete
```

```bash
chmod +x /etc/cron.daily/cms-backup
```

---

## Local development (Docker)

```bash
docker compose up --build
# Then in a second terminal:
docker compose exec php php bin/setup.php
```

Visit `http://localhost:8080/admin/`.

---

## Updating

```bash
cd /var/www/cms
git pull
composer install --no-dev --optimize-autoloader
# Log in to admin and click "Rebuild entire site"
```

---

## PHP extension reference

| Extension | Package | Purpose |
|---|---|---|
| `pdo_sqlite` | `php8.3-sqlite3` | SQLite database |
| `fileinfo` | `php8.3-fileinfo` | Upload MIME validation |
| `mbstring` | `php8.3-mbstring` | Required by league/commonmark |
| `simplexml` | `php8.3-xml` | XML-RPC API request parsing |
| `gd` | `php8.3-gd` | OG image generation (requires FreeType) |
| `curl` | `php8.3-curl` | Mastodon & Bluesky API calls |
| `intl` | `php8.3-intl` | Locale-aware string handling |
| `session` | built-in | Admin sessions |
| `hash` | built-in | Content-hash diffing |
| `json` | built-in | Config/settings |
| `openssl` | built-in | Session security |
