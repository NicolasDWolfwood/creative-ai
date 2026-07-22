<?php

namespace App\Filament\Resources\Artworks;

use App\Enums\PostMediaType;
use App\Filament\Actions\CreateJournalDraftAction;
use App\Filament\Forms\JournalPlanningFields;
use App\Filament\Resources\Artworks\Pages\ManageArtworks;
use App\Filament\Resources\Posts\PostResource;
use App\Jobs\GenerateArtworkVariants;
use App\Models\Artwork;
use App\Models\Collection as ArtworkCollection;
use App\Models\Tag;
use App\Rules\SafeArtworkImageDimensions;
use App\Services\ArtworkAiMetadataService;
use App\Services\ArtworkAiQueueService;
use App\Services\ArtworkBulkEditorialService;
use App\Services\ArtworkCollectionCurationService;
use App\Services\AutomaticCollectionService;
use App\Services\JournalDraftAutomationService;
use App\Services\SmartCollectionService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Js;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ArtworkResource extends Resource
{
    protected static ?string $model = Artwork::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-photo';

    protected static string|\UnitEnum|null $navigationGroup = 'Showcase';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->required()->maxLength(255),
            TextInput::make('slug')
                ->maxLength(255)
                ->helperText('Leave empty to generate from the title. Save changes before copying the publication-aware original-image URL.')
                ->suffixAction(
                    Action::make('copyPublicImageUrl')
                        ->label('Copy public image URL')
                        ->icon('heroicon-o-clipboard-document')
                        ->tooltip('Copy public image URL')
                        ->visible(fn (?Artwork $record): bool => filled($record?->slug) && filled($record?->image_path))
                        ->alpineClickHandler(function (?Artwork $record): ?string {
                            if (! $record) {
                                return null;
                            }

                            $url = Js::from($record->public_image_url);
                            $message = Js::from('Public image URL copied');

                            return <<<JS
                                window.navigator.clipboard.writeText({$url})
                                \$tooltip({$message}, {
                                    theme: \$store.theme,
                                    timeout: 2000,
                                })
                                JS;
                        }),
                ),
            Select::make('collections')
                ->label('Manual collections')
                ->relationship(
                    'collections',
                    'title',
                    modifyQueryUsing: fn (Builder $query): Builder => $query
                        ->where('collections.is_smart', false)
                        ->select(['collections.id', 'collections.title'])
                        ->reorder('collections.title'),
                )
                ->multiple()
                ->searchable()
                ->preload()
                ->helperText('Only manual memberships are editable here. Smart and automatic collection memberships are derived from their rules and remain unchanged.'),
            FileUpload::make('image_path')
                ->label('Image')
                ->disk('local')
                ->directory('artworks/originals')
                ->visibility('private')
                ->image()
                ->imageEditor()
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                ->maxSize(25600)
                ->rules([new SafeArtworkImageDimensions])
                ->helperText('JPEG, PNG, or WebP up to 25 MiB and 20 megapixels.')
                ->storeFileNamesIn('original_filename')
                ->openable()
                ->downloadable()
                ->required(),
            Section::make('Applied tags')
                ->description('These persisted tags drive smart and automatic collection membership. Prefer broad, reusable subjects and themes. Applying a new AI suggestion later replaces this curated list.')
                ->schema([
                    Text::make(fn (?Artwork $record): string => static::formatAppliedTags($record)),
                    Select::make('tags')
                        ->label('Add or remove tags')
                        ->relationship(
                            'tags',
                            'name',
                            modifyQueryUsing: fn (Builder $query): Builder => $query
                                ->select(['tags.id', 'tags.name'])
                                ->reorder('tags.name'),
                        )
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->createOptionForm([
                            TextInput::make('name')
                                ->label('New reusable tag')
                                ->helperText('Use a broad lowercase word or short phrase that can apply to more than one artwork.')
                                ->required()
                                ->maxLength(80),
                        ])
                        ->createOptionUsing(function (array $data): int {
                            $name = Str::of((string) ($data['name'] ?? ''))
                                ->replace('#', '')
                                ->squish()
                                ->lower()
                                ->toString();
                            $slug = Str::slug($name);

                            if ($name === '' || $slug === '') {
                                throw ValidationException::withMessages([
                                    'name' => 'Enter a tag containing letters or numbers.',
                                ]);
                            }

                            return (int) Tag::query()->firstOrCreate(
                                ['slug' => $slug],
                                ['name' => $name],
                            )->getKey();
                        })
                        ->saveRelationshipsUsing(function (Select $component): void {
                            $record = $component->getRecord();

                            if (! $record instanceof Artwork) {
                                return;
                            }

                            $selectedIds = collect($component->getState() ?? [])
                                ->map(fn (mixed $id): int => (int) $id)
                                ->filter()
                                ->unique()
                                ->sort()
                                ->values();
                            $currentIds = $record->tags()
                                ->pluck('tags.id')
                                ->map(fn (mixed $id): int => (int) $id)
                                ->sort()
                                ->values();

                            if ($selectedIds->all() === $currentIds->all()) {
                                return;
                            }

                            // Existing AI categories are preserved by sync(). New
                            // attachments use the pivot's "other" default and are
                            // included in the automatic collection vocabulary.
                            $record->tags()->sync($selectedIds->all());
                            $record->unsetRelation('tags');

                            app(AutomaticCollectionService::class)->refreshExisting(sync: false);
                            app(SmartCollectionService::class)->syncAutomatic();
                        })
                        ->helperText('Existing AI categories are kept. New manual tags are treated as general collection tags. Live smart collections refresh when the artwork is saved; reviewed public snapshots still require an explicit refresh.')
                        ->columnSpanFull(),
                ])
                ->columnSpanFull(),
            Textarea::make('description')->rows(3)->columnSpanFull(),
            Textarea::make('alt_text')
                ->label('Alt text')
                ->rows(2)
                ->maxLength(200)
                ->columnSpanFull(),
            Textarea::make('prompt')->rows(4)->columnSpanFull(),
            Textarea::make('process_notes')
                ->label('Process notes')
                ->rows(5)
                ->helperText('Public notes about the tools, iterations, and decisions behind this artwork.')
                ->columnSpanFull(),
            TextInput::make('sort_order')->numeric()->default(0),
            Toggle::make('featured')
                ->helperText('Makes this artwork eligible for the public homepage frame once any future date is due. It does not add the artwork to All artwork, its detail page, collections, or the sitemap.'),
            Toggle::make('published')
                ->label('Publish as standalone artwork')
                ->helperText('Artwork can also be available through a published collection without appearing separately in All artwork.')
                ->default(true)
                ->live(),
            DateTimePicker::make('generated_at'),
            DateTimePicker::make('published_at')
                ->label('Standalone publish date')
                ->helperText('A future date also delays collection-only visibility until that time.'),
            JournalPlanningFields::make(PostMediaType::Artwork),
            Section::make('AI suggestions')
                ->schema([
                    TextInput::make('ai_status')
                        ->label('Status')
                        ->disabled()
                        ->dehydrated(false),
                    TextInput::make('ai_model')
                        ->label('Model')
                        ->disabled()
                        ->dehydrated(false),
                    DateTimePicker::make('ai_analyzed_at')
                        ->label('Analyzed')
                        ->disabled()
                        ->dehydrated(false),
                    TextInput::make('ai_suggestion.confidence')
                        ->label('Confidence')
                        ->disabled()
                        ->dehydrated(false),
                    TextInput::make('ai_suggestion.title')
                        ->label('Suggested title')
                        ->disabled()
                        ->dehydrated(false),
                    Textarea::make('ai_suggestion.description')
                        ->label('Suggested description')
                        ->rows(3)
                        ->disabled()
                        ->dehydrated(false)
                        ->columnSpanFull(),
                    Textarea::make('ai_suggestion.alt_text')
                        ->label('Suggested alt text')
                        ->rows(2)
                        ->disabled()
                        ->dehydrated(false)
                        ->columnSpanFull(),
                    Text::make(fn (?Artwork $record): string => static::formatAiTags($record))
                        ->columnSpanFull(),
                    Textarea::make('ai_suggestion.content_warning')
                        ->label('Content warning')
                        ->rows(2)
                        ->disabled()
                        ->dehydrated(false)
                        ->columnSpanFull(),
                    Textarea::make('ai_error')
                        ->label('Error')
                        ->rows(2)
                        ->disabled()
                        ->dehydrated(false)
                        ->columnSpanFull(),
                    Actions::make([
                        Action::make('applySuggestionFromEdit')
                            ->label('Apply AI suggestions')
                            ->icon('heroicon-o-check-circle')
                            ->color('success')
                            ->visible(fn (?Artwork $record): bool => filled($record?->ai_suggestion)
                                && $record->ai_status === Artwork::AI_STATUS_READY)
                            ->requiresConfirmation()
                            ->modalDescription('This replaces the public title, description, alt text, and tags. The existing slug remains unchanged.')
                            ->action(function (?Artwork $record, Set $set): void {
                                if (! $record) {
                                    return;
                                }

                                $record = app(ArtworkAiMetadataService::class)->applySuggestion($record);

                                $set('title', $record->title);
                                $set('description', $record->description);
                                $set('alt_text', $record->alt_text);
                                $set('ai_status', $record->ai_status);
                                $set('ai_model', $record->ai_model);
                                $set('ai_analyzed_at', $record->ai_analyzed_at);
                                $set('ai_error', null);
                                $set('ai_suggestion', $record->ai_suggestion);

                                Notification::make()->title('AI suggestions applied.')->success()->send();
                            }),
                    ])->columnSpanFull(),
                ])
                ->columns(2)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->extraAttributes(['class' => 'ca-artwork-table'])
            ->poll('5s')
            ->defaultSort('sort_order', 'desc')
            ->columns([
                ImageColumn::make('thumb_path')
                    ->getStateUsing(fn (Artwork $record): string => $record->thumb_url)
                    ->square()
                    ->label('Preview'),
                TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Artwork $record): string => $record->collections->pluck('title')->implode(' · ') ?: 'Uncollected')
                    ->wrap(),
                TextColumn::make('variant_status')
                    ->label('Image')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => ucfirst($state ?: Artwork::VARIANT_STATUS_PENDING))
                    ->color(fn (?string $state): string => match ($state) {
                        Artwork::VARIANT_STATUS_READY => 'success',
                        Artwork::VARIANT_STATUS_QUEUED, Artwork::VARIANT_STATUS_PROCESSING => 'warning',
                        Artwork::VARIANT_STATUS_FAILED => 'danger',
                        default => 'gray',
                    })
                    ->description(fn (Artwork $record): ?string => $record->variant_status === Artwork::VARIANT_STATUS_FAILED
                        ? str($record->variant_error)->limit(80)->toString()
                        : null),
                TextColumn::make('ai_status')
                    ->label('AI')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => ucfirst($state ?: Artwork::AI_STATUS_IDLE))
                    ->color(fn (?string $state): string => match ($state) {
                        Artwork::AI_STATUS_READY => 'success',
                        Artwork::AI_STATUS_QUEUED, Artwork::AI_STATUS_PROCESSING => 'warning',
                        Artwork::AI_STATUS_FAILED => 'danger',
                        Artwork::AI_STATUS_APPLIED => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('tags.name')
                    ->label('Tags')
                    ->badge()
                    ->limitList(3)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('published')
                    ->label('Availability')
                    ->badge()
                    ->formatStateUsing(fn (bool $state, Artwork $record): string => match (true) {
                        $record->isPubliclyPublished() => 'Standalone',
                        $record->isPubliclyAvailable() => 'Via collection',
                        $state => 'Standalone scheduled',
                        $record->collections->contains(
                            fn (ArtworkCollection $collection): bool => $collection->grantsMemberPublication()
                                && (bool) $collection->published,
                        ) => 'Collection scheduled',
                        $record->featured && $record->published_at?->isFuture() => 'Homepage scheduled',
                        $record->isHomepageHeroEligible() => 'Homepage only',
                        default => 'Draft',
                    })
                    ->color(fn (bool $state, Artwork $record): string => match (true) {
                        $record->isPubliclyPublished() => 'success',
                        $record->isPubliclyAvailable() => 'info',
                        $state || $record->collections->contains(
                            fn (ArtworkCollection $collection): bool => $collection->grantsMemberPublication()
                                && (bool) $collection->published,
                        ) || ($record->featured && $record->published_at?->isFuture()) => 'warning',
                        $record->isHomepageHeroEligible() => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('sort_order')->sortable()->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('featured')->boolean()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')->since()->sortable()->label('Updated')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('ai_status')
                    ->label('AI status')
                    ->options([
                        Artwork::AI_STATUS_IDLE => 'Not analyzed',
                        Artwork::AI_STATUS_QUEUED => 'Queued',
                        Artwork::AI_STATUS_PROCESSING => 'Processing',
                        Artwork::AI_STATUS_READY => 'Ready to review',
                        Artwork::AI_STATUS_FAILED => 'Failed',
                        Artwork::AI_STATUS_APPLIED => 'Applied',
                    ]),
                SelectFilter::make('collections')
                    ->options(fn (): array => ArtworkCollection::query()
                        ->orderBy('sort_order')
                        ->orderBy('title')
                        ->pluck('title', 'id')
                        ->all())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, int|string $collectionId): Builder => $query->whereHas(
                            'collections',
                            fn (Builder $query): Builder => $query->whereKey($collectionId),
                        ),
                    ))
                    ->searchable()
                    ->preload(),
                TernaryFilter::make('collection_membership')
                    ->label('Collection membership')
                    ->placeholder('All artwork')
                    ->trueLabel('In any collection')
                    ->falseLabel('Not in any collection')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereHas('collections'),
                        false: fn (Builder $query): Builder => $query->whereDoesntHave('collections'),
                    ),
                TernaryFilter::make('published')->label('Standalone publication'),
                TernaryFilter::make('featured')->label('Featured'),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('retryImageVariants')
                        ->label('Retry image sizes')
                        ->icon('heroicon-o-arrow-path')
                        ->color('warning')
                        ->visible(fn (Artwork $record): bool => $record->variant_status !== Artwork::VARIANT_STATUS_READY)
                        ->action(function (Artwork $record): void {
                            GenerateArtworkVariants::dispatchFor($record);

                            Notification::make()->title('Image sizes queued for regeneration.')->success()->send();
                        }),
                    Action::make('analyzeWithAi')
                        ->label('Analyze with AI')
                        ->icon('heroicon-o-sparkles')
                        ->color('info')
                        ->requiresConfirmation()
                        ->action(function (Artwork $record): void {
                            app(ArtworkAiQueueService::class)->queue($record);

                            Notification::make()->title('Artwork queued for AI analysis.')->success()->send();
                        }),
                    Action::make('applyAiSuggestion')
                        ->label('Apply AI suggestions')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->visible(fn (Artwork $record): bool => filled($record->ai_suggestion)
                            && $record->ai_status === Artwork::AI_STATUS_READY)
                        ->action(function (Artwork $record): void {
                            app(ArtworkAiMetadataService::class)->applySuggestion($record);

                            Notification::make()->title('AI suggestions applied.')->success()->send();
                        }),
                    CreateJournalDraftAction::make()->allowPrivateSources(),
                    EditAction::make(),
                    DeleteAction::make(),
                ])->icon('heroicon-m-ellipsis-horizontal')->tooltip('Artwork actions'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('createDraftCollectionFromSelected')
                        ->label('Create draft collection from selected')
                        ->icon('heroicon-o-rectangle-stack')
                        ->color('info')
                        ->schema([
                            TextInput::make('title')
                                ->label('Collection title')
                                ->required()
                                ->maxLength(255),
                            Textarea::make('description')
                                ->rows(3)
                                ->maxLength(1000),
                        ])
                        ->requiresConfirmation()
                        ->modalDescription('Creates one private manual collection from selected artwork that is still in no collection when this action runs. It never publishes the collection or its members, and skips records that gained a membership in the meantime.')
                        ->modalSubmitActionLabel('Create draft collection')
                        ->deselectRecordsAfterCompletion()
                        ->action(function (EloquentCollection $records, array $data): void {
                            $result = app(ArtworkCollectionCurationService::class)->createDraftFromUncollected(
                                $records->modelKeys(),
                                (string) $data['title'],
                                $data['description'] ?? null,
                            );

                            if (! $result['collection']) {
                                Notification::make()
                                    ->title('No uncollected artwork remained')
                                    ->body($result['skipped'].' selected item'.($result['skipped'] === 1 ? ' was' : 's were').' no longer eligible. Nothing was created.')
                                    ->info()
                                    ->send();

                                return;
                            }

                            Notification::make()
                                ->title('Draft collection created')
                                ->body($result['attached'].' artwork added to “'.$result['collection']->title.'”.'
                                    .($result['skipped'] > 0 ? ' '.$result['skipped'].' selected item'.($result['skipped'] === 1 ? ' was' : 's were').' skipped because no longer eligible.' : '')
                                    .' Publication and Featured settings were left unchanged.')
                                ->success()
                                ->send();
                        }),
                    BulkAction::make('publishSelectedNow')
                        ->label('Publish selected now')
                        ->icon('heroicon-o-globe-alt')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalDescription('Makes selected artwork public now as standalone artwork in All artwork. Future dates are replaced with the current time; existing past publication dates and collection memberships are preserved.')
                        ->deselectRecordsAfterCompletion()
                        ->action(function (EloquentCollection $records): void {
                            $result = app(ArtworkBulkEditorialService::class)->publishNow($records);
                            $unchanged = $result['selected'] - $result['changed'];
                            $notification = Notification::make()
                                ->title($result['changed'] > 0
                                    ? 'Published '.$result['changed'].' selected item'.($result['changed'] === 1 ? '' : 's').' as standalone artwork'
                                    : 'No standalone publication changes')
                                ->body($unchanged > 0
                                    ? $unchanged.' selected item'.($unchanged === 1 ? ' was' : 's were').' already public now.'
                                    : 'All selected artwork is now available in All artwork.');

                            ($result['changed'] > 0 ? $notification->success() : $notification->info())->send();
                        }),
                    BulkAction::make('unpublishSelected')
                        ->label('Remove standalone publication')
                        ->icon('heroicon-o-eye-slash')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalDescription('Removes selected artwork from standalone publication. Artwork may remain public through a published collection that grants member access; publication dates and collection memberships are preserved.')
                        ->deselectRecordsAfterCompletion()
                        ->action(function (EloquentCollection $records): void {
                            $result = app(ArtworkBulkEditorialService::class)->removeStandalonePublication($records);
                            $unchanged = $result['selected'] - $result['changed'];
                            $body = $result['still_available_via_collection'].' selected item'
                                .($result['still_available_via_collection'] === 1 ? ' remains' : 's remain')
                                .' available through published collections. Dates and memberships were preserved.';

                            if ($unchanged > 0) {
                                $body .= ' '.$unchanged.' selected item'.($unchanged === 1 ? ' was' : 's were').' already off standalone publication.';
                            }

                            $notification = Notification::make()
                                ->title($result['changed'] > 0
                                    ? 'Removed standalone publication from '.$result['changed'].' selected item'.($result['changed'] === 1 ? '' : 's')
                                    : 'No standalone publication changes')
                                ->body($body);

                            ($result['changed'] > 0 ? $notification->success() : $notification->info())->send();
                        }),
                    BulkAction::make('featureSelected')
                        ->label('Mark selected as Featured')
                        ->icon('heroicon-o-star')
                        ->color('info')
                        ->requiresConfirmation()
                        ->modalDescription('Makes selected artwork eligible for the public homepage frame and preferred for collection covers once any future date is due. A Featured draft can appear on the homepage, but remains absent from All artwork, its detail page, collections, and the sitemap unless published separately. Publication fields and memberships are unchanged.')
                        ->deselectRecordsAfterCompletion()
                        ->action(function (EloquentCollection $records): void {
                            $result = app(ArtworkBulkEditorialService::class)->setFeatured($records, true);
                            $unchanged = $result['selected'] - $result['changed'];
                            $notification = Notification::make()
                                ->title($result['changed'] > 0
                                    ? 'Marked '.$result['changed'].' selected item'.($result['changed'] === 1 ? '' : 's').' as Featured'
                                    : 'No Featured changes')
                                ->body('Standalone publication is unchanged; eligible artwork may now appear in the homepage frame.'
                                    .($unchanged > 0 ? ' '.$unchanged.' selected item'.($unchanged === 1 ? ' was' : 's were').' already Featured.' : ''));

                            ($result['changed'] > 0 ? $notification->success() : $notification->info())->send();
                        }),
                    BulkAction::make('unfeatureSelected')
                        ->label('Remove Featured from selected')
                        ->icon('heroicon-o-minus-circle')
                        ->requiresConfirmation()
                        ->modalDescription('Removes Featured homepage and collection-cover preference from selected artwork. Standalone publication dates and collection memberships are unchanged; published artwork remains eligible for the homepage frame.')
                        ->deselectRecordsAfterCompletion()
                        ->action(function (EloquentCollection $records): void {
                            $result = app(ArtworkBulkEditorialService::class)->setFeatured($records, false);
                            $unchanged = $result['selected'] - $result['changed'];
                            $notification = Notification::make()
                                ->title($result['changed'] > 0
                                    ? 'Removed Featured from '.$result['changed'].' selected item'.($result['changed'] === 1 ? '' : 's')
                                    : 'No Featured changes')
                                ->body('Publication is unchanged.'
                                    .($unchanged > 0 ? ' '.$unchanged.' selected item'.($unchanged === 1 ? ' was' : 's were').' already not Featured.' : ''));

                            ($result['changed'] > 0 ? $notification->success() : $notification->info())->send();
                        }),
                    BulkAction::make('createJournalBatch')
                        ->label('Create one Journal draft')
                        ->icon('heroicon-o-document-plus')
                        ->color('info')
                        ->schema(JournalPlanningFields::actionOptions(includeRequestToggle: false))
                        ->requiresConfirmation()
                        ->modalDescription('Creates one private Journal draft for the selected artwork that is not already connected. This never runs AI or publishes the story.')
                        ->deselectRecordsAfterCompletion()
                        ->action(function (EloquentCollection $records, array $data): void {
                            try {
                                $result = app(JournalDraftAutomationService::class)->createBatch($records, $data);
                            } catch (Throwable $exception) {
                                report($exception);
                                Notification::make()
                                    ->danger()
                                    ->title('Journal batch could not be created')
                                    ->body('The selected artwork is unchanged. Check its source files and try again.')
                                    ->send();

                                return;
                            }

                            $notification = Notification::make()
                                ->success()
                                ->title($result->created() ? 'Batch Journal draft created' : 'No duplicate draft created')
                                ->body($result->connected.' artwork connected; '.$result->skipped.' already planned.');

                            if ($result->post !== null) {
                                $notification->actions([
                                    Action::make('openJournalBatch')
                                        ->label('Open Journal draft')
                                        ->url(PostResource::getUrl('edit', ['record' => $result->post])),
                                ]);
                            }

                            $notification->send();
                        }),
                    BulkAction::make('analyzeSelectedWithAi')
                        ->label('Analyze selected with AI')
                        ->icon('heroicon-o-sparkles')
                        ->color('info')
                        ->schema([
                            Toggle::make('apply_immediately')
                                ->label('Apply suggestions automatically')
                                ->helperText('Skips review and publishes the generated metadata as each analysis completes.')
                                ->default(false),
                        ])
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->action(function (EloquentCollection $records, array $data): void {
                            $applyImmediately = (bool) ($data['apply_immediately'] ?? false);

                            $records->each(fn (Artwork $record) => app(ArtworkAiQueueService::class)->queue(
                                $record,
                                applyAfterAnalysis: $applyImmediately,
                            ));

                            Notification::make()
                                ->title($records->count().' artwork queued for AI analysis.')
                                ->body($applyImmediately ? 'Suggestions will be applied automatically.' : 'Suggestions will wait for review.')
                                ->success()
                                ->send();
                        }),
                    BulkAction::make('applySelectedAiSuggestions')
                        ->label('Apply selected AI suggestions')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalDescription('Only selected artwork with unapplied AI suggestions will be changed. Existing slugs remain unchanged.')
                        ->deselectRecordsAfterCompletion()
                        ->action(function (EloquentCollection $records): void {
                            $count = app(ArtworkAiMetadataService::class)->applySuggestions($records);

                            Notification::make()
                                ->title($count.' AI suggestion'.($count === 1 ? '' : 's').' applied.')
                                ->success()
                                ->send();
                        }),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('collections');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageArtworks::route('/'),
        ];
    }

    protected static function formatAiTags(?Artwork $record): string
    {
        if (! $record?->ai_suggestion) {
            return 'Suggested tags: none yet';
        }

        $suggestion = $record->ai_suggestion;
        $groups = [
            'Subject' => $suggestion['tags'] ?? [],
            'Style' => $suggestion['style_tags'] ?? [],
            'Mood' => $suggestion['mood_tags'] ?? [],
            'Color' => $suggestion['color_tags'] ?? [],
            'Medium' => $suggestion['medium_tags'] ?? [],
        ];

        $summary = collect($groups)
            ->map(fn (array $tags, string $label): ?string => count($tags) ? $label.': '.implode(', ', $tags) : null)
            ->filter()
            ->implode(' | ');

        return 'Suggested tags: '.($summary ?: 'none');
    }

    protected static function formatAppliedTags(?Artwork $record): string
    {
        if (! $record?->exists) {
            return 'No tags have been applied yet.';
        }

        $categoryOrder = array_flip(['subject', 'style', 'mood', 'color', 'medium']);
        $summary = $record->tags()
            ->get()
            ->groupBy(fn ($tag): string => filled($tag->pivot?->category) ? (string) $tag->pivot->category : 'other')
            ->sortKeysUsing(fn (string $left, string $right): int => [
                $categoryOrder[$left] ?? PHP_INT_MAX,
                $left,
            ] <=> [
                $categoryOrder[$right] ?? PHP_INT_MAX,
                $right,
            ])
            ->map(fn ($tags, string $category): string => str($category)->headline().': '.$tags
                ->pluck('name')
                ->sort(SORT_NATURAL | SORT_FLAG_CASE)
                ->implode(', '))
            ->implode(' | ');

        return $summary ?: 'No tags have been applied yet.';
    }
}
