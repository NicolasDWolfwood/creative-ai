# Creative-Ai project status

Last reviewed: 2026-07-11

Keep this file free of credentials, internal addresses, and other machine-specific values. Update it after material deployment or architecture changes.

## Verified rollout state

- The Laravel/Filament application is the active codebase; the retired static-site files have been removed from the current branch and remain recoverable from Git history.
- Local development runs through the root Docker Compose stack with PostgreSQL, Redis, migrations, the website, and the queue worker.
- Pull requests run tests and an isolated stack smoke test.
- A merge-triggered push to `main` publishes and smoke-tests one immutable GHCR image.
- Staging and production use separate Compose Manager stacks and the same approved image digest.
- The public reverse proxy, canonical-host redirect, HTTPS assets, indexing policy, readiness endpoint, and administrator login were verified during cutover.
- Persistent storage and database behavior were verified, and scheduled backups are managed outside this repository.
- The former public backend is retained temporarily as an unrouted rollback target.

The exact deployed image references and infrastructure values live in Compose Manager and ignored local environment files. Inspect them only when required and never print their secret-bearing contents.

## Known follow-up work

1. **Upload limits:** the container currently uses PHP defaults (`upload_max_filesize=2M`, `post_max_size=8M`, `memory_limit=128M`) while the administration UI permits images up to 25 MiB and tracks up to 100 MiB. Add a tracked PHP configuration, matching reverse-proxy body/time limits, and upload tests before relying on those advertised limits.
2. **Artwork variants:** image-variant errors are logged without surfacing an administration error. Missing `display_path` or `thumb_path` values can therefore produce blank previews. Add integration coverage, an operator-visible failure state, a fallback preview, and an idempotent variant-regeneration command.
3. **Administration background:** the Filament stylesheet references a legacy `/storage/artworks/display/0200.jpg` background that is absent on a clean installation. Replace it with a bundled or configurable asset.
4. **Reboot readiness:** web and worker services use `restart: unless-stopped`, but Compose Manager stack autostart and external PostgreSQL/Redis startup ordering have not been proven with a controlled reboot. The migration job also has no bounded dependency retry.
5. **Credential maintenance:** rotate any credentials previously exposed during setup. Separate Redis ACL users remain preferred; the current shared-password rotation and durable ACL setup were deliberately deferred.
6. **Runtime parity:** local development currently uses Redis 7 while the deployed shared service uses Redis 8. Align and pin the supported runtime version in a dedicated maintenance change.

These items are not launch blockers for the current personal site, but a future session should review this list before expanding traffic, upload sizes, or operational automation.
