<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Proxy SSL | Coolify
    </x-slot>
    <livewire:server.navbar :server="$server" />
    @if ($server->isFunctional())
        <div class="flex flex-col h-full gap-8 sm:flex-row">
            <x-server.sidebar-proxy :server="$server" :parameters="['server_uuid' => $server->uuid]" />
            <div class="flex flex-col w-full gap-6">
                <div>
                    <h2>Proxy SSL</h2>
                    <p class="pb-4 text-sm">Control how Traefik obtains TLS certificates for this server. Use manual
                        mode when Let's Encrypt is unavailable.</p>
                </div>

                <div class="pb-6 w-full sm:w-96">
                    <x-forms.select canGate="update" :canResource="$server" id="proxySslMode" label="SSL Mode"
                        helper="Manual mode uses uploaded certificates. Disabled mode exposes applications over HTTP only.">
                        @foreach (\App\Enums\ProxySslMode::cases() as $mode)
                            <option value="{{ $mode->value }}">{{ $mode->label() }}</option>
                        @endforeach
                    </x-forms.select>
                    @can('update', $server)
                        <x-forms.button wire:click="saveSslMode" class="mt-2">Save SSL Mode</x-forms.button>
                    @endcan
                </div>

                <div class="space-y-4">
                    <div class="flex items-center gap-2">
                        <h3>Manual Certificate</h3>
                        @can('update', $server)
                            <x-forms.button wire:click="saveProxyCertificate">Save Certificate</x-forms.button>
                        @endcan
                    </div>

                    @if ($certificateCommonName)
                        <x-callout type="info" title="Current Certificate">
                            <div class="space-y-1 text-sm">
                                <div><strong>Common Name:</strong> {{ $certificateCommonName }}</div>
                                @if ($certificateValidUntil)
                                    <div><strong>Valid until:</strong>
                                        @if (now()->gt($certificateValidUntil))
                                            <span class="text-red-500">{{ $certificateValidUntil->format('d.m.Y H:i:s') }}
                                                (Expired)</span>
                                        @elseif(now()->addDays(30)->gt($certificateValidUntil))
                                            <span class="text-red-500">{{ $certificateValidUntil->format('d.m.Y H:i:s') }}
                                                (Expiring soon)</span>
                                        @else
                                            <span>{{ $certificateValidUntil->format('d.m.Y H:i:s') }}</span>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        </x-callout>
                    @endif

                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-sm">Certificate (fullchain.pem)</span>
                            @can('view', $server)
                                <x-forms.button wire:click="toggleCertificate" type="button" class="py-1! px-2! text-sm">
                                    {{ $showCertificate ? 'Hide' : 'Show' }}
                                </x-forms.button>
                            @endcan
                        </div>
                        @if ($showCertificate)
                            <textarea class="w-full h-64 input" wire:model="certificateContent"
                                placeholder="Paste PEM certificate content here..."></textarea>
                        @else
                            <div class="w-full h-64 input">
                                <div class="flex flex-col items-center justify-center h-full text-gray-300">
                                    <div class="mb-2">━━━━━━━━ CERTIFICATE CONTENT ━━━━━━━━</div>
                                    <div class="text-sm">Click "Show" to view or edit</div>
                                </div>
                            </div>
                        @endif
                    </div>

                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-sm">Private Key (privkey.pem)</span>
                            @can('view', $server)
                                <x-forms.button wire:click="togglePrivateKey" type="button" class="py-1! px-2! text-sm">
                                    {{ $showPrivateKey ? 'Hide' : 'Show' }}
                                </x-forms.button>
                            @endcan
                        </div>
                        @if ($showPrivateKey)
                            <textarea class="w-full h-64 input" wire:model="privateKeyContent"
                                placeholder="Paste PEM private key content here..."></textarea>
                        @else
                            <div class="w-full h-64 input">
                                <div class="flex flex-col items-center justify-center h-full text-gray-300">
                                    <div class="mb-2">━━━━━━━━ PRIVATE KEY CONTENT ━━━━━━━━</div>
                                    <div class="text-sm">Click "Show" to view or edit</div>
                                </div>
                            </div>
                        @endif
                    </div>

                    <x-callout type="warning" title="After changing SSL settings">
                        Redeploy applications that already have HTTPS domains so Traefik labels are regenerated without
                        Let's Encrypt.
                    </x-callout>
                </div>
            </div>
        </div>
    @else
        <div>Server is not validated. Validate first.</div>
    @endif
</div>
