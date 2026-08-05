#!/usr/bin/env bash
# Run ON VM1 (10.10.10.10) — full deploy of custom Coolify with SSL feature.
#
# Usage on VM:
#   export COOLIFY_SSH_PASSWORD='...'   # if sudo needs password
#   export REGISTRY_URL=ghcr-mirror.liara.ir
#   export COOLIFY_TAG=4.1.2
#   bash scripts/run-on-vm.sh

set -euo pipefail

REGISTRY_URL="${REGISTRY_URL:-ghcr-mirror.liara.ir}"
COOLIFY_TAG="${COOLIFY_TAG:-4.1.2}"
CUSTOM_TAG="${CUSTOM_TAG:-4.1.2-custom}"
SOURCE_DIR="${SOURCE_DIR:-/tmp/coolify-4.x}"
COMPOSE_DIR="/data/coolify/source"

init_sudo() {
  if sudo -n true 2>/dev/null; then
    return 0
  fi

  local pw="${COOLIFY_SUDO_PASSWORD:-${COOLIFY_SSH_PASSWORD:-}}"
  if [ -z "${pw}" ]; then
    echo "ERROR: sudo required but COOLIFY_SSH_PASSWORD not set"
    exit 1
  fi

  echo "${pw}" | sudo -S -p '' true
}

run_sudo() {
  init_sudo
  sudo "$@"
}

echo "==> Coolify custom deploy"
echo "    REGISTRY_URL=${REGISTRY_URL}"
echo "    SOURCE_DIR=${SOURCE_DIR}"

if [ ! -d "${SOURCE_DIR}/app" ]; then
  echo "ERROR: Source not found at ${SOURCE_DIR}"
  exit 1
fi

cd "${SOURCE_DIR}"

resolve_local_base_image() {
  local candidate=""

  if docker image inspect coolify-base:local >/dev/null 2>&1; then
    candidate="coolify-base:local"
  elif candidate="$(docker ps --filter name=^coolify$ --format '{{.Image}}' | head -1)" && [ -n "${candidate}" ]; then
    :
  elif docker image inspect "coolify-custom:${CUSTOM_TAG}" >/dev/null 2>&1; then
    candidate="coolify-custom:${CUSTOM_TAG}"
  elif [ -n "${REGISTRY_URL}" ] && docker image inspect "${REGISTRY_URL}/coollabsio/coolify:${COOLIFY_TAG}" >/dev/null 2>&1; then
    candidate="${REGISTRY_URL}/coollabsio/coolify:${COOLIFY_TAG}"
  else
    candidate="$(docker images --format '{{.Repository}}:{{.Tag}}' | grep -E 'coolify-custom|coollabsio/coolify' | grep -v "${CUSTOM_TAG}" | head -1 || true)"
  fi

  if [ -z "${candidate}" ]; then
    return 1
  fi

  echo "==> Using local base image: ${candidate}"
  docker tag "${candidate}" coolify-base:local
}

resolve_base_image() {
  if [ "${SKIP_PULL:-0}" = "1" ]; then
    resolve_local_base_image || {
      echo "ERROR: SKIP_PULL=1 but no local Coolify image found."
      exit 1
    }
    return
  fi

  if resolve_local_base_image; then
    echo "    (Set FORCE_PULL=1 to refresh base from registry)"
    if [ "${FORCE_PULL:-0}" != "1" ]; then
      return
    fi
    echo "==> FORCE_PULL=1 — refreshing base from registry"
  fi

  if [ -z "${REGISTRY_URL}" ]; then
    resolve_local_base_image || {
      echo "ERROR: No local Coolify image and REGISTRY_URL is empty."
      exit 1
    }
    return
  fi

  BASE_IMAGE="${REGISTRY_URL}/coollabsio/coolify:${COOLIFY_TAG}"
  echo "==> Pulling base: ${BASE_IMAGE}"
  if docker pull "${BASE_IMAGE}"; then
    docker tag "${BASE_IMAGE}" coolify-base:local
    return
  fi

  echo "WARN: Registry pull failed — falling back to local image"
  resolve_local_base_image || {
    echo "ERROR: Pull failed and no local Coolify image found."
    exit 1
  }
}

resolve_base_image

cat > Dockerfile.custom <<'EOF'
FROM coolify-base:local
COPY app /var/www/html/app
COPY bootstrap /var/www/html/bootstrap
COPY database/migrations /var/www/html/database/migrations
COPY resources/views /var/www/html/resources/views
COPY routes/web.php /var/www/html/routes/web.php
EOF

echo "==> Building ${CUSTOM_TAG}"
DOCKER_BUILDKIT=0 docker build --pull=false -f Dockerfile.custom -t "coolify-custom:${CUSTOM_TAG}" .

FINAL_IMAGE="coolify-custom:${CUSTOM_TAG}"

echo "==> Writing compose override + .env"
run_sudo mkdir -p "${COMPOSE_DIR}"

run_sudo tee "${COMPOSE_DIR}/docker-compose.custom.yml" > /dev/null <<EOF
services:
  coolify:
    image: ${FINAL_IMAGE}
    pull_policy: never
EOF

run_sudo bash -s <<SCRIPT
set -euo pipefail
ENV_FILE="${COMPOSE_DIR}/.env"
touch "\${ENV_FILE}"

set_or_add() {
  local key="\$1" val="\$2"
  if grep -q "^\${key}=" "\${ENV_FILE}"; then
    sed -i "s|^\${key}=.*|\${key}=\${val}|" "\${ENV_FILE}"
  else
    echo "\${key}=\${val}" >> "\${ENV_FILE}"
  fi
}

set_or_add REGISTRY_URL "${REGISTRY_URL}"
set_or_add AUTOUPDATE false
set_or_add HELPER_IMAGE "${REGISTRY_URL}/coollabsio/coolify-helper"
set_or_add REALTIME_IMAGE "${REGISTRY_URL}/coollabsio/coolify-realtime"
SCRIPT

echo "==> Restarting Coolify"
run_sudo docker compose \
  --project-directory "${COMPOSE_DIR}" \
  -f "${COMPOSE_DIR}/docker-compose.yml" \
  -f "${COMPOSE_DIR}/docker-compose.prod.yml" \
  -f "${COMPOSE_DIR}/docker-compose.custom.yml" \
  up -d --remove-orphans

echo "==> Waiting for container..."
sleep 12

echo "==> Migration"
docker exec coolify php artisan migrate --force
docker exec coolify php artisan view:clear

echo "==> Health check"
curl -sf http://127.0.0.1:8000/api/health && echo " OK" || echo " FAILED"

echo ""
echo "==> DONE"
docker ps --format 'table {{.Names}}\t{{.Image}}\t{{.Status}}' | grep -E 'NAMES|coolify'
echo ""
echo "Next: Coolify UI → Server → Proxy → SSL"
