<?php

namespace App\Console\Commands;

use App\Jobs\RestartProxyJob;
use App\Models\Server;
use Illuminate\Console\Command;

/**
 * Platform patch: ops-triggered proxy restart with no data output.
 *
 * Regenerates the coolify-proxy docker-compose.yml from the current
 * `generateDefaultProxyConfiguration()` code path and recreates the
 * coolify-proxy container, so config-generation changes (e.g. removing
 * an unused published port) take effect without going through the UI.
 */
class RestartServerProxy extends Command
{
    protected $signature = 'proxy:restart {--server= : Server ID or UUID (defaults to the only server if exactly one exists)}';

    protected $description = 'Regenerate proxy config from current code and recreate the coolify-proxy container.';

    public function handle(): int
    {
        $serverOption = $this->option('server');

        if ($serverOption) {
            $server = Server::where('id', $serverOption)->orWhere('uuid', $serverOption)->first();
        } else {
            $servers = Server::all();
            if ($servers->count() !== 1) {
                $this->error('Multiple or zero servers found — pass --server=<id|uuid> explicitly.');

                return self::FAILURE;
            }
            $server = $servers->first();
        }

        if (! $server) {
            $this->error('Server not found.');

            return self::FAILURE;
        }

        $this->info('Restarting proxy...');
        RestartProxyJob::dispatchSync($server);
        $this->info('Done. Verify with `docker ps` / `docker inspect coolify-proxy`.');

        return self::SUCCESS;
    }
}
