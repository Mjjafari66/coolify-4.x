<?php

use App\Models\Application;
use App\Models\SslCertificate;

it('includes certificate SAN hostnames in manualSslHosts', function () {
    $application = Mockery::mock(Application::class)->makePartial();
    $application->shouldAllowMockingProtectedMethods();

    $certificate = new SslCertificate([
        'domain' => 'm-shahabadi.com',
        'common_name' => 'm-shahabadi.com',
        'subject_alternative_names' => [
            'DNS:m-shahabadi.com',
            'DNS:www.m-shahabadi.com',
        ],
    ]);

    $application->shouldReceive('traefikSslCertificates')->andReturn(
        new class($certificate)
        {
            public function __construct(private SslCertificate $certificate) {}

            public function get($columns = ['*'])
            {
                return collect([$this->certificate]);
            }
        }
    );

    expect($application->manualSslHosts())->toBe([
        'm-shahabadi.com',
        'www.m-shahabadi.com',
    ]);
});
