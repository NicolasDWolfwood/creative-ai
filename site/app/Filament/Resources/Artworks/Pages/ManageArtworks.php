<?php

namespace App\Filament\Resources\Artworks\Pages;

use App\Filament\Actions\CreateSourceWithJournalAction;
use App\Filament\Forms\JournalPlanningFields;
use App\Filament\Resources\Artworks\ArtworkResource;
use App\Models\Artwork;
use App\Models\Collection;
use App\Rules\SafeArtworkImageDimensions;
use App\Services\ArtworkAiMetadataService;
use App\Services\ArtworkAiQueueService;
use App\Services\ArtworkBulkUploadService;
use App\Services\JournalDraftAutomationService;
use App\Services\JournalPlanningSettings;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Throwable;

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
                    Toggle::make('apply_immediately')
                        ->label('Apply suggestions automatically')
                        ->helperText('Skips review and publishes the generated metadata as each analysis completes.')
                        ->default(false),
                ])
                ->requiresConfirmation()
                ->modalDescription('Only artwork without a completed analysis is queued. Existing queued or processing jobs are left alone.')
                ->action(function (array $data): void {
                    $applyImmediately = (bool) ($data['apply_immediately'] ?? false);
                    $count = app(ArtworkAiQueueService::class)->queuePending(
                        statuses: $data['statuses'],
                        limit: (int) $data['limit'],
                        applyAfterAnalysis: $applyImmediately,
                    );

                    Notification::make()
                        ->success()
                        ->title($count.' artwork queued for analysis')
                        ->body($applyImmediately ? 'Suggestions will be applied automatically.' : 'Suggestions will wait for review.')
                        ->send();
                }),
            Action::make('applyReadySuggestions')
                ->label('Apply ready')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->schema([
                    TextInput::make('limit')
                        ->label('Maximum to apply')
                        ->helperText('Use 0 for every ready suggestion.')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(10000)
                        ->default(0)
                        ->required(),
                ])
                ->requiresConfirmation()
                ->modalDescription('Applies public metadata and tags for ready artwork. Existing slugs remain unchanged.')
                ->action(function (array $data): void {
                    $count = app(ArtworkAiMetadataService::class)->applyReadySuggestions((int) $data['limit']);

                    Notification::make()
                        ->success()
                        ->title($count.' AI suggestion'.($count === 1 ? '' : 's').' applied')
                        ->send();
                }),
            Action::make('bulkUpload')
                ->label('Bulk upload')
                ->icon('heroicon-o-arrow-up-tray')
                ->schema([
                    FileUpload::make('images')
                        ->label('Artwork files')
                        ->disk('local')
                        ->directory('artworks/originals')
                        ->visibility('private')
                        ->image()
                        ->multiple()
                        ->reorderable()
                        ->appendFiles()
                        ->storeFileNamesIn('original_names')
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                        ->maxSize(25600)
                        ->rules([new SafeArtworkImageDimensions])
                        ->helperText('Each JPEG, PNG, or WebP may be up to 25 MiB and 20 megapixels.')
                        ->required()
                        ->columnSpanFull(),
                    Select::make('collection_ids')
                        ->label('Add to manual collections')
                        ->options(fn (): array => Collection::query()
                            ->where('is_smart', false)
                            ->orderBy('title')
                            ->pluck('title', 'id')
                            ->all())
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->helperText('Smart and automatic memberships are derived from their rules.'),
                    Toggle::make('published')->label('Publish immediately')->default(true),
                    Toggle::make('analyze_after_upload')->label('Queue AI analysis after upload')->default(true),
                    ...JournalPlanningFields::actionOptions(
                        app(JournalPlanningSettings::class)->current()->artworkBatchMode,
                    ),
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

                    if (! (bool) ($data['journal_create_draft'] ?? false) || $created->isEmpty()) {
                        return;
                    }

                    try {
                        $result = app(JournalDraftAutomationService::class)->createBatch($created, $data);
                    } catch (Throwable $exception) {
                        report($exception);
                        Notification::make()
                            ->warning()
                            ->title('Artwork uploaded; Journal batch needs attention')
                            ->body('The artwork is safe. Select this batch in the artwork library and use Bulk actions → Create one Journal draft.')
                            ->actions([
                                Action::make('retryJournalBatch')
                                    ->label('Open artwork library')
                                    ->url(ArtworkResource::getUrl()),
                            ])
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->success()
                        ->title($result->created() ? 'Batch Journal draft created' : 'Existing Journal plans kept')
                        ->body($result->connected.' artwork connected; '.$result->skipped.' already planned.')
                        ->send();
                }),
            CreateSourceWithJournalAction::make()->label('New artwork'),
        ];
    }
}
