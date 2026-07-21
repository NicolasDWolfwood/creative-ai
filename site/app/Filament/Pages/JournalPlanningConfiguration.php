<?php

namespace App\Filament\Pages;

use App\Enums\JournalPlanningMode;
use App\Models\PostTemplate;
use App\Models\User;
use App\Services\JournalPlanningSettings;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;

class JournalPlanningConfiguration extends Page
{
    protected string $view = 'filament.pages.journal-planning-configuration';

    protected static ?string $title = 'Journal planning defaults';

    protected static ?string $navigationLabel = 'Journal planning';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-plus';

    protected static string|\UnitEnum|null $navigationGroup = 'Publishing';

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'journal-planning';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->is_admin;
    }

    public function mount(): void
    {
        $this->form->fill(app(JournalPlanningSettings::class)->formValues());
    }

    public function getTitle(): string|Htmlable
    {
        return 'Journal planning defaults';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Choose when content workflows offer or create a private linked Journal draft. Nothing here publishes a story or invokes AI.';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Single-source workflows')
                    ->description('Off does nothing. Ask lets the workflow offer a choice. Automatic creates only a private Draft after the source operation succeeds.')
                    ->columns(2)
                    ->schema([
                        $this->modeSelect('artwork_mode', 'Artwork'),
                        $this->modeSelect('collection_mode', 'Collection'),
                        $this->modeSelect('album_mode', 'Album'),
                        $this->modeSelect('playlist_mode', 'Playlist'),
                        $this->modeSelect('track_mode', 'Standalone track'),
                    ]),
                Section::make('Batch and import workflows')
                    ->description('Artwork batches use one draft for the ordered upload. Album imports use at most one draft per detected album, never one per member track.')
                    ->columns(2)
                    ->schema([
                        $this->modeSelect('artwork_batch_mode', 'Artwork bulk upload'),
                        $this->modeSelect('album_import_mode', 'Album import'),
                    ]),
                Section::make('New draft defaults')
                    ->description('These values only prefill a requested private draft. Source media and publication state remain unchanged.')
                    ->columns(2)
                    ->schema([
                        Select::make('post_template_id')
                            ->label('Journal template')
                            ->placeholder('Start without a template')
                            ->options(fn (): array => PostTemplate::query()
                                ->active()
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->columnSpanFull(),
                        Toggle::make('copy_shared_tags')
                            ->label('Copy public shared tags')
                            ->helperText('Private-only tags remain excluded.')
                            ->inline(false),
                        Toggle::make('use_source_artwork_as_cover')
                            ->label('Use suitable source artwork as cover')
                            ->helperText('The draft workflow may copy an available image into Journal cover storage.')
                            ->inline(false),
                    ]),
            ]);
    }

    public function save(): void
    {
        $defaults = app(JournalPlanningSettings::class)->save($this->form->getState());
        $this->form->fill($defaults->toArray());

        Notification::make()
            ->success()
            ->title('Journal planning defaults saved')
            ->body('These defaults can only create private drafts; publication and AI remain separate human-controlled actions.')
            ->send();
    }

    private function modeSelect(string $name, string $label): Select
    {
        return Select::make($name)
            ->label($label)
            ->options(JournalPlanningMode::options())
            ->required()
            ->native(false);
    }
}
