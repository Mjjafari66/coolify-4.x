<?php

namespace App\Livewire\Server\Proxy;

use App\Actions\Proxy\ApplyProxySslCertificate;
use App\Actions\Proxy\SyncProxySslConfiguration;
use App\Enums\ProxySslMode;
use App\Models\Server;
use App\Models\SslCertificate;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Locked;
use Livewire\Component;

class SslSettings extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public Server $server;

    public string $proxySslMode = ProxySslMode::Letsencrypt->value;

    public ?SslCertificate $proxyCertificate = null;

    public string $certificateContent = '';

    public string $privateKeyContent = '';

    public bool $showCertificate = false;

    public bool $showPrivateKey = false;

    public ?Carbon $certificateValidUntil = null;

    public ?string $certificateCommonName = null;

    public function mount(string $server_uuid): void
    {
        try {
            $this->server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
            $this->loadSettings();
        } catch (\Throwable $e) {
            redirect()->route('server.index');
        }
    }

    public function loadSettings(): void
    {
        $this->proxySslMode = $this->server->settings->proxy_ssl_mode ?? ProxySslMode::Letsencrypt->value;
        $this->proxyCertificate = $this->server->proxyCertificate();

        if ($this->proxyCertificate) {
            $this->certificateContent = $this->proxyCertificate->ssl_certificate;
            $this->privateKeyContent = $this->proxyCertificate->ssl_private_key;
            $this->certificateValidUntil = $this->proxyCertificate->valid_until;
            $this->certificateCommonName = $this->proxyCertificate->common_name;
        }
    }

    public function toggleCertificate(): void
    {
        $this->showCertificate = ! $this->showCertificate;
    }

    public function togglePrivateKey(): void
    {
        $this->showPrivateKey = ! $this->showPrivateKey;
    }

    public function saveSslMode(): void
    {
        try {
            $this->authorize('update', $this->server);

            $mode = ProxySslMode::tryFrom($this->proxySslMode);
            if ($mode === null) {
                throw new \Exception('Invalid SSL mode selected.');
            }

            if ($mode === ProxySslMode::Manual && ! $this->server->proxyCertificate()) {
                throw new \Exception('Upload a certificate and private key before enabling manual SSL.');
            }

            $this->server->settings->proxy_ssl_mode = $mode->value;
            $this->server->settings->save();

            SyncProxySslConfiguration::run($this->server);

            $this->loadSettings();
            $this->dispatch('success', 'Proxy SSL mode updated.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function saveProxyCertificate(): void
    {
        try {
            $this->authorize('update', $this->server);

            if ($this->certificateContent === '' || $this->privateKeyContent === '') {
                throw new \Exception('Certificate and private key are required.');
            }

            ApplyProxySslCertificate::run(
                $this->server,
                $this->certificateContent,
                $this->privateKeyContent,
            );

            $this->loadSettings();
            $this->dispatch('success', 'Proxy certificate saved and applied.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.server.proxy.ssl-settings');
    }
}
