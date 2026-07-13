<?php

namespace App\Filament\Resources\JournalAiRuns;

use App\Enums\PostAiOperation;
use App\Enums\PostAiRunStatus;
use App\Filament\Resources\JournalAiRuns\Pages\ManageJournalAiRuns;
use App\Filament\Resources\Posts\PostResource;
use App\Models\PostAiRun;
use App\Services\JournalAiRunService;
use DomainException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class JournalAiRunResource extends Resource
{
    protected static ?string $model = PostAiRun::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-sparkles';

    protected static string|\UnitEnum|null $navigationGroup = 'AI & Automation';

    protected static ?string $navigationLabel = 'Journal AI jobs';

    protected static ?string $modelLabel = 'Journal AI job';

    protected static ?string $pluralModelLabel = 'Journal AI queue';

    protected static ?int $navigationSort = 30;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with([
                'acknowledgedBy',
                'post',
                'requester',
            ])
            ->withCount('retries')
            ->whereIn('status', static::actionableStatuses());
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::actionableQuery()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        $query = static::actionableQuery();

        if ((clone $query)->whereIn('status', [
            PostAiRunStatus::Failed->value,
            PostAiRunStatus::Stale->value,
        ])->exists()) {
            return 'danger';
        }

        if ((clone $query)->whereIn('status', [
            PostAiRunStatus::Queued->value,
            PostAiRunStatus::Processing->value,
        ])->exists()) {
            return 'warning';
        }

        return 'success';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll('5s')
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderBy('queued_at')
                ->orderBy('id'))
            ->columns([
                TextColumn::make('post.title')
                    ->label('Post')
                    ->getStateUsing(fn (PostAiRun $record): string => match (true) {
                        $record->post === null => 'Unavailable post',
                        $record->post->trashed() => $record->post->title.' (Trashed)',
                        default => $record->post->title,
                    })
                    ->description(fn (PostAiRun $record): string => $record->post?->trashed()
                        ? 'Trashed post — actions are unavailable'
                        : 'Post #'.$record->post_id)
                    ->url(fn (PostAiRun $record): ?string => static::assistantUrl($record))
                    ->searchable()
                    ->wrap(),
                TextColumn::make('operation')
                    ->label('Operation')
                    ->badge()
                    ->formatStateUsing(fn (PostAiOperation $state): string => $state->label())
                    ->color('info')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (PostAiRunStatus $state): string => str($state->value)
                        ->replace('_', ' ')
                        ->title()
                        ->toString())
                    ->color(fn (PostAiRunStatus $state): string => match ($state) {
                        PostAiRunStatus::Queued => 'warning',
                        PostAiRunStatus::Processing => 'info',
                        PostAiRunStatus::Ready => 'success',
                        PostAiRunStatus::Failed, PostAiRunStatus::Stale => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('error_category')
                    ->label('Error category')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => str($state)
                        ->replace('_', ' ')
                        ->title()
                        ->toString())
                    ->color('danger')
                    ->placeholder('—'),
                TextColumn::make('provider')
                    ->label('Provider / model')
                    ->formatStateUsing(fn (string $state): string => str($state)->headline()->toString())
                    ->description(fn (PostAiRun $record): string => $record->model
                        .($record->external_processing ? ' · External processing' : ' · Local processing'))
                    ->searchable()
                    ->wrap(),
                TextColumn::make('requester.name')
                    ->label('Requested by')
                    ->getStateUsing(fn (PostAiRun $record): string => $record->requester?->name ?? 'Deleted account')
                    ->description(fn (PostAiRun $record): string => $record->acknowledgedBy === null
                        ? 'Acknowledgement unavailable'
                        : 'Acknowledged by '.$record->acknowledgedBy->name.' '.$record->acknowledged_at?->diffForHumans())
                    ->searchable()
                    ->wrap(),
                TextColumn::make('source_revision_id')
                    ->label('Provenance')
                    ->formatStateUsing(fn (?int $state): string => $state === null ? 'Saved post' : 'Revision #'.$state)
                    ->description(fn (PostAiRun $record): string => $record->prompt_version.' · '.$record->schema_version)
                    ->wrap(),
                TextColumn::make('retry_of_id')
                    ->label('Lineage')
                    ->getStateUsing(fn (PostAiRun $record): string => $record->retry_of_id === null
                        ? 'Original request'
                        : 'Retry of #'.$record->retry_of_id)
                    ->description(fn (PostAiRun $record): ?string => $record->retries_count > 0
                        ? $record->retries_count.' later attempt'.($record->retries_count === 1 ? '' : 's')
                        : null),
                TextColumn::make('duration_ms')
                    ->label('Duration')
                    ->getStateUsing(fn (PostAiRun $record): ?string => static::durationLabel($record->duration_ms))
                    ->placeholder('—'),
                TextColumn::make('token_telemetry')
                    ->label('Tokens')
                    ->getStateUsing(fn (PostAiRun $record): ?string => static::tokenTelemetry($record))
                    ->description('Input / output; out-of-range provider telemetry is discarded')
                    ->placeholder('—'),
                TextColumn::make('queued_at')
                    ->label('Queued')
                    ->dateTime('M j, Y H:i:s')
                    ->description(fn (PostAiRun $record): ?string => $record->queued_at?->diffForHumans())
                    ->sortable(),
                TextColumn::make('completed_at')
                    ->label('Completed')
                    ->dateTime('M j, Y H:i:s')
                    ->description(fn (PostAiRun $record): ?string => $record->completed_at?->diffForHumans())
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(static::actionableStatuses())->mapWithKeys(
                        fn (string $status): array => [$status => str($status)->replace('_', ' ')->title()->toString()],
                    )->all()),
                SelectFilter::make('operation')
                    ->options(collect(PostAiOperation::cases())->mapWithKeys(
                        fn (PostAiOperation $operation): array => [$operation->value => $operation->label()],
                    )->all()),
            ])
            ->recordActions([
                Action::make('openAssistant')
                    ->label(fn (PostAiRun $record): string => match ($record->status) {
                        PostAiRunStatus::Ready => 'Review result',
                        PostAiRunStatus::Failed, PostAiRunStatus::Stale => 'Review / retry',
                        default => 'Open assistant',
                    })
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('info')
                    ->url(fn (PostAiRun $record): ?string => static::assistantUrl($record))
                    ->visible(fn (PostAiRun $record): bool => static::hasManageablePost($record)),
                Action::make('prioritize')
                    ->label('Prioritize')
                    ->icon('heroicon-o-arrow-up-circle')
                    ->color('info')
                    ->visible(fn (PostAiRun $record): bool => $record->status === PostAiRunStatus::Queued
                        && static::hasManageablePost($record))
                    ->action(function (PostAiRun $record): void {
                        try {
                            app(JournalAiRunService::class)->prioritize($record, auth()->user());

                            Notification::make()
                                ->title('Journal AI job moved to the high-priority queue.')
                                ->success()
                                ->send();
                        } catch (DomainException) {
                            Notification::make()
                                ->title('The Journal AI job changed before it could be prioritized.')
                                ->warning()
                                ->send();
                        }
                    }),
                Action::make('cancel')
                    ->label('Cancel')
                    ->icon('heroicon-o-x-circle')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->visible(fn (PostAiRun $record): bool => in_array($record->status, [
                        PostAiRunStatus::Queued,
                        PostAiRunStatus::Processing,
                    ], true) && static::hasManageablePost($record))
                    ->action(function (PostAiRun $record): void {
                        try {
                            app(JournalAiRunService::class)->cancel($record, auth()->user());

                            Notification::make()
                                ->title('Journal AI job canceled.')
                                ->success()
                                ->send();
                        } catch (DomainException) {
                            Notification::make()
                                ->title('The Journal AI job changed before it could be canceled.')
                                ->warning()
                                ->send();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageJournalAiRuns::route('/'),
        ];
    }

    /** @return list<string> */
    private static function actionableStatuses(): array
    {
        return [
            PostAiRunStatus::Queued->value,
            PostAiRunStatus::Processing->value,
            PostAiRunStatus::Ready->value,
            PostAiRunStatus::Failed->value,
            PostAiRunStatus::Stale->value,
        ];
    }

    private static function actionableQuery(): Builder
    {
        return PostAiRun::query()->whereIn('status', static::actionableStatuses());
    }

    private static function hasManageablePost(PostAiRun $run): bool
    {
        return $run->post !== null && ! $run->post->trashed();
    }

    private static function assistantUrl(PostAiRun $run): ?string
    {
        if (! static::hasManageablePost($run)) {
            return null;
        }

        return PostResource::getUrl('assistant', ['record' => $run->post]);
    }

    private static function durationLabel(?int $durationMs): ?string
    {
        if ($durationMs === null) {
            return null;
        }

        return $durationMs < 1000
            ? number_format($durationMs).' ms'
            : number_format($durationMs / 1000, 2).' s';
    }

    private static function tokenTelemetry(PostAiRun $run): ?string
    {
        if ($run->input_tokens === null && $run->output_tokens === null) {
            return null;
        }

        return ($run->input_tokens === null ? '—' : number_format($run->input_tokens))
            .' / '
            .($run->output_tokens === null ? '—' : number_format($run->output_tokens));
    }
}
