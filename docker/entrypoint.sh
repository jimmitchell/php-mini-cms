#!/bin/sh
set -e

CMS_ROOT=/var/www/cms

# ── Composer install if vendor/ is absent ─────────────────────────────────────
if [ ! -d "${CMS_ROOT}/vendor" ]; then
    echo "[entrypoint] Running composer install..."
    composer install --no-interaction --prefer-dist --working-dir="${CMS_ROOT}"
fi

# ── Create required runtime directories ───────────────────────────────────────
mkdir -p "${CMS_ROOT}/data"
mkdir -p "${CMS_ROOT}/content/media"

# ── Remind about first-time setup ─────────────────────────────────────────────
if [ ! -f "${CMS_ROOT}/data/cms.db" ]; then
    echo ""
    echo "┌─────────────────────────────────────────────────────────┐"
    echo "│  First run: no database found.                          │"
    echo "│                                                         │"
    echo "│  Run setup in a second terminal:                        │"
    echo "│    docker compose exec php php bin/setup.php            │"
    echo "└─────────────────────────────────────────────────────────┘"
    echo ""
fi

exec "$@"
