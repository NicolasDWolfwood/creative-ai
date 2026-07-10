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

The production deployment path is `deploy/unraid/compose.yaml` through two isolated Unraid Compose Manager Plus stacks. See the root `UNRAID.md` for staging, promotion, cutover, and rollback instructions. The root `compose.yaml` is for building the current checkout locally.
