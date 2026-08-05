#!/usr/bin/env bash
# Run this ON VM1 after source is extracted at /tmp/coolify-4.x
set -euo pipefail

cd /tmp/coolify-4.x
IMAGE_TAG="coolify-custom:4.1.2-custom"

BASE_IMAGE=$(docker ps --filter name=^coolify$ --format '{{.Image}}' | head -1)
if [ -z "${BASE_IMAGE}" ]; then
  BASE_IMAGE=$(docker images --format '{{.Repository}}:{{.Tag}}' | grep -E 'coollabsio/coolify|coolify' | grep -v coolify-custom | head -1)
fi
if [ -z "${BASE_IMAGE}" ]; then
  echo "ERROR: No local Coolify image. Run: docker images | grep coolify"
  exit 1
fi

echo "Base image: ${BASE_IMAGE}"
docker tag "${BASE_IMAGE}" coolify-base:local

cat > Dockerfile.custom <<'EOF'
FROM coolify-base:local
COPY app /var/www/html/app
COPY bootstrap /var/www/html/bootstrap
COPY database/migrations /var/www/html/database/migrations
COPY resources/views /var/www/html/resources/views
COPY routes/web.php /var/www/html/routes/web.php
EOF

DOCKER_BUILDKIT=0 docker build --pull=false -f Dockerfile.custom -t "${IMAGE_TAG}" .

sudo tee /data/coolify/source/docker-compose.custom.yml > /dev/null <<EOF
services:
  coolify:
    image: ${IMAGE_TAG}
    pull_policy: never
EOF

if sudo grep -q '^AUTOUPDATE=' /data/coolify/source/.env 2>/dev/null; then
  sudo sed -i 's/^AUTOUPDATE=.*/AUTOUPDATE=false/' /data/coolify/source/.env
else
  echo 'AUTOUPDATE=false' | sudo tee -a /data/coolify/source/.env > /dev/null
fi

cd /data/coolify/source
sudo docker compose -f docker-compose.yml -f docker-compose.prod.yml -f docker-compose.custom.yml up -d

sleep 5
docker exec coolify php artisan migrate --force
curl -sf http://127.0.0.1:8000/api/health && echo " - Health OK"
echo "Done. Go to Server → Proxy → SSL in Coolify UI."
