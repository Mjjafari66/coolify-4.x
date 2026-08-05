#!/usr/bin/env bash
# Run ON Coolify VM1 (ssh -p 2221 coolify@134.255.200.131)
set -euo pipefail

echo "=== Host / Docker ==="
hostname
docker ps --format 'table {{.Names}}\t{{.Status}}\t{{.Ports}}' | head -30

echo
echo "=== Traefik proxy ==="
docker ps --filter name=traefik --format '{{.Names}} {{.Status}}' || true
PROXY=$(docker ps --format '{{.Names}}' | grep -E 'coolify-proxy|traefik' | head -1 || true)
if [ -n "${PROXY:-}" ]; then
  echo "Proxy container: $PROXY"
  docker logs "$PROXY" --tail 40 2>&1 | tail -40
fi

echo
echo "=== Coolify DB: recent apps + FQDN ==="
docker exec coolify php artisan tinker --execute '
$apps = \App\Models\Application::query()
    ->select(["id","name","uuid","fqdn","status"])
    ->latest("updated_at")
    ->limit(15)
    ->get();
foreach ($apps as $a) {
    echo $a->name." | ".$a->status." | ".$a->fqdn.PHP_EOL;
}
' 2>/dev/null || echo "(tinker failed — check coolify container name)"

echo
echo "=== Application SSL certs (SANs) ==="
docker exec coolify php artisan tinker --execute '
\App\Models\SslCertificate::query()
    ->where("is_proxy_certificate", false)
    ->latest("updated_at")
    ->limit(20)
    ->get(["common_name","subject_alternative_names","valid_until"])
    ->each(function ($c) {
        echo $c->common_name." | SAN: ".json_encode($c->subject_alternative_names)." | until ".$c->valid_until.PHP_EOL;
    });
' 2>/dev/null || true

echo
echo "=== Traefik dynamic certs dir (sample) ==="
if [ -n "${PROXY:-}" ]; then
  docker exec "$PROXY" sh -c 'ls -la /traefik/certs/apps 2>/dev/null | head -20; find /traefik/certs/apps -name fullchain.pem 2>/dev/null | head -20' || true
fi

echo
echo "=== Sample www vs apex curl (from VM) ==="
for domain in andya.ir www.andya.ir vandapc.ir www.vandapc.ir; do
  code=$(curl -sk -o /dev/null -w '%{http_code}' --max-time 5 "https://$domain" 2>/dev/null || echo ERR)
  echo "$domain -> HTTP $code"
done

echo
echo "=== DNS from VM ==="
for domain in andya.ir www.andya.ir; do
  echo -n "$domain A: "; dig +short A "$domain" 2>/dev/null | tr '\n' ' '; echo
  echo -n "$domain CNAME: "; dig +short CNAME "$domain" 2>/dev/null | tr '\n' ' '; echo
done
