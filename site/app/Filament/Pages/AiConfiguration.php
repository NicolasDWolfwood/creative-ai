<?php

namespace App\Filament\Pages;

use App\Services\AiProviderManager;
use App\Services\AiSettings;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Throwable;

class AiConfiguration extends Page
{
    protected string $view = 'filament.pages.ai-configuration';

    protected static ?string $title = 'AI configuration';

    protected static ?string $navigationLabel = 'AI providers';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cpu-chip';

    protected static string|\UnitEnum|null $navigationGroup = 'AI & Automation';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'ai-configuration';

    /** @var array<string, mixed> */
    public ?array $data = [];

    /** @var array<int, array<string, mixed>> */
    public array $providerModels = [];

    public ?string $providerVersion = null;

    public ?string $providerError = null;

    public function mount(): void
    {
        $this->form->fill(app(AiSettings::class)->formValues());
        $this->refreshModels(notify: false);
    }

    public function getTitle(): string|Htmlable
    {
        return 'AI providers';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Choose local or cloud image intelligence, compare available models, and keep credentials encrypted.';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Tabs::make('AI settings')
                    ->persistTabInQueryString('settings-tab')
                    ->tabs([
                        Tab::make('Provider')
                            ->icon('heroicon-o-cpu-chip')
                            ->schema([
                                Section::make('Active metadata provider')
                                    ->description('Queued artwork uses the provider and model selected here when the worker starts the job.')
                                    ->schema([
                                        Select::make('provider')
                                            ->options(AiSettings::PROVIDERS)
                                            ->native(false)
                                            ->required()
                                            ->live()
                                            ->afterStateUpdated(function (): void {
                                                $this->refreshModels(notify: false);
                                            })
                                            ->columnSpanFull(),
                                    ]),
                                $this->ollamaSection(),
                                $this->cloudSection('openai', 'OpenAI', 'https://api.openai.com/v1'),
                                $this->cloudSection('anthropic', 'Claude by Anthropic', 'https://api.anthropic.com/v1'),
                                $this->cloudSection('zai', 'Z.AI', 'https://api.z.ai/api/paas/v4'),
                            ]),
                        Tab::make('Images')
                            ->icon('heroicon-o-photo')
                            ->schema([
                                Section::make('Analysis image')
                                    ->description('Original uploads stay private. A stripped, resized JPEG is sent to the selected provider.')
                                    ->columns(2)
                                    ->schema([
                                        TextInput::make('image_max_width')
                                            ->label('Maximum width')
                                            ->suffix('pixels')
                                            ->numeric()
                                            ->minValue(256)
                                            ->maxValue(2048)
                                            ->step(64)
                                            ->required(),
                                        TextInput::make('image_jpeg_quality')
                                            ->label('JPEG quality')
                                            ->suffix('%')
                                            ->numeric()
                                            ->minValue(40)
                                            ->maxValue(95)
                                            ->required(),
                                        Toggle::make('auto_analyze_uploads')
                                            ->label('Analyze new uploads automatically')
                                            ->helperText('Bulk upload can override this per batch.')
                                            ->columnSpanFull(),
                                    ]),
                            ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    public function save(): void
    {
        $settings = app(AiSettings::class)->save($this->form->getState());
        $this->form->fill($settings);

        Notification::make()
            ->success()
            ->title('AI settings saved')
            ->body('The next queued analysis will use these settings. Saved API keys remain encrypted and hidden.')
            ->send();
    }

    public function refreshModels(bool $notify = true): void
    {
        $provider = (string) ($this->data['provider'] ?? app(AiSettings::class)->provider());
        $this->providerError = null;

        try {
            $inspection = app(AiProviderManager::class)->inspect($provider, [
                'base_url' => $this->data[$provider.'_base_url'] ?? null,
                'api_key' => $this->data[$provider.'_api_key'] ?? null,
                'model' => $this->data[$provider.'_model'] ?? null,
            ]);
            $this->providerVersion = $inspection['version'];
            $this->providerModels = $inspection['models'];

            if ($notify) {
                Notification::make()
                    ->success()
                    ->title(AiSettings::PROVIDERS[$provider].' is ready')
                    ->body(count($this->providerModels).' compatible models found.')
                    ->send();
            }
        } catch (Throwable $exception) {
            $this->providerVersion = null;
            $this->providerModels = [];
            $this->providerError = str($exception->getMessage())->squish()->limit(240, '')->toString();

            if ($notify) {
                Notification::make()
                    ->danger()
                    ->title('Unable to inspect provider')
                    ->body($this->providerError)
                    ->send();
            }
        }
    }

    public function chooseModel(string $model): void
    {
        if (! collect($this->providerModels)->contains(fn (array $candidate): bool => $candidate['name'] === $model)) {
            return;
        }

        $provider = (string) ($this->data['provider'] ?? 'ollama');
        $this->data[$provider.'_model'] = $model;
    }

    /** @return array<string, string> */
    public function getModelOptions(string $provider): array
    {
        $options = collect($this->providerModels)
            ->where('suitable', true)
            ->mapWithKeys(fn (array $model): array => [
                $model['name'] => ($model['label'] ?? $model['name']).' · '.$model['context_label'],
            ])
            ->all();
        $selected = (string) ($this->data[$provider.'_model'] ?? '');

        if (filled($selected) && ! isset($options[$selected])) {
            $options = [$selected => $selected.' · saved selection'] + $options;
        }

        return $options;
    }

    public function providerLabel(): string
    {
        return AiSettings::PROVIDERS[$this->data['provider'] ?? 'ollama'] ?? 'AI provider';
    }

    /** @return array<int, array{label:string, value:string, state:string}> */
    public function getDeploymentSummary(): array
    {
        $settings = app(AiSettings::class);

        return [
            ['label' => 'Application URL', 'value' => (string) config('app.url'), 'state' => 'Unraid environment'],
            ['label' => 'Database', 'value' => strtoupper((string) config('database.default')), 'state' => 'Unraid environment'],
            ['label' => 'Cache and queue', 'value' => ucfirst((string) config('queue.default')), 'state' => 'Unraid environment'],
            ['label' => 'Cloud credentials', 'value' => collect(['openai', 'anthropic', 'zai'])->filter(fn (string $provider): bool => $settings->hasApiKey($provider))->count().' configured', 'state' => 'Encrypted database settings'],
        ];
    }

    protected function ollamaSection(): Section
    {
        return Section::make('Ollama connection')
            ->visible(fn (Get $get): bool => $get('provider') === 'ollama')
            ->columns(2)
            ->schema([
                TextInput::make('ollama_base_url')->label('Server URL')->url()->required()->maxLength(255)->columnSpanFull(),
                Select::make('ollama_model')->label('Model')->options(fn (): array => $this->getModelOptions('ollama'))->searchable()->native(false)->required(),
                TextInput::make('ollama_request_timeout')->label('Request timeout')->suffix('seconds')->numeric()->minValue(30)->maxValue(600)->required(),
                TextInput::make('ollama_context_length')->label('Context length')->numeric()->minValue(2048)->maxValue(131072)->step(1024)->required(),
                TextInput::make('ollama_keep_alive')->label('Keep alive')->placeholder('5m')->regex('/^-?\d+(?:ms|s|m|h)?$/')->required()->maxLength(20),
            ]);
    }

    protected function cloudSection(string $provider, string $label, string $placeholder): Section
    {
        return Section::make($label.' connection')
            ->visible(fn (Get $get): bool => $get('provider') === $provider)
            ->columns(2)
            ->schema([
                TextInput::make($provider.'_api_key')
                    ->label('API key')
                    ->password()
                    ->revealable()
                    ->autocomplete(false)
                    ->placeholder(app(AiSettings::class)->hasApiKey($provider) ? 'Configured - leave blank to keep' : 'Paste API key')
                    ->helperText('Encrypted before storage. Leave blank to preserve the configured key.')
                    ->maxLength(1000)
                    ->columnSpanFull(),
                TextInput::make($provider.'_base_url')->label('API base URL')->placeholder($placeholder)->url()->required()->maxLength(255)->columnSpanFull(),
                Select::make($provider.'_model')->label('Model')->options(fn (): array => $this->getModelOptions($provider))->searchable()->native(false)->required(),
                TextInput::make($provider.'_request_timeout')->label('Request timeout')->suffix('seconds')->numeric()->minValue(30)->maxValue(600)->required(),
            ]);
    }
}
