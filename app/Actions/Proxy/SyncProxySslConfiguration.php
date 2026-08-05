<?php

namespace App\Actions\Proxy;

use App\Enums\ProxySslMode;
use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;

class SyncProxySslConfiguration
{
    use AsAction;

    public function handle(Server $server, bool $restartProxy = true): void
    {
        $proxySslMode = serverProxySslMode($server);

        if ($proxySslMode === ProxySslMode::Manual && $server->proxyCertificate()) {
            writeProxyManualSslConfigurationToServer(
                $server,
                $server->proxyCertificate()->ssl_certificate,
                $server->proxyCertificate()->ssl_private_key,
            );
        }

        if ($proxySslMode !== ProxySslMode::Manual) {
            removeProxyManualSslConfigurationFromServer($server);
        }

        syncApplicationTraefikCertificatesOnServer($server);

        GetProxyConfiguration::run($server, forceRegenerate: true);
        $server->setupDefaultRedirect();
        $server->setupDynamicProxyConfiguration();

        if ($restartProxy && $server->proxySet()) {
            StartProxy::run($server, async: false, force: true, restarting: true);
        }
    }
}
