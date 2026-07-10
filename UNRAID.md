# Creative-Ai on Unraid with Compose Manager

This runbook creates two clean and isolated website stacks:

| Environment | Access | Data | Image |
| --- | --- | --- | --- |
| Staging | Private network or VPN | Staging PostgreSQL database, Redis namespaces, storage, and `APP_KEY` | Exact GHCR digest from `main` |
| Production | Public reverse proxy | Production PostgreSQL database, Redis namespaces, storage, and `APP_KEY` | The exact digest tested on staging |

Each completed stack contains the migration, website, and queue-worker containers. Existing PostgreSQL, Redis, reverse-proxy, and Docker-network services remain external to the website stacks.

There is no host deployment script and no published website port. Compose Manager owns the complete lifecycle.

## 1. Release model

```text
branch -> pull request -> CI -> merge main -> immutable GHCR digest
  -> Compose Manager staging -> test -> GitHub production approval
  -> same digest in Compose Manager production
```

Never deploy `latest`, a moving staging tag, or a rebuilt copy. Both environments use a complete reference such as:

```text
ghcr.io/example/creative-ai@sha256:0123456789abcdef...
```

The GitHub Actions summary prints the exact value after a successful `main` build.

## 2. One-time prerequisites

Before creating a stack, prepare:

- an existing Docker network reachable by the reverse proxy, PostgreSQL, and Redis;
- one unused static address for each website container;
- a new PostgreSQL database and login role per environment;
- distinct Redis database numbers or, preferably, separate Redis ACL users;
- one persistent Unraid storage directory per environment;
- GHCR read access when the package is private.

Create a GitHub deployment environment named `production` under the repository's **Settings -> Environments**. Enable **Required reviewers** and select the reviewer who must approve promotion. The workflow references this exact environment name; without a protection rule, GitHub does not pause the promotion job for a second approval.

If the GHCR package is private, authenticate Unraid once with a classic personal access token that has only `read:packages` access:

```bash
read -rsp "GHCR token: " CR_PAT; echo
printf '%s' "$CR_PAT" | docker login ghcr.io --username YOUR_GITHUB_USER --password-stdin
unset CR_PAT
```

Docker retains registry credentials for future pulls. Restrict access to the Unraid administrator account and its Docker configuration.

Example PostgreSQL statements, run as a PostgreSQL administrator and adapted locally:

```sql
CREATE ROLE creative_ai_staging LOGIN PASSWORD 'replace-with-a-long-password';
CREATE DATABASE creative_ai_staging OWNER creative_ai_staging;

CREATE ROLE creative_ai_production LOGIN PASSWORD 'replace-with-a-different-long-password';
CREATE DATABASE creative_ai_production OWNER creative_ai_production;
```

Do not put these passwords or real network details in Git. URL-encode reserved characters when building `DB_URL`.

Create the two storage directories using the Unraid file manager or terminal. Generic examples are:

```text
/mnt/user/appdata/creative-ai/staging/storage
/mnt/user/appdata/creative-ai/production/storage
```

Generate a separate persistent `APP_KEY` for each clean environment:

```bash
docker run --rm php:8.3-cli php -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'
```

Changing `APP_KEY` later invalidates sessions and prevents decryption of provider API keys saved in PostgreSQL. Back it up securely with the matching environment.

## 3. Create staging in Compose Manager

1. Add a stack named `creative-ai-staging` in the Unraid Compose Manager plugin.
2. Prefer the plugin's normal project folder so the Compose and `.env` tabs remain the single source of truth. Use an external/indirect path only when deliberately managing those files elsewhere, create it first, and verify the editor's **Editing file** path before saving.
3. Paste [deploy/unraid/compose.yaml](deploy/unraid/compose.yaml) into the Compose tab.
4. Paste [deploy/unraid/staging.env.example](deploy/unraid/staging.env.example) into the `.env` tab.
5. Replace every placeholder and every documentation address/hostname with local values.
6. Keep `DEPLOYMENT_ENV=staging` and `ALLOW_INDEXING=false`.
7. Put the complete immutable image reference from the successful GitHub Actions `main` run in `CREATIVE_AI_IMAGE`.
8. Save/apply the Compose and `.env` tabs.
9. Use Compose Up. Compose automatically pulls the exact digest when it is not already present; a separate Compose Pull is optional.

Startup is intentionally ordered:

1. `creative-ai-migrate` runs all pending migrations and exits with code `0`.
2. `creative-ai` starts only after migrations succeed and becomes healthy through `/ready`.
3. `creative-ai-worker` starts only after the website is healthy.

An exited migration container with exit code `0` is expected. Compose Manager may therefore show `partial (2/3)` even while the website and worker are healthy. If migration fails, the website and worker remain stopped; inspect the migration logs, fix the database or release, and run Compose Up again.

## 4. Create the first administrator

After the website is healthy, open its Unraid container console and run:

```bash
gosu www-data php artisan creative-ai:admin:create admin@example.com
```

The command prompts for the display name and a hidden password. Administrator identity and password hashes are stored in PostgreSQL, not `.env`.

Recovery commands are available inside the same image:

```bash
gosu www-data php artisan creative-ai:admin:reset-password admin@example.com --generate-password
gosu www-data php artisan creative-ai:admin:revoke admin@example.com
```

The final administrator cannot be revoked accidentally.

## 5. Reverse proxy

Route the private staging hostname directly to the staging web container’s static Docker address on internal port `80`. Route the public hostname to the production web container in the same way. No Unraid host port is published.

The proxy must replace client-supplied forwarded headers and set at least:

- `Host`
- `X-Forwarded-For`
- `X-Forwarded-Host`
- `X-Forwarded-Port`
- `X-Forwarded-Proto`

Set `TRUSTED_PROXIES` to the source address the website container actually receives, not necessarily the reverse proxy's host address. Docker routing or source NAT can make this the application network gateway. Make one proxied request, inspect the website access log, and trust only the observed proxy-side address or smallest dedicated CIDR. Set `TRUSTED_HOSTS` to the literal environment hostnames.

If that observed address is a gateway shared by other clients, restrict direct access to the container at the firewall or network layer so only the intended reverse proxy can supply forwarded headers.

After enabling HTTPS, inspect the rendered page once and confirm its CSS and JavaScript URLs also start with `https://`. HTTP asset URLs on an HTTPS page mean the forwarded scheme was not trusted and browsers will block the styling as mixed content.

For staging, also enforce a LAN/VPN allowlist at the proxy or firewall. `ALLOW_INDEXING=false` and robots headers prevent indexing but are not access control.

## 6. Routine staging update

After a pull request is merged and the `main` workflow succeeds:

1. Copy the complete immutable image reference from the Actions summary.
2. In Compose Manager, run Compose Down for the staging stack. This gracefully stops the worker and website.
3. Replace only `CREATIVE_AI_IMAGE` in the staging `.env` tab and apply it.
4. Run Compose Up. It pulls the missing exact digest before creating the containers. Do not use Restart; Restart does not apply changed Compose or environment values.
5. Confirm migration exited `0`, website is healthy, and worker is running.
6. Test public pages, administrator actions, uploads, generated variants, media playback, and one queued job.
7. Verify the staging response is still private and non-indexable.

If testing fails, do not promote the digest. Put the previously known good digest back into staging and repeat Down and Up. Code rollback is safe only while database migrations remain backward-compatible.

## 7. Create production after staging is proven

Production uses the same Compose file but its own `.env`, database, Redis namespaces, storage, address, and `APP_KEY`.

1. Create a second Compose Manager stack named `creative-ai-production`.
2. Paste the same Compose YAML.
3. Paste [deploy/unraid/production.env.example](deploy/unraid/production.env.example) into its `.env` tab.
4. Replace every placeholder with production-only values.
5. Keep the stack stopped while staging is tested.
6. Run the GitHub **Approve image for production** workflow with the staging-tested `sha256:...` digest.
7. After approval, put the workflow’s complete image reference into the production `.env`.
8. Run Compose Up; Compose pulls the missing approved digest automatically.
9. Create a new production administrator and enter production provider settings in the admin interface.
10. Validate production through a temporary private HTTPS proxy rule before public cutover.

This is a clean production environment. Do not copy the staging database, Redis data, storage, users, or encrypted application settings. Upload desired content through the new administration process.

When validation succeeds, change the existing public reverse-proxy rule from the old website backend to the new production container. Keep the old backend stopped or unreachable for a short rollback window.

## 8. Routine production update

1. Confirm the digest passed staging acceptance and GitHub production approval.
2. Run Compose Down for production so web writes and queue processing stop consistently.
3. Back up PostgreSQL, persistent storage, the protected `.env`/`APP_KEY`, and reverse-proxy configuration.
4. Replace `CREATIVE_AI_IMAGE` with the complete approved staging image reference.
5. Run Compose Up; Compose pulls the missing approved digest automatically.
6. Confirm migration exited `0`, web is healthy, and worker is running.
7. Verify HTTPS, login, media, uploads, indexing, and a queued job.

This deliberately uses a short maintenance window in exchange for a small and understandable deployment process.

## 9. Backups and rollback

A usable production backup contains matching copies of:

- PostgreSQL database
- persistent `/app/storage` data
- `.env`, especially `APP_KEY`
- reverse-proxy configuration
- deployed image digest

Run `pg_dump` against the production database after the stack is down, copy the storage directory, verify the dump with `pg_restore --list`, and retain an off-server copy.

For a backward-compatible code rollback:

1. Compose Down.
2. Restore the previous complete image reference in `.env`.
3. Compose Up.

For an incompatible schema or data change, restore the matching database, storage, `.env`/`APP_KEY`, and image digest together. All normal migrations should therefore use an expand/contract approach and remain backward-compatible through at least one release.

## 10. Environment ownership

PostgreSQL stores users, roles, content, AI provider choice, models, behavior settings, and encrypted provider keys. The `.env` contains only values required before PostgreSQL can be used or values that define the Docker/security boundary:

- project and environment identity
- immutable image digest
- Docker network, static address, and storage mount
- `APP_KEY`, canonical URL, indexing policy, trusted hosts, and trusted proxies
- PostgreSQL and Redis connection URLs

Keep completed `.env` files out of Git and screenshots. The tracked examples intentionally contain only generic documentation values.
