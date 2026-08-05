<?php

namespace App\Livewire\Project\Application;

use App\Actions\Proxy\ApplyApplicationTraefikCertificate;
use App\Actions\Proxy\RemoveApplicationTraefikCertificate;
use App\Models\Application;
use App\Models\SslCertificate;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Spatie\Url\Url;

class Ssl extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public Application $application;

    public bool $manualSslOnly = false;

    /** @var array<string, string> */
    public array $certificateContent = [];

    /** @var array<string, string> */
    public array $privateKeyContent = [];

    /** @var array<string, bool> */
    public array $showCertificate = [];

    /** @var array<string, bool> */
    public array $showPrivateKey = [];

    /** @var array<int, string> */
    public array $domains = [];

    /** @var array<string, ?SslCertificate> */
    public array $certificates = [];

    public string $newDomain = '';

    public function mount(Application $application): void
    {
        $this->application = $application->load(['settings', 'destination.server', 'traefikSslCertificates']);
        $this->loadSettings();
    }

    public function domainKey(string $domain): string
    {
        return 'domain_'.md5($domain);
    }

    public function resolveDomain(string $domainKey): string
    {
        foreach ($this->domains as $domain) {
            if ($this->domainKey($domain) === $domainKey) {
                return $domain;
            }
        }

        throw new \RuntimeException('Unknown domain key.');
    }

    public function loadSettings(): void
    {
        $this->manualSslOnly = (bool) ($this->application->settings?->manual_ssl_only ?? false);
        $this->domains = collect(applicationConfiguredDomainHosts($this->application))
            ->merge($this->application->traefikSslCertificates()->pluck('domain'))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $this->certificates = [];
        $this->certificateContent = [];
        $this->privateKeyContent = [];
        $this->showCertificate = [];
        $this->showPrivateKey = [];

        foreach ($this->domains as $domain) {
            $key = $this->domainKey($domain);
            $certificate = $this->application->traefikSslCertificateForDomain($domain);
            $this->certificates[$key] = $certificate;
            $this->certificateContent[$key] = $certificate?->ssl_certificate ?? '';
            $this->privateKeyContent[$key] = $certificate?->ssl_private_key ?? '';
            $this->showCertificate[$key] = false;
            $this->showPrivateKey[$key] = false;
        }
    }

    public function toggleCertificate(string $domainKey): void
    {
        $this->showCertificate[$domainKey] = ! ($this->showCertificate[$domainKey] ?? false);
    }

    public function togglePrivateKey(string $domainKey): void
    {
        $this->showPrivateKey[$domainKey] = ! ($this->showPrivateKey[$domainKey] ?? false);
    }

    public function saveManualSslOnly(): void
    {
        try {
            $this->authorize('update', $this->application);

            $this->application->settings->manual_ssl_only = $this->manualSslOnly;
            $this->application->settings->save();

            $this->dispatch('success', 'Manual SSL setting updated. Redeploy this application to apply Traefik changes.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function addDomain(): void
    {
        try {
            $this->authorize('update', $this->application);

            $input = trim($this->newDomain);
            if ($input === '') {
                throw new \Exception('Domain is required.');
            }

            if (! str_contains($input, '://')) {
                $input = "https://{$input}";
            }

            Url::fromString($input, ['http', 'https']);
            $host = extractApplicationDomainHost($input);
            $normalized = 'https://'.$host;

            $existing = collect(explode(',', (string) $this->application->fqdn))
                ->map(fn (string $domain) => trim($domain))
                ->filter()
                ->values();

            $hostExists = $existing->contains(
                fn (string $domain) => extractApplicationDomainHost($domain) === $host
            );

            if (! $hostExists) {
                $existing->push($normalized);
            }

            $this->application->fqdn = $existing->implode(',');
            $this->application->save();
            $this->application->refresh();
            $this->loadSettings();

            $key = $this->domainKey($host);
            $this->showCertificate[$key] = true;
            $this->showPrivateKey[$key] = true;
            $this->newDomain = '';

            $this->dispatch('success', "Domain {$host} added below. Paste certificate and private key, then save.");
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function saveDomainCertificate(string $domainKey): void
    {
        try {
            $this->authorize('update', $this->application);

            $domain = $this->resolveDomain($domainKey);
            $certificate = trim($this->certificateContent[$domainKey] ?? '');
            $privateKey = trim($this->privateKeyContent[$domainKey] ?? '');

            if ($certificate === '' || $privateKey === '') {
                throw new \Exception('Certificate and private key are required.');
            }

            ApplyApplicationTraefikCertificate::run(
                $this->application,
                $domain,
                $certificate,
                $privateKey,
            );

            $this->application->refresh();
            $this->loadSettings();

            $this->dispatch('success', "Certificate saved for {$domain}. Redeploy this application to apply HTTPS routing.");
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function removeDomainCertificate(string $domainKey): void
    {
        try {
            $this->authorize('update', $this->application);

            $domain = $this->resolveDomain($domainKey);

            RemoveApplicationTraefikCertificate::run($this->application, $domain);

            $this->application->refresh();
            $this->loadSettings();

            $this->dispatch('success', "Certificate removed for {$domain}. Redeploy this application to update routing.");
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function certificateValidUntil(string $domainKey): ?Carbon
    {
        return $this->certificates[$domainKey]?->valid_until;
    }

    public function certificateCommonName(string $domainKey): ?string
    {
        return $this->certificates[$domainKey]?->common_name;
    }

    public function render()
    {
        return view('livewire.project.application.ssl');
    }
}
