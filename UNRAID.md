# Creative-Ai on Unraid with Compose Manager Plus

This repo now contains a Dockerized Laravel + Filament rebuild in `site/`.

## Compose Manager Plus

1. Create a new stack named `creative-ai`.
2. Paste `compose.yaml` into the Compose tab.
3. Paste `unraid.env.example` into the `.env` tab and fill in real values.
4. Set `DB_HOST` and `REDIS_HOST` to either the container names on a shared Docker network or your Unraid host IP if those services are exposed on host ports.
5. If Redis requires authentication, set `REDIS_PASSWORD` to the Redis password. Leave `REDIS_USERNAME=null` unless your Redis container uses ACL users.
6. Start with `RUN_MIGRATIONS=true` for the first boot.
7. Set `RUN_LEGACY_IMPORT=true` once to copy the current `images/` and `music/` archive into persistent Laravel storage.
8. Set both flags back to `false` after the first successful run. The `creative-ai-worker` service always forces these flags off to avoid duplicate migration/import runs.

Generate an app key before production use:

```bash
docker compose run --rm creative-ai php artisan key:generate --show
```

Put the generated value in the `.env` tab as `APP_KEY=base64:...`.

Set `ADMIN_EMAIL` in the `.env` tab to the email you want to use, then create the admin user after migrations:

```bash
docker compose exec creative-ai php artisan creative-ai:create-admin
```

If `ADMIN_PASSWORD` is blank, the command prints a generated password once.

## Persistent data

The stack maps `/mnt/user/appdata/creative-ai/storage` to `/app/storage`. Uploaded images, generated thumbnails, cached views, and logs stay there across container rebuilds.

## AI artwork metadata

The app supports local Ollama plus OpenAI, Claude by Anthropic, and Z.AI cloud APIs. It sends a downscaled, re-encoded JPEG analysis image instead of the original upload. Ollama is the recommended default for this Unraid deployment:

```env
AI_PROVIDER=ollama
OLLAMA_BASE_URL=http://192.168.1.176:11434
OLLAMA_MODEL=qwen3.5:latest
OLLAMA_REQUEST_TIMEOUT=150
OLLAMA_CONTEXT_LENGTH=4096
OLLAMA_KEEP_ALIVE=5m
AI_AUTO_ANALYZE_UPLOADS=false
AI_IMAGE_MAX_WIDTH=768
AI_IMAGE_JPEG_QUALITY=72
```

These values are first-boot fallbacks. After startup, use `Admin > AI & Automation > AI providers` to select a provider, configure its server and model, compare model capabilities, and tune image analysis. Saved cloud API keys are encrypted with `APP_KEY` before storage in Postgres, are never displayed again, and take effect on the next queued job without restarting the stack.

Cloud keys can be entered in the admin page or kept as recovery fallbacks in the Compose Manager Plus `.env` tab:

```env
OPENAI_API_KEY=
OPENAI_MODEL=gpt-5.4-mini
```

OpenAI model guidance:

- `gpt-5.4-mini`: default for artwork metadata; good quality/cost balance for downscaled image analysis.
- `gpt-5.5`: quality upgrade for a small batch of important artwork descriptions.
- `gpt-5-mini`: lower-cost fallback for broad bulk tagging.

Keep `AI_AUTO_ANALYZE_UPLOADS=false` while testing. Use the Filament `Analyze with AI` row action or `Analyze selected with AI` bulk action first, then apply suggestions after review. The Filament `AI Queue` page shows queued, processing, and failed jobs; queued items can be canceled or moved to the high-priority queue.

Database credentials, Redis credentials, `APP_KEY`, ports, storage mounts, the public URL, and API secrets remain deployment-managed values. They are shown as read-only status information in the admin because changing them from the running web process can break connectivity or expose secrets.

## Existing Postgres and Redis

The compose file does not create Postgres or Redis. It expects your existing Postgres 17 and Redis containers to be reachable through the `.env` values. Create the `creative_ai` database and user in Postgres before running migrations. For a password-protected Redis container, set `REDIS_PASSWORD` in the Compose Manager Plus `.env` tab. If your Redis image uses ACL users, set `REDIS_USERNAME`; otherwise keep it as `null`.

## Legacy site

The current static root remains untouched during development. The Docker image copies `images/` and `music/` into `/app/legacy` so `creative-ai:import-legacy` can import the existing 200 images and 11 music tracks.
