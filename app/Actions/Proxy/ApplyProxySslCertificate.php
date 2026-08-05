<?php

namespace App\Actions\Proxy;

use App\Enums\ProxySslMode;
use App\Helpers\SslHelper;
use App\Models\Server;
use App\Models\SslCertificate;
use Lorisleiva\Actions\Concerns\AsAction;

class ApplyProxySslCertificate
{
    use AsAction;

    public function handle(Server $server, string $certificate, string $privateKey): SslCertificate
    {
        $parsed = SslHelper::parseAndValidateProxyCertificate($certificate, $privateKey);

        $server->sslCertificates()
            ->where('is_proxy_certificate', true)
            ->delete();

        $sslCertificate = SslCertificate::create([
            'ssl_certificate' => $parsed['certificate'],
            'ssl_private_key' => $parsed['private_key'],
            'server_id' => $server->id,
            'common_name' => $parsed['common_name'],
            'subject_alternative_names' => $parsed['subject_alternative_names'],
            'valid_until' => $parsed['valid_until'],
            'is_ca_certificate' => false,
            'is_proxy_certificate' => true,
        ]);

        writeProxyManualSslConfigurationToServer(
            $server,
            $parsed['certificate'],
            $parsed['private_key']
        );

        if ($server->settings->proxy_ssl_mode !== ProxySslMode::Manual->value) {
            $server->settings->proxy_ssl_mode = ProxySslMode::Manual->value;
            $server->settings->save();
        }

        SyncProxySslConfiguration::run($server);

        return $sslCertificate;
    }
}
