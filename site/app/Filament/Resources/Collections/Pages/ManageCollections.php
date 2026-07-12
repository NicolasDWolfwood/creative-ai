<?php

namespace App\Filament\Resources\Collections\Pages;

use App\Filament\Resources\Collections\CollectionResource;
use App\Models\Collection;
use App\Services\AutomaticCollectionService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Throwable;

class ManageCollections extends ManageRecords
{
    protected static string $resource = CollectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generateAutomaticCollections')
                ->label(fn (): string => Collection::query()->where('is_auto_generated', true)->exists()
                    ? 'Refresh automatic'
                    : 'Generate automatic')
                ->icon('heroicon-o-bolt')
                ->color('info')
                ->schema([
                    Select::make('target_count')
                        ->label('Automatic collections')
                        ->options([1 => '1 collection', 2 => '2 collections', 3 => '3 collections', 4 => '4 collections', 5 => '5 collections'])
                        ->default(AutomaticCollectionService::DEFAULT_TARGET)
                        ->required()
                        ->native(false)
                        ->position('top'),
                    TextInput::make('minimum_artwork')
                        ->label('Minimum matching artwork')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(500)
                        ->default(AutomaticCollectionService::DEFAULT_MINIMUM_ARTWORK)
                        ->required(),
                    Toggle::make('published')
                        ->label('Publish generated collections')
                        ->default(true),
                ])
                ->requiresConfirmation()
                ->modalDescription('Builds a managed set from the most common broad tags on AI-approved artwork. Manual and custom smart collections are never changed.')
                ->action(function (array $data): void {
                    $result = app(AutomaticCollectionService::class)->maintain(
                        target: (int) $data['target_count'],
                        minimumArtwork: (int) $data['minimum_artwork'],
                        published: (bool) $data['published'],
                    );
                    $summary = collect($result['collections'])
                        ->map(fn (array $collection): string => $collection['title'].' ('.$collection['count'].')')
                        ->implode(', ');

                    Notification::make()
                        ->success()
                        ->title($result['collection_count'].' automatic collection'.($result['collection_count'] === 1 ? '' : 's').' ready')
                        ->body($summary ?: 'No broad theme met the minimum artwork threshold.')
                        ->send();
                }),
            Action::make('createCollectionWithAi')
                ->label('Create with AI')
                ->icon('heroicon-o-sparkles')
                ->color('success')
                ->schema([
                    Textarea::make('guidance')
                        ->label('Theme or creative direction')
                        ->helperText('Optional. For example: cars, architectural studies, or dreamlike portraits.')
                        ->rows(3)
                        ->maxLength(500),
                    TextInput::make('minimum_artwork')
                        ->label('Minimum matching artwork')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(500)
                        ->default(AutomaticCollectionService::DEFAULT_MINIMUM_ARTWORK)
                        ->required(),
                    Toggle::make('published')->label('Publish immediately')->default(true),
                ])
                ->requiresConfirmation()
                ->modalDescription('Your configured AI provider will choose existing approved tags, create a smart collection, and keep its membership synchronized.')
                ->action(function (array $data): void {
                    try {
                        $result = app(AutomaticCollectionService::class)->createWithAi(
                            guidance: $data['guidance'] ?? null,
                            minimumArtwork: (int) $data['minimum_artwork'],
                            published: (bool) $data['published'],
                        );
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->danger()
                            ->title('AI collection could not be created')
                            ->body(str($exception->getMessage())->squish()->limit(300, '')->toString())
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->success()
                        ->title($result['collection']->title.' created')
                        ->body($result['explanation'].' '.$result['count'].' artwork matched.')
                        ->send();
                }),
            CreateAction::make(),
        ];
    }
}
