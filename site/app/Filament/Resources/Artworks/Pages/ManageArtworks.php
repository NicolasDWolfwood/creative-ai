<?php

namespace App\Filament\Resources\Artworks\Pages;

use App\Filament\Resources\Artworks\ArtworkResource;
use App\Models\Artwork;
use App\Models\Collection;
use App\Services\ArtworkAiQueueService;
use App\Services\ArtworkBulkUploadService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;

class ManageArtworks extends ManageRecords
{
    protected static string $resource = ArtworkResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('analyzePending')
                ->label('Analyze pending')
                ->icon('heroicon-o-sparkles')
                ->color('info')
                ->schema([
                    Select::make('statuses')
                        ->label('Include statuses')
                        ->options([
                            Artwork::AI_STATUS_IDLE => 'Not analyzed',
                            Artwork::AI_STATUS_FAILED => 'Failed attempts',
                        ])
                        ->multiple()
                        ->default([Artwork::AI_STATUS_IDLE, Artwork::AI_STATUS_FAILED])
                        ->required(),
                    TextInput::make('limit')
                        ->label('Maximum to queue')
                        ->helperText('Use 0 for every matching artwork.')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(10000)
                        ->default(0)
                        ->required(),
                ])
                ->requiresConfirmation()
                ->modalDescription('Only artwork without a completed analysis is queued. Existing queued or processing jobs are left alone.')
                ->action(function (array $data): void {
                    $count = app(ArtworkAiQueueService::class)->queuePending($data['statuses'], (int) $data['limit']);

                    Notification::make()->success()->title($count.' artwork queued for analysis')->send();
                }),
            Action::make('bulkUpload')
                ->label('Bulk upload')
                ->icon('heroicon-o-arrow-up-tray')
                ->schema([
                    FileUpload::make('images')
                        ->label('Artwork files')
                        ->disk('public')
                        ->directory('artworks/originals')
                        ->visibility('public')
                        ->image()
                        ->multiple()
                        ->reorderable()
                        ->appendFiles()
                        ->storeFileNamesIn('original_names')
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                        ->maxSize(25600)
                        ->required()
                        ->columnSpanFull(),
                    Select::make('collection_ids')
                        ->label('Add to collections')
                        ->options(fn (): array => Collection::query()->orderBy('title')->pluck('title', 'id')->all())
                        ->multiple()
                        ->searchable()
                        ->preload(),
                    Toggle::make('published')->label('Publish immediately')->default(true),
                    Toggle::make('analyze_after_upload')->label('Queue AI analysis after upload')->default(true),
                ])
                ->modalWidth('4xl')
                ->action(function (array $data): void {
                    $created = app(ArtworkBulkUploadService::class)->create(
                        paths: $data['images'] ?? [],
                        originalNames: $data['original_names'] ?? [],
                        collectionIds: $data['collection_ids'] ?? [],
                        published: (bool) ($data['published'] ?? false),
                        analyze: (bool) ($data['analyze_after_upload'] ?? false),
                    );

                    Notification::make()
                        ->success()
                        ->title($created->count().' artwork uploaded')
                        ->body(($data['analyze_after_upload'] ?? false) ? 'The batch has been added to the AI queue.' : 'The batch is ready for review.')
                        ->send();
                }),
            CreateAction::make()->label('New artwork'),
        ];
    }
}
