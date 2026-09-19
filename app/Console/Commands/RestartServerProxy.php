<?php

namespace App\Console\Commands;

use App\Actions\Proxy\GetProxyConfiguration;
use App\Actions\Proxy\SaveProxyConfiguration;
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

        // GetProxyConfiguration::run() reads `last_saved_proxy_configuration` from the
        // DB by default and only falls back to generateDefaultProxyConfiguration() when
        // that field is empty — on a long-running server it never is, so a plain
        // RestartProxyJob (used by both the UI's "Restart Proxy" button and this command)
        // just re-saves the stale cached config. Force a fresh generation from the
        // current code and persist it *before* restarting, so the job's own (non-forced)
        // read picks up what we just wrote.
        $this->info('Regenerating proxy configuration from current code...');
        $configuration = GetProxyConfiguration::run($server, forceRegenerate: true);
        SaveProxyConfiguration::run($server, $configuration);

        $this->info('Restarting proxy...');
        RestartProxyJob::dispatchSync($server);
        $this->info('Done. Verify with `docker ps` / `docker inspect coolify-proxy`.');

        return self::SUCCESS;
    }
}
