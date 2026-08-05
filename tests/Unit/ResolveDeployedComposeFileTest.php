<?php

it('keeps yml extension for production deployments', function () {
    $result = resolveDeployedComposeFile('/docker-compose.yml');

    expect($result['basename'])->toBe('docker-compose.yml')
        ->and($result['location'])->toBe('/docker-compose.yml');
});

it('keeps yaml extension for production deployments', function () {
    $result = resolveDeployedComposeFile('/docker-compose.yaml');

    expect($result['basename'])->toBe('docker-compose.yaml')
        ->and($result['location'])->toBe('/docker-compose.yaml');
});

it('uses yaml default when compose location is empty', function () {
    $result = resolveDeployedComposeFile('/');

    expect($result['basename'])->toBe('docker-compose.yaml')
        ->and($result['location'])->toBe('/docker-compose.yaml');
});

it('preserves extension for preview deployments', function () {
    $result = resolveDeployedComposeFile('/docker-compose.yml', pullRequestId: 42);

    expect($result['basename'])->toBe('docker-compose-pr-42.yml')
        ->and($result['location'])->toBe('/docker-compose-pr-42.yml');
});

it('preserves nested compose filenames', function () {
    $result = resolveDeployedComposeFile('/backend/docker-compose.prod.yml');

    expect($result['basename'])->toBe('backend/docker-compose.prod.yml')
        ->and($result['location'])->toBe('/backend/docker-compose.prod.yml');
});
