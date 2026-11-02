#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
COMPOSE_FILE="$SCRIPT_DIR/docker-compose.yml"
SSH_TARGET="root@reisinger.pictures"
STACK_DIR="/opt/stacks/portal-reisinger-pictures"
REMOTE_COMPOSE="$STACK_DIR/compose.yaml"
REMOTE_ENV="$STACK_DIR/.env"
RSYNC_BIN="${RSYNC_BIN:-rsync}"
CONTROL_PATH="/tmp/ssh-deploy-%r@%h:%p"
SSH_OPTS=(-o ControlMaster=auto -o "ControlPath=$CONTROL_PATH" -o ControlPersist=60 -o BatchMode=yes)
RSYNC_RSH="ssh ${SSH_OPTS[*]}"
RSYNC_OPTS=(--archive --no-owner --no-group --chmod=F644 --rsh="$RSYNC_RSH")

DRY_RUN=false
case "${1:-}" in
  "") ;;
  --dry-run) DRY_RUN=true ;;
  *)
    printf 'Usage: %s [--dry-run]\n' "$0" >&2
    exit 2
    ;;
esac

if [[ $# -gt 1 ]]; then
  printf 'Usage: %s [--dry-run]\n' "$0" >&2
  exit 2
fi

[[ -f "$COMPOSE_FILE" ]] || {
  printf 'ERROR: Compose-Datei fehlt: %s\n' "$COMPOSE_FILE" >&2
  exit 1
}

REMOTE_PREFLIGHT="if [ ! -f '$REMOTE_ENV' ]; then echo 'ERROR: Host-.env fehlt: $REMOTE_ENV (wird nicht aus dem Repo erzeugt oder überschrieben).' >&2; exit 1; fi; if [ ! -d '$STACK_DIR' ]; then echo 'ERROR: Stack-Verzeichnis fehlt: $STACK_DIR' >&2; exit 1; fi"
REMOTE_UP="docker compose -f $REMOTE_COMPOSE up -d --wait"

print_command() {
  printf '  '
  printf '%q ' "$@"
  printf '\n'
}

if [[ "$DRY_RUN" == true ]]; then
  printf 'Dry-run: keine Verbindung und keine Änderungen am Host. Geplante Befehle:\n'
  print_command ssh "${SSH_OPTS[@]}" -T "$SSH_TARGET" "$REMOTE_PREFLIGHT"
  print_command "$RSYNC_BIN" "${RSYNC_OPTS[@]}" -- "$COMPOSE_FILE" "$SSH_TARGET:$REMOTE_COMPOSE"
  print_command ssh "${SSH_OPTS[@]}" -T "$SSH_TARGET" "$REMOTE_UP"
  exit 0
fi

printf 'Prüfe Host-.env und Stack-Verzeichnis auf %s ...\n' "$SSH_TARGET"
ssh "${SSH_OPTS[@]}" -T "$SSH_TARGET" "$REMOTE_PREFLIGHT"

printf 'Übertrage ausschließlich deployment/docker-compose.yml ...\n'
"$RSYNC_BIN" "${RSYNC_OPTS[@]}" -- "$COMPOSE_FILE" "$SSH_TARGET:$REMOTE_COMPOSE"

printf 'Aktualisiere Stack %s ...\n' "$STACK_DIR"
ssh "${SSH_OPTS[@]}" -T "$SSH_TARGET" "$REMOTE_UP"

printf 'Deploy abgeschlossen.\n'
