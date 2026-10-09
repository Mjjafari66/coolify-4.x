<?php

it('reads compose memory values in MB', function () {
    expect(composeMemoryToMb('512m'))->toBe(512);
    expect(composeMemoryToMb('1g'))->toBe(1024);
    expect(composeMemoryToMb('1.5G'))->toBe(1536);
    expect(composeMemoryToMb('524288k'))->toBe(512);
    expect(composeMemoryToMb(536870912))->toBe(512);
    expect(composeMemoryToMb('0'))->toBeNull();
    expect(composeMemoryToMb('lots'))->toBeNull();
    expect(composeMemoryToMb(null))->toBeNull();
});

it('weighs services by image', function () {
    expect(composeServiceWeight('redis:7'))->toBe(1);
    expect(composeServiceWeight('postgres:16-alpine'))->toBe(2);
    expect(composeServiceWeight('ghcr.io/acme/web:1'))->toBe(3);
    expect(composeServiceWeight(null))->toBe(3);
});

it('leaves an app without a plan alone', function () {
    $services = ['web' => ['image' => 'nginx']];
    expect(applyComposePackLimits($services, '0', '0'))->toBe([$services, []]);
});

it('gives a single service the whole plan, without swap', function () {
    [$services, $applied] = applyComposePackLimits(['web' => ['image' => 'acme/web']], '512m', '0.25');
    expect($services['web'])->toMatchArray([
        'mem_limit' => '512m',
        'memswap_limit' => '512m',
        'mem_reservation' => '256m',
        'cpus' => 0.25,
    ]);
    expect($applied)->toBe(['web' => ['memory_mb' => 512, 'cpus' => 0.25]]);
});

it('splits the plan by weight and stays within it', function () {
    [$services] = applyComposePackLimits([
        'web' => ['image' => 'acme/web'],
        'db' => ['image' => 'postgres:16'],
        'cache' => ['image' => 'redis:7'],
    ], '1024m', '0.5');
    expect($services['web']['mem_limit'])->toBe('512m');
    expect($services['db']['mem_limit'])->toBe('341m');
    expect($services['cache']['mem_limit'])->toBe('170m');
    $total = composeMemoryToMb($services['web']['mem_limit'])
        + composeMemoryToMb($services['db']['mem_limit'])
        + composeMemoryToMb($services['cache']['mem_limit']);
    expect($total)->toBeLessThanOrEqual(1024);
    expect($services['web']['cpus'] + $services['db']['cpus'] + $services['cache']['cpus'])->toBeLessThanOrEqual(0.5);
});

it('keeps a lower limit the service sets itself and caps a higher one', function () {
    [$services] = applyComposePackLimits([
        'web' => ['image' => 'acme/web', 'mem_limit' => '2g', 'cpus' => 4],
        'worker' => ['image' => 'acme/worker', 'mem_limit' => '100m', 'cpus' => '0.1'],
    ], '1024m', '1');
    expect($services['web']['mem_limit'])->toBe('512m');
    expect($services['web']['cpus'])->toBe(0.5);
    expect($services['worker']['mem_limit'])->toBe('100m');
    expect($services['worker']['cpus'])->toBe(0.1);
});

it('keeps deploy.resources in line with the flat keys', function () {
    [$services] = applyComposePackLimits([
        'web' => ['image' => 'acme/web', 'deploy' => ['resources' => [
            'limits' => ['memory' => '4G', 'cpus' => '2'],
            'reservations' => ['memory' => '2G'],
        ]]],
    ], '512m', '0.25');
    expect($services['web']['deploy']['resources']['limits'])->toBe(['memory' => '512M', 'cpus' => '0.25']);
    expect($services['web']['deploy']['resources']['reservations']['memory'])->toBe('512M');
    expect($services['web']['mem_limit'])->toBe('512m');
    // docker compose rejects a flat mem_reservation that differs from deploy's.
    expect($services['web']['mem_reservation'])->toBe('512m');
});

it('keeps a lower reservation and caps a higher one', function () {
    [$services] = applyComposePackLimits([
        'web' => ['image' => 'acme/web', 'mem_reservation' => '100m'],
        'cache' => ['image' => 'redis:7', 'mem_reservation' => '1g'],
    ], '1024m', '1');
    expect($services['web']['mem_reservation'])->toBe('100m');
    expect($services['cache']['mem_reservation'])->toBe('256m');
});
