<x-filament-panels::page>
    <form wire:submit="save" class="ca-ai-settings-form">
        {{ $this->form }}

        <div class="ca-ai-settings-actions">
            <x-filament::button type="submit" icon="heroicon-o-check">Save settings</x-filament::button>
            <x-filament::button type="button" color="gray" icon="heroicon-o-arrow-path" wire:click="refreshModels">
                Inspect provider
            </x-filament::button>
        </div>
    </form>

    <section class="ca-ai-server" aria-labelledby="provider-models-heading">
        <div class="ca-ai-section-heading">
            <div>
                <p class="creative-admin-eyebrow">{{ $this->providerLabel() }}</p>
                <h2 id="provider-models-heading">Model capabilities</h2>
            </div>
            <div class="ca-ai-server-status {{ $providerError ? 'is-error' : 'is-online' }}">
                <span></span>
                {{ $providerError ? 'Configuration needed' : 'Ready'.($providerVersion ? ' · '.$providerVersion : '') }}
            </div>
        </div>

        @if ($providerError)
            <div class="ca-ai-error" role="alert">{{ $providerError }}</div>
        @elseif (count($providerModels))
            <div class="ca-ai-model-table-wrap">
                <table class="ca-ai-model-table">
                    <thead>
                        <tr>
                            <th>Model</th>
                            <th>Runtime</th>
                            <th>Profile</th>
                            <th>Context</th>
                            <th title="Image input">Vision</th>
                            <th title="Text generation">Text</th>
                            <th title="Schema-constrained output">JSON</th>
                            <th>Tools</th>
                            <th title="Extended reasoning support">Reason</th>
                            <th title="Suitable for artwork metadata">Image</th>
                            <th title="Suitable for Journal writing">Journal</th>
                            <th>Use model</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($providerModels as $model)
                            @php($provider = $data['provider'] ?? 'ollama')
                            @php($imageSelected = ($data[$provider.'_model'] ?? null) === $model['name'])
                            @php($journalSelected = ($data[$provider.'_journal_model'] ?? null) === $model['name'])
                            @php($selected = $imageSelected || $journalSelected)
                            <tr wire:key="provider-model-{{ md5($provider.$model['name']) }}" class="{{ $selected ? 'is-selected' : '' }}">
                                <td>
                                    <div class="ca-ai-model-name">
                                        <strong>{{ $model['label'] ?? $model['name'] }}</strong>
                                        <small>{{ $model['name'] }}</small>
                                        @if ($model['recommended'])
                                            <span>Recommended</span>
                                        @elseif (! $model['image_suitable'] && ! $model['journal_suitable'])
                                            <span class="is-muted">No supported use</span>
                                        @endif
                                    </div>
                                </td>
                                <td>{{ $model['parameter_size'] }}</td>
                                <td>{{ $model['quantization'] }}</td>
                                <td>{{ $model['context_label'] }}</td>
                                @foreach (['vision', 'completion', 'structured', 'tools', 'thinking'] as $capability)
                                    <td class="ca-ai-capability">
                                        @if (in_array($capability, $model['capabilities'], true))
                                            <x-filament::icon icon="heroicon-m-check" title="Supported" /><span class="sr-only">Supported</span>
                                        @else
                                            <x-filament::icon icon="heroicon-m-minus" title="Not reported" /><span class="sr-only">Not reported</span>
                                        @endif
                                    </td>
                                @endforeach
                                @foreach (['image_suitable', 'journal_suitable'] as $suitability)
                                    <td class="ca-ai-capability">
                                        @if ($model[$suitability])
                                            <x-filament::icon icon="heroicon-m-check" title="Suitable" /><span class="sr-only">Suitable</span>
                                        @else
                                            <x-filament::icon icon="heroicon-m-minus" title="Not suitable" /><span class="sr-only">Not suitable</span>
                                        @endif
                                    </td>
                                @endforeach
                                <td class="ca-ai-model-action">
                                    @if ($imageSelected)
                                        <span class="ca-ai-selected-label">Image</span>
                                    @elseif ($model['image_suitable'])
                                        <button type="button" wire:click="chooseModel(@js($model['name']), 'image')" title="Use {{ $model['name'] }} for image analysis">
                                            <x-filament::icon icon="heroicon-o-photo" /><span class="sr-only">Select {{ $model['name'] }} for image analysis</span>
                                        </button>
                                    @endif
                                    @if ($journalSelected)
                                        <span class="ca-ai-selected-label">Journal</span>
                                    @elseif ($model['journal_suitable'])
                                        <button type="button" wire:click="chooseModel(@js($model['name']), 'journal')" title="Use {{ $model['name'] }} for Journal writing">
                                            <x-filament::icon icon="heroicon-o-document-text" /><span class="sr-only">Select {{ $model['name'] }} for Journal writing</span>
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div class="ca-ai-empty">Inspect this provider to load models available to the configured account or server.</div>
        @endif
    </section>

    <section class="ca-deployment-settings" aria-labelledby="deployment-settings-heading">
        <div class="ca-ai-section-heading">
            <div><p class="creative-admin-eyebrow">Deployment</p><h2 id="deployment-settings-heading">Runtime status</h2></div>
        </div>
        <div class="ca-deployment-grid">
            @foreach ($this->getDeploymentSummary() as $setting)
                <div><span>{{ $setting['label'] }}</span><strong>{{ $setting['value'] }}</strong><small>{{ $setting['state'] }}</small></div>
            @endforeach
        </div>
    </section>
</x-filament-panels::page>
