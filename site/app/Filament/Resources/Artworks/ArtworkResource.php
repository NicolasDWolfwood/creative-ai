<?php

namespace App\Filament\Resources\Artworks;

use App\Filament\Resources\Artworks\Pages\ManageArtworks;
use App\Jobs\GenerateArtworkVariants;
use App\Models\Artwork;
use App\Models\Collection as ArtworkCollection;
use App\Rules\SafeArtworkImageDimensions;
use App\Services\ArtworkAiMetadataService;
use App\Services\ArtworkAiQueueService;
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
                ->label('Collections')
                ->relationship(
                    'collections',
                    'title',
                    modifyQueryUsing: fn (Builder $query): Builder => $query
                        ->select(['collections.id', 'collections.title'])
                        ->reorder('collections.title'),
                )
                ->multiple()
                ->searchable()
                ->preload(),
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
            Toggle::make('featured'),
            Toggle::make('published')->default(true),
            DateTimePicker::make('generated_at'),
            DateTimePicker::make('published_at'),
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
                                && $record->ai_status !== Artwork::AI_STATUS_APPLIED)
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
                IconColumn::make('published')->boolean()->label('Live'),
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
                TernaryFilter::make('published')->label('Published'),
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
                        ->visible(fn (Artwork $record): bool => filled($record->ai_suggestion))
                        ->action(function (Artwork $record): void {
                            app(ArtworkAiMetadataService::class)->applySuggestion($record);

                            Notification::make()->title('AI suggestions applied.')->success()->send();
                        }),
                    EditAction::make(),
                    DeleteAction::make(),
                ])->icon('heroicon-m-ellipsis-horizontal')->tooltip('Artwork actions'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
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
}
