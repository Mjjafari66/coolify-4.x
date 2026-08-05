<?php

namespace App\Actions\Proxy;

use App\Helpers\SslHelper;
use App\Models\Application;
use App\Models\SslCertificate;
use Lorisleiva\Actions\Concerns\AsAction;

class ApplyApplicationTraefikCertificate
{
    use AsAction;

    public function handle(Application $application, string $domain, string $certificate, string $privateKey): SslCertificate
    {
        $normalizedDomain = extractApplicationDomainHost($domain);
        $configuredDomains = applicationConfiguredDomainHosts($application);

        if (! in_array($normalizedDomain, $configuredDomains, true)) {
            throw new \RuntimeException("Domain {$normalizedDomain} is not configured on this application.");
        }

        $parsed = SslHelper::parseAndValidateApplicationTraefikCertificate(
            $certificate,
            $privateKey,
            $normalizedDomain,
        );

        $application->traefikSslCertificates()
            ->where('domain', $normalizedDomain)
            ->delete();

        $sslCertificate = SslCertificate::create([
            'ssl_certificate' => $parsed['certificate'],
            'ssl_private_key' => $parsed['private_key'],
            'resource_type' => Application::class,
            'resource_id' => $application->id,
            'server_id' => $application->destination->server->id,
            'domain' => $normalizedDomain,
            'common_name' => $parsed['common_name'],
            'subject_alternative_names' => $parsed['subject_alternative_names'],
            'valid_until' => $parsed['valid_until'],
            'is_ca_certificate' => false,
            'is_proxy_certificate' => false,
            'is_application_traefik_certificate' => true,
        ]);

        writeApplicationTraefikCertificateToServer(
            $application,
            $normalizedDomain,
            $parsed['certificate'],
            $parsed['private_key'],
        );

        SyncApplicationTraefikCertificates::run($application->destination->server);

        return $sslCertificate;
    }
}
