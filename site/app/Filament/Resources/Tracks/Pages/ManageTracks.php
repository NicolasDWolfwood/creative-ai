<?php

namespace App\Filament\Resources\Tracks\Pages;

use App\Filament\Resources\Tracks\TrackResource;
use App\Jobs\AnalyzeTrackAudio;
use App\Models\Album;
use App\Models\Track;
use App\Services\AlbumImportService;
use App\Services\SmartPlaylistService;
use App\Services\TrackAiMetadataService;
use App\Services\TrackAiQueueService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;

class ManageTracks extends ManageRecords
{
    protected static string $resource = TrackResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('analyzeAudio')->label('Analyze audio health')->icon('heroicon-o-signal')->color('warning')->schema([
                Select::make('status')->options(['pending' => 'Pending', 'failed' => 'Failed', 'unknown' => 'Unknown', 'all' => 'All tracks'])->default('pending')->required(),
            ])->action(function (array $data): void {
                $query = Track::query();
                if ($data['status'] !== 'all') {
                    $query->where('analysis_status', $data['status']);
                }
                $count = 0;
                $query->each(function (Track $track) use (&$count): void {
                    $track->markTechnicalAnalysisPending();
                    AnalyzeTrackAudio::dispatch($track->id);
                    $count++;
                });
                Notification::make()->success()->title($count.' technical analyses queued')->body('Health statuses refresh automatically as the jobs finish.')->send();
            }),
            Action::make('analyzePending')
                ->label('Analyze pending')
                ->icon('heroicon-o-sparkles')
                ->color('info')
                ->schema([
                    Select::make('statuses')->label('Include statuses')->options([
                        Track::AI_STATUS_IDLE => 'Not analyzed',
                        Track::AI_STATUS_FAILED => 'Failed attempts',
                    ])->multiple()->default([Track::AI_STATUS_IDLE, Track::AI_STATUS_FAILED])->required(),
                    TextInput::make('limit')->label('Maximum to queue')->helperText('Use 0 for every matching track.')->numeric()->minValue(0)->maxValue(10000)->default(0)->required(),
                    Toggle::make('apply_immediately')->label('Apply suggestions automatically')->helperText('Skips review and applies tags when analysis completes.')->default(false),
                ])
                ->requiresConfirmation()
                ->action(function (array $data): void {
                    $count = app(TrackAiQueueService::class)->queuePending($data['statuses'], (int) $data['limit'], (bool) ($data['apply_immediately'] ?? false));
                    Notification::make()->success()->title($count.' tracks queued for analysis')->send();
                }),
            Action::make('applyReadySuggestions')
                ->label('Apply ready')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->schema([
                    TextInput::make('limit')->label('Maximum to apply')->helperText('Use 0 for every ready suggestion.')->numeric()->minValue(0)->maxValue(10000)->default(0)->required(),
                ])
                ->requiresConfirmation()
                ->action(function (array $data): void {
                    $count = app(TrackAiMetadataService::class)->applyReadySuggestions((int) $data['limit']);
                    Notification::make()->success()->title($count.' track suggestions applied')->send();
                }),
            Action::make('importAlbum')
                ->label('Bulk upload')
                ->icon('heroicon-o-arrow-up-tray')
                ->schema([
                    FileUpload::make('audio_files')
                        ->label('Audio files')
                        ->disk('local')
                        ->directory('tracks/audio')
                        ->visibility('private')
                        ->multiple()
                        ->reorderable()
                        ->appendFiles()
                        ->storeFileNamesIn('original_names')
                        ->acceptedFileTypes(config('creative_ai.uploads.track_mime_types'))
                        ->maxSize(config('creative_ai.uploads.max_track_size_kb'))
                        ->helperText('Embedded tags are preferred; missing values are derived from filenames. Imported tracks remain private for review until their album is published or they are released as standalone tracks.')
                        ->required()
                        ->columnSpanFull(),
                    Select::make('album_id')
                        ->label('Force into an existing album')
                        ->options(fn (): array => Album::query()->orderBy('title')->pluck('title', 'id')->all())
                        ->searchable()->preload()
                        ->helperText('Leave empty to group files by their embedded album tags.'),
                    Toggle::make('standalone_published')
                        ->label('Release imported tracks as standalone')
                        ->helperText('Leave off for album releases. Publishing the album makes its tracks playable without listing every track separately.')
                        ->default(false),
                ])
                ->modalWidth('5xl')
                ->action(function (array $data): void {
                    $tracks = app(AlbumImportService::class)->import(
                        $data['audio_files'] ?? [],
                        $data['original_names'] ?? [],
                        filled($data['album_id'] ?? null) ? (int) $data['album_id'] : null,
                        (bool) ($data['standalone_published'] ?? false),
                    );
                    $albums = $tracks->pluck('album_id')->filter()->unique()->count();
                    Notification::make()->success()->title($tracks->count().' tracks imported')
                        ->body($albums.' album'.($albums === 1 ? '' : 's').' detected and grouped. Review the detected metadata in the track and album tables.')
                        ->send();
                }),
            CreateAction::make()->after(fn () => app(SmartPlaylistService::class)->syncAutomatic()),
        ];
    }
}
