# Creative-Ai Site

This directory contains the Laravel + Filament rebuild of Creative-Ai.

## Runtime

- Laravel 13
- Filament 5 admin panel at `/admin`
- PostgreSQL database
- Redis cache/session/queue
- Public uploaded media on Laravel's `public` disk

## Useful commands

```bash
php artisan migrate
php artisan creative-ai:create-admin
php artisan creative-ai:import-legacy
npm run build
```

The intended production deployment path is the root `compose.yaml` through Unraid Compose Manager Plus.
