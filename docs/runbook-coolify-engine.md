# Coolify engine runbook (production)

Private deploy engine VM (`10.10.10.10`). End users never access Coolify UI directly.

## Locked image policy

Always run Coolify from a **custom built image** with platform patches. Never rely on upstream auto-update.

| Item | Value |
|------|--------|
| Source on VM | `/tmp/coolify-4.x` or synced from dev machine |
| Compose dir | `/data/coolify/source` |
| Custom image | `coolify-custom:4.1.2-custom` |
| Override file | `/data/coolify/source/docker-compose.custom.yml` |

### Required env (`/data/coolify/source/.env`)

```bash
AUTOUPDATE=false
REGISTRY_URL=ghcr-mirror.liara.ir   # or empty if offline
```

## Deploy procedure (full)

1. Sync source to VM (exclude `.git`, `vendor`, `node_modules` if rebuilding elsewhere).
2. Set credentials:
   ```bash
   export COOLIFY_SSH_PASSWORD='...'   # sudo password
   export REGISTRY_URL=ghcr-mirror.liara.ir
   export COOLIFY_TAG=4.1.2
   ```
3. Run from source tree:
   ```bash
   cd /tmp/coolify-4.x
   bash scripts/run-on-vm.sh
   ```
4. **Always** use three compose files:
   ```bash
   sudo docker compose \
     --project-directory /data/coolify/source \
     -f /data/coolify/source/docker-compose.yml \
     -f /data/coolify/source/docker-compose.prod.yml \
     -f /data/coolify/source/docker-compose.custom.yml \
     up -d --remove-orphans
   ```

**Do not** run `compose up` without `docker-compose.custom.yml` — you will lose patches.

## Post-deploy verification

```bash
# Custom image in use
docker inspect coolify --format '{{.Config.Image}}'
# expect: coolify-custom:4.1.2-custom

# Override file exists
test -f /data/coolify/source/docker-compose.custom.yml && echo OK

# AUTOUPDATE disabled
grep '^AUTOUPDATE=false' /data/coolify/source/.env

# Patches present inside container
docker exec coolify grep -l restart_compose_without_git /var/www/html/app/Jobs/ApplicationDeploymentJob.php
docker exec coolify grep -l 'Injected platform-generated Dockerfile' /var/www/html/app/Jobs/ApplicationDeploymentJob.php
docker exec coolify grep -l 'Platform proxy is in manual TLS mode' /var/www/html/bootstrap/helpers/proxy.php

# Health
curl -sf http://127.0.0.1:8000/api/health && echo OK
```

## Restart without losing patches

```bash
sudo docker compose \
  --project-directory /data/coolify/source \
  -f /data/coolify/source/docker-compose.yml \
  -f /data/coolify/source/docker-compose.prod.yml \
  -f /data/coolify/source/docker-compose.custom.yml \
  restart coolify
```

Re-run verification after any restart.

## Rollback (emergency)

If custom image breaks production:

```bash
sudo rm /data/coolify/source/docker-compose.custom.yml
sudo docker compose \
  --project-directory /data/coolify/source \
  -f /data/coolify/source/docker-compose.yml \
  -f /data/coolify/source/docker-compose.prod.yml \
  up -d --remove-orphans
```

Then rebuild custom image from last known-good git commit and re-apply override.

## Git egress check (from engine VM)

```bash
getent hosts github.com
git ls-remote https://github.com/coollabsio/coolify.git HEAD
```

Fix DNS/firewall before running customer git deploys.

## Sudo requirement

`scripts/run-on-vm.sh` needs passwordless sudo or `COOLIFY_SSH_PASSWORD` / `COOLIFY_SUDO_PASSWORD`.
Do not mark deploy complete if sudo writes to `/data/coolify/source/` failed.
