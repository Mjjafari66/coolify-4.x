<?php

namespace App\Actions\Proxy;

use App\Models\Application;
use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;

class RemoveApplicationTraefikCertificate
{
    use AsAction;

    public function handle(Application $application, string $domain): void
    {
        $normalizedDomain = extractApplicationDomainHost($domain);

        $application->traefikSslCertificates()
            ->where('domain', $normalizedDomain)
            ->delete();

        removeApplicationTraefikCertificateFromServer($application, $normalizedDomain);

        SyncApplicationTraefikCertificates::run($application->destination->server);
    }
}
