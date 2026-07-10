<?php

namespace App\Filament\Pages;

use App\Services\AiSettings;
use App\Services\OllamaClient;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Throwable;

class AiConfiguration extends Page
{
    protected string $view = 'filament.pages.ai-configuration';

    protected static ?string $title = 'AI configuration';

    protected static ?string $navigationLabel = 'AI configuration';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cpu-chip';

    protected static string|\UnitEnum|null $navigationGroup = 'AI & Automation';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'ai-configuration';

    /** @var array<string, mixed> */
    public ?array $data = [];

    /** @var array<int, array<string, mixed>> */
    public array $ollamaModels = [];

    public ?string $ollamaVersion = null;

    public ?string $ollamaError = null;

    public function mount(): void
    {
        $this->form->fill(app(AiSettings::class)->all());
        $this->refreshModels(notify: false);
    }

    public function getTitle(): string|Htmlable
    {
        return 'AI configuration';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Select the metadata provider and tune image analysis without rebuilding the stack.';
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
                                Section::make('Metadata provider')
                                    ->columns(2)
                                    ->schema([
                                        Select::make('provider')
                                            ->options([
                                                'ollama' => 'Ollama (local)',
                                                'openai' => 'OpenAI API',
                                            ])
                                            ->native(false)
                                            ->required()
                                            ->live(),
                                        TextInput::make('openai_model')
                                            ->label('OpenAI model')
                                            ->required()
                                            ->maxLength(255),
                                        TextInput::make('openai_request_timeout')
                                            ->label('OpenAI timeout')
                                            ->suffix('seconds')
                                            ->numeric()
                                            ->minValue(30)
                                            ->maxValue(300)
                                            ->required(),
                                    ]),
                                Section::make('Ollama connection')
                                    ->columns(2)
                                    ->schema([
                                        TextInput::make('ollama_base_url')
                                            ->label('Server URL')
                                            ->placeholder('http://192.168.1.176:11434')
                                            ->url()
                                            ->required()
                                            ->maxLength(255)
                                            ->columnSpanFull(),
                                        Select::make('ollama_model')
                                            ->label('Model')
                                            ->options(fn (): array => $this->getOllamaModelOptions())
                                            ->searchable()
                                            ->native(false)
                                            ->required(),
                                        TextInput::make('ollama_request_timeout')
                                            ->label('Request timeout')
                                            ->suffix('seconds')
                                            ->numeric()
                                            ->minValue(30)
                                            ->maxValue(300)
                                            ->required(),
                                        TextInput::make('ollama_context_length')
                                            ->label('Context length')
                                            ->numeric()
                                            ->minValue(2048)
                                            ->maxValue(32768)
                                            ->step(1024)
                                            ->required(),
                                        TextInput::make('ollama_keep_alive')
                                            ->label('Keep alive')
                                            ->placeholder('5m')
                                            ->regex('/^-?\d+(?:ms|s|m|h)?$/')
                                            ->required()
                                            ->maxLength(20),
                                    ]),
                            ]),
                        Tab::make('Images')
                            ->icon('heroicon-o-photo')
                            ->schema([
                                Section::make('Analysis image')
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
            ->body('The next queued analysis will use these settings.')
            ->send();
    }

    public function refreshModels(bool $notify = true): void
    {
        $this->ollamaError = null;

        try {
            $inspection = app(OllamaClient::class)->inspect($this->data['ollama_base_url'] ?? null);
            $this->ollamaVersion = $inspection['version'];
            $this->ollamaModels = $inspection['models'];

            if ($notify) {
                Notification::make()
                    ->success()
                    ->title('Ollama connection successful')
                    ->body(count($this->ollamaModels).' installed models found.')
                    ->send();
            }
        } catch (Throwable $exception) {
            $this->ollamaVersion = null;
            $this->ollamaModels = [];
            $this->ollamaError = str($exception->getMessage())->squish()->limit(240, '')->toString();

            if ($notify) {
                Notification::make()
                    ->danger()
                    ->title('Unable to connect to Ollama')
                    ->body($this->ollamaError)
                    ->send();
            }
        }
    }

    public function chooseModel(string $model): void
    {
        if (! collect($this->ollamaModels)->contains(fn (array $candidate): bool => $candidate['name'] === $model)) {
            return;
        }

        $this->data['ollama_model'] = $model;
    }

    /**
     * @return array<string, string>
     */
    public function getOllamaModelOptions(): array
    {
        $options = collect($this->ollamaModels)
            ->where('suitable', true)
            ->mapWithKeys(fn (array $model): array => [
                $model['name'] => $model['name'].' · '.$model['parameter_size'].' · '.$model['quantization'],
            ])
            ->all();

        $selected = (string) ($this->data['ollama_model'] ?? '');

        if (filled($selected) && ! isset($options[$selected])) {
            $options = [$selected => $selected.' · not reported by server'] + $options;
        }

        return $options;
    }

    /**
     * @return array<int, array{label:string, value:string, state:string}>
     */
    public function getDeploymentSummary(): array
    {
        return [
            [
                'label' => 'Application URL',
                'value' => (string) config('app.url'),
                'state' => 'Unraid environment',
            ],
            [
                'label' => 'Database',
                'value' => strtoupper((string) config('database.default')),
                'state' => 'Unraid environment',
            ],
            [
                'label' => 'Cache and queue',
                'value' => ucfirst((string) config('queue.default')),
                'state' => 'Unraid environment',
            ],
            [
                'label' => 'OpenAI API key',
                'value' => filled(config('services.openai.api_key')) ? 'Configured' : 'Not configured',
                'state' => 'Secret environment value',
            ],
        ];
    }
}
