<?php

namespace App\Filament\Resources\Posts\Pages;

use App\Filament\Resources\Posts\PostResource;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPost extends EditRecord
{
    protected static string $resource = PostResource::class;

    protected function getHeaderActions(): array
    {
        return [
            PostResource::previewAction(),
            PostResource::readinessAction(),
            ActionGroup::make(PostResource::workflowActions())
                ->label('Workflow')
                ->icon('heroicon-o-arrows-right-left')
                ->button(),
            DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return PostResource::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
