<?php

namespace App\Filament\Resources\Artworks\Pages;

use App\Filament\Resources\Artworks\ArtworkResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageArtworks extends ManageRecords
{
    protected static string $resource = ArtworkResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
