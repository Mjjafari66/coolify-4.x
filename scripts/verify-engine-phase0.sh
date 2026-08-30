#!/usr/bin/env bash
# Verify P0 Coolify engine gates. Run on the Coolify VM.
set -euo pipefail

COMPOSE_DIR="${COMPOSE_DIR:-/data/coolify/source}"
CUSTOM_IMAGE="${CUSTOM_IMAGE:-coolify-custom:4.1.2-custom}"

fail() {
  echo "FAIL: $*" >&2
  exit 1
}

pass() {
  echo "OK: $*"
}

[ -f "${COMPOSE_DIR}/docker-compose.custom.yml" ] || fail "missing docker-compose.custom.yml"

img=$(docker inspect coolify --format '{{.Config.Image}}' 2>/dev/null || true)
[[ "$img" == *"coolify-custom"* ]] || fail "coolify not on custom image (got: $img)"

grep -q '^AUTOUPDATE=false' "${COMPOSE_DIR}/.env" 2>/dev/null \
  || fail "AUTOUPDATE=false not set in ${COMPOSE_DIR}/.env"

docker exec coolify grep -q restart_compose_without_git \
  /var/www/html/app/Jobs/ApplicationDeploymentJob.php \
  || fail "restart_compose_without_git patch missing"

docker exec coolify grep -q 'Injected platform-generated Dockerfile' \
  /var/www/html/app/Jobs/ApplicationDeploymentJob.php \
  || fail "platform-generated Dockerfile inject patch missing"

docker exec coolify grep -q preferBuiltComposeImage \
  /var/www/html/bootstrap/helpers/applications.php \
  || fail "preferBuiltComposeImage patch missing"

curl -sf http://127.0.0.1:8000/api/health >/dev/null || fail "engine health failed"

pass "P0 Coolify engine checks"
