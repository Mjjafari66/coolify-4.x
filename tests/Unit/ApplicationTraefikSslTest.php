<?php

use App\Enums\ProxySslMode;
use App\Helpers\SslHelper;
use App\Models\Application;
use App\Models\Server;
use App\Models\ServerSetting;

it('only enables https labels for domains with uploaded manual certificates', function () {
    $labels = fqdnLabelsForTraefik(
        uuid: 'test-uuid',
        domains: collect(['https://secured.example.com', 'https://plain.example.com']),
        proxy_ssl_mode: ProxySslMode::Manual,
        manual_ssl_hosts: ['secured.example.com'],
    );

    expect($labels->contains('traefik.http.routers.https-0-test-uuid.tls=true'))->toBeTrue();
    expect($labels->contains('traefik.http.routers.https-1-test-uuid.tls=true'))->toBeFalse();
    expect($labels->contains('traefik.http.routers.http-1-test-uuid.entryPoints=http'))->toBeTrue();
    expect($labels->contains(fn ($label) => str_contains($label, 'certresolver=letsencrypt')))->toBeFalse();
});

it('uses manual ssl mode for applications when server is in manual mode', function () {
    $server = Mockery::mock(Server::class)->makePartial();
    $settings = new ServerSetting;
    $settings->proxy_ssl_mode = ProxySslMode::Manual->value;
    $server->settings = $settings;

    $application = Mockery::mock(Application::class)->makePartial();
    $application->settings = null;
    $destination = new stdClass;
    $destination->server = $server;
    $application->destination = $destination;

    expect(applicationEffectiveProxySslMode($application))->toBe(ProxySslMode::Manual);
});

it('forces manual ssl mode when application manual ssl only is enabled', function () {
    $server = Mockery::mock(Server::class)->makePartial();
    $settings = new ServerSetting;
    $settings->proxy_ssl_mode = ProxySslMode::Letsencrypt->value;
    $server->settings = $settings;

    $application = Mockery::mock(Application::class)->makePartial();
    $application->settings = (object) ['manual_ssl_only' => true];
    $destination = new stdClass;
    $destination->server = $server;
    $application->destination = $destination;

    $application->shouldReceive('traefikSslCertificates')->andReturn(
        new class
        {
            public function exists(): bool
            {
                return false;
            }
        }
    );

    expect(applicationEffectiveProxySslMode($application))->toBe(ProxySslMode::Manual);
});

it('returns traefik ssl options with manual hosts for applications with uploaded certificates', function () {
    $server = Mockery::mock(Server::class)->makePartial();
    $settings = new ServerSetting;
    $settings->proxy_ssl_mode = ProxySslMode::Letsencrypt->value;
    $server->settings = $settings;

    $application = Mockery::mock(Application::class)->makePartial();
    $application->settings = (object) ['manual_ssl_only' => false];
    $destination = new stdClass;
    $destination->server = $server;
    $application->destination = $destination;

    $application->shouldReceive('traefikSslCertificates')->andReturn(
        new class
        {
            public function exists(): bool
            {
                return true;
            }
        }
    );
    $application->shouldReceive('manualSslHosts')->andReturn(['m-shahabadi.com']);

    [$proxySslMode, $manualSslHosts] = traefikSslOptionsForApplication($application);

    expect($proxySslMode)->toBe(ProxySslMode::Manual);
    expect($manualSslHosts)->toBe(['m-shahabadi.com']);
});

it('builds application certificate paths for traefik dynamic config', function () {
    $application = Mockery::mock(Application::class)->makePartial();
    $application->uuid = 'app-uuid-123';

    $paths = applicationTraefikCertificateRelativePaths($application, 'example.com');

    expect($paths)->toBe([
        'certFile' => '/traefik/certs/apps/app-uuid-123/example.com/fullchain.pem',
        'keyFile' => '/traefik/certs/apps/app-uuid-123/example.com/privkey.pem',
    ]);
});

it('extracts normalized application domain hosts from fqdn', function () {
    expect(extractApplicationDomainHosts('https://Example.com,http://www.test.io'))
        ->toBe(['example.com', 'www.test.io']);
});

it('drops colliding user proxy labels but keeps uuid-scoped and non-proxy labels', function () {
    $labels = collect([
        'traefik.enable=true',
        'traefik.http.routers.homa-app.rule=Host(`${APP_DOMAIN}`)',
        'traefik.http.routers.homa-app.entrypoints=http',
        'traefik.http.services.homa-app.loadbalancer.server.port=5000',
        'traefik.http.routers.https-0-myuuid-app.rule=Host(`example.com`)',
        'traefik.http.middlewares.gzip.compress=true',
        'my.custom.label=value',
    ]);

    $filtered = filterUnsafeComposeProxyLabels($labels, 'myuuid');

    expect($filtered->values()->all())->toBe([
        'traefik.enable=true',
        'traefik.http.routers.https-0-myuuid-app.rule=Host(`example.com`)',
        'traefik.http.middlewares.gzip.compress=true',
        'my.custom.label=value',
    ]);
});

it('includes docker compose domains in configured domain hosts', function () {
    $application = Mockery::mock(Application::class)->makePartial();
    $application->fqdn = null;
    $application->docker_compose_domains = json_encode([
        'app' => ['domain' => 'https://misaghfood.com,https://www.misaghfood.com'],
    ]);

    expect(applicationConfiguredDomainHosts($application))
        ->toBe(['misaghfood.com', 'www.misaghfood.com']);
});

it('preserves intermediate certificates in the stored chain', function () {
    [$leaf, $leafKey] = generateSelfSignedCertificateForDomain('chain.example.com');
    [$fakeIntermediate] = generateSelfSignedCertificateForDomain('intermediate.example');

    $parsed = SslHelper::parseAndValidateApplicationTraefikCertificate(
        $leaf."\n".$fakeIntermediate,
        $leafKey,
        'chain.example.com',
    );

    expect(substr_count($parsed['certificate'], 'BEGIN CERTIFICATE'))->toBe(2);
    expect($parsed['common_name'])->toBe('chain.example.com');
});

it('validates certificate domain coverage', function () {
    [$certificate, $privateKey] = generateSelfSignedCertificateForDomain('app.example.com');

    $parsed = SslHelper::parseAndValidateApplicationTraefikCertificate(
        $certificate,
        $privateKey,
        'app.example.com',
    );

    expect($parsed['common_name'])->toBe('app.example.com');
});

function generateSelfSignedCertificateForDomain(string $domain): array
{
    $privateKey = openssl_pkey_new([
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
        'private_key_bits' => 2048,
    ]);

    openssl_pkey_export($privateKey, $privateKeyStr);

    $csr = openssl_csr_new(['CN' => $domain], $privateKey, ['digest_alg' => 'sha256']);
    $x509 = openssl_csr_sign($csr, null, $privateKey, 365, ['digest_alg' => 'sha256']);
    openssl_x509_export($x509, $certificate);

    return [$certificate, $privateKeyStr];
}
