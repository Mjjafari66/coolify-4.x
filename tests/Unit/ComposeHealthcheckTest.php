<?php

it('infers compose service port from expose', function () {
    expect(inferComposeServicePort([
        'expose' => ['5000/tcp'],
    ]))->toBe(5000);
});

it('infers compose service port from published ports', function () {
    expect(inferComposeServicePort([
        'ports' => ['8080:5000'],
    ]))->toBe(5000);
});

it('injects compose healthcheck when only the image defines curl healthcheck', function () {
    $service = ensureComposeApplicationHealthcheck([
        'expose' => ['5000'],
    ]);

    $test = $service['healthcheck']['test'];

    expect($test[0])->toBe('CMD-SHELL');
    expect($test[1])->toContain('python3 -c');
    expect($test[1])->toContain('127.0.0.1:5000');
});

it('normalizes curl compose healthchecks to include python fallback', function () {
    $service = normalizeComposeHealthcheck([
        'healthcheck' => [
            'test' => ['CMD', 'curl', '-f', 'http://localhost:5000/'],
        ],
    ]);

    $test = $service['healthcheck']['test'];

    expect($test[0])->toBe('CMD-SHELL');
    expect($test[1])->toContain('curl');
    expect($test[1])->toContain('wget');
    expect($test[1])->toContain('python3 -c');
    expect($test[1])->toContain('http://localhost:5000/');
});

it('adds traefik service port labels when compose expose port is known', function () {
    $labels = fqdnLabelsForTraefik(
        uuid: 'test-uuid',
        domains: collect(['https://example.com']),
        onlyPort: 5000,
        proxy_ssl_mode: \App\Enums\ProxySslMode::Manual,
        manual_ssl_hosts: ['example.com'],
    );

    expect($labels->contains(fn ($label) => str_contains($label, 'loadbalancer.server.port=5000')))->toBeTrue();
    expect($labels->contains(fn ($label) => str_contains($label, '.service=https-0-test-uuid')))->toBeTrue();
});
