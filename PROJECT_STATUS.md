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
- Album publication cascades to current and future tracks, including a one-time reconciliation for releases published before the cascade existed. The persistent player exposes separately grouped album and playlist choices and preserves uninterrupted playback across public-page navigation.
- The former public backend is retained temporarily as an unrouted rollback target.

The exact deployed image references and infrastructure values live in Compose Manager and ignored local environment files. Inspect them only when required and never print their secret-bearing contents.

## Known follow-up work

1. **Artwork variant rollout:** queued generation, fallback previews, operator-visible status/errors, retry handling, cleanup, and an idempotent regeneration command are implemented in the current maintenance branch. On its next staging rollout, run `creative-ai:artwork-variants:regenerate`, wait for the worker to settle all existing records, and verify original/display/thumbnail responses before promotion.
2. **Administration background:** the Filament stylesheet references a legacy `/storage/artworks/display/0200.jpg` background that is absent on a clean installation. Replace it with a bundled or configurable asset.
3. **Reboot readiness:** web and worker services use `restart: unless-stopped`, but Compose Manager stack autostart and external PostgreSQL/Redis startup ordering have not been proven with a controlled reboot. The migration job also has no bounded dependency retry.
4. **Credential maintenance:** rotate any credentials previously exposed during setup. Separate Redis ACL users remain preferred; the current shared-password rotation and durable ACL setup were deliberately deferred.
5. **Runtime parity:** local development currently uses Redis 7 while the deployed shared service uses Redis 8. Align and pin the supported runtime version in a dedicated maintenance change.

These items are not launch blockers for the current personal site, but a future session should review this list before expanding traffic, upload sizes, or operational automation.
