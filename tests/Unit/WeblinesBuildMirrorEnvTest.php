<?php

// Weblines: weblinesBuildMirrorEnv() (bootstrap/helpers/docker.php).

afterEach(function () {
    putenv('WEBLINES_BUILD_MIRROR');
    putenv('WEBLINES_APT_MIRROR');
});

test('defaults to the Weblines mirror for every package manager', function () {
    putenv('WEBLINES_BUILD_MIRROR');
    $env = weblinesBuildMirrorEnv();

    expect($env['PIP_INDEX_URL'])->toBe('https://mirror.weblines.ir/pypi/simple/')
        ->and($env['NPM_CONFIG_REGISTRY'])->toBe('https://mirror.weblines.ir/npm/')
        ->and($env['COREPACK_NPM_REGISTRY'])->toBe('https://mirror.weblines.ir/npm')
        ->and($env['COREPACK_INTEGRITY_KEYS'])->toBe('0')
        ->and($env['PRISMA_ENGINES_MIRROR'])->toBe('https://mirror.weblines.ir/prisma')
        ->and(array_keys($env))->toContain('UV_DEFAULT_INDEX', 'YARN_REGISTRY', 'YARN_NPM_REGISTRY_SERVER', 'BUN_CONFIG_REGISTRY');
});

test('WEBLINES_BUILD_MIRROR overrides the base URL', function () {
    putenv('WEBLINES_BUILD_MIRROR=https://mirror-eu.weblines.ir/');
    expect(weblinesBuildMirrorEnv()['PIP_INDEX_URL'])->toBe('https://mirror-eu.weblines.ir/pypi/simple/');
});

test('WEBLINES_BUILD_MIRROR=off disables it', function () {
    putenv('WEBLINES_BUILD_MIRROR=off');
    expect(weblinesBuildMirrorEnv())->toBe([]);
});

test('every value is safe as a bash env value and a build arg', function () {
    putenv('WEBLINES_BUILD_MIRROR');
    foreach (weblinesBuildMirrorEnv() as $key => $value) {
        expect($key)->toMatch('/^[A-Z][A-Z0-9_]*$/')
            ->and($value)->not->toMatch('/[\s\'"$`]/');
    }
});

// Weblines: weblinesAptMirrorHosts() and weblinesAddAptMirrorToComposeBuilds().

test('apt hosts point at the mirror IP', function () {
    putenv('WEBLINES_BUILD_MIRROR');
    putenv('WEBLINES_APT_MIRROR');
    $hosts = weblinesAptMirrorHosts(fn (string $host) => $host === 'mirror.weblines.ir' ? '5.61.28.86' : $host);

    expect($hosts)->toBe([
        'deb.debian.org' => '5.61.28.86',
        'security.debian.org' => '5.61.28.86',
        'debian.map.fastlydns.net' => '5.61.28.86',
        'archive.ubuntu.com' => '5.61.28.86',
        'security.ubuntu.com' => '5.61.28.86',
    ]);
});

test('no apt hosts when the name does not resolve, apt is off, or the mirror is off', function () {
    putenv('WEBLINES_BUILD_MIRROR');
    // gethostbyname() returns the name itself on failure.
    expect(weblinesAptMirrorHosts(fn (string $host) => $host))->toBe([]);

    putenv('WEBLINES_APT_MIRROR=off');
    expect(weblinesAptMirrorHosts(fn () => '5.61.28.86'))->toBe([]);
    putenv('WEBLINES_APT_MIRROR');

    putenv('WEBLINES_BUILD_MIRROR=off');
    expect(weblinesAptMirrorHosts(fn () => '5.61.28.86'))->toBe([]);
    putenv('WEBLINES_BUILD_MIRROR');
});

test('compose builds get the apt hosts; image-only services and own entries are kept', function () {
    $hosts = ['deb.debian.org' => '1.2.3.4', 'archive.ubuntu.com' => '1.2.3.4'];
    $services = weblinesAddAptMirrorToComposeBuilds([
        'db' => ['image' => 'postgres:16-alpine'],
        'web' => ['build' => '.'],
        'api' => ['build' => ['context' => '.', 'dockerfile' => 'apps/api/Dockerfile', 'extra_hosts' => ['deb.debian.org=9.9.9.9']]],
        'map' => ['build' => ['context' => '.', 'extra_hosts' => ['archive.ubuntu.com' => '8.8.8.8']]],
    ], $hosts);

    expect($services['db'])->toBe(['image' => 'postgres:16-alpine'])
        ->and($services['web']['build'])->toBe(['context' => '.', 'extra_hosts' => ['deb.debian.org:1.2.3.4', 'archive.ubuntu.com:1.2.3.4']])
        ->and($services['api']['build']['extra_hosts'])->toBe(['deb.debian.org=9.9.9.9', 'archive.ubuntu.com:1.2.3.4'])
        ->and($services['map']['build']['extra_hosts'])->toBe(['archive.ubuntu.com' => '8.8.8.8', 'deb.debian.org' => '1.2.3.4']);
});

test('compose services are unchanged without apt hosts', function () {
    $services = ['web' => ['build' => '.']];
    expect(weblinesAddAptMirrorToComposeBuilds($services, []))->toBe($services);
});
