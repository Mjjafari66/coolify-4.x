<?php

use App\Enums\ProxySslMode;
use Illuminate\Support\Collection;

it('does not add letsencrypt certresolver in manual ssl mode', function () {
    $labels = fqdnLabelsForTraefik(
        uuid: 'test-uuid',
        domains: collect(['https://example.com']),
        proxy_ssl_mode: ProxySslMode::Manual,
    );

    expect($labels->contains('traefik.http.routers.https-0-test-uuid.tls=true'))->toBeTrue();
    expect($labels->contains(fn ($label) => str_contains($label, 'certresolver=letsencrypt')))->toBeFalse();
});

it('does not create https routers when ssl mode is off', function () {
    $labels = fqdnLabelsForTraefik(
        uuid: 'test-uuid',
        domains: collect(['https://example.com']),
        proxy_ssl_mode: ProxySslMode::Off,
    );

    expect($labels->contains(fn ($label) => str_contains($label, 'entryPoints=https')))->toBeFalse();
    expect($labels->contains('traefik.http.routers.http-0-test-uuid.entryPoints=http'))->toBeTrue();
});

it('keeps letsencrypt certresolver in automatic ssl mode', function () {
    $labels = fqdnLabelsForTraefik(
        uuid: 'test-uuid',
        domains: collect(['https://example.com']),
        proxy_ssl_mode: ProxySslMode::Letsencrypt,
    );

    expect($labels->contains('traefik.http.routers.https-0-test-uuid.tls.certresolver=letsencrypt'))->toBeTrue();
});

it('generates manual ssl dynamic configuration for traefik', function () {
    $yaml = generateProxyManualSslDynamicConfiguration();

    expect($yaml)->toContain('certFile: /traefik/certs/fullchain.pem');
    expect($yaml)->toContain('keyFile: /traefik/certs/privkey.pem');
});

it('returns tls config based on server proxy ssl mode', function () {
    $server = Mockery::mock(\App\Models\Server::class)->makePartial();
    $settings = new \App\Models\ServerSetting;
    $settings->proxy_ssl_mode = ProxySslMode::Manual->value;
    $server->settings = $settings;

    expect(traefikRouterTlsConfig($server))->toBe([]);
});

it('returns letsencrypt resolver config for automatic mode', function () {
    $server = Mockery::mock(\App\Models\Server::class)->makePartial();
    $settings = new \App\Models\ServerSetting;
    $settings->proxy_ssl_mode = ProxySslMode::Letsencrypt->value;
    $server->settings = $settings;

    expect(traefikRouterTlsConfig($server))->toBe(['certResolver' => 'letsencrypt']);
});
