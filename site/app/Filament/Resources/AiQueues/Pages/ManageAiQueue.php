<?php

namespace App\Filament\Resources\AiQueues\Pages;

use App\Filament\Resources\AiQueues\AiQueueResource;
use App\Services\ArtworkAiQueueService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;

class ManageAiQueue extends ManageRecords
{
    protected static string $resource = AiQueueResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('clearQueued')
                ->label('Clear queued')
                ->icon('heroicon-o-trash')
                ->color('gray')
                ->requiresConfirmation()
                ->action(function (): void {
                    $count = app(ArtworkAiQueueService::class)->clearQueued();

                    Notification::make()
                        ->title($count.' queued AI job canceled.')
                        ->success()
                        ->send();
                }),
            Action::make('retryFailed')
                ->label('Retry all failed')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->action(function (): void {
                    $count = app(ArtworkAiQueueService::class)->retryFailed();

                    Notification::make()
                        ->title($count.' failed AI job queued again.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
