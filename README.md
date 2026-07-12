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

For a fresh maintenance session, start with [AGENTS.md](AGENTS.md) and [PROJECT_STATUS.md](PROJECT_STATUS.md). They point to the source-of-truth runbooks and list known follow-up work without exposing machine-specific values.

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

The website port binds to `127.0.0.1` by default. When a reverse proxy on another host or container must reach the development stack, set `DEV_HTTP_BIND=0.0.0.0`, keep `DEV_HTTP_PORT` on the intended development port, and point the proxy upstream at the Docker host's LAN address and that port. Set `APP_URL` and `TRUSTED_HOSTS` to the HTTPS development hostname, trust only the proxy address seen by Laravel in `TRUSTED_PROXIES`, and enable `SESSION_SECURE_COOKIE`. Do not expose the development port directly to the internet; keep public access behind the reverse proxy.

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

Administration uploads accept music tracks up to 100 MiB. The image bakes in matching PHP and Livewire limits; any reverse proxy in front of the container must allow request bodies of at least 105 MiB as well.

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

## Routine code change

Start each change from an up-to-date `main` branch and work on a short-lived branch:

```bash
git switch main
git pull --ff-only
git switch -c feature/describe-the-change
```

Make the change, rebuild the local stack, and run the relevant tests. Review the diff before committing:

```bash
git status --short
git diff --check
git add --all
git commit -m "Describe the change"
git push --set-upstream origin HEAD
```

Open a pull request, wait for the final **CI result** check, review the proposed changes, and merge only when it is green. After merging, wait for the new workflow run triggered by the push to `main`. That run publishes and smoke-tests the staging candidate and prints the complete immutable image reference in its Actions summary.

A manually dispatched **Test and publish release candidate** workflow validates the repository but does not publish a staging image. Image publication occurs only on a push to `main`.

Protect `main` with a GitHub ruleset that requires a pull request and the final **CI result** status check. Keep direct pushes disabled except for deliberate administrator recovery.

## First administrator

Administrator identity and passwords live only in PostgreSQL. No administrator values belong in `.env`.

Create the first administrator interactively:

```bash
docker compose exec --user www-data creative-ai php artisan creative-ai:admin:create admin@example.test
```

The administrator panel requires authenticator-app MFA. On the first login, complete the TOTP setup and store the one-time recovery codes in the same protected password manager used for the administrator credential.

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

## Artwork image variants

New artwork uploads queue display and thumbnail generation on the `default` queue. Until that job completes, public and administrator previews fall back to the original image. Failed jobs are visible in the artwork table and can be retried there.

After deploying the variant-tracking migration to an environment that already contains artwork, queue an idempotent backfill from the website container:

```bash
gosu www-data php artisan creative-ai:artwork-variants:regenerate
```

Artwork originals, generated variants, track audio, embedded album covers, and journal covers are stored privately. Public media is streamed through publication-aware application routes; administrators can preview drafts while anonymous visitors receive `404` responses. After first deploying this storage change to an environment with existing media, back up storage and run the idempotent migration command from the website container:

```bash
gosu www-data php artisan creative-ai:media:privatize --dry-run
gosu www-data php artisan creative-ai:media:privatize
```

The command copies each referenced file, verifies its SHA-256 hash, and only then removes the old public copy. A conflicting private/public pair is left untouched and reported as a failure.

The normal worker consumes those jobs. Run the command with `--sync` only for deliberate foreground maintenance. It exits unsuccessfully when an original file is missing and records that failure on the artwork row; rerunning it leaves completed variants unchanged and recovers queued or processing work after the configurable stale interval.

## Music library workflow

The track library supports single-file creation and multi-file audio import. Imports read embedded audio metadata (including title, artist, album, album artist, genre, year, disc/track number, duration, and embedded cover art) and fall back to common filename patterns when tags are absent. Explicitly entered values are never overwritten. Bulk imports default to unpublished tracks with an operator-visible metadata review state.

Albums are first-class records with intrinsic disc/track ordering, shared release metadata, embedded-cover fallback, and optional artwork-gallery covers. Published albums appear as listening sessions before playlists. Track and album actions can rank published artwork by shared music/artwork tags and show the matching tags and score before applying a cover.

Manual playlists use a drag-sortable track sequence with duplicate prevention. Smart playlists can combine tags, artist, album, duration, release year, cover availability, analysis health, recency, ordering, and result limits. A smart result can be frozen as a manual snapshot. The playlist library mirrors artwork Collections with `Generate/Refresh automatic`, `Create with AI`, and `New playlist`: managed automatic playlists are derived from recurring genres and moods, while manual and custom smart playlists are preserved.

Album identity is normalized from embedded album title and album-artist metadata, so tracks from one release—including compilations with different per-track artists—are grouped into one ordered album during import. The Albums library can also run `Organize from metadata` to consolidate older imports; curated records with descriptions or selected artwork are preserved.

Publishing an album publishes every current track in that album, and tracks subsequently assigned to a published album inherit its public state. Unpublishing the album leaves track publication unchanged so those tracks can remain available through playlists. The public player groups its source selector into explicit Albums and Playlists sections.

Audio uploads queue FFmpeg/FFprobe analysis for codec, bitrate, sample rate, channel count, duration, content hashes, duplicate warnings, and compact waveform data. Library health is visible and retryable from individual, selected, or whole-library actions. Albums expose their embedded cover preview and can explicitly prefer embedded, gallery, automatic, or no cover; embedded covers can be imported into the artwork library as drafts.

The public `/music` listening room provides searchable track, album, and playlist discovery with dedicated album and track pages. Shared tags connect tracks to fitting artwork. The site-wide player continues uninterrupted across public collection, artwork, music, and journal navigation; it also persists the selected track, position, volume, shuffle/repeat settings, and queue across full reloads and displays precomputed waveforms before playback.

The track library mirrors the artwork review workflow with `Analyze pending`, `Apply ready`, `Bulk upload`, and create actions. AI analysis is queued on the `ai` queue and stages tag suggestions for review unless automatic application is explicitly selected. The table also supports selected-track analysis/application, best-match artwork assignment, metadata review, and deletion; artwork assignment preserves existing manual covers unless replacement is explicitly enabled.

## Releases and Unraid

Pull requests run application tests and a real PostgreSQL/Redis stack smoke test. The push to `main` created by a successful merge publishes and smoke-tests one immutable GHCR digest. Copy the complete `ghcr.io/...@sha256:...` reference into staging; after testing, approve and copy that same digest into production.

Unraid uses Compose Manager and does not run a deployment script. See [UNRAID.md](UNRAID.md) for stack creation, updates, backups, promotion, and rollback.

## License and credits

The retired static presentation was based on Multiverse by HTML5 UP, released under the Creative Commons Attribution 3.0 license. Its source remains available in Git history, and the attribution is retained in [LICENSE.txt](LICENSE.txt).
