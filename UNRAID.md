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

Only the workflow run triggered by a push to `main` publishes a staging candidate. A manually dispatched **Test and publish release candidate** run performs validation but does not publish an image.

The production approval workflow accepts the bare digest (`sha256:...`) as its input. Compose Manager always receives the complete image reference (`ghcr.io/...@sha256:...`). The moving `:production` tag is informational and must not be deployed.

## 2. One-time prerequisites

Before creating a stack, prepare:

- an existing Docker network reachable by the reverse proxy, PostgreSQL, and Redis;
- one unused static address for each website container;
- a new PostgreSQL database and login role per environment;
- distinct Redis database numbers or, preferably, separate Redis ACL users;
- one persistent Unraid storage directory per environment;
- GHCR read access when the package is private.

The external Docker network must be routable from the reverse-proxy host. This matters when Nginx Proxy Manager runs on another machine: an isolated bridge that exists only inside Unraid is not sufficient.

Redis logical database numbers separate key namespaces but are not a security boundary. A client with broad commands can still select or flush another logical database. Prefer one restricted ACL user per environment and keep the application prefixes enabled. If a shared default user is unavoidable, use different verified-empty database numbers and plan a later ACL migration.

Common Redis URL forms are:

```text
# No authentication, only on a deliberately trusted isolated service
redis://redis.example.invalid:6379/10

# Password on the default ACL user
redis://:URL_ENCODED_PASSWORD@redis.example.invalid:6379/10

# Dedicated named ACL user, preferred
redis://creative_ai_staging:URL_ENCODED_PASSWORD@redis.example.invalid:6379/10
```

Never use the literal username `null`. Before assigning a shared logical database, authenticate with `redis-cli`, select the proposed number, and confirm `DBSIZE` is zero. Staging data, staging cache, production data, and production cache must each resolve to a distinct Redis endpoint/database pair.

Create a GitHub deployment environment named `production` under the repository's **Settings -> Environments**. Enable **Required reviewers** and select the reviewer who must approve promotion. The workflow references this exact environment name; without a protection rule, GitHub does not pause the promotion job for a second approval.

Also protect `main` with a repository ruleset that requires pull requests and the final **CI result** status check. Disable routine direct pushes to `main`.

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

### First staging acceptance

Do not create or expose production until the initial staging stack passes the same acceptance gate used for later releases:

1. Confirm `creative-ai-migrate` exited with code `0`.
2. Confirm the website and worker are both healthy and `/ready` returns `{"status":"ready"}`.
3. Load the staging page through HTTPS and confirm its CSS and JavaScript assets return `200` without mixed-content warnings.
4. Confirm the proxy access rule blocks non-LAN/VPN clients and the response contains the staging no-index policy.
5. Create the first administrator and verify `/admin` login.
   Complete the required authenticator-app MFA setup, store the recovery codes securely, then sign out and verify a fresh login requires a current TOTP code.
6. Upload an image, publish it, and verify the original, display variant, and thumbnail return `200`.
7. Run one queued action and confirm the worker consumes it.
8. Import a test album with an embedded cover while leaving the album and member tracks unpublished. Run **Analyze audio health**, confirm the track table updates without a manual reload, and verify the inherited cover does not produce a false missing-cover warning. Publish the album while leaving **Publish as standalone track** off, then verify its album page, member track page, and audio return `200`, the member is absent from the standalone list, and a track-title search finds the album. Through the staging HTTPS proxy, request bytes `0-1023` of the publication-aware audio URL. Confirm `206 Partial Content`, `Accept-Ranges: bytes`, an exact `Content-Range`, a 1,024-byte body, and inline delivery; then seek during native-player playback and confirm it continues without downloading a separate asset URL. Unpublish the album and verify both full and byte-range anonymous audio requests return `404`.
9. Create an incomplete Journal draft with a private brief and notes. Confirm it can be saved and previewed by the administrator, remains `404` anonymously, and does not expose the private fields. Confirm the Journal list offers **Blank draft**, **Create from content**, and **Manage templates**. Create an active Journal template, choose **Create from content**, and create a draft from a public source with that template, optional public shared tags, and **Use source artwork as Journal cover** enabled. Confirm the source is connected at position one but its fields, files, and publication state are unchanged; the new post and its copied cover remain private; and the source leaves Story opportunities. Change or unpublish the source and confirm the copied cover remains stable because it is an independently owned snapshot. From **Connections**, replace the cover from another currently public connected source and confirm a stale second tab cannot overwrite the newer choice. Create a draft deliberately from one private or future source and confirm advance planning works without copying private source imagery or exposing either record anonymously. In the draft's **AI assistant**, request Directions with only title/body selected. Verify the exact provider/model/endpoint and outbound JSON, confirm private notes are omitted, and confirm nothing queues before the unchecked acknowledgement is accepted. Let the worker finish, review the escaped result and verification claims, and confirm the post and anonymous responses are unchanged. Apply one bounded metadata suggestion, confirm one **AI-assisted edit** revision, then undo it before making newer writing. Complete the readiness blockers, schedule the post in UTC, and confirm persistent AI apply/undo is unavailable after scheduling. Verify the story remains private before the due time and appears on the Journal page, RSS, and sitemap after that time without a scheduler job. Change its slug and confirm the former URL redirects once to the current canonical URL. Confirm **History** contains the baseline and labelled workflow/slug events. Restore earlier writing and verify only the safe content/SEO fields change while slug, workflow, private notes, featured placement, tags, and media stay current. Open a second editor or History tab, make a newer edit, and confirm the stale tab is rejected instead of overwriting it. Move the post to Trash, confirm the page, cover, and former slug fail closed, then restore it and confirm it returns as a private Draft rather than resuming publication.
10. Open **Journal planning defaults** before changing anything and confirm every single-source, artwork-batch, and album-import mode is Off. Create a source and confirm no Journal draft or AI work appears. Temporarily exercise **Ask each time** and **Create automatically**: verify the form offers or preselects one linked-draft choice but still permits opting out; an enabled single-source workflow produces at most one connected private Draft; one artwork bulk upload produces one ordered batch story rather than one story per artwork; and a multi-track import produces at most one story per detected album with no album-member track stories. Start with an already-connected source and confirm assisted planning keeps the existing plan instead of creating a duplicate. Confirm every generated story is Draft, no AI request is queued, and source creation/publication remains successful independently of Journal planning. Reset all modes to Off after acceptance.
11. Run Compose Down followed by Compose Up and confirm the administrator, uploaded records/media/settings, Journal workflow and planning-default state, album-only standalone state, and album playback behavior persist.

Record the complete image reference that passed this gate. Missing media variants, queue failures, or lost data are release failures unless explicitly accepted and recorded in [PROJECT_STATUS.md](PROJECT_STATUS.md).

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

For Nginx Proxy Manager or Nginx Proxy Manager Plus, create or edit a **Proxy Host** with:

- the literal environment hostname in **Domain Names**;
- `http` as the upstream scheme;
- the web container's static address as **Forward Hostname/IP**;
- `80` as **Forward Port**;
- a LAN/VPN access list for staging and public access for production;
- a valid certificate, **Force SSL**, and HTTP/2 in the TLS settings.

When the canonical public hostname uses `www`, create a separate **Redirection Host** for the apex domain. Redirect it permanently (`301`) to the canonical HTTPS hostname, preserve the path and query string, and select a certificate that explicitly covers the apex name. A wildcard certificate alone does not cover the apex domain.

Set the proxy request-body limit to at least `105 MiB` and allow enough request time for the available upstream bandwidth. The application accepts individual music files up to `100 MiB`; the additional request capacity covers multipart form overhead. Increasing only the proxy limit does not increase PHP's accepted upload size.

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

After a pull request is merged and its push-triggered `main` workflow succeeds:

1. Open that merge-triggered Actions run and copy the complete immutable image reference from its summary. A manual workflow run has no staging candidate.
2. In Compose Manager, run Compose Down for the staging stack. This gracefully stops the worker and website.
3. Replace only `CREATIVE_AI_IMAGE` in the staging `.env` tab and apply it.
4. Run Compose Up. It pulls the missing exact digest before creating the containers. A separate Compose Pull is unnecessary; do not use Restart because Restart does not apply changed Compose or environment values.
5. Confirm migration exited `0`, website is healthy, and worker is running.
6. When the release introduces image-variant tracking, or the artwork table shows pending or failed image sizes, open the website container console and run `gosu www-data php artisan creative-ai:artwork-variants:regenerate`. Leave the worker running, wait until the Image status badges settle, and investigate any recorded failure before promotion.
7. When the release introduces private media storage, back up storage, run `gosu www-data php artisan creative-ai:media:privatize --dry-run`, then run `gosu www-data php artisan creative-ai:media:privatize`. Investigate any reported collision or missing file before promotion.
8. Sign out of the administrator panel and verify a fresh login requires the current password and TOTP code.
9. Test public pages, administrator actions, uploads, original/display/thumbnail media, media playback, and one queued job. In the artwork editor, use the copy button beside **Slug** to copy the publication-aware original-image URL; open that URL in a private window and confirm a draft returns `404`. Do not use Filament's temporary signed `/storage/...` preview URL for the publication check.
10. Confirm an album member is playable from its published album while **Publish as standalone track** remains off, does not appear in the default standalone list, and is found through an album-track search. Through the staging HTTPS proxy, send `Range: bytes=0-1023` to its publication-aware audio URL and confirm `206 Partial Content`, `Accept-Ranges: bytes`, an exact `Content-Range`, a 1,024-byte body, and inline delivery. Seek in the native player and confirm playback continues through the same protected route. Unpublish the test album and confirm both full and ranged anonymous track/audio requests now return `404`. In the track library, keep album grouping enabled with a saved page size smaller than one album and confirm every album heading remains visible. Run **Analyze audio health** for a draft album with an embedded cover or a selected artwork that will be published with the release, watch the health states update without reloading the page, and confirm its tracks do not receive a false **Cover artwork is missing** warning. Review and explicitly re-enable any exceptional album members that should also appear as singles.
11. Preview a Journal draft and confirm the response is private, no-store, noindex, and free of private brief/notes. Confirm the Journal list exposes **Blank draft**, **Create from content**, and **Manage templates**. In **Journal templates**, create an active outline using `{{ source_title }}` and `{{ source_type }}`. Choose **Create from content** and create a draft for one public source with optional public shared tags and a source-artwork cover; confirm the generated title/body/brief, Draft state, position-one connection, removal from Story opportunities, and that the source record and publication state did not change. Confirm the copied cover is private while the post is Draft and remains visually and byte-stable after the source image changes or becomes private. Use **Connections** to replace it from another currently public connected source, and confirm a private/future connection cannot be used as a cover or exposed publicly. Create one deliberate advance-planning draft from a private or future source and confirm it has no copied private source image and remains `404` anonymously. In **AI assistant**, review and acknowledge an exact minimal-context request, watch it in **Journal AI jobs**, and verify failed/stale work exposes only safe categories and review links. Confirm an unapplied result changes no public or saved post bytes. Apply one selected suggestion to the Draft, confirm one attributed History revision and protected URL/workflow/private/connections, then undo it. Make newer safe writing and confirm the old Undo action remains blocked even if the same visible text is later recreated. Schedule the completed post in UTC and confirm AI persistent mutation is no longer available. Verify anonymous page/cover/feed/sitemap visibility changes only when due, then change its slug and verify the former URL returns one `301` to the new canonical URL. Confirm **History** contains the migration baseline plus labelled workflow and slug events. Preview and restore a revision, confirming only the safe public content/SEO fields change while slug, publication, schedule, featured placement, private notes, shared tags, and ordered media do not. Verify a stale editor tab and a stale History tab both reject changes made after they were opened. Replace or remove a historical cover source and confirm restore fails without changing the post. Move it to Trash and verify page, cover, feed/sitemap entry, reciprocal links, and redirects return or resolve as private; restore it and confirm it is Draft. On a published test post, add a shared tag and ordered public plus draft media connections. Confirm the public post preserves the public-media order, omits the draft source, appears reciprocally on each public media page, and links to a public tag archive that contains no private or future records. Review the Studio work queues and confirm their counts/oldest ages link to the expected libraries without showing stored error text.
12. Open **Journal planning defaults** and confirm every mode is Off before the first deliberate configuration. With the relevant source mode still Off, create a source and confirm no draft or AI work appears. Temporarily test **Ask each time** and **Create automatically** for representative workflows: confirm the source form offers or preselects **Also create a linked Journal draft**, that opting out remains possible, and that opting in creates only one connected private Draft. Confirm a repeated assisted attempt against an already-connected source keeps the existing plan. Upload a small artwork batch with planning enabled and verify one ordered Journal story connects the batch instead of creating one story per artwork. Import a multi-track album with planning enabled and verify one story is created per detected album with no story for its member tracks. Confirm automatically maintained collections and playlists do not create silent drafts. Verify none of these workflows queues Journal AI or changes a story from Draft; publishing or scheduling still requires its existing explicit human action. Return all planning modes to Off unless a different staging default is deliberately accepted and recorded.
13. Make a harmless draft edit, run Compose Down followed by Compose Up, and confirm the edit, Journal workflow and planning-default state, normalized standalone state, album playback, administrator access, and saved settings persist. Confirm migration again exits `0` and both long-running services return healthy.
14. Verify the staging response is still private and non-indexable.

If testing fails, do not promote the digest. Put the previously known good digest back into staging and repeat Down and Up. Code rollback is safe only while database migrations remain backward-compatible. The inherited-album and Journal lifecycle releases keep legacy publication fields as compatibility mirrors for this read-only rollback path; the connected-stories, planning-tools, revision, redirect, and soft-delete additions are ignored by an older image. Trashed rows remain private because their legacy publication state is cleared. Avoid music publication and every Journal edit—including slugs, trash, history, connections, or templates—while an older image is temporarily restored.

## 7. Create production after staging is proven

Production uses the same Compose file but its own `.env`, database, Redis namespaces, storage, address, and `APP_KEY`.

1. Create a second Compose Manager stack named `creative-ai-production`.
2. Paste the same Compose YAML.
3. Paste [deploy/unraid/production.env.example](deploy/unraid/production.env.example) into its `.env` tab.
4. Replace every placeholder with production-only values.
5. Keep the stack stopped while staging is tested.
6. Run the GitHub **Approve image for production** workflow from `main` with the bare staging-tested `sha256:...` digest.
7. After approval, put the workflow’s complete image reference into the production `.env`.
8. Run Compose Up; Compose pulls the missing approved digest automatically.
9. Create a new production administrator and enter production provider settings in the admin interface.
10. Rotate any bootstrap credential that was exposed in chat, screenshots, logs, or shell history. Update the matching protected `.env`, recreate the stack, and verify login again. Do not rotate `APP_KEY` after storing encrypted provider credentials without a separate re-encryption plan.
11. Preflight the backend from Unraid using the real production Host header before public cutover. Adapt these documentation values locally:

    ```bash
    PRODUCTION_IP=192.0.2.21
    PRODUCTION_HOST=www.example.com

    curl --fail --silent --show-error \
      --header "Host: ${PRODUCTION_HOST}" \
      "http://${PRODUCTION_IP}/ready"

    curl --fail --silent --show-error \
      --header "Host: ${PRODUCTION_HOST}" \
      "http://${PRODUCTION_IP}/robots.txt"
    ```

    Expect readiness JSON, production `Allow: /`, the `/admin` exclusion, and the canonical sitemap URL.

This is a clean production environment. Do not copy the staging database, Redis data, storage, users, or encrypted application settings. Upload desired content through the new administration process.

Before cutover, record or export the old proxy backend. Change only the existing public Proxy Host's upstream address to the new production web container; keep the scheme `http`, port `80`, domains, certificate, access policy, and advanced settings unchanged. Keep the old backend intact and unrouted for a short rollback window.

Immediately after saving the proxy change:

1. Load the canonical HTTPS homepage in a private browser window and confirm styling.
2. Check `/ready`, `/robots.txt`, and at least one compiled CSS and JavaScript asset over public HTTPS.
3. Log in to `/admin` to verify secure sessions through the proxy.
4. Confirm the apex domain redirects once to the canonical hostname with a valid certificate and preserved path.

If any check fails, restore the old Proxy Host upstream first. This returns traffic to the old site without changing DNS while the new backend is investigated.

## 8. Routine production update

1. Confirm the digest passed staging acceptance and GitHub production approval.
2. Finish or deliberately defer in-flight administration and queue work, then run Compose Down so web writes and queue processing stop consistently.
3. Back up PostgreSQL, persistent storage, the protected `.env`/`APP_KEY`, and reverse-proxy configuration.
4. Replace `CREATIVE_AI_IMAGE` with the complete approved staging image reference.
5. Run Compose Up; Compose pulls the missing approved digest automatically.
6. Confirm migration exited `0`, web is healthy, and worker is running.
7. When the release introduces image-variant tracking, or the artwork table shows pending or failed image sizes, open the website container console and run `gosu www-data php artisan creative-ai:artwork-variants:regenerate`. Leave the worker running, wait until the Image status badges settle, and investigate any recorded failure before continuing.
8. When the release introduces private media storage, back up storage, run `gosu www-data php artisan creative-ai:media:privatize --dry-run`, then run `gosu www-data php artisan creative-ai:media:privatize`. Stop on any reported collision or missing file.
9. Verify HTTPS, MFA login, original/display/thumbnail media, uploads, draft-media denial, indexing, and a queued job. When production contains music, also verify album-only playback, standalone-list exclusion, and denial after unpublishing a test album.

This deliberately uses a short maintenance window in exchange for a small and understandable deployment process.

Never replace the complete digest reference with the convenience `:production` tag. The tag moves; the approved digest is the rollback and audit identity.

## 9. Backups and rollback

A usable production backup contains matching copies of:

- PostgreSQL database
- persistent `/app/storage` data
- `.env`, especially `APP_KEY`
- reverse-proxy configuration
- deployed image digest

After the application stack is down, create a PostgreSQL custom-format archive using protected connection credentials:

```bash
pg_dump --format=custom --no-owner --no-acl \
  --file creative-ai-production-YYYYMMDD-HHMM.dump \
  PRODUCTION_DATABASE_NAME

pg_restore --list creative-ai-production-YYYYMMDD-HHMM.dump >/dev/null
```

Do not put the database password directly in shell history. Use the database container's protected environment, a temporary `PGPASSFILE`, or an equivalent backup facility. `pg_restore --list` checks archive readability, not full recoverability; periodically restore into a disposable database and run `/ready` against it.

Copy the storage directory while the application is down or through a storage snapshot. Back up the protected Compose Manager project `.env`, the reverse-proxy host and certificate configuration, and a text record of the complete current and previous image references. Keep checksums and at least one encrypted off-server copy according to the local retention policy.

Redis cache and sessions may be treated as disposable, but queued jobs are not equivalent to PostgreSQL content. Drain queues before a planned backup or document how pending work will be recreated. When Redis ACL users are enabled, include the external ACL configuration in the infrastructure backup.

For a backward-compatible code rollback:

1. Compose Down.
2. Restore the previous complete image reference in `.env`.
3. Compose Up.

For the inherited-album and Journal lifecycle releases, retain the expand-compatible migrations and do not run `migrate:rollback`. The connected-stories, planning-tools, revision, redirect, and soft-delete additions can also remain applied because older images ignore their columns, tables, and indexes. Treat music and all Journal administration as read-only while the older image is running, then restore the current image before making publication, scheduling, slug, trash, history, connection, template, or album-membership changes.

The Journal AI foundation and assistant columns are additive; leave them applied during an image rollback. The assistant migration deliberately refuses to drop non-null application manifests because that would destroy audit history, and it refuses to shrink stored 500-character cover alternative text into the former 255-character column. Its serialized queue job still requires an image containing that class. Before rolling back to an image without it, use the current image to cancel pending Journal AI work, leave its worker running until cancelled and superseded payloads have been consumed as no-ops, and confirm no Journal AI run remains queued or processing. Then run Compose Down and replace the image digest. Keep Journal AI administration paused while the older image is active.

For an incompatible schema or data change, restore the matching database, storage, `.env`/`APP_KEY`, and image digest together. All normal migrations should therefore use an expand/contract approach and remain backward-compatible through at least one release.

## 10. Environment ownership

PostgreSQL stores users, roles, content, AI provider choice, models, behavior settings, and encrypted provider keys. The `.env` contains only values required before PostgreSQL can be used or values that define the Docker/security boundary:

- project and environment identity
- immutable image digest
- Docker network, static address, and storage mount
- `APP_KEY`, canonical URL, indexing policy, trusted hosts, and trusted proxies
- PostgreSQL and Redis connection URLs

Keep completed `.env` files out of Git and screenshots. The tracked examples intentionally contain only generic documentation values.

When rotating credentials:

- replace a PostgreSQL role password in both PostgreSQL and the protected stack `.env`, then recreate and verify the stack;
- prefer adding a new Redis ACL user/password, switching the application, verifying it, and only then revoking the old credential;
- remember that rotating a shared Redis default password affects every client, including dormant containers;
- reset an administrator password with the bundled Artisan command instead of putting it in `.env`;
- refresh a GHCR read token with `docker login` and revoke the old token after a successful pull;
- treat `APP_KEY` differently from an ordinary password: changing it invalidates sessions and makes existing encrypted provider settings unreadable unless a deliberate key-migration procedure is used.

## 11. Reboot and autostart

The website and worker use Docker's `restart: unless-stopped` policy. The migration service is deliberately one-shot with `restart: "no"`; it should remain exited after a successful deployment.

Compose Manager's stack **Autostart** toggle is host state and is not stored in this repository. Before enabling it:

1. Ensure the external Docker network is preserved and available at Docker startup.
2. Enable and verify autostart for the external PostgreSQL and Redis services.
3. Enable stack Autostart for production and staging only after their first acceptance checks pass.
4. Perform one controlled Unraid reboot during a maintenance window.
5. Confirm PostgreSQL and Redis are ready, migration is either successfully completed or already satisfied, website and worker are healthy, and public `/ready` returns `200`.

The current migration command has no bounded wait for external PostgreSQL. If Compose Manager starts the stack before PostgreSQL is ready, migration can exit nonzero and prevent the dependent services from starting. Wait for the external services, inspect the migration error, and run Compose Up again. Dependency retry hardening remains tracked in [PROJECT_STATUS.md](PROJECT_STATUS.md).

If reliable stack ordering cannot be guaranteed, leave Compose Manager stack Autostart disabled and rely on the long-running containers' restart policies, with a documented manual Compose Up recovery after the external services are healthy. Do not claim reboot readiness until the controlled test has passed.

## 12. Troubleshooting

### Stack shows `0/0` or no containers

From the Compose Manager project directory, validate the exact files the plugin is using:

```bash
docker compose --env-file .env --file compose.yaml config --services
```

Expect `creative-ai-migrate`, `creative-ai`, and `creative-ai-worker`. If they are absent, open the stack editor and check the **Editing file** path. A normal project uses `/boot/config/plugins/compose.manager/projects/STACK_NAME/compose.yaml`; an external path must already exist and contain the intended file.

### Compose Pull closes without activity

Use Compose Up. The normal deployment flow does not require a separate Pull because Up pulls a missing pinned digest before creation. Verify the image reference was saved in the stack's actual `.env` tab.

### Migration exits nonzero

Inspect the one-shot container before changing anything:

```bash
docker compose --env-file .env --file compose.yaml ps --all
docker compose --env-file .env --file compose.yaml logs creative-ai-migrate
```

Common causes are a missing database, wrong role/password, trailing whitespace in a database name, an unreachable external service, or reserved URL characters that were not encoded. Fix the underlying configuration and run Compose Up again. Do not remove the migration dependency.

### Website is healthy but unstyled

Request the homepage through its real HTTPS hostname and inspect the generated asset URLs. HTTP assets on an HTTPS page indicate incorrect forwarded-scheme trust. Confirm the proxy's source address in the application access log, set the smallest correct `TRUSTED_PROXIES` value, recreate the stack, and retest.

### Stack shows `partial (2/3)`

This is normal when migration exited with code `0` and both long-running services are healthy. It is not normal when migration exited nonzero or either running service is unhealthy.

### Artwork exists but its preview is blank

Confirm the original media file is reachable, then inspect application logs and the artwork's display/thumbnail variant state. The current known variant-recovery gap is documented in [PROJECT_STATUS.md](PROJECT_STATUS.md); do not diagnose it by restoring the retired root static-site folders.
