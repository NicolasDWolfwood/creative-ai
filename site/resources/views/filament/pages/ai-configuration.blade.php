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
                            <th><span class="sr-only">Selection</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($providerModels as $model)
                            @php($provider = $data['provider'] ?? 'ollama')
                            @php($selected = ($data[$provider.'_model'] ?? null) === $model['name'])
                            <tr wire:key="provider-model-{{ md5($provider.$model['name']) }}" class="{{ $selected ? 'is-selected' : '' }}">
                                <td>
                                    <div class="ca-ai-model-name">
                                        <strong>{{ $model['label'] ?? $model['name'] }}</strong>
                                        <small>{{ $model['name'] }}</small>
                                        @if ($model['recommended'])
                                            <span>Recommended</span>
                                        @elseif (! $model['suitable'])
                                            <span class="is-muted">Not suitable</span>
                                        @endif
                                    </div>
                                </td>
                                <td>{{ $model['parameter_size'] }}</td>
                                <td>{{ $model['quantization'] }}</td>
                                <td>{{ $model['context_label'] }}</td>
                                @foreach (['vision', 'completion', 'structured', 'tools', 'thinking'] as $capability)
                                    <td class="ca-ai-capability">
                                        @if (in_array($capability, $model['capabilities'], true))
                                            <x-heroicon-m-check title="Supported" /><span class="sr-only">Supported</span>
                                        @else
                                            <x-heroicon-m-minus title="Not reported" /><span class="sr-only">Not reported</span>
                                        @endif
                                    </td>
                                @endforeach
                                <td class="ca-ai-model-action">
                                    @if ($selected)
                                        <span class="ca-ai-selected-label">Selected</span>
                                    @elseif ($model['suitable'])
                                        <button type="button" wire:click="chooseModel(@js($model['name']))" title="Use {{ $model['name'] }}">
                                            <x-heroicon-o-check-circle /><span class="sr-only">Select {{ $model['name'] }}</span>
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
