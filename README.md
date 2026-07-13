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

## Journal editorial workflow

Journal posts move through explicit **Draft**, **Ready**, **Scheduled**, and **Published** states. Drafts can hold incomplete writing plus a private editorial brief and private notes. The readiness check reports publication blockers and quality warnings, and the administrator preview renders the last saved article without making it public, canonical, cacheable, or indexable.

Scheduling is query-driven: a valid Scheduled post becomes publicly effective when its stored UTC publication time arrives, without a scheduler process or a state-changing page request. The same model predicate controls Journal pages, homepage cards, private cover delivery, RSS, sitemap entries, and post structured data. Publishing and unpublishing are explicit atomic actions; editing ordinary content never changes publication state.

The lifecycle migration keeps the former `published` and `published_at` fields synchronized as a temporary old-image compatibility mirror. Leave the expand-compatible migration applied during an image rollback, and treat Journal publication controls as read-only until the current image is restored. An older image does not know how to update the new workflow state.

Journal stories can also carry shared archive tags and an explicit ordered set of artwork, collections, albums, playlists, and tracks. Administrators manage these on the post's separate **Connections** page, where draft sources remain visible for planning but are labelled private. Public story pages, reciprocal media links, structured data, shared `/tags/{slug}` archives, and sitemap entries all use the canonical publication checks on both sides, so a public story never exposes a draft or future source and private stories never appear on media pages. Removing a connection never deletes its source record or files.

The connected-stories migration is additive: it creates only relation tables and can remain applied during an image rollback. An older image simply ignores those relations. Avoid editing connections while that image is running because it cannot display or maintain them.

The administrator planning loop starts on **Story opportunities**, a bounded work queue of currently public artwork, collections, albums, playlists, and tracks that have no Journal connection yet. Any public source can create a private Draft from its resource action without changing the source or its publication state. An optional active Journal template supplies a reusable outline, private brief, and public default tags; only the inert `{{ source_title }}` and `{{ source_type }}` placeholders are replaced. Optional source-tag copying follows the same public-only rules as the shared archive, and every generated post remains Draft until it passes the normal human-controlled editorial workflow.

The planning-tools migration adds template tables and inverse indexes for the opportunity queries. It is additive and can stay applied during an image rollback; an older image ignores templates and the extra indexes. Drafts already created by the newer image remain ordinary Journal drafts and connections, so avoid planning and connection edits until the current image is restored.

Journal content edits create immutable, deduplicated revisions containing only the public writing/SEO allowlist plus read-only snapshots of shared tags and ordered media. The migration gives every existing post a baseline before later edits can replace its writing, and workflow, slug, trash, and restore actions add labelled audit entries. Private briefs, notes, workflow state, schedules, publication flags, featured placement, and durable slugs are never copied into a restorable snapshot. The **History** workspace can preview the writing and connection context, while **Restore safe fields** applies only title, excerpt, body, cover source/alternative text, and SEO fields. Current connections and every excluded field stay untouched. Historical covers are checked against their recorded byte length and SHA-256 digest, and missing or changed bytes fail the restore atomically.

Edit and restore actions use row locks plus saved-state fingerprints. A stale editor tab cannot overwrite newer public or private editorial fields, and a stale History tab cannot restore over newer public writing without first reloading. Restoring into any non-canonical Draft state also reruns publication readiness, so incomplete historical writing cannot become public through a current or future schedule.

Changing a Journal slug reserves the former slug as a direct permanent redirect to that post's current canonical URL; later slug changes never create redirect chains. Redirects recheck the target on every request and return `404` while it is private, future, trashed, or gone. Moving a post to Trash immediately withdraws it and clears its workflow to Draft. Restoring it never republishes or reschedules it. Permanent deletion removes the database post/history/connections but retains private cover files and permanent slug tombstones, preventing a former public URL from being reassigned accidentally.

The history-safety migration adds `deleted_at`, revision and redirect tables and performs the one-time baseline backfill without invoking application model events. It is expand-compatible for an image rollback: an older image ignores the new tables and sees trashed rows only as private Drafts because the trash service clears their legacy publication state. Leave the migration applied, avoid all Journal editing under the older image, and restore the current image before changing slugs, trash, history, connections, or publication.

## Journal AI safety foundation

Journal AI work is recorded as immutable, administrator-owned attempts instead of being written directly into a post. Each attempt pins the provider, the separate Journal text model, the normalized endpoint, credential identity, prompt, schema, selected source revision, exact outbound context manifest, and source fingerprints. Endpoint or credential changes invalidate an already queued attempt instead of silently redirecting private writing. External destinations require HTTPS and provider redirects are rejected rather than replaying acknowledged context to another host. The provider destination and exact field selection must be acknowledged for every request; private editorial brief and notes are excluded unless separately selected, and connected media contributes only explicitly selected, effectively public, text metadata. Safe provider request identifiers and token counts are retained when available, but raw provider responses are not.

The foundation uses versioned operation contracts for directions, outlines, editorial feedback, selected-passage improvement, and metadata suggestions. Provider-side structured output is only a steering mechanism: application validation rejects unknown fields, unsafe Markdown links, malformed UTF-8, control characters, and oversized or structurally invalid output before storing a normalized result. Provider envelopes are streamed through a fixed byte cap before JSON decoding. A pinned Ollama context must conservatively fit the complete trusted prompt, acknowledged input, portable schema, framing reserve, and maximum output before the request can be acknowledged or sent; the application never silently enlarges or truncates it. Provider tools are disabled, raw responses and exception bodies are not retained, and editorial review identifies claims that still require human verification.

This slice is deliberately dormant: it provides the audited data, provider, privacy, queue, cancellation, retry, priority, and stale-result boundaries, but no administrator controls for starting a run or applying a result. A ready result cannot edit writing, tags, durable URLs, workflow state, scheduling, or publication, and unapplied results do not alter any public response. The human review and granular application workspace belongs to the following Journal AI assistant slice.

The additive `post_ai_runs` table can remain in place during an image rollback. Before restoring an image that does not contain the Journal AI job class, keep the current worker running until every queued Journal AI payload has been consumed or cancelled and consumed as a no-op; then stop the stack. An older image ignores the table, but Journal AI administration must remain paused until the current image returns.

## Music library workflow

The track library supports single-file creation and multi-file audio import. Imports read embedded audio metadata (including title, artist, album, album artist, genre, year, disc/track number, duration, and embedded cover art) and fall back to common filename patterns when tags are absent. Explicitly entered values are never overwritten. Bulk imports default to not being published as standalone tracks and retain an operator-visible metadata review state. Automatically detected albums begin as drafts; forcing files into an already-published album makes those tracks immediately publicly playable through that album.

Albums are first-class records with intrinsic disc/track ordering, shared release metadata, embedded-cover fallback, and optional artwork-gallery covers. Published albums appear as listening sessions before playlists. Track and album actions can rank published artwork by shared music/artwork tags and show the matching tags and score before applying a cover.

Manual playlists use a drag-sortable track sequence with duplicate prevention. Smart playlists can combine tags, artist, album, duration, release year, cover availability, analysis health, recency, ordering, and result limits. A smart result can be frozen as a manual snapshot. The playlist library mirrors artwork Collections with `Generate/Refresh automatic`, `Create with AI`, and `New playlist`: managed automatic playlists are derived from recurring genres and moods, while manual and custom smart playlists are preserved.

Album identity is normalized from embedded album title and album-artist metadata, so tracks from one release—including compilations with different per-track artists—are grouped into one ordered album during import. The Albums library can also run `Organize from metadata` to consolidate older imports; curated records with descriptions or selected artwork are preserved.

Publishing an album makes its complete track listing publicly playable without publishing each member as a standalone track. A track's own publication control is reserved for singles or tracks that should also appear separately in discovery. Unpublishing an album withdraws album-only tracks; a deliberately published standalone track remains available. The public player groups its source selector into explicit Albums, Playlists, and Tracks sections.

The forward migration that introduces this model gives tracks an explicit standalone publication flag and date. Every existing album member starts album-only because the former cascade did not retain enough information to distinguish inherited publication from an intentional single; re-enable only exceptional album tracks that should also be listed independently. The former publication columns remain synchronized as a one-release compatibility mirror so the previous image can still serve the same effective audio during an image-only rollback. Leave this expand-compatible migration applied and keep music administration read-only until the current image is restored.

Audio uploads queue FFmpeg/FFprobe analysis for codec, bitrate, sample rate, channel count, duration, content hashes, duplicate warnings, and compact waveform data. Library health is visible and retryable from individual, selected, or whole-library actions. Retrying clears stale health state, and the track table refreshes every five seconds so queued/processing results settle without a manual page reload. Cover health uses the configured track or album choice even while an album is still a draft; the public cover URL remains publication-gated. Choosing **No cover** is treated as an intentional opt-out. Health results are stored snapshots, so rerun **Analyze audio health** after changing a cover choice or to clear an older false warning. Albums expose their embedded cover preview and can explicitly prefer embedded, gallery, automatic, or no cover; embedded covers can be imported into the artwork library as drafts.

The public `/music` listening room is release-centric: albums own their ordered track listings, while a separate singles section contains only deliberately standalone tracks. Searches still match tracks inside public albums and return the containing release, avoiding dozens of duplicate rows as the library grows. Dedicated album and track pages remain available, and shared tags connect playable tracks to fitting artwork. The site-wide player continues uninterrupted across public collection, artwork, music, and journal navigation; it also persists the selected track, position, volume, shuffle/repeat settings, and queue across full reloads and displays precomputed waveforms before playback.

The track library mirrors the artwork review workflow with `Analyze pending`, `Apply ready`, `Bulk upload`, and create actions. Its default collapsible album grouping and album filter keep large imports manageable. Grouped mode shows every matching album regardless of a previously saved track page size; disabling grouping restores normal track-level pagination. AI analysis is queued on the `ai` queue and stages tag suggestions for review unless automatic application is explicitly selected. The table also supports selected-track analysis/application, best-match artwork assignment, metadata review, and deletion; artwork assignment preserves existing manual covers unless replacement is explicitly enabled.

## Releases and Unraid

Pull requests run application tests and a real PostgreSQL/Redis stack smoke test. The push to `main` created by a successful merge publishes and smoke-tests one immutable GHCR digest. Copy the complete `ghcr.io/...@sha256:...` reference into staging; after testing, approve and copy that same digest into production.

Unraid uses Compose Manager and does not run a deployment script. See [UNRAID.md](UNRAID.md) for stack creation, updates, backups, promotion, and rollback.

## License and credits

The retired static presentation was based on Multiverse by HTML5 UP, released under the Creative Commons Attribution 3.0 license. Its source remains available in Git history, and the attribution is retained in [LICENSE.txt](LICENSE.txt).
