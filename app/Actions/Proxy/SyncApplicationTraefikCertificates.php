<?php

namespace App\Actions\Proxy;

use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;

class SyncApplicationTraefikCertificates
{
    use AsAction;

    public function handle(Server $server, bool $restartProxy = true): void
    {
        syncApplicationTraefikCertificatesOnServer($server);

        if ($restartProxy && $server->proxySet()) {
            StartProxy::run($server, async: false, force: true, restarting: true);
        }
    }
}
