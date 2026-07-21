<?php

namespace App\Filament\Resources\Posts\Pages;

use App\Filament\Pages\StoryOpportunities;
use App\Filament\Resources\Posts\PostResource;
use App\Filament\Resources\PostTemplates\PostTemplateResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPosts extends ListRecords
{
    protected static string $resource = PostResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Blank draft')
                ->icon('heroicon-o-document-plus'),
            Action::make('createFromContent')
                ->label('Create from content')
                ->icon('heroicon-o-light-bulb')
                ->color('info')
                ->url(StoryOpportunities::getUrl()),
            Action::make('manageTemplates')
                ->label('Manage templates')
                ->icon('heroicon-o-document-duplicate')
                ->url(PostTemplateResource::getUrl()),
        ];
    }
}
