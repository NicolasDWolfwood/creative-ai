<?php

namespace App\Filament\Resources\PostTemplates\Pages;

use App\Filament\Resources\PostTemplates\PostTemplateResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePostTemplate extends CreateRecord
{
    protected static string $resource = PostTemplateResource::class;

    protected function getRedirectUrl(): string
    {
        return PostTemplateResource::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
