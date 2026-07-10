# Creative-Ai application

This directory contains the Laravel and Filament application. Run it through the repository root [compose.yaml](../compose.yaml) so PostgreSQL, Redis, migrations, the web process, and the queue worker use the same lifecycle as staging and production.

Application content and settings live in PostgreSQL. Provider API keys are encrypted with the environment’s persistent `APP_KEY`.

Administrator recovery commands inside a running web container are:

```bash
php artisan creative-ai:admin:create admin@example.test
php artisan creative-ai:admin:reset-password admin@example.test --generate-password
php artisan creative-ai:admin:revoke admin@example.test
```

Use `docker compose exec --user www-data creative-ai ...` from the repository root, or prefix the command with `gosu www-data` inside the container console.

See the root [README.md](../README.md) for development and [UNRAID.md](../UNRAID.md) for deployment.
