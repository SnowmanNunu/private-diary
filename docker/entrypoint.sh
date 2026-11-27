#!/bin/sh
set -e

cd /var/www/html

# The host .env is bind-mounted. Bootstrap it on a fresh checkout so
# `docker compose up` works without a local PHP installation.
if [ ! -f .env ]; then
    cp .env.example .env
fi

if ! grep -Eq '^APP_KEY=base64:.+' .env; then
    key="$(php artisan key:generate --show)"
    sed -i "s|^APP_KEY=.*|APP_KEY=${key}|" .env
fi

DB_FILE="${DB_DATABASE:-/var/www/html/database/database.sqlite}"
mkdir -p "$(dirname "$DB_FILE")"
if [ ! -f "$DB_FILE" ]; then
    : > "$DB_FILE"
fi

mkdir -p \
    bootstrap/cache \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs

# Only the app service migrates; the scheduler uses the same volume and would race on first boot.
if [ "${MIGRATE:-0}" = "1" ]; then
    php artisan migrate --force
fi
php artisan config:clear >/dev/null 2>&1 || true

chown -R www-data:www-data bootstrap/cache storage database

exec "$@"
