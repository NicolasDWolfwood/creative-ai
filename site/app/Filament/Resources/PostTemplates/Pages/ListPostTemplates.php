<?php

namespace App\Filament\Resources\PostTemplates\Pages;

use App\Filament\Resources\PostTemplates\PostTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPostTemplates extends ListRecords
{
    protected static string $resource = PostTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New template'),
        ];
    }
}
