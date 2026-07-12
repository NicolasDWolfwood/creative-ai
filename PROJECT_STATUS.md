# Creative-Ai project status

Last reviewed: 2026-07-12

Keep this file free of credentials, internal addresses, and other machine-specific values. Update it after material deployment or architecture changes.

## Verified rollout state

- The Laravel/Filament application is the active codebase; the retired static-site files have been removed from the current branch and remain recoverable from Git history.
- Local development runs through the root Docker Compose stack with PostgreSQL, Redis, migrations, the website, and the queue worker.
- Pull requests run tests and an isolated stack smoke test.
- A merge-triggered push to `main` publishes and smoke-tests one immutable GHCR image.
- Staging and production use separate Compose Manager stacks and the same approved image digest.
- The public reverse proxy, canonical-host redirect, HTTPS assets, indexing policy, readiness endpoint, and administrator login were verified during cutover.
- Persistent storage and database behavior were verified, and scheduled backups are managed outside this repository.
- PHP, Livewire, and the track form share a tested 100 MiB music-upload limit, with a 105 MiB PHP request allowance for multipart overhead.
- Music imports extract embedded metadata and covers with filename fallback, albums are first-class ordered releases, cover suggestions use explainable shared-tag ranking, and manual playlists have drag-sortable sequencing.
- Music includes background technical analysis, waveform and duplicate/health data, rich smart-playlist rules and snapshots, explicit embedded-cover handling, public album/track discovery with cross-media recommendations, and a persistent browser queue.
- Playlists now mirror automatic artwork collections with managed tag/theme generation and AI-assisted creation. Normalized album matching groups multi-artist tracks by release metadata during upload, with an administrator repair action for older imports.
- The former public backend is retained temporarily as an unrouted rollback target.

The exact deployed image references and infrastructure values live in Compose Manager and ignored local environment files. Inspect them only when required and never print their secret-bearing contents.

## Current development checkpoint

- Pull request #11 merged the release-centric album/track work into `main`. Its merge-triggered workflow published an immutable GHCR candidate and successfully smoke-tested that exact digest. It has not been deployed to staging or production.
- Album publication now grants inherited playability to current and future member tracks without listing them as standalone releases. Album pages own their ordered track listings; direct track/audio access, public playlists, recommendations, and smart-playlist eligibility use effective album-or-track availability. The persistent player exposes separately grouped album, playlist, and standalone-track choices.
- The administrator track library defaults to collapsible album groups. Grouped mode deliberately loads every matching track so an album cannot be hidden by a saved per-page limit; ordinary pagination returns when grouping is disabled. Audio-health results poll every five seconds, retries clear stale state, and a draft album's configured embedded/gallery cover is evaluated independently from its intentionally private public URL.
- The expand-compatible migration adds explicit standalone publication fields, normalizes existing album members to album-only, changes the legacy default, and maintains the legacy publication fields as a compatibility mirror for a temporary old-image rollback. Do not perform music publication edits while an older image is restored.
- Branch `feature/public-artwork-archive` is the next reviewable roadmap slice. It adds durable artwork pages with process notes, prompt/context presentation, deterministic adjacent navigation, reciprocal music recommendations, artwork SEO and structured data, artwork sitemap coverage, and stable cursor-based gallery loading with an accessible link fallback.
- Local validation for the artwork slice passed: 125 application tests with 787 assertions; Pint across 148 PHP files; frontend production build; both tracked Unraid Compose examples; and a rebuilt PostgreSQL/Redis development stack with the new migration applied and healthy web/worker services.
- A real multi-track draft album with an embedded cover was reanalyzed after the health fix. All 14 jobs completed, the table advanced without a manual refresh, and all 14 tracks finished healthy rather than retaining the false missing-cover warning.
- The remaining album/track release gate is authorized staging deployment and focused acceptance using the already verified immutable candidate. Do not deploy or promote it without separate authorization, and do not promote to production until that digest is accepted on staging.

## Known follow-up work

1. **Release and staging acceptance:** required TOTP MFA, private publication-aware media delivery, scheduled-publication enforcement, security headers, accessible navigation/lightbox/player behavior, bundled admin visuals, private-media migration, and artwork variants passed the earlier development and staging/test acceptance. The album-first candidate passed PR, CI, immutable publication, and exact-digest smoke testing; it still needs separately authorized staging deployment and focused acceptance before approval.
2. **Album-track normalization and stored health:** the former cascade did not record whether an album member was intentionally released as a single. The new forward migration therefore resets every existing album member to album-only. After the next staging migration, review the standalone track list and explicitly re-enable only exceptional album tracks that should also appear as singles. Rerun **Analyze audio health** for album tracks carrying an older false missing-cover warning and verify inherited embedded/gallery covers and explicit no-cover choices settle correctly.
3. **Reboot readiness:** web and worker services use `restart: unless-stopped`, but Compose Manager stack autostart and external PostgreSQL/Redis startup ordering have not been proven with a controlled reboot. The migration job also has no bounded dependency retry.
4. **Credential maintenance:** rotate any credentials previously exposed during setup. Separate Redis ACL users remain preferred; the current shared-password rotation and durable ACL setup were deliberately deferred.
5. **Runtime parity:** local development currently uses Redis 7 while the deployed shared service uses Redis 8. Align and pin the supported runtime version in a dedicated maintenance change.

Items 1 and 2 are required before the first production promotion. Items 3 through 5 are recorded operational follow-ups rather than launch blockers for the current personal site.
