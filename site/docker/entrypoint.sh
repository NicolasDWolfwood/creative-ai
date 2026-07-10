#!/usr/bin/env bash
set -euo pipefail

mkdir -p \
    storage/app/public \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

chown -R www-data:www-data storage bootstrap/cache public || true

if [ -z "${APP_KEY:-}" ]; then
    echo "APP_KEY is empty. Generate one with: docker compose run --rm creative-ai php artisan key:generate --show"
fi

php artisan package:discover --ansi || true
php artisan storage:link --force || true
php artisan filament:assets || true

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force
fi

if [ "${RUN_LEGACY_IMPORT:-false}" = "true" ]; then
    php artisan creative-ai:import-legacy
fi

if [ "${APP_ENV:-production}" = "production" ]; then
    php artisan config:cache || true
    php artisan route:cache || true
    php artisan view:cache || true
fi

exec "$@"
