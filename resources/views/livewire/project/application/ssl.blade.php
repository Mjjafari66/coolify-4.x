<div>
    <div>
        <h2>Domain SSL</h2>
        <p class="pb-4 text-sm">
            Add a domain, upload its manual TLS certificate, then redeploy.
            Automatic Let's Encrypt is never used when manual SSL is enabled on the server or for this application.
        </p>
    </div>

    <div class="pb-6 w-full sm:w-96">
        <x-forms.checkbox instantSave="saveManualSslOnly" canGate="update" :canResource="$application"
            id="manualSslOnly" label="Manual SSL only"
            helper="Disable automatic Let's Encrypt for this application even if the server uses automatic SSL." />
    </div>

    <div class="pb-8 max-w-xl">
        <h3 class="pb-2">Add domain</h3>
        <div class="flex flex-col gap-2 sm:flex-row sm:items-end">
            <div class="flex-1">
                <x-forms.input canGate="update" :canResource="$application" id="newDomain" label="Domain"
                    placeholder="https://example.com or example.com"
                    helper="After Add Domain, the domain appears in the list below (the input clears intentionally)."
                    wire:model="newDomain" />
            </div>
            @can('update', $application)
                <x-forms.button wire:click="addDomain" class="sm:mb-1">Add Domain</x-forms.button>
            @endcan
        </div>
    </div>

    @if (empty($domains))
        <x-callout type="info" title="Next step">
            Add a domain above to unlock certificate upload fields for that domain.
        </x-callout>
    @else
        <div class="space-y-8">
            @foreach ($domains as $domain)
                @php($domainKey = $this->domainKey($domain))
                <div class="p-4 border rounded-lg border-coolgray-300 dark:border-coolgray-200" wire:key="ssl-domain-{{ $domainKey }}">
                    <div class="flex flex-wrap items-center justify-between gap-2 pb-4">
                        <h3>{{ $domain }}</h3>
                        @if ($certificates[$domainKey] ?? null)
                            <span class="text-xs text-green-500">Certificate uploaded</span>
                        @else
                            <span class="text-xs text-yellow-500">No certificate — HTTPS disabled for this domain</span>
                        @endif
                    </div>

                    @if ($this->certificateCommonName($domainKey))
                        <x-callout type="info" title="Current Certificate" class="mb-4">
                            <div class="space-y-1 text-sm">
                                <div><strong>Common Name:</strong> {{ $this->certificateCommonName($domainKey) }}</div>
                                @if ($this->certificateValidUntil($domainKey))
                                    <div><strong>Valid until:</strong>
                                        {{ $this->certificateValidUntil($domainKey)?->format('d.m.Y H:i:s') }}
                                    </div>
                                @endif
                            </div>
                        </x-callout>
                    @endif

                    <div class="space-y-4">
                        <div>
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-sm">Certificate (fullchain.pem)</span>
                                @can('view', $application)
                                    <x-forms.button wire:click="toggleCertificate('{{ $domainKey }}')" type="button"
                                        class="py-1! px-2! text-sm">
                                        {{ ($showCertificate[$domainKey] ?? false) ? 'Hide' : 'Show' }}
                                    </x-forms.button>
                                @endcan
                            </div>
                            @if ($showCertificate[$domainKey] ?? false)
                                <textarea class="w-full h-48 input" wire:model.live="certificateContent.{{ $domainKey }}"
                                    placeholder="Paste PEM certificate content here..."></textarea>
                            @else
                                <div class="w-full h-48 input">
                                    <div class="flex flex-col items-center justify-center h-full text-gray-300">
                                        <div class="text-sm">Click Show to paste certificate</div>
                                    </div>
                                </div>
                            @endif
                        </div>

                        <div>
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-sm">Private Key (privkey.pem)</span>
                                @can('view', $application)
                                    <x-forms.button wire:click="togglePrivateKey('{{ $domainKey }}')" type="button"
                                        class="py-1! px-2! text-sm">
                                        {{ ($showPrivateKey[$domainKey] ?? false) ? 'Hide' : 'Show' }}
                                    </x-forms.button>
                                @endcan
                            </div>
                            @if ($showPrivateKey[$domainKey] ?? false)
                                <textarea class="w-full h-48 input" wire:model.live="privateKeyContent.{{ $domainKey }}"
                                    placeholder="Paste PEM private key content here..."></textarea>
                            @else
                                <div class="w-full h-48 input">
                                    <div class="flex flex-col items-center justify-center h-full text-gray-300">
                                        <div class="text-sm">Click Show to paste private key</div>
                                    </div>
                                </div>
                            @endif
                        </div>

                        <div class="flex flex-wrap gap-2">
                            @can('update', $application)
                                <x-forms.button wire:click="saveDomainCertificate('{{ $domainKey }}')">
                                    Save Certificate
                                </x-forms.button>
                                @if ($certificates[$domainKey] ?? null)
                                    <x-forms.button isDanger wire:click="removeDomainCertificate('{{ $domainKey }}')">
                                        Remove Certificate
                                    </x-forms.button>
                                @endif
                            @endcan
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <x-callout type="warning" title="After changing domains or certificates" class="mt-6">
            Redeploy this application so Traefik labels are regenerated. Domains without an uploaded certificate stay on
            HTTP only.
        </x-callout>
    @endif
</div>
