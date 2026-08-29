#!/usr/bin/env bash
# Re-run Traefik Flexible-SSL HTTP bypass whenever a container starts/stops.
# Complements the every-minute cron so new catalog FQDNs appear within seconds.
set -euo pipefail
SCRIPT="${WEBLINES_BYPASS_SCRIPT:-/home/coolify/sync-weblines-http-bypass.py}"
LOG="${WEBLINES_BYPASS_LOG:-/home/coolify/weblines-http-bypass.log}"

ts() { date -u +%Y-%m-%dT%H:%M:%SZ; }

echo "$(ts) watcher-start" >>"$LOG"
# Initial sync
/usr/bin/python3 "$SCRIPT" >>"$LOG" 2>&1 || true

docker events \
  --filter 'type=container' \
  --filter 'event=start' \
  --filter 'event=die' \
  --filter 'event=destroy' \
  --format '{{.Status}} {{.Actor.Attributes.name}}' \
| while read -r line; do
    # Debounce bursts of compose events
    sleep 2
    echo "$(ts) event: $line" >>"$LOG"
    /usr/bin/python3 "$SCRIPT" >>"$LOG" 2>&1 || true
  done
