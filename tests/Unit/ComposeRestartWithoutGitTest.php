<?php

use App\Models\Application;

it('allows compose restart without git when raw compose is stored', function () {
    $application = new Application([
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => "services:\n  app:\n    image: nginx\n",
    ]);

    expect(applicationCanRestartComposeWithoutGit($application))->toBeTrue();
});

it('does not skip git for non-compose applications', function () {
    $application = new Application([
        'build_pack' => 'nixpacks',
        'docker_compose_raw' => "services:\n  app:\n    image: nginx\n",
    ]);

    expect(applicationCanRestartComposeWithoutGit($application))->toBeFalse();
});

it('does not skip git when compose raw is missing', function () {
    $application = new Application([
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => null,
    ]);

    expect(applicationCanRestartComposeWithoutGit($application))->toBeFalse();
});

it('prefers built compose image over third-party tags', function () {
    $uuid = 'abc-123';
    $images = [
        'redis:7.4-alpine',
        "{$uuid}_app:deadbeef1234567",
    ];

    expect(preferBuiltComposeImage($uuid, $images))->toBe("{$uuid}_app:deadbeef1234567");
});

it('rejects registry tags when extracting git sha from image', function () {
    expect(gitLikeShaFromComposeImageTag('redis:7.4-alpine'))->toBeNull();
    expect(gitLikeShaFromComposeImageTag('app-uuid_app:deadbeef1234567'))->toBe('deadbeef1234567');
    expect(gitLikeShaFromComposeImageTag('app-uuid_app:HEAD'))->toBeNull();
});

it('normalizes compose domain keys to the parser lookup form', function () {
    expect(normalizeComposeDomainKey('sakhtstudio-website'))->toBe('sakhtstudio_website');
    expect(normalizeComposeDomainKey('api.v2'))->toBe('api_v2');
    expect(normalizeComposeDomainKey('app'))->toBe('app');
});

it('rewrites stored hyphenated compose domain keys', function () {
    $stored = json_encode(['sakhtstudio-website' => ['domain' => 'https://a.example,https://b.example']]);

    expect(json_decode(normalizedComposeDomainsJson($stored), true))
        ->toBe(['sakhtstudio_website' => ['domain' => 'https://a.example,https://b.example']]);
});

it('leaves already-normalized compose domain keys alone', function () {
    expect(normalizedComposeDomainsJson(json_encode(['web_app' => ['domain' => 'https://a.example']])))->toBeNull();
    expect(normalizedComposeDomainsJson(null))->toBeNull();
    expect(normalizedComposeDomainsJson('not json'))->toBeNull();
});

it('keeps the normalized entry when both key forms are stored', function () {
    $stored = json_encode([
        'web_app' => ['domain' => 'https://new.example'],
        'web-app' => ['domain' => 'https://old.example'],
    ]);

    expect(json_decode(normalizedComposeDomainsJson($stored), true))
        ->toBe(['web_app' => ['domain' => 'https://new.example']]);
});
