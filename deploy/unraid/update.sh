#!/usr/bin/env bash
set -Eeuo pipefail

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
compose_file="${COMPOSE_FILE:-${script_dir}/compose.yaml}"
env_file="${ENV_FILE:-${script_dir}/.env}"
assume_yes=false

if [[ "${1:-}" == "--yes" ]]; then
    assume_yes=true
elif [[ -n "${1:-}" ]]; then
    echo "Usage: bash $0 [--yes]" >&2
    exit 64
fi

if [[ ! -f "$compose_file" ]]; then
    echo "Compose file not found: $compose_file" >&2
    exit 66
fi

if [[ ! -f "$env_file" ]]; then
    echo "Environment file not found: $env_file" >&2
    exit 66
fi

compose_args=(--env-file "$env_file" --file "$compose_file")
for override_file in \
    "${script_dir}/compose.override.yaml" \
    "${script_dir}/compose.override.yml" \
    "${script_dir}/docker-compose.override.yaml" \
    "${script_dir}/docker-compose.override.yml"; do
    if [[ -f "$override_file" ]]; then
        compose_args+=(--file "$override_file")
    fi
done

compose=(docker compose "${compose_args[@]}")
"${compose[@]}" config --quiet

deployment_env="$(sed -n 's/^DEPLOYMENT_ENV=//p' "$env_file" | tail -n 1 | tr -d '\r"' | tr -d "'")"
case "$deployment_env" in
    staging|production) ;;
    *)
        echo "DEPLOYMENT_ENV must explicitly be staging or production." >&2
        exit 65
        ;;
esac

resolved_project="$("${compose[@]}" config | sed -n 's/^name: //p' | tail -n 1)"
expected_project="creative-ai-${deployment_env}"
if [[ "$resolved_project" != "$expected_project" ]]; then
    echo "COMPOSE_PROJECT_NAME must resolve to ${expected_project}; got ${resolved_project:-nothing}." >&2
    exit 65
fi

if grep -Eiq '^[A-Za-z_][A-Za-z0-9_]*=.*(replace[-_ ]?me|change[-_ ]?me)' "$env_file"; then
    echo "The environment file still contains a replace-me/change-me assignment." >&2
    exit 65
fi

app_key="$(sed -n 's/^APP_KEY=//p' "$env_file" | tail -n 1 | tr -d '\r"' | tr -d "'")"
if [[ ! "$app_key" =~ ^base64:[A-Za-z0-9+/]{43}=$ ]]; then
    echo "APP_KEY must be a valid base64-encoded 32-byte Laravel key." >&2
    exit 65
fi

if ! command -v flock >/dev/null 2>&1; then
    echo "The flock command is required to prevent concurrent deployments." >&2
    exit 69
fi

exec 9>"/var/lock/${resolved_project}.update.lock"
if ! flock --nonblock 9; then
    echo "Another ${resolved_project} update is already running." >&2
    exit 75
fi

mapfile -t configured_images < <("${compose[@]}" config --images | sort -u)
if [[ "${#configured_images[@]}" -ne 1 ]]; then
    echo "Web and worker must use exactly one identical image reference." >&2
    exit 65
fi
configured_image_ref="${configured_images[0]}"
if [[ "$configured_image_ref" == *@* ]]; then
    configured_repository="${configured_image_ref%@*}"
else
    configured_repository="${configured_image_ref%:*}"
fi

if [[ "$deployment_env" == "production" ]]; then
    if [[ "$assume_yes" == true ]]; then
        echo "--yes is deliberately disabled for production deployments." >&2
        exit 65
    fi

    if [[ ! "$configured_image_ref" =~ ^.+@sha256:[0-9a-f]{64}$ ]]; then
        echo "Production CREATIVE_AI_IMAGE must be pinned to an @sha256 digest." >&2
        exit 65
    fi

    if [[ ! -t 0 ]]; then
        echo "Production update requires interactive backup confirmation." >&2
        exit 65
    fi

    read -r -p "Confirm a consistent PostgreSQL, storage, env/APP_KEY, and proxy backup is verified [y/N]: " answer
    if [[ "$answer" != "y" && "$answer" != "Y" ]]; then
        echo "Production update cancelled."
        exit 0
    fi
fi

previous_web_id="$("${compose[@]}" ps --quiet creative-ai 2>/dev/null || true)"
previous_worker_id="$("${compose[@]}" ps --quiet creative-ai-worker 2>/dev/null || true)"
previous_image_id=""
previous_image_ref=""

if [[ -n "$previous_web_id" ]]; then
    previous_image_id="$(docker inspect --format '{{.Image}}' "$previous_web_id")"
    mapfile -t previous_repo_digests < <(docker image inspect --format '{{range .RepoDigests}}{{println .}}{{end}}' "$previous_image_id" 2>/dev/null || true)
    for repo_digest in "${previous_repo_digests[@]}"; do
        if [[ "$repo_digest" == "${configured_repository}@sha256:"* ]]; then
            previous_image_ref="$repo_digest"
            break
        fi
    done
fi

echo "Pulling the configured image..."
"${compose[@]}" pull creative-ai creative-ai-worker

resolved_image_ref=""
if [[ "$deployment_env" == "production" ]]; then
    if ! docker image inspect "$configured_image_ref" >/dev/null 2>&1; then
        echo "The approved production digest was not available after pulling it." >&2
        exit 1
    fi
    resolved_image_ref="$configured_image_ref"
else
    mapfile -t resolved_repo_digests < <(docker image inspect --format '{{range .RepoDigests}}{{println .}}{{end}}' "$configured_image_ref" 2>/dev/null || true)
    for repo_digest in "${resolved_repo_digests[@]}"; do
        if [[ "$repo_digest" == "${configured_repository}@sha256:"* ]]; then
            resolved_image_ref="$repo_digest"
            break
        fi
    done
fi
if [[ ! "$resolved_image_ref" =~ ^.+@sha256:[0-9a-f]{64}$ ]]; then
    echo "Could not resolve the configured registry image to an immutable digest." >&2
    exit 1
fi

pinned_compose=(env "CREATIVE_AI_IMAGE=${resolved_image_ref}" docker compose "${compose_args[@]}")
echo "Resolved deployment image: ${resolved_image_ref}"

echo "Running a new-image application preflight..."
"${pinned_compose[@]}" run --rm --no-deps creative-ai php artisan about --only=environment --no-ansi >/dev/null

web_was_running=false
worker_was_running=false
maintenance_enabled=false
worker_stopped=false
database_migrated=false

if [[ -n "$previous_web_id" ]] && [[ "$(docker inspect --format '{{.State.Running}}' "$previous_web_id")" == "true" ]]; then
    web_was_running=true
fi
if [[ -n "$previous_worker_id" ]] && [[ "$(docker inspect --format '{{.State.Running}}' "$previous_worker_id")" == "true" ]]; then
    worker_was_running=true
fi

deployment_error()
{
    status=$?
    trap - ERR
    echo "Deployment stopped before the queue worker was replaced." >&2
    if [[ "$database_migrated" == true ]]; then
        echo "The database is migrated and the worker remains stopped to avoid mixed code versions." >&2
        echo "Inspect web logs and either finish this digest deployment or restore the paired backup." >&2
    else
        echo "No migration was applied; restoring the previous running services." >&2
        if [[ "$maintenance_enabled" == true ]]; then
            "${compose[@]}" exec --no-tty creative-ai php artisan up --no-ansi || true
        fi
        if [[ "$worker_was_running" == true ]] && [[ -n "$previous_worker_id" ]]; then
            previous_worker_running="$(docker inspect --format '{{.State.Running}}' "$previous_worker_id" 2>/dev/null || true)"
            if [[ "$previous_worker_running" != "true" ]]; then
                docker start "$previous_worker_id" >/dev/null || true
            fi
        fi
    fi
    if [[ -n "$previous_image_ref" ]]; then
        echo "Previous web image: $previous_image_ref" >&2
    elif [[ -n "$previous_image_id" ]]; then
        echo "Previous local web image ID: $previous_image_id" >&2
    fi
    exit "$status"
}
trap deployment_error ERR

if [[ "$web_was_running" == true ]]; then
    echo "Enabling maintenance mode to stop web writes..."
    "${compose[@]}" exec --no-tty creative-ai php artisan down --retry=60 --no-ansi
    maintenance_enabled=true
fi

if [[ "$worker_was_running" == true ]]; then
    echo "Gracefully stopping the old queue worker..."
    docker stop --timeout 240 "$previous_worker_id" >/dev/null
    worker_stopped=true
fi

echo "Running database migrations with the resolved image..."
if ! "${pinned_compose[@]}" run --rm --no-deps creative-ai php artisan migrate --force --no-interaction; then
    echo "Migration failed; restoring the previous running services." >&2
    if [[ "$maintenance_enabled" == true ]]; then
        "${compose[@]}" exec --no-tty creative-ai php artisan up --no-ansi || true
    fi
    if [[ "$worker_stopped" == true ]]; then
        docker start "$previous_worker_id" >/dev/null || true
    fi
    trap - ERR
    exit 1
fi
database_migrated=true

echo "Recreating the web container..."
"${pinned_compose[@]}" up --detach --no-deps --force-recreate creative-ai
web_container_id="$("${pinned_compose[@]}" ps --quiet creative-ai)"

if [[ "$maintenance_enabled" == true || "$web_was_running" == false ]]; then
    echo "Clearing application maintenance mode on the new web container..."
    "${pinned_compose[@]}" exec --no-tty creative-ai php artisan up --no-ansi
    maintenance_enabled=false
fi

health_status="starting"
for _ in $(seq 1 90); do
    health_status="$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$web_container_id")"

    if [[ "$health_status" == "healthy" ]]; then
        break
    fi

    if [[ "$health_status" == "unhealthy" || "$health_status" == "exited" || "$health_status" == "dead" ]]; then
        break
    fi

    sleep 2
done

if [[ "$health_status" != "healthy" ]]; then
    echo "The new web container did not become healthy (status: $health_status)." >&2
    "${pinned_compose[@]}" logs --tail 120 creative-ai >&2 || true
    false
fi

echo "Checking the homepage, PostgreSQL migrations, www-data storage access, and Redis queues..."
"${pinned_compose[@]}" exec --no-tty creative-ai curl --fail --silent --show-error --header 'Host: localhost' http://127.0.0.1/ >/dev/null
"${pinned_compose[@]}" exec --no-tty creative-ai php artisan migrate:status --no-ansi >/dev/null
# shellcheck disable=SC2016
"${pinned_compose[@]}" exec --no-tty --user www-data creative-ai sh -c 'probe="storage/app/public/.deploy-write-test-$$"; umask 077; : > "$probe"; rm -f "$probe"'
"${pinned_compose[@]}" exec --no-tty creative-ai php artisan queue:monitor redis:ai-high,redis:ai,redis:default --max=1000000 --json >/dev/null

echo "Starting the new queue worker..."
"${pinned_compose[@]}" up --detach --no-deps --force-recreate creative-ai-worker
sleep 2
worker_container_id="$("${pinned_compose[@]}" ps --quiet creative-ai-worker)"
if [[ -z "$worker_container_id" ]] || [[ "$(docker inspect --format '{{.State.Running}}' "$worker_container_id")" != "true" ]]; then
    echo "The new queue worker is not running." >&2
    false
fi

worker_stopped=false
trap - ERR

revision="$(docker image inspect --format '{{index .Config.Labels "org.opencontainers.image.revision"}}' "$resolved_image_ref" 2>/dev/null || true)"
deployed_at="$(date --iso-8601=seconds)"
printf '%s\t%s\t%s\t%s\n' "$deployed_at" "$resolved_project" "$resolved_image_ref" "${revision:-unknown}" >> "${script_dir}/deployments.log"

echo "Deployment complete: ${resolved_image_ref}"
if [[ -n "$revision" ]]; then
    echo "Git revision: $revision"
fi
