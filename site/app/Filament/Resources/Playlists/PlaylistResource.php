<?php

namespace App\Filament\Resources\Playlists;

use App\Filament\Resources\Playlists\Pages\ManagePlaylists;
use App\Models\Playlist;
use App\Models\Tag;
use App\Services\SmartPlaylistService;
use App\Services\SmartRuleAiService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
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
use Illuminate\Database\Eloquent\Builder;

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
            Select::make('tracks')
                ->relationship(
                    'tracks',
                    'title',
                    modifyQueryUsing: fn (Builder $query): Builder => $query
                        ->select(['tracks.id', 'tracks.title'])
                        ->reorder('tracks.title'),
                )
                ->multiple()
                ->searchable()
                ->preload()
                ->helperText('Manual playlists keep this selection. Smart playlists replace it from their rules.')
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
                    Toggle::make('smart_rules.only_published')->label('Published tracks only')->default(true),
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
                TextColumn::make('is_smart')->label('Type')->badge()->formatStateUsing(fn (bool $state): string => $state ? 'Smart' : 'Manual')->color(fn (bool $state): string => $state ? 'info' : 'gray'),
                TextColumn::make('sort_order')->sortable(),
                IconColumn::make('featured')->boolean(),
                IconColumn::make('published')->boolean(),
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
