<?php

namespace App\Filament\Resources\Playlists;

use App\Filament\Actions\CreateJournalDraftAction;
use App\Filament\Resources\Playlists\Pages\ManagePlaylists;
use App\Models\Album;
use App\Models\Playlist;
use App\Models\Tag;
use App\Models\Track;
use App\Services\SmartPlaylistService;
use App\Services\SmartRuleAiService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PlaylistResource extends Resource
{
    protected static ?string $model = Playlist::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-queue-list';

    protected static string|\UnitEnum|null $navigationGroup = 'Music Library';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Listening playlists';

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->required()->maxLength(255),
            TextInput::make('slug')->maxLength(255)->helperText('Leave empty to generate from the title.'),
            Select::make('cover_artwork_id')
                ->relationship('coverArtwork', 'title')
                ->searchable()
                ->preload(),
            Repeater::make('entries')
                ->relationship()
                ->label('Track sequence')
                ->schema([
                    Select::make('track_id')
                        ->label('Track')
                        ->options(fn (): array => Track::query()->with('album')->orderBy('title')->get()->mapWithKeys(fn ($track): array => [$track->id => $track->title.($track->album ? ' — '.$track->album->title : '')])->all())
                        ->searchable()->preload()->required()->disableOptionsWhenSelectedInSiblingRepeaterItems(),
                    TextInput::make('position')->numeric()->required()->default(1),
                ])
                ->columns(2)
                ->orderColumn('position')
                ->reorderable()
                ->helperText('Drag tracks into playback order. Smart playlists replace this sequence from their rules.')
                ->visible(fn (Get $get): bool => ! (bool) $get('is_smart'))
                ->columnSpanFull(),
            Textarea::make('description')->rows(4)->columnSpanFull(),
            TextInput::make('sort_order')->numeric()->default(0),
            Toggle::make('featured'),
            Toggle::make('published')->default(true),
            DateTimePicker::make('published_at'),
            Toggle::make('is_smart')
                ->label('Smart playlist')
                ->helperText('Automatically fill this playlist from track tags.')
                ->disabled(fn (?Playlist $record): bool => (bool) $record?->is_auto_generated)
                ->live(),
            Section::make('Smart playlist rules')
                ->visible(fn (Get $get): bool => (bool) $get('is_smart'))
                ->columns(2)
                ->schema([
                    Select::make('smart_rules.tag_ids')
                        ->label('Track tags')
                        ->options(fn (): array => Tag::query()->whereHas('tracks')->orderBy('name')->pluck('name', 'id')->all())
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->columnSpanFull(),
                    Select::make('smart_rules.match')
                        ->label('Tag matching')
                        ->options(['any' => 'Match any selected tag', 'all' => 'Match every selected tag'])
                        ->default('any')
                        ->native(false),
                    Toggle::make('smart_rules.only_published')->label('Publicly playable tracks only')->default(true),
                    Select::make('smart_rules.artist')->label('Artist')->options(fn (): array => Track::query()->whereNotNull('artist')->distinct()->orderBy('artist')->pluck('artist', 'artist')->all())->searchable(),
                    Select::make('smart_rules.album_ids')->label('Albums')->multiple()->options(fn (): array => Album::query()->orderBy('title')->pluck('title', 'id')->all())->searchable()->preload(),
                    TextInput::make('smart_rules.min_duration')->label('Minimum seconds')->numeric()->minValue(0),
                    TextInput::make('smart_rules.max_duration')->label('Maximum seconds')->numeric()->minValue(0),
                    TextInput::make('smart_rules.year_from')->label('Release year from')->numeric(),
                    TextInput::make('smart_rules.year_to')->label('Release year through')->numeric(),
                    Select::make('smart_rules.health_status')->label('Library health')->options(['healthy' => 'Healthy', 'attention' => 'Needs attention', 'unknown' => 'Not analyzed'])->native(false),
                    Select::make('smart_rules.has_cover')->label('Cover artwork')->options([1 => 'Has cover', 0 => 'Missing cover'])->native(false),
                    TextInput::make('smart_rules.added_within_days')->label('Added within days')->numeric()->minValue(1),
                    Select::make('smart_rules.order')->label('Track order')->options(['library' => 'Library order', 'newest' => 'Newest first', 'oldest' => 'Oldest first', 'title' => 'Title', 'duration' => 'Shortest first', 'random' => 'Random on sync'])->default('library')->native(false),
                    TextInput::make('smart_rules.max_tracks')->label('Maximum tracks')->numeric()->minValue(0)->helperText('0 means no limit.'),
                    Toggle::make('auto_sync')->label('Keep playlist synchronized')->default(true),
                ])
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('title')->searchable()->sortable(),
                TextColumn::make('tracks_count')->counts('tracks')->label('Tracks')->sortable(),
                TextColumn::make('is_auto_generated')->label('Type')->badge()
                    ->formatStateUsing(fn (bool $state, Playlist $record): string => $state ? 'Automatic' : ($record->is_smart ? 'Smart' : 'Manual'))
                    ->color(fn (bool $state, Playlist $record): string => $state ? 'success' : ($record->is_smart ? 'info' : 'gray')),
                TextColumn::make('sort_order')->sortable(),
                IconColumn::make('featured')->boolean(),
                IconColumn::make('published')->boolean(),
                TextColumn::make('last_synced_at')->label('Synced')->since()->placeholder('Never')->toggleable(),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('syncSmartPlaylist')
                        ->label('Sync smart playlist')
                        ->icon('heroicon-o-arrow-path')
                        ->visible(fn (Playlist $record): bool => $record->is_smart)
                        ->action(function (Playlist $record): void {
                            $count = app(SmartPlaylistService::class)->sync($record);
                            Notification::make()->success()->title($count.' tracks matched')->send();
                        }),
                    Action::make('suggestSmartRules')
                        ->label('Suggest rules with AI')
                        ->icon('heroicon-o-sparkles')
                        ->color('info')
                        ->requiresConfirmation()
                        ->visible(fn (Playlist $record): bool => $record->is_smart)
                        ->action(function (Playlist $record): void {
                            $suggestion = app(SmartRuleAiService::class)->suggest(
                                'music playlist',
                                $record->title,
                                $record->description,
                                Tag::query()->whereHas('tracks')->orderBy('name')->get(),
                            );
                            $record->forceFill(['smart_rules' => array_replace($record->smart_rules ?? [], [
                                'tag_ids' => $suggestion['tag_ids'],
                                'match' => 'any',
                                'ai_explanation' => $suggestion['explanation'],
                                'ai_model' => $suggestion['model'],
                            ])])->saveQuietly();
                            $count = app(SmartPlaylistService::class)->sync($record);

                            Notification::make()->success()->title('AI rules suggested')->body($suggestion['explanation'].' '.$count.' tracks matched.')->send();
                        }),
                    Action::make('snapshot')
                        ->label('Freeze as manual snapshot')->icon('heroicon-o-camera')->color('warning')
                        ->visible(fn (Playlist $record): bool => $record->is_smart)->requiresConfirmation()
                        ->action(function (Playlist $record): void {
                            app(SmartPlaylistService::class)->sync($record);
                            $record->forceFill([
                                'is_smart' => false,
                                'is_auto_generated' => false,
                                'auto_generation_key' => null,
                                'auto_sync' => false,
                                'smart_rules' => array_merge($record->smart_rules ?? [], ['snapshot_at' => now()->toIso8601String()]),
                            ])->saveQuietly();
                            Notification::make()->success()->title('Manual playlist snapshot created')->send();
                        }),
                    Action::make('keepAsCustom')
                        ->label('Keep as custom smart playlist')->icon('heroicon-o-lock-open')->color('warning')
                        ->visible(fn (Playlist $record): bool => $record->is_auto_generated)->requiresConfirmation()
                        ->modalDescription('Stops the automatic playlist generator from replacing or removing this playlist. Tag synchronization stays enabled.')
                        ->action(function (Playlist $record): void {
                            $record->forceFill(['is_auto_generated' => false, 'auto_generation_key' => null])->saveQuietly();
                            Notification::make()->success()->title('Playlist is now custom')->send();
                        }),
                    CreateJournalDraftAction::make(),
                    EditAction::make(),
                    DeleteAction::make(),
                ])->icon('heroicon-m-ellipsis-horizontal')->tooltip('Playlist actions'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManagePlaylists::route('/'),
        ];
    }
}
