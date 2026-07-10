#!/usr/bin/env bash
set -Eeuo pipefail

if [[ ! "${APP_KEY:-}" =~ ^base64:[A-Za-z0-9+/]{43}=$ ]]; then
    echo "APP_KEY must be a persistent base64-encoded 32-byte Laravel key." >&2
    exit 78
fi

install -d -m 0775 -o www-data -g www-data \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache
chown www-data:www-data storage bootstrap/cache

if [ "${APP_ENV:-production}" = "production" ]; then
    php artisan config:cache

    if [ "${1:-}" = "apache2-foreground" ]; then
        php artisan route:cache
        php artisan view:cache
    fi
fi

if [ "${1:-}" = "apache2-foreground" ]; then
    exec "$@"
fi

exec gosu www-data "$@"
