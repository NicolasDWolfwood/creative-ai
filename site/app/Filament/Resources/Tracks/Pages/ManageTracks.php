<?php

namespace App\Filament\Resources\Tracks\Pages;

use App\Filament\Resources\Tracks\TrackResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageTracks extends ManageRecords
{
    protected static string $resource = TrackResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
