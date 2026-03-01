# Installation — PHP Mini CMS

## Requirements (VPS / production)

| Component | Minimum version |
|---|---|
| OS | Ubuntu 22.04 LTS (24.04 recommended) |
| PHP | 8.1 (8.3 recommended) |
| PHP extensions | `pdo_sqlite`, `fileinfo`, `mbstring`, `gd` (with FreeType) |
| Web server | Nginx 1.18+ |
| PHP process manager | PHP-FPM |
| Composer | 2.x |

---

## 1 — System packages and PHP extensions

```bash
# GD with FreeType is required for OG image generation
apt-get install -y php8.3-gd libfreetype6-dev
systemctl restart php8.3-fpm
```

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

See the steps below.

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
