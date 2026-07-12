<?php

namespace App\Filament\Resources\Albums\Pages;

use App\Filament\Resources\Albums\AlbumResource;
use App\Models\Album;
use App\Services\AlbumOrganizationService;
use App\Services\AlbumPublishingService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;

class ManageAlbums extends ManageRecords
{
    protected static string $resource = AlbumResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('organizeFromMetadata')
                ->label('Organize from metadata')
                ->icon('heroicon-o-rectangle-stack')
                ->color('info')
                ->requiresConfirmation()
                ->modalDescription('Groups existing tracks by normalized embedded album title and album artist. Curated albums with artwork or descriptions are preserved.')
                ->action(function (): void {
                    $result = app(AlbumOrganizationService::class)->organizeExisting();
                    Notification::make()->success()->title($result['albums_used'].' albums organized')
                        ->body($result['tracks_organized'].' tracks moved; '.$result['empty_imports_removed'].' empty import records removed.')
                        ->send();
                }),
            CreateAction::make()->label('New album')->after(function (Album $record): void {
                if ($record->published) {
                    app(AlbumPublishingService::class)->publishTracks($record);
                }
            }),
        ];
    }
}
