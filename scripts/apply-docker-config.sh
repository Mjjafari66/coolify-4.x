#!/usr/bin/env bash
# Apply Docker daemon DNS/log config from this repo onto the Coolify engine VM.
#
# Why: builds that pull ghcr.io (nixpacks runtime) fail when the host DNS
# (e.g. 10.10.10.1) does not forward external lookups. Pinning public DNS
# in /etc/docker/daemon.json fixes that for every rebuild.
#
# Idempotent — safe to re-run after Coolify upgrades / VM rebuilds.
#
# Usage (on the Coolify VM, as root or with sudo):
#   sudo bash /path/to/coolify-4.x/scripts/apply-docker-config.sh
#
# Or from a checkout:
#   sudo ./scripts/apply-docker-config.sh

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SRC="${ROOT}/config/docker-daemon.json"
DEST="/etc/docker/daemon.json"
BACKUP_DIR="/etc/docker/backups"

if [[ ! -f "$SRC" ]]; then
  echo "ERROR: missing source config: $SRC" >&2
  exit 1
fi

if [[ "$(id -u)" -ne 0 ]]; then
  echo "ERROR: run as root (sudo)" >&2
  exit 1
fi

mkdir -p /etc/docker "$BACKUP_DIR"

if [[ -f "$DEST" ]]; then
  stamp="$(date +%Y%m%d-%H%M%S)"
  cp -a "$DEST" "${BACKUP_DIR}/daemon.json.${stamp}"
  echo "Backed up existing daemon.json → ${BACKUP_DIR}/daemon.json.${stamp}"
fi

# Merge DNS into an existing daemon.json when present, else install the template.
if [[ -f "$DEST" ]] && command -v python3 >/dev/null 2>&1; then
  python3 - "$SRC" "$DEST" <<'PY'
import json, sys
src_path, dest_path = sys.argv[1], sys.argv[2]
with open(src_path) as f:
    wanted = json.load(f)
with open(dest_path) as f:
    current = json.load(f)
# Prefer wanted DNS/log settings; keep any other keys (registry-mirrors, etc.).
current["dns"] = wanted.get("dns", ["8.8.8.8", "8.8.4.4"])
if "log-driver" in wanted:
    current["log-driver"] = wanted["log-driver"]
if "log-opts" in wanted:
    current["log-opts"] = wanted["log-opts"]
with open(dest_path, "w") as f:
    json.dump(current, f, indent=2)
    f.write("\n")
print(f"Merged Docker DNS config into {dest_path}")
PY
else
  cp "$SRC" "$DEST"
  echo "Installed Docker DNS config → $DEST"
fi

if command -v systemctl >/dev/null 2>&1; then
  systemctl restart docker
  echo "Docker daemon restarted"
else
  service docker restart
  echo "Docker service restarted"
fi

echo "Verifying DNS egress via docker pull hello-world…"
docker pull hello-world >/dev/null
docker image rm -f hello-world >/dev/null 2>&1 || true
echo "OK: Docker DNS config applied and pull succeeded"
