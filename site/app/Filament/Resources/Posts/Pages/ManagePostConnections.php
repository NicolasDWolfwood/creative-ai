<?php

namespace App\Filament\Resources\Posts\Pages;

use App\Enums\PostMediaType;
use App\Filament\Resources\Posts\PostResource;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\Tag;
use App\Models\Track;
use App\Services\JournalPostCoverService;
use App\Services\JournalSourceImageResolver;
use App\Services\PostConnectionService;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

class ManagePostConnections extends ManageRelatedRecords
{
    protected static string $resource = PostResource::class;

    protected static string $relationship = 'mediaItems';

    protected static ?string $navigationLabel = 'Connections';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-link';

    public static function canAccess(array $parameters = []): bool
    {
        $record = $parameters['record'] ?? null;

        return $record instanceof Post
            && Gate::allows('manageConnections', $record);
    }

    protected function canReorder(): bool
    {
        return Gate::allows('manageConnections', $this->post());
    }

    public function getTitle(): string
    {
        return 'Connections for '.$this->post()->title;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('editTags')
                ->label('Edit shared tags')
                ->icon('heroicon-o-tag')
                ->authorize('manageConnections')
                ->fillForm(function (Post $record): array {
                    $tagIds = $record->tags()->pluck('tags.id')->map(fn (mixed $id): int => (int) $id)->all();

                    return [
                        'tag_ids' => $tagIds,
                        'expected_tag_ids' => json_encode($tagIds, JSON_THROW_ON_ERROR),
                    ];
                })
                ->schema([
                    Hidden::make('expected_tag_ids'),
                    Select::make('tag_ids')
                        ->label('Shared tags')
                        ->helperText('These are the same tags used by artwork and music, and help connect this story across the archive.')
                        ->options(fn (): array => Tag::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->multiple()
                        ->searchable()
                        ->preload(),
                ])
                ->action(function (array $data, Post $record): void {
                    $this->performTagSync(
                        $record,
                        collect($data['tag_ids'] ?? [])->map(fn (mixed $id): int => (int) $id)->all(),
                        $this->decodedIds($data['expected_tag_ids'] ?? '[]'),
                    );
                    $record->unsetRelation('tags');

                    Notification::make()->success()->title('Shared tags updated.')->send();
                }),
            Action::make('addMedia')
                ->label('Add connected media')
                ->icon('heroicon-o-plus')
                ->authorize('manageConnections')
                ->schema([
                    Select::make('type')
                        ->label('Media type')
                        ->options(static::typeOptions())
                        ->required()
                        ->native(false)
                        ->live(),
                    Select::make('media_id')
                        ->label('Media')
                        ->options(fn (Get $get): array => $this->sourceOptions(
                            PostMediaType::tryFrom((string) $get('type')),
                        ))
                        ->getSearchResultsUsing(fn (Get $get, string $search): array => $this->sourceOptions(
                            PostMediaType::tryFrom((string) $get('type')),
                            $search,
                        ))
                        ->optionsLimit(50)
                        ->required()
                        ->searchable()
                        ->visible(fn (Get $get): bool => PostMediaType::tryFrom((string) $get('type')) !== null),
                ])
                ->modalDescription('Connected media appears in this order on the Journal entry when both the story and source are public.')
                ->action(function (array $data): void {
                    $type = PostMediaType::tryFrom((string) ($data['type'] ?? ''));

                    if ($type === null) {
                        throw ValidationException::withMessages(['type' => 'Choose a supported media type.']);
                    }

                    $items = $this->post()->mediaItems()->get();
                    $references = $items
                        ->map(fn (PostMedia $record): array => $this->referenceFor($record))
                        ->all();
                    $references[] = ['type' => $type->value, 'id' => (int) $data['media_id']];
                    $this->performMediaSync($references, $items->pluck('id')->all());
                    $this->post()->unsetRelation('mediaItems');

                    Notification::make()->success()->title('Media connected.')->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Ordered media')
            ->description(fn (): HtmlString => new HtmlString(
                '<p>Drag items into the order used by the Journal story. Unlinking an item removes only this connection; it never deletes the source artwork, collection, album, playlist, or track.</p>'.$this->tagSummary(),
            ))
            ->defaultSort('position')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'artwork',
                'collection',
                'album',
                'playlist',
                'track',
            ]))
            ->paginated(false)
            ->reorderable('position')
            ->columns([
                TextColumn::make('position')->label('#')->sortable(),
                TextColumn::make('media_type')
                    ->label('Type')
                    ->getStateUsing(fn (PostMedia $record): ?PostMediaType => $record->type())
                    ->formatStateUsing(fn (?PostMediaType $state): string => $state ? static::typeLabel($state) : 'Unknown')
                    ->badge()
                    ->color('info'),
                TextColumn::make('media_title')
                    ->label('Media')
                    ->getStateUsing(fn (PostMedia $record): string => $record->mediaTitle())
                    ->description(fn (PostMedia $record): string => $record->media()?->getKey()
                        ? 'Source ID '.$record->media()->getKey()
                        : 'The source is no longer available.')
                    ->wrap(),
                TextColumn::make('visibility')
                    ->getStateUsing(fn (PostMedia $record): string => $record->mediaIsPublic() ? 'Public' : 'Draft / private')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Public' ? 'success' : 'warning'),
            ])
            ->recordActions([
                Action::make('useAsJournalCover')
                    ->label('Use as Journal cover')
                    ->icon('heroicon-o-photo')
                    ->color('info')
                    ->authorize(fn (): bool => Gate::allows('manageConnections', $this->post()))
                    ->visible(function (PostMedia $record): bool {
                        $source = $record->media();

                        return $source !== null
                            && $record->mediaIsPublic()
                            && app(JournalSourceImageResolver::class)->resolve($source) !== null;
                    })
                    ->fillForm(fn (): array => [
                        'expected_cover_fingerprint' => app(JournalPostCoverService::class)
                            ->coverFingerprint($this->post()),
                    ])
                    ->schema([
                        Hidden::make('expected_cover_fingerprint')->required(),
                    ])
                    ->requiresConfirmation()
                    ->modalHeading('Use this source artwork as the Journal cover?')
                    ->modalDescription(fn (): string => filled($this->post()->cover_image_path)
                        ? 'A private snapshot will replace the current cover. Existing cover bytes are retained for Journal history.'
                        : 'A stable private snapshot will be copied into the Journal post. Later source changes will not replace it.')
                    ->action(function (PostMedia $record, array $data, Action $action): void {
                        try {
                            app(JournalPostCoverService::class)->replaceFromConnection(
                                post: $this->post(),
                                connection: $record,
                                expectedCoverFingerprint: (string) ($data['expected_cover_fingerprint'] ?? ''),
                            );
                        } catch (DomainException $exception) {
                            Notification::make()->danger()
                                ->title('Journal cover could not be changed')
                                ->body($exception->getMessage())
                                ->send();
                            $action->failure();

                            return;
                        } catch (\Throwable $exception) {
                            report($exception);
                            Notification::make()->danger()
                                ->title('Journal cover could not be changed')
                                ->body('The existing cover is unchanged. Check the source artwork and try again.')
                                ->send();
                            $action->failure();

                            return;
                        }

                        $this->post()->refresh();
                        Notification::make()->success()->title('Journal cover updated.')->send();
                    }),
                Action::make('unlink')
                    ->label('Unlink')
                    ->icon('heroicon-o-link-slash')
                    ->color('danger')
                    ->authorize(fn (): bool => Gate::allows('manageConnections', $this->post()))
                    ->requiresConfirmation()
                    ->modalHeading('Unlink this media item?')
                    ->modalDescription('This removes the media from the Journal story only. The source media and its files are not deleted.')
                    ->action(function (PostMedia $record): void {
                        $items = $this->post()->mediaItems()->get();
                        $references = $items
                            ->reject(fn (PostMedia $item): bool => $item->is($record))
                            ->map(fn (PostMedia $item): array => $this->referenceFor($item))
                            ->values()
                            ->all();
                        $this->performMediaSync($references, $items->pluck('id')->all());
                        $this->post()->unsetRelation('mediaItems');

                        Notification::make()->success()->title('Media unlinked. The source was not deleted.')->send();
                    }),
            ])
            ->emptyStateHeading('No connected media yet')
            ->emptyStateDescription('Add artwork, a collection, an album, a playlist, or a track to give this Journal story structured context.');
    }

    /** @param array<int|string> $order */
    public function reorderTable(array $order, int|string|null $draggedRecordKey = null): void
    {
        Gate::authorize('manageConnections', $this->post());

        $records = $this->post()->mediaItems()->whereKey($order)->get()->keyBy(
            fn (PostMedia $record): string => (string) $record->getKey(),
        );

        if ($records->count() !== count($order)) {
            throw ValidationException::withMessages([
                'media' => 'The media list changed before the new order was saved. Refresh and try again.',
            ]);
        }

        $references = collect($order)
            ->map(fn (int|string $key): array => $this->referenceFor($records->get((string) $key)))
            ->all();

        $this->performMediaSync($references, $order);
        $this->post()->unsetRelation('mediaItems');
    }

    /** @return array{type: string, id: int} */
    protected function referenceFor(?PostMedia $record): array
    {
        $type = $record?->type();
        $media = $record?->media();

        if ($type === null || $media === null) {
            throw ValidationException::withMessages([
                'media' => 'A connected media source is missing. Refresh the page before changing the list.',
            ]);
        }

        return ['type' => $type->value, 'id' => (int) $media->getKey()];
    }

    protected function post(): Post
    {
        /** @var Post */
        return $this->getRecord();
    }

    /** @return array<string, string> */
    protected static function typeOptions(): array
    {
        return collect(PostMediaType::cases())->mapWithKeys(
            fn (PostMediaType $type): array => [$type->value => static::typeLabel($type)],
        )->all();
    }

    protected static function typeLabel(PostMediaType $type): string
    {
        return $type->label();
    }

    /** @return array<int, string> */
    protected function sourceOptions(?PostMediaType $type, ?string $search = null): array
    {
        if ($type === null) {
            return [];
        }

        $model = $type->modelClass();
        $linkedIds = $this->post()->mediaItems()->get()
            ->filter(fn (PostMedia $item): bool => $item->type() === $type)
            ->map(fn (PostMedia $item): ?int => $item->media()?->getKey())
            ->filter()
            ->all();

        return $model::query()
            ->when($linkedIds !== [], fn (Builder $query): Builder => $query->whereKeyNot($linkedIds))
            ->when(filled($search), fn (Builder $query): Builder => $query->whereRaw(
                'LOWER(title) LIKE ?',
                ['%'.strtolower((string) $search).'%'],
            ))
            ->orderBy('title')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (Model $source): array => [
                (int) $source->getKey() => $source->getAttribute('title').' — '.($this->sourceIsPublic($source) ? 'Public' : 'Draft / private'),
            ])
            ->all();
    }

    protected function sourceIsPublic(Model $source): bool
    {
        return $source instanceof Track
            ? $source->isPubliclyAvailable()
            : (method_exists($source, 'isPubliclyPublished') && $source->isPubliclyPublished());
    }

    protected function tagSummary(): string
    {
        $tags = $this->post()->tags()->orderBy('name')->pluck('name');

        return '<p class="mt-2"><strong>Shared tags:</strong> '.e($tags->isEmpty() ? 'None selected' : $tags->implode(' · ')).'</p>';
    }

    /**
     * @param  list<array{type: string, id: int}>  $references
     * @param  array<int, int|string>  $expectedIds
     */
    protected function performMediaSync(array $references, array $expectedIds): void
    {
        try {
            app(PostConnectionService::class)->syncMedia($this->post(), $references, $expectedIds);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['media' => $exception->getMessage()]);
        }
    }

    /**
     * @param  list<int>  $tagIds
     * @param  list<int>  $expectedIds
     */
    protected function performTagSync(Post $post, array $tagIds, array $expectedIds): void
    {
        try {
            app(PostConnectionService::class)->syncTags($post, $tagIds, $expectedIds);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['tag_ids' => $exception->getMessage()]);
        }
    }

    /** @return list<int> */
    protected function decodedIds(mixed $value): array
    {
        if (! is_string($value)) {
            throw ValidationException::withMessages(['tag_ids' => 'The tag list is invalid. Reload the page and try again.']);
        }

        $ids = json_decode($value, true);

        if (! is_array($ids)) {
            throw ValidationException::withMessages(['tag_ids' => 'The tag list is invalid. Reload the page and try again.']);
        }

        return collect($ids)->map(fn (mixed $id): int => (int) $id)->all();
    }
}
