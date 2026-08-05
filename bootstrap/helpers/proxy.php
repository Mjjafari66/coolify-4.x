<?php

use App\Actions\Proxy\SaveProxyConfiguration;
use App\Enums\ProxySslMode;
use App\Enums\ProxyTypes;
use App\Models\Application;
use App\Models\Server;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Yaml\Yaml;

/**
 * Check if a network name is a Docker predefined system network.
 * These networks cannot be created, modified, or managed by docker network commands.
 *
 * @param  string  $network  Network name to check
 * @return bool True if it's a predefined network that should be skipped
 */
function isDockerPredefinedNetwork(string $network): bool
{
    // Only filter 'default' and 'host' to match existing codebase patterns
    // See: bootstrap/helpers/parsers.php:891, bootstrap/helpers/shared.php:689,748
    return in_array($network, ['default', 'host'], true);
}

function collectProxyDockerNetworksByServer(Server $server)
{
    if (! $server->isFunctional()) {
        return collect();
    }
    $proxyType = $server->proxyType();
    if (is_null($proxyType) || $proxyType === 'NONE') {
        return collect();
    }
    $networks = instant_remote_process(['docker inspect --format="{{json .NetworkSettings.Networks }}" coolify-proxy'], $server, false);

    return collect($networks)->map(function ($network) {
        return collect(json_decode($network))->keys();
    })->flatten()->unique();
}
function collectDockerNetworksByServer(Server $server)
{
    $allNetworks = collect([]);
    if ($server->isSwarm()) {
        $networks = collect($server->swarmDockers)->map(function ($docker) {
            return $docker['network'];
        });
    } else {
        // Standalone networks
        $networks = collect($server->standaloneDockers)->map(function ($docker) {
            return $docker['network'];
        });
    }
    $allNetworks = $allNetworks->merge($networks);
    // Service networks
    foreach ($server->services()->get() as $service) {
        if ($service->isRunning()) {
            $networks->push($service->networks());
        }
        $allNetworks->push($service->networks());
    }
    // Docker compose based apps
    $docker_compose_apps = $server->dockerComposeBasedApplications();
    foreach ($docker_compose_apps as $app) {
        if ($app->isRunning()) {
            $networks->push($app->uuid);
        }
        $allNetworks->push($app->uuid);
    }
    // Docker compose based preview deployments
    $docker_compose_previews = $server->dockerComposeBasedPreviewDeployments();
    foreach ($docker_compose_previews as $preview) {
        if (! $preview->isRunning()) {
            continue;
        }
        $pullRequestId = $preview->pull_request_id;
        $applicationId = $preview->application_id;
        $application = Application::find($applicationId);
        if (! $application) {
            continue;
        }
        $network = "{$application->uuid}-{$pullRequestId}";
        $networks->push($network);
        $allNetworks->push($network);
    }
    $networks = collect($networks)->flatten()->unique()->filter(function ($network) {
        return ! isDockerPredefinedNetwork($network);
    });
    $allNetworks = $allNetworks->flatten()->unique()->filter(function ($network) {
        return ! isDockerPredefinedNetwork($network);
    });
    if ($server->isSwarm()) {
        if ($networks->count() === 0) {
            $networks = collect(['coolify-overlay']);
            $allNetworks = collect(['coolify-overlay']);
        }
    } else {
        if ($networks->count() === 0) {
            $networks = collect(['coolify']);
            $allNetworks = collect(['coolify']);
        }
    }

    return [
        'networks' => $networks,
        'allNetworks' => $allNetworks,
    ];
}
function connectProxyToNetworks(Server $server)
{
    ['networks' => $networks] = collectDockerNetworksByServer($server);
    if ($server->isSwarm()) {
        $commands = $networks->map(function ($network) {
            $safe = escapeshellarg($network);

            return [
                "docker network ls --format '{{.Name}}' | grep '^{$network}$' >/dev/null || docker network create --driver overlay --attachable {$safe} >/dev/null",
                "docker network connect {$safe} coolify-proxy >/dev/null 2>&1 || true",
                "echo 'Successfully connected coolify-proxy to {$safe} network.'",
            ];
        });
    } else {
        $commands = $networks->map(function ($network) {
            $safe = escapeshellarg($network);

            return [
                "docker network ls --format '{{.Name}}' | grep '^{$network}$' >/dev/null || docker network create --attachable {$safe} >/dev/null",
                "docker network connect {$safe} coolify-proxy >/dev/null 2>&1 || true",
                "echo 'Successfully connected coolify-proxy to {$safe} network.'",
            ];
        });
    }

    return $commands->flatten();
}

/**
 * Ensures all required networks exist before docker compose up.
 * This must be called BEFORE docker compose up since the compose file declares networks as external.
 *
 * @param  Server  $server  The server to ensure networks on
 * @return Collection Commands to create networks if they don't exist
 */
function ensureProxyNetworksExist(Server $server)
{
    ['allNetworks' => $networks] = collectDockerNetworksByServer($server);

    if ($server->isSwarm()) {
        $commands = $networks->map(function ($network) {
            $safe = escapeshellarg($network);

            return [
                "echo 'Ensuring network {$safe} exists...'",
                "docker network ls --format '{{.Name}}' | grep -q '^{$network}$' || docker network create --driver overlay --attachable {$safe}",
            ];
        });
    } else {
        $commands = $networks->map(function ($network) {
            $safe = escapeshellarg($network);

            return [
                "echo 'Ensuring network {$safe} exists...'",
                "docker network ls --format '{{.Name}}' | grep -q '^{$network}$' || docker network create --attachable {$safe}",
            ];
        });
    }

    return $commands->flatten();
}

function serverProxySslMode(Server $server): ProxySslMode
{
    $mode = data_get($server, 'settings.proxy_ssl_mode', ProxySslMode::Letsencrypt->value);

    return ProxySslMode::tryFrom($mode) ?? ProxySslMode::Letsencrypt;
}

function traefikRouterTlsConfig(Server $server): array
{
    return match (serverProxySslMode($server)) {
        ProxySslMode::Letsencrypt => ['certResolver' => 'letsencrypt'],
        ProxySslMode::Manual => [],
        ProxySslMode::Off => [],
    };
}

function proxyManualSslDynamicConfigFilename(): string
{
    return 'coolify-manual-ssl.yaml';
}

function generateProxyManualSslDynamicConfiguration(): string
{
    $dynamic_conf = [
        'tls' => [
            'certificates' => [
                [
                    'certFile' => '/traefik/certs/fullchain.pem',
                    'keyFile' => '/traefik/certs/privkey.pem',
                ],
            ],
        ],
    ];

    $yaml = Yaml::dump($dynamic_conf, 12, 2);

    return "# This file is automatically generated by Coolify.\n# Do not edit it manually.\n\n".$yaml;
}

function writeProxyManualSslConfigurationToServer(Server $server, string $certificate, string $privateKey): void
{
    $proxy_path = $server->proxyPath();
    $certs_path = "{$proxy_path}/certs";
    $dynamic_path = "{$proxy_path}/dynamic";
    $dynamic_file = proxyManualSslDynamicConfigFilename();

    $base64Cert = base64_encode($certificate);
    $base64Key = base64_encode($privateKey);
    $base64Dynamic = base64_encode(generateProxyManualSslDynamicConfiguration());

    instant_remote_process([
        "mkdir -p {$certs_path} {$dynamic_path}",
        "echo '{$base64Cert}' | base64 -d | tee {$certs_path}/fullchain.pem > /dev/null",
        "echo '{$base64Key}' | base64 -d | tee {$certs_path}/privkey.pem > /dev/null",
        "chmod 644 {$certs_path}/fullchain.pem",
        "chmod 600 {$certs_path}/privkey.pem",
        "echo '{$base64Dynamic}' | base64 -d | tee {$dynamic_path}/{$dynamic_file} > /dev/null",
    ], $server);
}

function removeProxyManualSslConfigurationFromServer(Server $server): void
{
    $proxy_path = $server->proxyPath();
    $dynamic_file = proxyManualSslDynamicConfigFilename();

    instant_remote_process([
        "rm -f {$proxy_path}/dynamic/{$dynamic_file}",
        "rm -f {$proxy_path}/certs/fullchain.pem {$proxy_path}/certs/privkey.pem",
    ], $server);
}

function applicationTraefikCertificateDirectoryName(\App\Models\Application $application, string $domain): string
{
    $slug = preg_replace('/[^a-zA-Z0-9.-]+/', '-', strtolower($domain));

    return "{$application->uuid}/{$slug}";
}

function applicationTraefikCertificateRelativePaths(\App\Models\Application $application, string $domain): array
{
    $directory = applicationTraefikCertificateDirectoryName($application, $domain);

    return [
        'certFile' => "/traefik/certs/apps/{$directory}/fullchain.pem",
        'keyFile' => "/traefik/certs/apps/{$directory}/privkey.pem",
    ];
}

function applicationTraefikCertificateHostPath(\App\Models\Application $application, string $domain): string
{
    $directory = applicationTraefikCertificateDirectoryName($application, $domain);

    return $application->destination->server->proxyPath()."/certs/apps/{$directory}";
}

function proxyApplicationTraefikDynamicConfigFilename(): string
{
    return 'coolify-application-certificates.yaml';
}

function generateApplicationTraefikCertificatesDynamicConfiguration(\App\Models\Server $server): string
{
    $certificates = \App\Models\SslCertificate::query()
        ->where('server_id', $server->id)
        ->where('is_application_traefik_certificate', true)
        ->get();

    $tlsCertificates = $certificates->map(function (\App\Models\SslCertificate $certificate) {
        $application = $certificate->application;
        if (! $application instanceof \App\Models\Application || empty($certificate->domain)) {
            return null;
        }

        return applicationTraefikCertificateRelativePaths($application, $certificate->domain);
    })->filter()->values()->all();

    $dynamic_conf = [
        'tls' => [
            'certificates' => $tlsCertificates,
        ],
    ];

    $yaml = Yaml::dump($dynamic_conf, 12, 2);

    // The generation timestamp makes the file content unique on every sync.
    // Traefik's file provider only re-reads certFile contents when the dynamic
    // config file itself changes, so without this a renewed certificate at the
    // same path would keep being served from Traefik's in-memory copy.
    return "# This file is automatically generated by Coolify.\n# Do not edit it manually.\n# Generated at: ".now()->toIso8601String()."\n\n".$yaml;
}

function writeApplicationTraefikCertificateToServer(
    \App\Models\Application $application,
    string $domain,
    string $certificate,
    string $privateKey,
): void {
    $server = $application->destination->server;
    $hostPath = applicationTraefikCertificateHostPath($application, $domain);

    $base64Cert = base64_encode($certificate);
    $base64Key = base64_encode($privateKey);

    instant_remote_process([
        "mkdir -p {$hostPath}",
        "echo '{$base64Cert}' | base64 -d | tee {$hostPath}/fullchain.pem > /dev/null",
        "echo '{$base64Key}' | base64 -d | tee {$hostPath}/privkey.pem > /dev/null",
        "chmod 644 {$hostPath}/fullchain.pem",
        "chmod 600 {$hostPath}/privkey.pem",
    ], $server);
}

function removeApplicationTraefikCertificateFromServer(\App\Models\Application $application, string $domain): void
{
    $server = $application->destination->server;
    $hostPath = applicationTraefikCertificateHostPath($application, $domain);

    instant_remote_process([
        "rm -rf {$hostPath}",
    ], $server);
}

function syncApplicationTraefikCertificatesOnServer(\App\Models\Server $server): void
{
    $proxy_path = $server->proxyPath();
    $dynamic_path = "{$proxy_path}/dynamic";
    $dynamic_file = proxyApplicationTraefikDynamicConfigFilename();
    $certificates = \App\Models\SslCertificate::query()
        ->where('server_id', $server->id)
        ->where('is_application_traefik_certificate', true)
        ->get();

    if ($certificates->isEmpty()) {
        instant_remote_process([
            "rm -f {$dynamic_path}/{$dynamic_file}",
        ], $server);

        return;
    }

    $base64Dynamic = base64_encode(generateApplicationTraefikCertificatesDynamicConfiguration($server));

    instant_remote_process([
        "mkdir -p {$dynamic_path}",
        "echo '{$base64Dynamic}' | base64 -d | tee {$dynamic_path}/{$dynamic_file} > /dev/null",
    ], $server);
}

function applicationEffectiveProxySslMode(\App\Models\Application $application): \App\Enums\ProxySslMode
{
    $serverMode = serverProxySslMode($application->destination->server);

    if ($serverMode !== \App\Enums\ProxySslMode::Letsencrypt) {
        return $serverMode;
    }

    if ($application->settings?->manual_ssl_only) {
        return \App\Enums\ProxySslMode::Manual;
    }

    if ($application->traefikSslCertificates()->exists()) {
        return \App\Enums\ProxySslMode::Manual;
    }

    return $serverMode;
}

/**
 * @return array{0: \App\Enums\ProxySslMode, 1: ?array<int, string>}
 */
function traefikSslOptionsForApplication(\App\Models\Application $application): array
{
    $proxySslMode = applicationEffectiveProxySslMode($application);
    $manualSslHosts = $proxySslMode === \App\Enums\ProxySslMode::Manual
        ? $application->manualSslHosts()
        : null;

    return [$proxySslMode, $manualSslHosts];
}

function extractApplicationDomainHost(string $domain): string
{
    return \App\Helpers\SslHelper::normalizeCertificateDomain($domain);
}

function extractApplicationDomainHosts(?string $fqdn): array
{
    if (empty($fqdn)) {
        return [];
    }

    return collect(explode(',', $fqdn))
        ->map(fn (string $domain) => extractApplicationDomainHost($domain))
        ->filter()
        ->unique()
        ->values()
        ->all();
}

/**
 * All domain hosts configured on an application, including per-service
 * docker-compose domains (where fqdn is empty for compose build packs).
 *
 * @return array<int, string>
 */
function applicationConfiguredDomainHosts(\App\Models\Application $application): array
{
    $hosts = collect(extractApplicationDomainHosts($application->fqdn));

    $composeDomains = json_decode($application->docker_compose_domains ?? '', true);
    if (is_array($composeDomains)) {
        foreach ($composeDomains as $serviceDomains) {
            $domain = data_get($serviceDomains, 'domain');
            if (filled($domain)) {
                $hosts = $hosts->merge(extractApplicationDomainHosts($domain));
            }
        }
    }

    return $hosts->filter()->unique()->values()->all();
}

function extractCustomProxyCommands(Server $server, string $existing_config): array
{
    $custom_commands = [];
    $proxy_type = $server->proxyType();

    if ($proxy_type !== ProxyTypes::TRAEFIK->value || empty($existing_config)) {
        return $custom_commands;
    }

    try {
        $yaml = Yaml::parse($existing_config);
        $existing_commands = data_get($yaml, 'services.traefik.command', []);

        if (empty($existing_commands)) {
            return $custom_commands;
        }

        // Define default commands that Coolify generates
        $default_command_prefixes = [
            '--ping=',
            '--api.',
            '--entrypoints.http.address=',
            '--entrypoints.https.address=',
            '--entrypoints.http.http.encodequerysemicolons=',
            '--entryPoints.http.http2.maxConcurrentStreams=',
            '--entrypoints.https.http.encodequerysemicolons=',
            '--entryPoints.https.http2.maxConcurrentStreams=',
            '--entrypoints.https.http3',
            '--providers.file.',
            '--certificatesresolvers.',
            '--providers.docker',
            '--providers.swarm',
            '--log.level=',
            '--accesslog.',
        ];

        // Extract commands that don't match default prefixes (these are custom)
        foreach ($existing_commands as $command) {
            $is_default = false;
            foreach ($default_command_prefixes as $prefix) {
                if (str_starts_with($command, $prefix)) {
                    $is_default = true;
                    break;
                }
            }
            if (! $is_default) {
                $custom_commands[] = $command;
            }
        }
    } catch (Exception $e) {
        // If we can't parse the config, return empty array
        // Silently fail to avoid breaking the proxy regeneration
    }

    return $custom_commands;
}
function generateDefaultProxyConfiguration(Server $server, array $custom_commands = [])
{
    Log::info('Generating default proxy configuration', [
        'server_id' => $server->id,
        'server_name' => $server->name,
        'custom_commands_count' => count($custom_commands),
        'caller' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3)[1]['class'] ?? 'unknown',
    ]);

    $proxy_path = $server->proxyPath();
    $proxy_type = $server->proxyType();

    if ($server->isSwarm()) {
        $networks = collect($server->swarmDockers)->map(function ($docker) {
            return $docker['network'];
        })->unique();
        if ($networks->count() === 0) {
            $networks = collect(['coolify-overlay']);
        }
    } else {
        $networks = collect($server->standaloneDockers)->map(function ($docker) {
            return $docker['network'];
        })->unique();
        if ($networks->count() === 0) {
            $networks = collect(['coolify']);
        }
    }

    $array_of_networks = collect([]);
    $filtered_networks = collect([]);
    $networks->map(function ($network) use ($array_of_networks, $filtered_networks) {
        if (isDockerPredefinedNetwork($network)) {
            return; // Predefined networks cannot be used in network configuration
        }

        $array_of_networks[$network] = [
            'external' => true,
        ];
        $filtered_networks->push($network);
    });
    if ($proxy_type === ProxyTypes::TRAEFIK->value) {
        $labels = [
            'traefik.enable=true',
            'traefik.http.routers.traefik.entrypoints=http',
            'traefik.http.routers.traefik.service=api@internal',
            'traefik.http.services.traefik.loadbalancer.server.port=8080',
            'coolify.managed=true',
            'coolify.proxy=true',
        ];
        $config = [
            'name' => 'coolify-proxy',
            'networks' => $array_of_networks->toArray(),
            'services' => [
                'traefik' => [
                    'container_name' => 'coolify-proxy',
                    'image' => 'traefik:v3.6',
                    'restart' => RESTART_MODE,
                    'extra_hosts' => [
                        'host.docker.internal:host-gateway',
                    ],
                    'networks' => $filtered_networks->toArray(),
                    'ports' => [
                        '80:80',
                        '443:443',
                        '443:443/udp',
                        '8080:8080',
                    ],
                    'healthcheck' => [
                        'test' => 'wget -qO- http://localhost:80/ping || exit 1',
                        'interval' => '4s',
                        'timeout' => '2s',
                        'retries' => 5,
                    ],
                    'volumes' => [
                        '/var/run/docker.sock:/var/run/docker.sock:ro',

                    ],
                    'command' => [
                        '--ping=true',
                        '--ping.entrypoint=http',
                        '--api.dashboard=true',
                        '--entrypoints.http.address=:80',
                        '--entrypoints.https.address=:443',
                        '--entrypoints.http.http.encodequerysemicolons=true',
                        '--entryPoints.http.http2.maxConcurrentStreams=250',
                        '--entrypoints.https.http.encodequerysemicolons=true',
                        '--entryPoints.https.http2.maxConcurrentStreams=250',
                        '--entrypoints.https.http3',
                        '--providers.file.directory=/traefik/dynamic/',
                        '--providers.file.watch=true',
                    ],
                    'labels' => $labels,
                ],
            ],
        ];

        $proxySslMode = serverProxySslMode($server);
        if ($proxySslMode->usesLetsEncrypt()) {
            $config['services']['traefik']['command'][] = '--certificatesresolvers.letsencrypt.acme.httpchallenge=true';
            $config['services']['traefik']['command'][] = '--certificatesresolvers.letsencrypt.acme.httpchallenge.entrypoint=http';
            $config['services']['traefik']['command'][] = '--certificatesresolvers.letsencrypt.acme.storage=/traefik/acme.json';
        }

        if (isDev()) {
            $config['services']['traefik']['command'][] = '--api.insecure=true';
            $config['services']['traefik']['command'][] = '--log.level=debug';
            $config['services']['traefik']['command'][] = '--accesslog.filepath=/traefik/access.log';
            $config['services']['traefik']['command'][] = '--accesslog.bufferingsize=100';
            $config['services']['traefik']['volumes'][] = '/var/lib/docker/volumes/coolify_dev_coolify_data/_data/proxy/:/traefik';
        } else {
            $config['services']['traefik']['command'][] = '--api.insecure=false';
            $config['services']['traefik']['volumes'][] = "{$proxy_path}:/traefik";
        }

        if ($proxySslMode->usesManualCertificates()) {
            $config['services']['traefik']['volumes'][] = "{$proxy_path}/certs:/traefik/certs:ro";
        }
        if ($server->isSwarm()) {
            data_forget($config, 'services.traefik.container_name');
            data_forget($config, 'services.traefik.restart');
            data_forget($config, 'services.traefik.labels');

            $config['services']['traefik']['command'][] = '--providers.swarm.endpoint=unix:///var/run/docker.sock';
            $config['services']['traefik']['command'][] = '--providers.swarm.exposedbydefault=false';
            $config['services']['traefik']['deploy'] = [
                'labels' => $labels,
                'placement' => [
                    'constraints' => [
                        'node.role==manager',
                    ],
                ],
            ];
        } else {
            $config['services']['traefik']['command'][] = '--providers.docker=true';
            $config['services']['traefik']['command'][] = '--providers.docker.exposedbydefault=false';
        }

        // Append custom commands (e.g., trustedIPs for Cloudflare)
        if (! empty($custom_commands)) {
            foreach ($custom_commands as $custom_command) {
                $config['services']['traefik']['command'][] = $custom_command;
            }
        }
    } elseif ($proxy_type === 'CADDY') {
        $config = [
            'networks' => $array_of_networks->toArray(),
            'services' => [
                'caddy' => [
                    'container_name' => 'coolify-proxy',
                    'image' => 'lucaslorentz/caddy-docker-proxy:2.8-alpine',
                    'restart' => RESTART_MODE,
                    'extra_hosts' => [
                        'host.docker.internal:host-gateway',
                    ],
                    'environment' => [
                        'CADDY_DOCKER_POLLING_INTERVAL=5s',
                        'CADDY_DOCKER_CADDYFILE_PATH=/dynamic/Caddyfile',
                    ],
                    'networks' => $filtered_networks->toArray(),
                    'ports' => [
                        '80:80',
                        '443:443',
                        '443:443/udp',
                    ],
                    'labels' => [
                        'coolify.managed=true',
                        'coolify.proxy=true',
                    ],
                    'volumes' => [
                        '/var/run/docker.sock:/var/run/docker.sock:ro',
                        "{$proxy_path}/dynamic:/dynamic",
                        "{$proxy_path}/config:/config",
                        "{$proxy_path}/data:/data",
                    ],
                ],
            ],
        ];
    } else {
        return null;
    }

    $config = Yaml::dump($config, 12, 2);
    SaveProxyConfiguration::run($server, $config);

    return $config;
}

function getExactTraefikVersionFromContainer(Server $server): ?string
{
    try {
        Log::debug("getExactTraefikVersionFromContainer: Server '{$server->name}' (ID: {$server->id}) - Checking for exact version");

        // Method A: Execute traefik version command (most reliable)
        $versionCommand = "docker exec coolify-proxy traefik version 2>/dev/null | grep -oP 'Version:\s+\K\d+\.\d+\.\d+'";
        Log::debug("getExactTraefikVersionFromContainer: Server '{$server->name}' (ID: {$server->id}) - Running: {$versionCommand}");

        $output = instant_remote_process([$versionCommand], $server, false);

        if (! empty(trim($output))) {
            $version = trim($output);
            Log::debug("getExactTraefikVersionFromContainer: Server '{$server->name}' (ID: {$server->id}) - Detected exact version from command: {$version}");

            return $version;
        }

        // Method B: Try OCI label as fallback
        $labelCommand = "docker inspect coolify-proxy --format '{{index .Config.Labels \"org.opencontainers.image.version\"}}' 2>/dev/null";
        Log::debug("getExactTraefikVersionFromContainer: Server '{$server->name}' (ID: {$server->id}) - Trying OCI label");

        $label = instant_remote_process([$labelCommand], $server, false);

        if (! empty(trim($label))) {
            // Extract version number from label (might have 'v' prefix)
            if (preg_match('/(\d+\.\d+\.\d+)/', trim($label), $matches)) {
                Log::debug("getExactTraefikVersionFromContainer: Server '{$server->name}' (ID: {$server->id}) - Detected from OCI label: {$matches[1]}");

                return $matches[1];
            }
        }

        Log::debug("getExactTraefikVersionFromContainer: Server '{$server->name}' (ID: {$server->id}) - Could not detect exact version");

        return null;
    } catch (Exception $e) {
        Log::error("getExactTraefikVersionFromContainer: Server '{$server->name}' (ID: {$server->id}) - Error: ".$e->getMessage());

        return null;
    }
}

function getTraefikVersionFromDockerCompose(Server $server): ?string
{
    try {
        Log::debug("getTraefikVersionFromDockerCompose: Server '{$server->name}' (ID: {$server->id}) - Starting version detection");

        // Try to get exact version from running container (e.g., "3.6.0")
        $exactVersion = getExactTraefikVersionFromContainer($server);
        if ($exactVersion) {
            Log::debug("getTraefikVersionFromDockerCompose: Server '{$server->name}' (ID: {$server->id}) - Using exact version: {$exactVersion}");

            return $exactVersion;
        }

        // Fallback: Check image tag (current method)
        Log::debug("getTraefikVersionFromDockerCompose: Server '{$server->name}' (ID: {$server->id}) - Falling back to image tag detection");

        $containerName = 'coolify-proxy';
        $inspectCommand = "docker inspect {$containerName} --format '{{.Config.Image}}' 2>/dev/null";

        $image = instant_remote_process([$inspectCommand], $server, false);

        if (empty(trim($image))) {
            Log::debug("getTraefikVersionFromDockerCompose: Server '{$server->name}' (ID: {$server->id}) - Container '{$containerName}' not found or not running");

            return null;
        }

        $image = trim($image);
        Log::debug("getTraefikVersionFromDockerCompose: Server '{$server->name}' (ID: {$server->id}) - Running container image: {$image}");

        // Extract version from image string (e.g., "traefik:v3.6" or "traefik:3.6.0" or "traefik:latest")
        if (preg_match('/traefik:(v?\d+\.\d+(?:\.\d+)?|latest)/i', $image, $matches)) {
            Log::debug("getTraefikVersionFromDockerCompose: Server '{$server->name}' (ID: {$server->id}) - Extracted version from image tag: {$matches[1]}");

            return $matches[1];
        }

        Log::debug("getTraefikVersionFromDockerCompose: Server '{$server->name}' (ID: {$server->id}) - Image format doesn't match expected pattern: {$image}");

        return null;
    } catch (Exception $e) {
        Log::error("getTraefikVersionFromDockerCompose: Server '{$server->name}' (ID: {$server->id}) - Error: ".$e->getMessage());

        return null;
    }
}
