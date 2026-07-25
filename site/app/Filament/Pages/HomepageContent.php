<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\SiteContentSettings;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;

class HomepageContent extends Page
{
    protected string $view = 'filament.pages.homepage-content';

    protected static ?string $title = 'Homepage content';

    protected static ?string $navigationLabel = 'Homepage';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-home';

    protected static string|\UnitEnum|null $navigationGroup = 'Showcase';

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'site-settings';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->is_admin;
    }

    public function mount(): void
    {
        $this->form->fill(app(SiteContentSettings::class)->formValues());
    }

    public function getTitle(): string|Htmlable
    {
        return 'Homepage content';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Edit the public introduction without creating or remembering technical setting keys.';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Homepage introduction')
                    ->description('The site name stays fixed. This is the editable sentence shown directly beneath it.')
                    ->schema([
                        Placeholder::make('homepage_heading')
                            ->label('Fixed heading')
                            ->content('Creative-Ai')
                            ->helperText('The site name is part of the public design and is not changed by this setting.'),
                        Textarea::make('body')
                            ->label('Introduction text')
                            ->helperText('Shown on the homepage, artwork archive, and tag-filtered views. It also supplies their default search description.')
                            ->required()
                            ->maxLength(SiteContentSettings::MAX_HOME_INTRO_LENGTH)
                            ->rows(3)
                            ->autosize()
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public function save(): void
    {
        $values = app(SiteContentSettings::class)->save($this->form->getState());
        $this->form->fill($values);

        Notification::make()
            ->success()
            ->title('Homepage content saved')
            ->body('The introduction is now public on the homepage and related showcase views.')
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('viewHomepage')
                ->label('View homepage')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->url(fn (): string => route('home'))
                ->openUrlInNewTab(),
        ];
    }
}
