<x-filament-panels::page>
    <form wire:submit="save" class="ca-ai-settings-form">
        {{ $this->form }}

        <div class="ca-ai-settings-actions">
            <x-filament::button type="submit" icon="heroicon-o-check">
                Save settings
            </x-filament::button>
            <x-filament::button type="button" color="gray" icon="heroicon-o-arrow-path" wire:click="refreshModels">
                Refresh models
            </x-filament::button>
        </div>
    </form>

    <section class="ca-ai-server" aria-labelledby="ollama-models-heading">
        <div class="ca-ai-section-heading">
            <div>
                <p class="creative-admin-eyebrow">Ollama server</p>
                <h2 id="ollama-models-heading">Model capabilities</h2>
            </div>
            <div class="ca-ai-server-status {{ $ollamaError ? 'is-error' : 'is-online' }}">
                <span></span>
                {{ $ollamaError ? 'Unavailable' : 'Online · v'.($ollamaVersion ?: 'unknown') }}
            </div>
        </div>

        @if ($ollamaError)
            <div class="ca-ai-error" role="alert">{{ $ollamaError }}</div>
        @elseif (count($ollamaModels))
            <div class="ca-ai-model-table-wrap">
                <table class="ca-ai-model-table">
                    <thead>
                        <tr>
                            <th>Model</th>
                            <th>Parameters</th>
                            <th>Quantization</th>
                            <th>Size</th>
                            <th>Context</th>
                            <th title="Image input">Vision</th>
                            <th title="Text generation">Text</th>
                            <th>Tools</th>
                            <th>Thinking</th>
                            <th>Embeddings</th>
                            <th><span class="sr-only">Selection</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($ollamaModels as $model)
                            @php($selected = ($data['ollama_model'] ?? null) === $model['name'])
                            <tr wire:key="ollama-model-{{ md5($model['name']) }}" class="{{ $selected ? 'is-selected' : '' }}">
                                <td>
                                    <div class="ca-ai-model-name">
                                        <strong>{{ $model['name'] }}</strong>
                                        @if ($model['recommended'])
                                            <span>Recommended</span>
                                        @elseif (! $model['suitable'])
                                            <span class="is-muted">Not suitable</span>
                                        @endif
                                    </div>
                                </td>
                                <td>{{ $model['parameter_size'] }}</td>
                                <td>{{ $model['quantization'] }}</td>
                                <td>{{ $model['size_label'] }}</td>
                                <td>{{ $model['context_label'] }}</td>
                                @foreach (['vision', 'completion', 'tools', 'thinking', 'embedding'] as $capability)
                                    <td class="ca-ai-capability">
                                        @if (in_array($capability, $model['capabilities'], true))
                                            <x-heroicon-m-check title="Supported" />
                                            <span class="sr-only">Supported</span>
                                        @else
                                            <x-heroicon-m-minus title="Not supported" />
                                            <span class="sr-only">Not supported</span>
                                        @endif
                                    </td>
                                @endforeach
                                <td class="ca-ai-model-action">
                                    @if ($selected)
                                        <span class="ca-ai-selected-label">Selected</span>
                                    @elseif ($model['suitable'])
                                        <button type="button" wire:click="chooseModel('{{ $model['name'] }}')" title="Use {{ $model['name'] }}">
                                            <x-heroicon-o-check-circle />
                                            <span class="sr-only">Select {{ $model['name'] }}</span>
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div class="ca-ai-empty">No models were reported by this Ollama server.</div>
        @endif
    </section>

    <section class="ca-deployment-settings" aria-labelledby="deployment-settings-heading">
        <div class="ca-ai-section-heading">
            <div>
                <p class="creative-admin-eyebrow">Deployment</p>
                <h2 id="deployment-settings-heading">Unraid-managed settings</h2>
            </div>
        </div>
        <div class="ca-deployment-grid">
            @foreach ($this->getDeploymentSummary() as $setting)
                <div>
                    <span>{{ $setting['label'] }}</span>
                    <strong>{{ $setting['value'] }}</strong>
                    <small>{{ $setting['state'] }}</small>
                </div>
            @endforeach
        </div>
    </section>
</x-filament-panels::page>
