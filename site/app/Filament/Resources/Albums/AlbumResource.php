<?php

namespace App\Filament\Resources\Albums;

use App\Filament\Resources\Albums\Pages\ManageAlbums;
use App\Models\Album;
use App\Services\AlbumCoverService;
use App\Services\MusicArtworkSuggestionService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AlbumResource extends Resource
{
    protected static ?string $model = Album::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-circle-stack';

    protected static string|\UnitEnum|null $navigationGroup = 'Music Library';

    protected static ?int $navigationSort = 15;

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->required()->maxLength(255),
            TextInput::make('artist')->maxLength(255),
            TextInput::make('album_artist')->label('Album artist')->maxLength(255),
            TextInput::make('slug')->maxLength(255),
            Select::make('cover_artwork_id')->relationship('coverArtwork', 'title')->searchable()->preload(),
            Select::make('cover_preference')->label('Cover source')->options(['auto' => 'Artwork, then embedded', 'artwork' => 'Artwork library only', 'embedded' => 'Embedded cover only', 'none' => 'No cover'])->default('auto')->native(false),
            FileUpload::make('embedded_cover_path')->label('Embedded cover preview')->disk('local')->visibility('private')->image()->disabled()->openable()->downloadable(),
            TextInput::make('release_year')->numeric()->minValue(1000)->maxValue(9999),
            Textarea::make('description')->rows(3)->columnSpanFull(),
            Repeater::make('tracks')
                ->relationship()
                ->label('Track listing')
                ->schema([
                    TextInput::make('disc_number')->label('Disc')->numeric()->default(1)->required(),
                    TextInput::make('track_number')->label('Track')->numeric()->required(),
                    TextInput::make('title')->required()->columnSpan(2),
                    TextInput::make('artist')->columnSpan(2),
                    Toggle::make('standalone_published')
                        ->label('Standalone')
                        ->helperText('Also show this track outside the album.')
                        ->inline(false),
                ])
                ->columns(7)
                ->orderColumn('track_number')
                ->reorderable()
                ->addable(false)
                ->deletable(false)
                ->columnSpanFull(),
            TextInput::make('sort_order')->numeric()->default(0),
            Toggle::make('featured'),
            Toggle::make('published')->helperText('Publishing the album makes its complete track listing publicly playable. Tracks stay off the standalone list unless enabled individually.'),
            DateTimePicker::make('published_at'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title')->searchable()->sortable(), TextColumn::make('artist')->searchable(),
            TextColumn::make('release_year')->sortable(), TextColumn::make('tracks_count')->counts('tracks')->label('Tracks'),
            IconColumn::make('published')->boolean(),
        ])->recordActions([ActionGroup::make([
            Action::make('suggestArtwork')->label('Suggest artwork')->icon('heroicon-o-photo')
                ->schema([Select::make('cover_artwork_id')->label('Ranked by shared tags')->options(fn (Album $record) => app(MusicArtworkSuggestionService::class)->albumOptions($record))->required()])
                ->action(function (Album $record, array $data): void {
                    $record->update(['cover_artwork_id' => $data['cover_artwork_id']]);
                    Notification::make()->success()->title('Album artwork selected')->send();
                }),
            Action::make('importEmbeddedCover')->label('Import embedded cover to artwork library')->icon('heroicon-o-arrow-down-tray')
                ->visible(fn (Album $record): bool => filled($record->embedded_cover_path))->requiresConfirmation()
                ->action(function (Album $record): void {
                    app(AlbumCoverService::class)->import($record);
                    Notification::make()->success()->title('Embedded cover imported as a draft artwork')->send();
                }),
            Action::make('publishAlbum')
                ->label('Publish album')->icon('heroicon-o-play-circle')->color('success')
                ->visible(fn (Album $record): bool => ! $record->published)
                ->requiresConfirmation()
                ->modalDescription('Publishes the album and makes its complete track listing playable. It does not add those tracks to the standalone track list.')
                ->action(function (Album $record): void {
                    $record->update(['published' => true, 'published_at' => $record->published_at ?: now()]);
                    Notification::make()->success()->title('Album published')->body($record->tracks()->count().' track'.($record->tracks()->count() === 1 ? '' : 's').' available through this album.')->send();
                }),
            EditAction::make(),
            DeleteAction::make(),
        ])]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageAlbums::route('/')];
    }
}
