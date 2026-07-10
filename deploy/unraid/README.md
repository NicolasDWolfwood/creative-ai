# Unraid Compose Manager bundle

Use [compose.yaml](compose.yaml) for both website stacks:

- staging with [staging.env.example](staging.env.example)
- production with [production.env.example](production.env.example)

Paste the YAML and matching environment template into the Unraid Compose Manager tabs, replace every placeholder locally, and pin a complete GHCR `@sha256` image reference.

For every release use:

```text
Compose Down -> edit complete image reference -> Compose Up
```

Compose Up pulls the exact digest when it is not already present. The migration container must exit `0`, the website must become healthy, and only then will the worker start. Compose Manager may describe the settled stack as partial because the completed migration container is intentionally stopped. No deployment script or host port is used.

See [UNRAID.md](../../UNRAID.md) for initial databases, administrator recovery, staging acceptance, production promotion, backups, proxy cutover, and rollback.
