<?php

namespace App\Filament\Resources\PostTemplates\Pages;

use App\Filament\Resources\PostTemplates\PostTemplateResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPostTemplate extends EditRecord
{
    protected static string $resource = PostTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->modalDescription('Deletes this template only. Existing Journal drafts and connected sources are not changed.'),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return PostTemplateResource::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
