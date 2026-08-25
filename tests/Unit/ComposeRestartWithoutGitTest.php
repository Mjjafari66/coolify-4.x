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
