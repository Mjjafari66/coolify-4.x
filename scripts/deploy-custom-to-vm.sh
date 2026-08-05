#!/usr/bin/env bash
# One command from MacBook/PC — prompts SSH password twice (scp + ssh).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
HOST="${COOLIFY_SSH_HOST:-134.255.200.131}"
PORT="${COOLIFY_SSH_PORT:-2221}"
USER="${COOLIFY_SSH_USER:-coolify}"
REGISTRY="${REGISTRY_URL:-ghcr-mirror.liara.ir}"
SSH_KEY="${COOLIFY_SSH_KEY:-}"
SSH_OPTS=(-p "$PORT" -o StrictHostKeyChecking=accept-new)
SCP_OPTS=(-P "$PORT" -o StrictHostKeyChecking=accept-new)
if [ -n "$SSH_KEY" ] && [ -f "$SSH_KEY" ]; then
  SSH_OPTS+=(-i "$SSH_KEY")
  SCP_OPTS+=(-i "$SSH_KEY")
fi

echo "==> Pack source"
tar czf /tmp/coolify-source-custom.tar.gz \
  --exclude='node_modules' --exclude='vendor' --exclude='.git' \
  -C "$(dirname "$ROOT")" "$(basename "$ROOT")"

echo "==> Upload"
scp "${SCP_OPTS[@]}" /tmp/coolify-source-custom.tar.gz "$USER@$HOST:/tmp/"
scp "${SCP_OPTS[@]}" "$ROOT/scripts/run-on-vm.sh" "$USER@$HOST:/tmp/run-on-vm.sh"

REMOTE_ENV="export REGISTRY_URL=${REGISTRY} && export COOLIFY_TAG=${COOLIFY_TAG:-4.1.2} &&"

echo "==> Deploy on VM"
ssh "${SSH_OPTS[@]}" "$USER@$HOST" "cd /tmp && tar xzf coolify-source-custom.tar.gz && chmod +x run-on-vm.sh && ${REMOTE_ENV} SOURCE_DIR=/tmp/coolify-4.x bash /tmp/run-on-vm.sh"

echo "==> Finished"
