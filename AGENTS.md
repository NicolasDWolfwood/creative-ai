# Creative-Ai repository guide

This file is the starting point for a new coding or operations session.

## Read first

1. [README.md](README.md) for local Docker development and the Git workflow.
2. [UNRAID.md](UNRAID.md) for staging, production, promotion, backups, and rollback.
3. [PROJECT_STATUS.md](PROJECT_STATUS.md) for the last verified rollout state and known follow-up work.
4. [deploy/unraid/README.md](deploy/unraid/README.md) for the Compose Manager bundle.

## Secrets and local state

Never print, commit, summarize, or include the contents of real environment files in command output. In particular, treat these as secret-bearing local files:

- `/.env`
- `/unraid.env`
- `/deploy/unraid/*.env.local`
- `/site/.env`

Use only tracked `*.env.example` files for documentation and automated validation. When diagnosing a live environment, prefer redacted names, boolean checks, counts, or hashes that do not reveal credentials.

## Architecture

- The Laravel and Filament application lives under `site/`.
- Root [compose.yaml](compose.yaml) is the isolated local development stack.
- [deploy/unraid/compose.yaml](deploy/unraid/compose.yaml) is shared by staging and production.
- PostgreSQL, Redis, the reverse proxy, and the external Docker network are existing infrastructure on the deployment host.
- Staging and production have separate databases, Redis namespaces, storage, `APP_KEY` values, cookies, static web addresses, and Compose project names.
- Migrations are a one-shot service. A settled stack normally has two running services and one migration container that exited successfully.

## Release rules

- Work on a branch and merge through a pull request after CI passes.
- A merge-triggered push to `main` publishes and smoke-tests an immutable GHCR digest.
- Deploy the complete `ghcr.io/...@sha256:...` reference to staging.
- Promote only the digest accepted on staging through the protected production workflow.
- Deploy that exact digest to production. Never deploy `latest` or the moving `:production` tag.
- Unraid deployments use Compose Manager Down, edit the image reference, and Up. There is no host deployment script and no published website port.

## Local validation

From the repository root:

```bash
docker compose up --build --detach --wait
docker compose --profile tools run --rm --build --no-deps creative-ai-test php artisan test --no-ansi
docker compose --profile tools run --rm --no-deps creative-ai-test vendor/bin/pint --test
```

Also validate both tracked Unraid examples after changing Compose or environment contracts:

```bash
docker compose --env-file deploy/unraid/staging.env.example --file deploy/unraid/compose.yaml config --quiet
docker compose --env-file deploy/unraid/production.env.example --file deploy/unraid/compose.yaml config --quiet
```

Do not mutate live Unraid, proxy, database, or registry state unless the user explicitly places that action in scope.
