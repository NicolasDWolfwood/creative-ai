# Creative-Ai staging and production on Unraid

This is the deployment runbook for the Laravel/Filament replacement site.

```text
branch or Codex change -> pull request -> CI -> merge to main
    -> GHCR candidate -> private staging -> exact digest recorded
    -> GitHub production approval -> same digest on production
```

Production never rebuilds an approved release. It only accepts the exact `@sha256:...` digest that ran on staging.

## Environment layout

| Environment | Hostname | Host port | Image | Persistent data |
| --- | --- | ---: | --- | --- |
| Staging | `test.creative-ai.nl` | `8080` | `:staging`, resolved once per update to a digest | staging database/user, storage, Redis DBs `2`/`3` |
| Production | `www.creative-ai.nl` | `8081` | required approved `@sha256:...` digest | production database/user, storage, Redis DBs `0`/`1` |

PostgreSQL, Redis, Ollama, and the reverse proxy remain existing Unraid services. Each site environment gets its own web and queue-worker containers.

## Deployment files

- `deploy/unraid/compose.yaml`: use for both Unraid stacks.
- `deploy/unraid/staging.env.example`: staging `.env` template.
- `deploy/unraid/production.env.example`: production `.env` template.
- `deploy/unraid/update.sh`: locked, digest-pinned migration/restart/health workflow.

The root `compose.yaml` builds the current local checkout. Do not use it for these image-based staging and production stacks.

## 1. One-time GitHub setup

1. Merge `.github/workflows/` into `main`. Pull requests run formatting, tests, frontend build, deployment-file validation, and a container build. A merge to `main` publishes:
   - moving candidate `ghcr.io/nicolasdwolfwood/creative-ai:staging`
   - traceability tag `ghcr.io/nicolasdwolfwood/creative-ai:sha-<full-git-sha>`
   - the immutable digest shown in the workflow summary
2. In GitHub **Settings -> Environments**, create `production`, add a required reviewer, and restrict deployment branches to `main`. Enable prevent-self-review when a second trusted reviewer is available; in a solo repository, leave it off so your own deliberate approval remains possible. See [GitHub deployment environments](https://docs.github.com/en/actions/reference/workflows-and-actions/deployments-and-environments).
3. Protect `main`: require a pull request and the `Laravel and frontend tests` and `CI result` checks.
4. After the first build, choose a [GitHub Container Registry](https://docs.github.com/en/packages/working-with-a-github-packages-registry/working-with-the-container-registry) access model:
   - make the package public for anonymous Unraid pulls; or
   - keep it private and authenticate Unraid with a classic token carrying only `read:packages`.

For a private package:

```bash
read -rsp 'GHCR read token: ' CR_PAT; echo
printf '%s' "$CR_PAT" | docker login ghcr.io -u NicolasDWolfwood --password-stdin
unset CR_PAT
chmod 600 /root/.docker/config.json
```

Docker stores this credential in root's Docker configuration. Restrict and back up that file appropriately, rotate the token, and never put it in Compose or Git.

## 2. Create the Compose Manager Plus projects

Use Compose Manager Plus **Indirect Path** projects on the array instead of its default `/boot` flash folder. This gives normal permissions, avoids flash writes, and lets the VS Code SSH tasks use stable paths.

```bash
mkdir -p \
  /mnt/user/appdata/creative-ai-deploy/staging \
  /mnt/user/appdata/creative-ai-deploy/production
chmod 700 /mnt/user/appdata/creative-ai-deploy/{staging,production}
```

In **Docker -> Compose**:

1. Add `creative-ai-staging` with indirect path `/mnt/user/appdata/creative-ai-deploy/staging`.
2. Paste `deploy/unraid/compose.yaml` into its Compose tab.
3. Paste `deploy/unraid/staging.env.example` into its `.env` tab.
4. Replace every placeholder and keep `DEPLOYMENT_ENV=staging` and `ALLOW_INDEXING=false`.
5. Copy `update.sh` alongside the Compose file.
6. Repeat for `creative-ai-production` with indirect path `/mnt/user/appdata/creative-ai-deploy/production`. Do not start it yet.

Protect the completed environment files:

```bash
chmod 600 /mnt/user/appdata/creative-ai-deploy/{staging,production}/.env
```

Invoke the script with `bash update.sh`; this also works if you intentionally keep a project under `/boot`, where executable bits and `chmod 600` are not reliable. If secrets stay on flash, disable/restrict the Flash SMB share and protect flash backups.

Generate a new 32-byte Laravel key with:

```bash
printf 'base64:%s\n' "$(openssl rand -base64 32)"
```

The update script rejects missing environment classification, placeholders, malformed keys, a wrong project name, concurrent updates, and non-digest production images. If an `.env` value contains `$`, spaces, or `#`, single-quote the value so Compose does not reinterpret it.

## 3. Isolation and networking

Never share these between staging and production:

- PostgreSQL database, role, and password
- storage path
- session cookie name and admin password
- Redis database numbers, prefixes, and preferably credentials

The templates assign distinct names, paths, ports, Redis databases/prefixes, and cookies. Create the PostgreSQL databases and roles first.

Redis database numbers are collision protection, not a security boundary. Because staging can run manually published branch code, use separate Redis instances or ACL users constrained to each environment's prefixed keys when possible. Never expose PostgreSQL, Redis, Ollama, or ports `8080`/`8081` to the WAN.

The worker timeout is 180 seconds and Redis retry-after is 240 seconds. Keep retry-after above the worker timeout to prevent duplicate execution of a slow AI job.

### Reverse proxy

- `test.creative-ai.nl` -> Unraid LAN IP port `8080`
- `www.creative-ai.nl` -> Unraid LAN IP port `8081`
- `creative-ai.nl` -> permanent redirect to `https://www.creative-ai.nl`

The proxy must overwrite, rather than append or trust client values for, `Host`, `X-Forwarded-For`, `X-Forwarded-Host`, `X-Forwarded-Port`, and `X-Forwarded-Proto`; remove an unused `X-Forwarded-Prefix`. Determine the actual proxy source address seen by the app and set `TRUSTED_PROXIES` to that IP or the smallest dedicated proxy-network CIDR. Do not use `*` or a whole client LAN.

For staging, apply all of these:

1. split-horizon/internal DNS or no public DNS record;
2. reverse-proxy LAN/VPN allowlist;
3. `ALLOW_INDEXING=false` in Laravel;
4. proxy-wide `X-Robots-Tag: noindex, nofollow, noarchive`, which also covers Apache-served media/assets.

Robots directives are defense in depth, not access control. Bind the direct ports to the Unraid LAN IP and firewall them from other networks.

## 4. Convert the existing test stack

The existing test stack already occupies port `8080`. Convert it before adding the new production stack:

1. Finish/drain queued AI jobs and pause uploads.
2. Record its current database, storage path, and `APP_KEY`.
3. Back it up using the consistent-backup procedure below.
4. Stop/down the old single-instance Compose stack so its fixed container names and port are released.
5. Configure the new `creative-ai-staging` stack to use the existing test database, storage path, and existing `APP_KEY`. Do not generate a different key for this adopted database.
6. Move staging to Redis DBs `2`/`3` and staging-specific prefixes. Existing Redis sessions/cache/queues are disposable; expect to sign in again.
7. Run `bash update.sh --yes` from the new staging project and validate `https://test.creative-ai.nl`.

Keeping the current storage path (for example `/mnt/user/appdata/creative-ai/storage`) is fine as long as production uses a different path. If both stacks must overlap temporarily, give the new staging stack a temporary unused port instead of `8080`.

## 5. Consistent backups

For a production backup, first pause web writes and gracefully stop the worker. Replace service/container/database placeholders as appropriate:

```bash
cd /mnt/user/appdata/creative-ai-deploy/production
docker compose exec creative-ai php artisan down --retry=60
docker compose stop --timeout 240 creative-ai-worker

umask 077
backup_dir="/mnt/user/backups/creative-ai/$(date +%Y%m%d-%H%M%S)"
mkdir -p "$backup_dir/storage"
docker exec POSTGRES_CONTAINER pg_dump \
  --username=PRODUCTION_ROLE \
  --format=custom \
  PRODUCTION_DATABASE \
  > "$backup_dir/database.dump"
rsync -aH --numeric-ids --exclude='/framework/down' \
  /mnt/user/appdata/creative-ai-production/storage/ \
  "$backup_dir/storage/"
sha256sum "$backup_dir/database.dump" > "$backup_dir/SHA256SUMS"
docker exec -i POSTGRES_CONTAINER pg_restore --list \
  < "$backup_dir/database.dump" >/dev/null

docker compose start creative-ai-worker
docker compose exec creative-ai php artisan up
```

Also back up the protected deployment `.env`/`APP_KEY` and reverse-proxy configuration. Encrypt sensitive backups, keep an off-server copy, verify checksums, and test a restore before relying on the process.

For the initial staging-to-production clone, create a new production database/role, then restore without importing staging ownership or grants:

```bash
docker exec -i POSTGRES_CONTAINER pg_restore \
  --username=PRODUCTION_ROLE \
  --dbname=PRODUCTION_DATABASE \
  --no-owner \
  --no-privileges \
  < /mnt/user/backups/creative-ai/INITIAL_BACKUP/database.dump
```

Verify that the production role owns the restored schema/tables and audit its grants. Clone storage into `/mnt/user/appdata/creative-ai-production/storage/` with `rsync --exclude='/framework/down'`, remove any stale `/mnt/user/appdata/creative-ai-production/storage/framework/down` marker from an older backup, then test the restored production database before cutover. The updater also clears maintenance mode on a first deployment before it checks readiness.

The cloned database may contain cloud API keys encrypted with Laravel. Production must initially retain the source `APP_KEY` so those values remain decryptable.

## 6. Initial production cutover

1. Deploy and test a candidate on staging with `bash update.sh --yes`. Copy the exact `sha256:...` portion from `Resolved deployment image` or the last `deployments.log` entry.
2. Run GitHub Actions **Approve image for production** from `main`, enter that digest, inspect the resolved Git revision, and approve the protected `production` job.
3. Paste the workflow's complete `ghcr.io/...@sha256:...` value into production `CREATIVE_AI_IMAGE`.
4. Before creating the containers, create a temporary LAN-only HTTPS preview hostname/rule to port `8081` and include that literal hostname in production `TRUSTED_HOSTS`. Container environment is fixed when Compose creates it, so configure this before the first updater run.
5. Restore the cloned database/storage and use the source `APP_KEY` as described above.
6. Run `bash update.sh` from `/mnt/user/appdata/creative-ai-deploy/production` and confirm the verified backup prompt.

The script preflights the image, enables maintenance mode, gracefully stops the old worker, migrates, starts the web container, waits for `/ready` (PostgreSQL, Redis, and storage), performs smoke checks as `www-data`, then starts the new worker. It records the exact deployed digest.

Before public cutover, direct HTTP can validate only public responses:

```bash
curl -fsS -H 'Host: www.creative-ai.nl' http://192.168.1.176:8081/up
curl -fsS -H 'Host: www.creative-ai.nl' http://192.168.1.176:8081/ready
curl -fsS -H 'Host: www.creative-ai.nl' http://192.168.1.176:8081/ >/dev/null
```

Admin login uses secure cookies and must be tested through the temporary HTTPS preview. Complete the following credential cleanup before authenticating and before exposing this backend publicly.

### Required credential cleanup before public cutover

Cloning copies admin password hashes and encrypted provider keys:

1. From the production project, run `creative-ai:create-admin` with a newly generated password, audit/remove every other cloned user, and verify that only intended production provider credentials remain:

```bash
cd /mnt/user/appdata/creative-ai-deploy/production
docker compose exec creative-ai php artisan creative-ai:create-admin
```

2. Give staging a different admin password and remove stale users.
3. Blank cloud-key fallbacks in the staging `.env`, delete the staging `ai_configuration` setting from the staging project explicitly, and only then rotate staging's `APP_KEY` and recreate its containers:

```bash
cd /mnt/user/appdata/creative-ai-deploy/staging
docker compose exec creative-ai php artisan tinker --execute="App\Models\SiteSetting::query()->where('key', 'ai_configuration')->delete();"
# Put a newly generated base64 key in the staging .env, then:
bash update.sh --yes
```

Re-enter only test-safe provider credentials. Do not run a manually published branch against production-derived credentials.

Using the temporary preview, log in with the newly generated production admin credentials. Check admin, full-size images, thumbnails, one audio track, robots, sitemap, and a small queued AI job before continuing.

Cut over by switching the `www.creative-ai.nl` proxy target from the old backend to port `8081`. Verify HTTPS, canonical links, login, media, forwarded client IP, `/up`, and `/ready`. Keep the old backend intact but unreachable for a rollback window, and remove any deployed copy of the old `phpinfo.php`.

Remove the temporary preview DNS/proxy rule immediately after cutover. Remove its hostname from production `TRUSTED_HOSTS` and apply that environment change with the next controlled `bash update.sh` (or repeat the same approved digest deployment after another verified backup).

The fastest cutover rollback is switching the proxy back to the old backend; no DNS change is required.

## 7. Routine releases

### Publish and deploy staging

From VS Code, Codex Desktop, or Codex Web:

1. Create a short-lived branch, make the change, and review the diff.
2. Commit only intended files and push. The VS Code push task does not stage or commit unrelated work.
3. Open a pull request and wait for CI.
4. Merge to `main`; GitHub updates `:staging` and publishes the build digest.
5. Run **Creative-Ai: update staging on Unraid** or `bash update.sh --yes` in the staging project.
6. Record the exact deployed digest printed by the script, then test homepage/gallery/journal, admin workflow, media, worker/AI behavior, and staging robots controls.

To test a branch before merging, manually run **Test and build staging image** on that branch with `publish_staging=true`. Such branch code receives only staging credentials.

[Compose Manager Plus](https://ca.unraid.net/apps/compose-manager-plus-0wft8je0ra0zhy) can detect a changed staging tag, but its generic auto-update does not run this migration/maintenance/smoke workflow. Keep scheduled replacement disabled.

### Approve and deploy production

1. Run **Approve image for production** from the GitHub Actions `main` branch.
2. Enter the exact `sha256:...` digest reported by the tested Unraid staging deployment.
3. Review the resolved digest/revision and approve the `production` environment job.
4. Put the complete approved `ghcr.io/...@sha256:...` value in production `CREATIVE_AI_IMAGE`.
5. Make and verify a consistent production backup.
6. Run **Creative-Ai: update production on Unraid** or `bash update.sh` in the production project.
7. Verify `https://www.creative-ai.nl/up`, `/ready`, homepage, admin, media, and worker.

The GitHub environment records artifact approval, not the final LAN deployment. The Unraid update and external verification complete it.

Codex Web can create the branch/PR and run GitHub workflows but cannot reach LAN-only Unraid. Keep the final pull as a local Unraid/SSH action unless you later install a tightly restricted deploy runner. Never run pull-request code on a runner with the Docker socket; that socket is effectively root access.

## Failure handling and rollback

Before migration the updater stops web writes and the old worker. If preflight/migration fails, it restores the old web availability and worker. If a failure occurs after a successful migration, it deliberately leaves the worker stopped so old code cannot process jobs against the new schema; inspect logs and either finish deploying that digest or restore the paired backup.

All migrations and queued-job changes should use expand/contract compatibility. A code rollback alone is safe only while the migrated schema remains backward-compatible.

Each successful update appends timestamp, exact digest, and Git revision to `deployments.log`. For a code-only rollback, put the prior approved digest from that ledger in `CREATIVE_AI_IMAGE` and run `bash update.sh`. For incompatible schema/data changes, restore matching PostgreSQL, storage, `.env`/`APP_KEY`, and proxy backups together.

Do not use `latest`, rely on a mutable `sha-...` tag, rebuild an old commit, or run `migrate:rollback` blindly.

## First-boot administration

Create or update the configured administrator after migrations:

```bash
docker compose exec creative-ai php artisan creative-ai:create-admin
```

With `ADMIN_PASSWORD` blank, the command prints a generated password once. Leave that variable blank afterward so Compose does not retain a reusable plaintext password.

Run legacy import only when the database/storage does not already contain imported media:

```bash
docker compose run --rm creative-ai php artisan creative-ai:import-legacy
```

Never leave `RUN_LEGACY_IMPORT=true` in normal deployment configuration.
