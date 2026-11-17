#!/usr/bin/env bash
set -Eeuo pipefail

IMAGE="${IMAGE:-sawirstudio/sawirpixel:latest}"
PLATFORM="${PLATFORM:-linux/amd64}"
DEPLOY_HOST="${DEPLOY_HOST:-ricardosawir1}"
SSH_TRANSPORT="${SSH_TRANSPORT:-ssh}"
SSH_KEY="${SSH_KEY:-}"
REMOTE_COMPOSE="${REMOTE_COMPOSE:-/root/sawirpixel/compose.yaml}"
SERVICES="${SERVICES:-app task horizon}"
HEALTH_TIMEOUT="${HEALTH_TIMEOUT:-120}"
USER_ID="${USER_ID:-82}"
GROUP_ID="${GROUP_ID:-82}"

usage() {
    cat <<USAGE
Usage: ./deploy.sh [options]

Build the Docker image locally, send it directly to the server,
then recreate the remote Docker Compose services.

Options:
  --skip-build       Do not rebuild the local image before pushing.
  --skip-health      Do not wait for remote service health checks.
  --dry-run          Print commands without running them.
  -h, --help         Show this help.

Environment overrides:
  IMAGE             default: $IMAGE
  PLATFORM          default: $PLATFORM
  DEPLOY_HOST       default: $DEPLOY_HOST
  SSH_TRANSPORT     default: $SSH_TRANSPORT (tailscale or ssh)
  SSH_KEY           default: $SSH_KEY
  REMOTE_COMPOSE    default: $REMOTE_COMPOSE
  SERVICES          default: $SERVICES
  HEALTH_TIMEOUT    default: $HEALTH_TIMEOUT
  USER_ID           default: $USER_ID
  GROUP_ID          default: $GROUP_ID
USAGE
}

SKIP_BUILD=0
SKIP_HEALTH=0
DRY_RUN=0

while [[ $# -gt 0 ]]; do
    case "$1" in
        --skip-build)
            SKIP_BUILD=1
            ;;
        --skip-health)
            SKIP_HEALTH=1
            ;;
        --dry-run)
            DRY_RUN=1
            ;;
        -h | --help)
            usage
            exit 0
            ;;
        *)
            echo "Unknown option: $1" >&2
            usage >&2
            exit 2
            ;;
    esac

    shift
done

log() {
    printf '\n==> %s\n' "$*"
}

die() {
    echo "$*" >&2
    exit 1
}

require_cmd() {
    command -v "$1" >/dev/null 2>&1 || die "$1 is required but was not found."
}

run() {
    printf '+'
    printf ' %q' "$@"
    printf '\n'

    if [[ "$DRY_RUN" -eq 0 ]]; then
        "$@"
    fi
}

remote_exec() {
    if [[ "$SSH_TRANSPORT" == "tailscale" ]]; then
        tailscale ssh "$DEPLOY_HOST" "$@"
    else
        # shellcheck disable=SC2029
        ssh "${ssh_args[@]}" "$DEPLOY_HOST" "$@"
    fi
}

ssh_args=()
pussh_args=()

if [[ -n "$SSH_KEY" ]]; then
    ssh_args=(-i "$SSH_KEY")
    pussh_args=(-i "$SSH_KEY")
fi

read -r -a service_args <<< "$SERVICES"

case "$SSH_TRANSPORT" in
    tailscale)
        if [[ "$DRY_RUN" -eq 0 ]]; then
            require_cmd tailscale
        fi
        ;;
    ssh)
        if [[ "$DRY_RUN" -eq 0 ]]; then
            require_cmd ssh

            if ! docker pussh --help >/dev/null 2>&1; then
                die "docker pussh is required for SSH_TRANSPORT=ssh but was not found."
            fi
        fi
        ;;
    *)
        die "Unsupported SSH_TRANSPORT: $SSH_TRANSPORT (expected tailscale or ssh)"
        ;;
esac

if [[ "$SKIP_BUILD" -eq 0 ]]; then
    log "Building $IMAGE for $PLATFORM"
    run docker build \
        --pull \
        --platform "$PLATFORM" \
        --build-arg "USER_ID=$USER_ID" \
        --build-arg "GROUP_ID=$GROUP_ID" \
        -t "$IMAGE" \
        .
fi

log "Pushing $IMAGE to $DEPLOY_HOST with $SSH_TRANSPORT"
if [[ "$SSH_TRANSPORT" == "tailscale" ]]; then
    printf '+ docker image save %q | tailscale ssh %q docker image load\n' "$IMAGE" "$DEPLOY_HOST"

    if [[ "$DRY_RUN" -eq 0 ]]; then
        docker image save "$IMAGE" | tailscale ssh "$DEPLOY_HOST" docker image load
    fi
else
    run docker pussh "$IMAGE" "$DEPLOY_HOST" "${pussh_args[@]}"
fi

log "Recreating remote services: ${service_args[*]}"
if [[ "$DRY_RUN" -eq 1 ]]; then
    if [[ "$SSH_TRANSPORT" == "tailscale" ]]; then
        printf '+ tailscale ssh %q' "$DEPLOY_HOST"
    else
        printf '+ ssh'
        printf ' %q' "${ssh_args[@]}" "$DEPLOY_HOST"
    fi

    printf ' bash -s -- %q %q %q' "$REMOTE_COMPOSE" "$HEALTH_TIMEOUT" "$SKIP_HEALTH"
    printf ' %q' "${service_args[@]}"
    printf '\n'
    exit 0
fi

remote_cmd=(bash -s -- "$REMOTE_COMPOSE" "$HEALTH_TIMEOUT" "$SKIP_HEALTH" "${service_args[@]}")

remote_exec "${remote_cmd[@]}" <<'REMOTE'
set -Eeuo pipefail

compose_file="$1"
health_timeout="$2"
skip_health="$3"
shift 3
services=("$@")

docker compose -f "$compose_file" up -d --force-recreate "${services[@]}"

if [[ "$skip_health" -eq 1 ]]; then
    docker compose -f "$compose_file" ps
    exit 0
fi

for service in "${services[@]}"; do
    container_id="$(docker compose -f "$compose_file" ps -q "$service")"

    if [[ -z "$container_id" ]]; then
        echo "No container found for service: $service" >&2
        exit 1
    fi

    deadline=$((SECONDS + health_timeout))

    while (( SECONDS < deadline )); do
        state="$(docker inspect -f '{{.State.Status}}' "$container_id")"
        health="$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$container_id")"

        if [[ "$state" == "running" && ( "$health" == "healthy" || "$health" == "none" ) ]]; then
            echo "$service is $state ($health)"
            break
        fi

        if [[ "$state" == "exited" || "$state" == "dead" ]]; then
            echo "$service is $state ($health)" >&2
            docker logs --tail 120 "$container_id" >&2 || true
            exit 1
        fi

        sleep 2
    done

    if (( SECONDS >= deadline )); then
        echo "$service did not become healthy within ${health_timeout}s" >&2
        docker compose -f "$compose_file" ps >&2
        docker logs --tail 120 "$container_id" >&2 || true
        exit 1
    fi
done

docker compose -f "$compose_file" ps
REMOTE
