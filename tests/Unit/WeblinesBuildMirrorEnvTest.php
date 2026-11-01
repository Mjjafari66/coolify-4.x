<?php

// Weblines: weblinesBuildMirrorEnv() (bootstrap/helpers/docker.php).

afterEach(function () {
    putenv('WEBLINES_BUILD_MIRROR');
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
