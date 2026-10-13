<?php

it('allows a typical app stack', function () {
    // sakhtstudio's file as deployed (2026-10-13), plus common extras.
    $compose = <<<'YAML'
services:
  web:
    build:
      context: .
      dockerfile: Dockerfile
      args:
        NEXT_PUBLIC_ID: '${NEXT_PUBLIC_ID:-}'
    image: 'sakhtstudio:latest'
    container_name: sakhtstudio
    ports:
      - '${HOST_PORT:-3000}:3000'
    env_file: [.env]
    environment:
      PORT: '3000'
    volumes:
      - 'leads_data:/app/data'
      - './uploads:/app/uploads'
      - /var/cache/anon
      - { type: tmpfs, target: /tmp }
    depends_on: [redis]
    restart: unless-stopped
    networks: [default, backend]
    labels:
      com.example.team: web
    healthcheck:
      test: ['CMD-SHELL', 'curl -sf http://127.0.0.1:3000 || exit 1']
  redis:
    image: 'redis:7.4-alpine'
    networks:
      backend:
        aliases: [cache]
volumes:
  leads_data:
    name: sakhtstudio_leads
networks:
  backend: {}
YAML;
    expect(composeSecurityViolations($compose))->toBe([]);
});

it('blocks host takeover settings', function (string $service) {
    $compose = "services:\n  x:\n    image: alpine\n{$service}\n";
    expect(composeSecurityViolations($compose))->not->toBe([]);
})->with([
    'privileged' => '    privileged: true',
    'pid host' => '    pid: host',
    'ipc host' => '    ipc: host',
    'network_mode' => '    network_mode: host',
    'network_mode container' => '    network_mode: "container:abc"',
    'userns' => '    userns_mode: host',
    'cap_add' => '    cap_add: [SYS_ADMIN]',
    'devices' => '    devices: ["/dev/sda:/dev/sda"]',
    'sysctls' => '    sysctls: {net.ipv4.ip_forward: 1}',
    'security_opt' => '    security_opt: ["apparmor:unconfined"]',
    'cgroup_parent' => '    cgroup_parent: /',
    'runtime' => '    runtime: runc',
    'oom_score_adj' => '    oom_score_adj: -1000',
    'logging' => '    logging: {driver: syslog}',
    'gpus' => '    gpus: all',
    'post_start' => '    post_start: [{command: id, privileged: true}]',
    'docker socket' => '    volumes: ["/var/run/docker.sock:/var/run/docker.sock"]',
    'host root' => '    volumes: ["/:/host"]',
    'host tmp' => '    volumes: ["/tmp:/tmp"]',
    'home' => '    volumes: ["~/.ssh:/k"]',
    'escape' => '    volumes: ["./../../../etc:/e"]',
    'interpolated source' => '    volumes: ["${SRC}:/host"]',
    'long bind' => "    volumes:\n      - {type: bind, source: /etc, target: /e}",
    'propagation' => "    volumes:\n      - {type: bind, source: ./d, target: /d, bind: {propagation: rshared}}",
    'volumes_from container' => '    volumes_from: ["container:coolify-db"]',
    'env_file abs' => '    env_file: [/data/coolify/source/.env]',
    'env_file escape' => '    env_file: [../../.env]',
    'build context abs' => '    build: /',
    'build network' => "    build: {context: ., network: host}",
    'build entitlements' => "    build: {context: ., entitlements: [network.host]}",
    'external_links' => '    external_links: [coolify-db]',
    'undeclared network' => '    networks: [coolify]',
    'static ip' => "    networks:\n      default: {ipv4_address: 10.10.10.5}",
    'traefik label' => '    labels: ["traefik.http.routers.x.rule=Host(`console.weblines.ir`)"]',
    'traefik label map' => "    labels:\n      traefik.enable: 'true'",
    'coolify label' => '    labels: ["coolify.applicationId=1"]',
    'interpolated label' => '    labels: ["${L}=x"]',
    'deploy device' => "    deploy: {resources: {reservations: {devices: [{capabilities: [gpu]}]}}}",
]);

it('blocks top-level escapes', function (string $yaml) {
    expect(composeSecurityViolations("services:\n  x:\n    image: alpine\n{$yaml}\n"))->not->toBe([]);
})->with([
    'external network' => "networks:\n  coolify:\n    external: true",
    'named network' => "networks:\n  n:\n    name: b5f7sgc1y7j1g6q27g9hx1nt",
    'macvlan' => "networks:\n  n:\n    driver: macvlan",
    'ipam' => "networks:\n  n:\n    ipam: {config: [{subnet: 10.10.10.0/24}]}",
    'external volume' => "volumes:\n  v:\n    external: true",
    'nfs volume' => "volumes:\n  v:\n    driver_opts: {type: nfs, o: addr=10.10.10.1, device: ':/'}",
    'bind volume' => "volumes:\n  v:\n    driver_opts: {type: none, o: bind, device: /}",
    'secret file' => "secrets:\n  s:\n    file: /etc/shadow",
    'external secret' => "secrets:\n  s:\n    external: true",
    'include' => "include:\n  - /etc/compose.yml",
]);

it('ignores files it cannot parse', function () {
    expect(composeSecurityViolations("services: [\n"))->toBe([]);
    expect(composeSecurityViolations(null))->toBe([]);
});

it('turns published ports into exposed ones and caps processes', function () {
    [$services, $log] = hardenComposeServices([
        'web' => ['image' => 'x', 'ports' => ['${HOST_PORT:-3000}:3000', '127.0.0.1:8443:443/tcp', ['target' => 9000, 'published' => 40001]], 'expose' => ['3000']],
        'db' => ['image' => 'postgres', 'pids_limit' => 100000],
        'cache' => ['image' => 'redis', 'pids_limit' => 64],
    ]);
    expect($services['web'])->not->toHaveKey('ports');
    expect($services['web']['expose'])->toBe(['3000', '443', '9000']);
    expect($services['web']['pids_limit'])->toBe(1024);
    expect($services['db']['pids_limit'])->toBe(1024);
    expect($services['cache']['pids_limit'])->toBe(64);
    expect($log)->toHaveCount(1);
});
