<?php

namespace App\Filament\Resources\AiQueues;

use App\Filament\Resources\AiQueues\Pages\ManageAiQueue;
use App\Models\Artwork;
use App\Services\ArtworkAiQueueService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class AiQueueResource extends Resource
{
    protected static ?string $model = Artwork::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-queue-list';

    protected static string|\UnitEnum|null $navigationGroup = 'AI & Automation';

    protected static ?string $navigationLabel = 'AI Queue';

    protected static ?string $modelLabel = 'AI queue item';

    protected static ?string $pluralModelLabel = 'AI queue';

    protected static ?int $navigationSort = 20;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with('collection')
            ->whereIn('ai_status', [
                Artwork::AI_STATUS_QUEUED,
                Artwork::AI_STATUS_PROCESSING,
                Artwork::AI_STATUS_FAILED,
            ]);
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Artwork::query()
            ->whereIn('ai_status', [
                Artwork::AI_STATUS_QUEUED,
                Artwork::AI_STATUS_PROCESSING,
                Artwork::AI_STATUS_FAILED,
            ])
            ->count();

        return $count ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return Artwork::query()->where('ai_status', Artwork::AI_STATUS_FAILED)->exists()
            ? 'danger'
            : 'warning';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll('3s')
            ->defaultSort('ai_queued_at')
            ->columns([
                ImageColumn::make('thumb_path')->disk('public')->square()->label('Preview'),
                TextColumn::make('title')->searchable()->sortable(),
                TextColumn::make('collection.title')->label('Collection')->sortable(),
                TextColumn::make('ai_status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => ucfirst($state ?: Artwork::AI_STATUS_IDLE))
                    ->color(fn (?string $state): string => match ($state) {
                        Artwork::AI_STATUS_PROCESSING => 'info',
                        Artwork::AI_STATUS_FAILED => 'danger',
                        Artwork::AI_STATUS_QUEUED => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('ai_apply_after_analysis')
                    ->label('After analysis')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Auto-apply' : 'Review')
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                TextColumn::make('ai_queued_at')
                    ->label('Queued')
                    ->since()
                    ->sortable(),
                TextColumn::make('ai_started_at')
                    ->label('Started')
                    ->since()
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('ai_error')
                    ->label('Last error')
                    ->limit(80)
                    ->wrap()
                    ->placeholder('-'),
            ])
            ->recordActions([
                Action::make('prioritize')
                    ->label('Prioritize')
                    ->icon('heroicon-o-arrow-up-circle')
                    ->color('info')
                    ->visible(fn (Artwork $record): bool => $record->ai_status === Artwork::AI_STATUS_QUEUED)
                    ->action(function (Artwork $record): void {
                        app(ArtworkAiQueueService::class)->prioritize($record);

                        Notification::make()
                            ->title('Artwork moved to high-priority AI queue.')
                            ->success()
                            ->send();
                    }),
                Action::make('cancelQueued')
                    ->label('Cancel')
                    ->icon('heroicon-o-x-circle')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->visible(fn (Artwork $record): bool => $record->ai_status === Artwork::AI_STATUS_QUEUED)
                    ->action(function (Artwork $record): void {
                        app(ArtworkAiQueueService::class)->cancelQueued($record);

                        Notification::make()
                            ->title('Queued AI job canceled.')
                            ->success()
                            ->send();
                    }),
                Action::make('retryFailed')
                    ->label('Retry')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn (Artwork $record): bool => $record->ai_status === Artwork::AI_STATUS_FAILED)
                    ->action(function (Artwork $record): void {
                        app(ArtworkAiQueueService::class)->retry($record);

                        Notification::make()
                            ->title('Failed AI job queued again.')
                            ->success()
                            ->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('prioritizeSelected')
                        ->label('Prioritize selected')
                        ->icon('heroicon-o-arrow-up-circle')
                        ->color('info')
                        ->deselectRecordsAfterCompletion()
                        ->action(function (EloquentCollection $records): void {
                            $records->each(fn (Artwork $record) => app(ArtworkAiQueueService::class)->prioritize($record));

                            Notification::make()
                                ->title('Selected queued items moved to high-priority AI queue.')
                                ->success()
                                ->send();
                        }),
                    BulkAction::make('cancelSelected')
                        ->label('Cancel selected queued')
                        ->icon('heroicon-o-x-circle')
                        ->color('gray')
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->action(function (EloquentCollection $records): void {
                            $count = app(ArtworkAiQueueService::class)->cancelQueuedRecords($records);

                            Notification::make()
                                ->title($count.' queued AI job canceled.')
                                ->success()
                                ->send();
                        }),
                    BulkAction::make('retrySelectedFailed')
                        ->label('Retry selected failed')
                        ->icon('heroicon-o-arrow-path')
                        ->color('warning')
                        ->deselectRecordsAfterCompletion()
                        ->action(function (EloquentCollection $records): void {
                            $records
                                ->filter(fn (Artwork $record): bool => $record->ai_status === Artwork::AI_STATUS_FAILED)
                                ->each(fn (Artwork $record) => app(ArtworkAiQueueService::class)->retry($record));

                            Notification::make()
                                ->title('Selected failed AI jobs queued again.')
                                ->success()
                                ->send();
                        }),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAiQueue::route('/'),
        ];
    }
}
