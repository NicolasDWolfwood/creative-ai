<?php

namespace App\Filament\Resources\Collections\Pages;

use App\Filament\Actions\CreateSourceWithJournalAction;
use App\Filament\Resources\Collections\CollectionResource;
use App\Models\Collection;
use App\Services\AutomaticCollectionService;
use Filament\Actions\Action;
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
                        ->default(fn (): int => (int) data_get(
                            Collection::query()->where('is_auto_generated', true)->orderBy('id')->first()?->smart_rules,
                            'target_count',
                            AutomaticCollectionService::DEFAULT_TARGET,
                        ))
                        ->required()
                        ->native(false)
                        ->position('top'),
                    TextInput::make('minimum_artwork')
                        ->label('Minimum matching artwork')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(500)
                        ->default(fn (): int => (int) data_get(
                            Collection::query()->where('is_auto_generated', true)->orderBy('id')->first()?->smart_rules,
                            'minimum_artwork',
                            AutomaticCollectionService::DEFAULT_MINIMUM_ARTWORK,
                        ))
                        ->required(),
                    Toggle::make('published')
                        ->label('Publish generated collections')
                        ->default(fn (): bool => (bool) (Collection::query()
                            ->where('is_auto_generated', true)
                            ->value('published') ?? true))
                        ->live(),
                    Toggle::make('publishes_members')
                        ->label('Make matched artwork public inside these collections')
                        ->helperText('Takes effect when the collection is published. The artwork stays off All artwork unless published separately. Membership is snapshotted now; later AI metadata cannot add new public artwork until you explicitly refresh.')
                        ->default(fn (): bool => (bool) (Collection::query()
                            ->where('is_auto_generated', true)
                            ->value('publishes_members') ?? true)),
                ])
                ->requiresConfirmation()
                ->modalDescription('Builds a managed set from AI-approved artwork with usable image files. Future-scheduled standalone artwork is excluded. This explicit Generate or Refresh action is the publication gate; manual and custom smart collections are never changed.')
                ->action(function (array $data): void {
                    $result = app(AutomaticCollectionService::class)->maintain(
                        target: (int) $data['target_count'],
                        minimumArtwork: (int) $data['minimum_artwork'],
                        published: (bool) $data['published'],
                        publishesMembers: (bool) ($data['publishes_members'] ?? false),
                    );
                    $summary = collect($result['collections'])
                        ->map(fn (array $collection): string => $collection['title'].' ('.$collection['count'].' matched; +'.$collection['added'].' / -'.$collection['removed'].'; '.$collection['visible'].' visible)')
                        ->implode(', ');
                    $impact = $result['memberships_added'].' membership'.($result['memberships_added'] === 1 ? '' : 's').' added, '
                        .$result['memberships_removed'].' removed. '.$result['publicly_visible'].' unique artwork visible in the generated collections; '
                        .$result['collection_only'].' collection-only.';

                    Notification::make()
                        ->success()
                        ->title($result['collection_count'].' automatic collection'.($result['collection_count'] === 1 ? '' : 's').' ready')
                        ->body($summary ? $summary.'. '.$impact : 'No broad theme met the minimum artwork threshold. '.$impact)
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
                        ->default(AutomaticCollectionService::DEFAULT_AI_ASSISTED_MINIMUM_ARTWORK)
                        ->required(),
                    Toggle::make('published')->label('Publish immediately')->default(true)->live(),
                    Toggle::make('publishes_members')
                        ->label('Make matched artwork public inside this collection')
                        ->helperText('Takes effect when the collection is published. The artwork stays off All artwork unless published separately. This creates a reviewed snapshot; use Sync smart collection to approve later membership changes.')
                        ->default(true),
                ])
                ->requiresConfirmation()
                ->modalDescription('Your configured AI provider will choose existing approved tags. Creating the collection is the human publication gate; collection-only membership is snapshotted and will not be changed by later AI metadata.')
                ->action(function (array $data): void {
                    try {
                        $result = app(AutomaticCollectionService::class)->createWithAi(
                            guidance: $data['guidance'] ?? null,
                            minimumArtwork: (int) $data['minimum_artwork'],
                            published: (bool) $data['published'],
                            publishesMembers: (bool) ($data['publishes_members'] ?? false),
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
                        ->body($result['explanation'].' '.$result['count'].' artwork matched; +'.$result['added'].' / -'.$result['removed'].'. '.$result['visible'].' visible in the collection; '.$result['collection_only'].' collection-only.')
                        ->send();
                }),
            CreateSourceWithJournalAction::make(),
        ];
    }
}
