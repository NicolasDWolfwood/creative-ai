<?php

namespace App\Filament\Resources\Tracks;

use App\Enums\PostMediaType;
use App\Filament\Actions\CreateJournalDraftAction;
use App\Filament\Forms\JournalPlanningFields;
use App\Filament\Resources\Tracks\Pages\ManageTracks;
use App\Jobs\AnalyzeTrackAudio;
use App\Jobs\AnalyzeTrackMetadata;
use App\Models\Track;
use App\Services\MusicArtworkSuggestionService;
use App\Services\SmartPlaylistService;
use App\Services\TrackAiMetadataService;
use App\Services\TrackAiQueueService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\HtmlString;

class TrackResource extends Resource
{
    protected static ?string $model = Track::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-musical-note';

    protected static string|\UnitEnum|null $navigationGroup = 'Music Library';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Track library';

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->maxLength(255)->helperText('Leave empty to use embedded metadata or the filename.'),
            TextInput::make('artist')->maxLength(255),
            TextInput::make('slug')->maxLength(255)->helperText('Leave empty to generate from artist and title.'),
            Select::make('cover_artwork_id')
                ->relationship('coverArtwork', 'title')
                ->searchable()
                ->preload(),
            Select::make('album_id')->relationship('album', 'title')->searchable()->preload()->live(),
            FileUpload::make('audio_path')
                ->label('Audio')
                ->disk('local')
                ->directory('tracks/audio')
                ->visibility('private')
                ->acceptedFileTypes(config('creative_ai.uploads.track_mime_types'))
                ->maxSize(config('creative_ai.uploads.max_track_size_kb'))
                ->storeFileNamesIn('original_filename')
                ->extraAttributes(['class' => 'ca-track-audio-upload'])
                ->helperText(fn (?Track $record): ?string => filled($record?->original_filename) ? 'Original upload: '.$record->original_filename : null)
                ->downloadable()
                ->required(),
            Textarea::make('description')->rows(3)->columnSpanFull(),
            Select::make('tags')
                ->relationship('tags', 'name')
                ->multiple()
                ->searchable()
                ->preload()
                ->createOptionForm([
                    TextInput::make('name')->required()->maxLength(80),
                ])
                ->columnSpanFull(),
            TextInput::make('duration_seconds')->numeric(),
            TextInput::make('disc_number')->numeric()->minValue(1),
            TextInput::make('track_number')->numeric()->minValue(1),
            TextInput::make('release_year')->numeric()->minValue(1000)->maxValue(9999),
            TextInput::make('sort_order')->numeric()->default(0),
            Toggle::make('featured'),
            Toggle::make('standalone_published')
                ->label('Publish as standalone track')
                ->helperText('Album tracks are already playable when their album is published. Enable this only to list the track separately as a single.')
                ->default(false)
                ->live(),
            DateTimePicker::make('standalone_published_at')->label('Standalone publish date'),
            Section::make('Audio health and technical analysis')
                ->description('Read-only results from Analyze audio health. Run the analysis again after resolving an issue.')
                ->visible(fn (?Track $record): bool => $record !== null)
                ->schema([
                    Placeholder::make('health_status_display')
                        ->label('Library health')
                        ->content(fn (?Track $record): string => match ($record?->health_status) {
                            'healthy' => 'Healthy',
                            'attention' => 'Attention needed',
                            default => 'Not analyzed',
                        }),
                    Placeholder::make('analysis_status_display')
                        ->label('Analysis status')
                        ->content(fn (?Track $record): string => ucfirst($record?->analysis_status ?: 'pending')),
                    Placeholder::make('health_reasons_display')
                        ->label('Why this status?')
                        ->content(fn (?Track $record): HtmlString => new HtmlString(
                            '<div style="white-space: normal">'.e($record?->healthExplanation() ?? 'Technical analysis has not completed yet.').'</div>',
                        ))
                        ->columnSpanFull(),
                    Placeholder::make('technical_details_display')
                        ->label('Detected audio properties')
                        ->content(fn (?Track $record): string => $record?->technicalSummary() ?? 'No technical audio details are available yet.')
                        ->columnSpanFull(),
                    Placeholder::make('analyzed_at_display')
                        ->label('Last analyzed')
                        ->content(fn (?Track $record): string => $record?->analyzed_at?->format('d M Y, H:i:s') ?? 'Never'),
                    Placeholder::make('audio_hash_display')
                        ->label('Content fingerprint')
                        ->content(fn (?Track $record): string => $record?->audio_hash ? substr($record->audio_hash, 0, 16).'…' : 'Not available'),
                ])
                ->columns(2)
                ->columnSpanFull(),
            JournalPlanningFields::make(PostMediaType::Track),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderBy('disc_number')
                ->orderBy('track_number')
                ->orderBy('sort_order'))
            ->groups([
                Group::make('album.title')
                    ->label('Album')
                    ->getTitleFromRecordUsing(fn (Track $record): string => $record->album?->title ?? 'Singles / no album')
                    ->collapsible(),
            ])
            ->defaultGroup('album.title')
            ->collapsedGroupsByDefault()
            ->paginated(fn (ManageTracks $livewire): bool => $livewire->getTableGrouping() === null)
            ->poll('5s')
            ->columns([
                TextColumn::make('title')->searchable()->sortable(),
                TextColumn::make('artist')->searchable()->sortable(),
                TextColumn::make('album.title')->label('Album')->searchable()->sortable(),
                TextColumn::make('track_number')->label('#')->sortable(),
                TextColumn::make('tags.name')->label('Tags')->badge()->limitList(4),
                TextColumn::make('ai_status')->label('AI status')->badge()->sortable(),
                TextColumn::make('health_status')
                    ->label('Health')
                    ->badge()
                    ->colors(['success' => 'healthy', 'warning' => 'attention', 'gray' => 'unknown'])
                    ->description(fn (Track $record): string => $record->healthExplanation())
                    ->tooltip(fn (Track $record): string => $record->healthExplanation())
                    ->wrap()
                    ->sortable(),
                TextColumn::make('audio_codec')->label('Codec')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('playlists_count')->counts('playlists')->label('Playlists')->sortable(),
                TextColumn::make('duration_seconds')->label('Duration')->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('sort_order')->sortable()->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('featured')->boolean(),
                TextColumn::make('standalone_published')
                    ->label('Availability')
                    ->badge()
                    ->formatStateUsing(fn (bool $state, Track $record): string => match (true) {
                        $record->isPubliclyPublished() => 'Standalone',
                        $record->album?->isPubliclyPublished() => 'Via album',
                        $state => 'Standalone scheduled',
                        (bool) $record->album?->published => 'Album scheduled',
                        default => 'Draft',
                    })
                    ->color(fn (bool $state, Track $record): string => match (true) {
                        $record->isPubliclyPublished() => 'success',
                        $record->album?->isPubliclyPublished() => 'info',
                        $state || (bool) $record->album?->published => 'warning',
                        default => 'gray',
                    }),
                IconColumn::make('metadata_reviewed_at')->label('Reviewed')->boolean(),
            ])
            ->filters([
                SelectFilter::make('album_id')
                    ->label('Album')
                    ->relationship('album', 'title')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('generateTags')
                        ->label('Analyze with AI')
                        ->icon('heroicon-o-sparkles')
                        ->color('info')
                        ->requiresConfirmation()
                        ->action(function (Track $record): void {
                            app(TrackAiQueueService::class)->queue($record);
                            Notification::make()->success()->title('Track queued for AI analysis')->send();
                        }),
                    Action::make('applyAiSuggestion')
                        ->label('Apply AI suggestions')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->visible(fn (Track $record): bool => filled($record->ai_suggestion) && $record->ai_status !== Track::AI_STATUS_APPLIED)
                        ->requiresConfirmation()
                        ->action(function (Track $record): void {
                            app(TrackAiMetadataService::class)->applySuggestion($record);
                            Notification::make()->success()->title('Track tags applied')->send();
                        }),
                    Action::make('suggestArtwork')
                        ->label('Suggest artwork')
                        ->icon('heroicon-o-photo')
                        ->schema([
                            Select::make('cover_artwork_id')
                                ->label('Ranked by shared tags')
                                ->options(fn (Track $record): array => app(MusicArtworkSuggestionService::class)->trackOptions($record))
                                ->required(),
                        ])
                        ->action(function (Track $record, array $data): void {
                            $record->update(['cover_artwork_id' => $data['cover_artwork_id']]);
                            Notification::make()->success()->title('Track artwork selected')->send();
                        }),
                    Action::make('markMetadataReviewed')
                        ->label('Mark metadata reviewed')
                        ->icon('heroicon-o-check-circle')
                        ->visible(fn (Track $record): bool => $record->metadata_reviewed_at === null)
                        ->action(fn (Track $record) => $record->update(['metadata_reviewed_at' => now()])),
                    Action::make('analyzeAudio')->label('Analyze audio health')->icon('heroicon-o-signal')->action(function (Track $record): void {
                        $record->markTechnicalAnalysisPending();
                        AnalyzeTrackAudio::dispatch($record->id);
                        Notification::make()->success()->title('Technical analysis queued')->body('Health status refreshes automatically when the job finishes.')->send();
                    }),
                    CreateJournalDraftAction::make()->allowPrivateSources(),
                    EditAction::make()->after(fn () => app(SmartPlaylistService::class)->syncAutomatic()),
                    DeleteAction::make(),
                ])->icon('heroicon-m-ellipsis-horizontal')->tooltip('Track actions'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('analyzeAudio')->label('Analyze audio health')->icon('heroicon-o-signal')->action(function (Collection $records): void {
                        $records->each(function (Track $track): void {
                            $track->markTechnicalAnalysisPending();
                            AnalyzeTrackAudio::dispatch($track->id);
                        });
                        Notification::make()->success()->title($records->count().' technical analyses queued')->body('Health statuses refresh automatically as the jobs finish.')->send();
                    })->deselectRecordsAfterCompletion(),
                    BulkAction::make('generateSelectedTags')
                        ->label('Generate AI tags')
                        ->icon('heroicon-o-sparkles')
                        ->color('info')
                        ->requiresConfirmation()
                        ->modalDescription('Queues AI tag generation for every selected track. Existing track tags are replaced when each job completes.')
                        ->action(function (Collection $records): void {
                            $records->each(fn (Track $track) => AnalyzeTrackMetadata::dispatchFor($track));
                            Notification::make()->success()->title($records->count().' track analyses queued')->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('applySelectedAiSuggestions')
                        ->label('Apply selected AI suggestions')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(function (Collection $records): void {
                            $count = 0;
                            $records->each(function (Track $track) use (&$count): void {
                                if (filled($track->ai_suggestion) && $track->ai_status !== Track::AI_STATUS_APPLIED) {
                                    app(TrackAiMetadataService::class)->applySuggestion($track);
                                    $count++;
                                }
                            });
                            Notification::make()->success()->title($count.' track suggestions applied')->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('applySuggestedArtwork')
                        ->label('Apply best artwork matches')
                        ->icon('heroicon-o-photo')
                        ->schema([
                            Toggle::make('replace_existing')
                                ->label('Replace existing track artwork')
                                ->helperText('Off by default so manually selected covers are preserved.'),
                        ])
                        ->requiresConfirmation()
                        ->modalDescription('Selects the highest-scoring published artwork for each track from shared tags. Tracks without a match are skipped.')
                        ->action(function (Collection $records, array $data): void {
                            $result = app(MusicArtworkSuggestionService::class)->applyBestToTracks(
                                $records,
                                (bool) ($data['replace_existing'] ?? false),
                            );
                            Notification::make()
                                ->success()
                                ->title($result['applied'].' artwork matches applied')
                                ->body($result['skipped'].' selected tracks were preserved or had no match.')
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('markSelectedMetadataReviewed')
                        ->label('Mark metadata reviewed')
                        ->icon('heroicon-o-check-circle')
                        ->requiresConfirmation()
                        ->action(function (Collection $records): void {
                            Track::query()->whereKey($records->modelKeys())->update(['metadata_reviewed_at' => now()]);
                            Notification::make()->success()->title($records->count().' tracks marked reviewed')->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageTracks::route('/'),
        ];
    }
}
