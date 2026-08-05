#!/usr/bin/env bash
# Run on the Coolify server (SSH as root or a user with docker access).
# Collects data for reboot / image / restart troubleshooting.
set -euo pipefail

echo "=== Step 0: Host & uptime ==="
hostname
date -Is
uptime

echo
echo "=== Step 1: Docker service (must be enabled for auto-start after reboot) ==="
systemctl is-enabled docker 2>/dev/null || echo "systemctl unavailable"
systemctl is-active docker 2>/dev/null || echo "docker inactive"

echo
echo "=== Step 2: Disk usage (Docker + Coolify data paths) ==="
df -h / /var/lib/docker /data 2>/dev/null || df -h /

echo
echo "=== Step 3: Managed Coolify containers ==="
docker ps -a \
  --filter "label=coolify.managed=true" \
  --format 'table {{.Names}}\t{{.Status}}\t{{.Image}}' | head -40

echo
echo "=== Step 4: Restart policy + restart count (sample: first 5 managed containers) ==="
while IFS= read -r name; do
  [ -z "$name" ] && continue
  policy=$(docker inspect "$name" --format '{{.HostConfig.RestartPolicy.Name}}' 2>/dev/null || echo "unknown")
  restarts=$(docker inspect "$name" --format '{{.RestartCount}}' 2>/dev/null || echo "?")
  oom=$(docker inspect "$name" --format '{{.State.OOMKilled}}' 2>/dev/null || echo "?")
  echo "$name | restart=$policy | RestartCount=$restarts | OOMKilled=$oom"
done < <(docker ps -a --filter "label=coolify.managed=true" --format '{{.Names}}' | head -5)

echo
echo "=== Step 5: Application images (last 20) ==="
docker images --format 'table {{.Repository}}\t{{.Tag}}\t{{.Size}}\t{{.CreatedSince}}' | head -21

echo
echo "=== Step 6: Docker disk usage summary ==="
docker system df 2>/dev/null || true

echo
echo "=== Step 7: Coolify proxy ==="
docker ps --filter "name=coolify-proxy" --format '{{.Names}} {{.Status}}' || true
docker ps --filter "name=traefik" --format '{{.Names}} {{.Status}}' || true

echo
echo "=== Step 8: Coolify application config dirs (if present) ==="
for base in /data/coolify/applications /var/lib/docker/volumes/coolify-data/_data/applications; do
  if [ -d "$base" ]; then
    echo "-- $base"
    ls -la "$base" 2>/dev/null | head -10
    find "$base" -maxdepth 2 -name 'docker-compose.*' 2>/dev/null | head -10
  fi
done

echo
echo "=== Done. Paste this full output back to continue troubleshooting. ==="
