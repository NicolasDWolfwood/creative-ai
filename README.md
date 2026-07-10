# Creative-Ai

Creative-Ai is a Laravel and Filament website for publishing artwork, collections, music, playlists, and journal posts. Development and deployment use the same container image and the same startup order:

```text
PostgreSQL + Redis -> one-time migrations -> healthy website -> queue worker
```

The release path is:

```text
local development -> pull request and CI -> merge to main
  -> immutable GHCR image digest -> private staging
  -> approve the tested digest -> production
```

Staging and production run the exact same image. Production never rebuilds a release that was tested on staging.

## Local development

Requirements:

- Docker Engine or Docker Desktop with Docker Compose
- Git
- Optional local DNS entry for a friendly development hostname

Create the ignored development environment file:

```bash
cp .env.example .env
docker run --rm php:8.3-cli php -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'
```

Paste the generated value into `APP_KEY` in `.env`. The tracked example uses `dev.example.test`; either map that hostname to `127.0.0.1` or change `APP_URL` and `TRUSTED_HOSTS` to `localhost`.

Build and start the complete stack:

```bash
docker compose up --build --detach --wait
```

Open the URL configured in `.env`. Readiness is available at `/ready`.

The stack contains:

- `postgres`: local PostgreSQL 17 database
- `redis`: local cache, sessions, and queues
- `creative-ai-migrate`: exits successfully after applying migrations
- `creative-ai`: Apache and Laravel website
- `creative-ai-worker`: Laravel queue worker

The three named volumes preserve PostgreSQL, Redis, and uploaded media across rebuilds. A deliberately clean reset is:

```bash
docker compose down --volumes
docker compose up --build --detach --wait
```

This deletes all local development data.

## Editing and testing

Edit the repository in VS Code, Codex Desktop, or Codex Web. Rebuild after a code change:

```bash
docker compose up --build --detach --wait
```

Docker reuses cached dependency layers, recreates the changed image, reruns the migration container, and preserves named data volumes. This favors release parity over bind-mounted source or dependency drift.

Run the test image, which includes development-only Composer packages:

```bash
docker compose --profile tools run --rm --build --no-deps creative-ai-test php artisan test --no-ansi
```

Equivalent tasks are available from VS Code under `Terminal -> Run Task`.

## First administrator

Administrator identity and passwords live only in PostgreSQL. No administrator values belong in `.env`.

Create the first administrator interactively:

```bash
docker compose exec --user www-data creative-ai php artisan creative-ai:admin:create admin@example.test
```

For recovery, an explicit generated password can be printed once:

```bash
docker compose exec --user www-data creative-ai php artisan creative-ai:admin:reset-password admin@example.test --generate-password
```

Administration access can be removed without deleting the user:

```bash
docker compose exec --user www-data creative-ai php artisan creative-ai:admin:revoke admin@example.test
```

The final administrator is protected unless `--allow-no-admin` is supplied deliberately.

## Application settings

Users, administrator status, content, AI provider selection, models, timeouts, and encrypted provider API keys are stored in PostgreSQL. `APP_KEY` encrypts saved credentials and must remain stable for the life of that environment.

Only bootstrap and infrastructure values stay outside the database: image digest, `APP_KEY`, canonical URL, indexing policy, trusted hosts/proxies, PostgreSQL/Redis connections, storage mount, Docker network, and container address.

## Releases and Unraid

Pull requests run application tests, build the release image, and smoke-test it with real PostgreSQL and Redis. A successful merge to `main` publishes one immutable GHCR digest. Copy the complete `ghcr.io/...@sha256:...` reference into staging; after testing, approve and copy that same digest into production.

Unraid uses Compose Manager and does not run a deployment script. See [UNRAID.md](UNRAID.md) for stack creation, updates, backups, promotion, and rollback.

## License and credits

The original static presentation was based on Multiverse by HTML5 UP, released under the Creative Commons Attribution 3.0 license. See [LICENSE.txt](LICENSE.txt) for repository licensing information and retained attribution.
