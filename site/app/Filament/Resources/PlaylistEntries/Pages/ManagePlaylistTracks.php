<?php

namespace App\Filament\Resources\PlaylistEntries\Pages;

use App\Filament\Resources\PlaylistEntries\PlaylistTrackResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManagePlaylistTracks extends ManageRecords
{
    protected static string $resource = PlaylistTrackResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
