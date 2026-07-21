<?php

namespace App\Filament\Resources\Collections;

use App\Enums\PostMediaType;
use App\Filament\Actions\CreateJournalDraftAction;
use App\Filament\Forms\JournalPlanningFields;
use App\Filament\Resources\Collections\Pages\ManageCollections;
use App\Models\Artwork;
use App\Models\Collection;
use App\Models\Tag;
use App\Services\SmartCollectionService;
use App\Services\SmartRuleAiService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CollectionResource extends Resource
{
    protected static ?string $model = Collection::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static string|\UnitEnum|null $navigationGroup = 'Showcase';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->required()->maxLength(255),
            TextInput::make('slug')->maxLength(255)->helperText('Leave empty to generate from the title.'),
            Select::make('artworks')
                ->relationship(
                    'artworks',
                    'title',
                    modifyQueryUsing: fn (Builder $query): Builder => $query
                        ->select(['artworks.id', 'artworks.title'])
                        ->reorder('artworks.title'),
                )
                ->multiple()
                ->searchable()
                ->preload()
                ->visible(fn (Get $get): bool => ! (bool) $get('is_smart'))
                ->columnSpanFull(),
            Textarea::make('description')->rows(4)->columnSpanFull(),
            TextInput::make('sort_order')->numeric()->default(0),
            Toggle::make('featured'),
            Toggle::make('published')
                ->helperText('Publishing the collection controls its page. Member artwork can remain off the standalone archive.')
                ->default(true)
                ->live(),
            Toggle::make('publishes_members')
                ->label('Make member artwork public through this collection')
                ->helperText('Members become viewable from this published collection without appearing in All artwork. For smart collections that include drafts, membership becomes a reviewed snapshot.')
                ->default(false)
                ->live()
                ->afterStateUpdated(function (bool $state, Get $get, Set $set): void {
                    if ($state && (bool) $get('is_smart') && ! (bool) $get('smart_rules.only_published')) {
                        $set('auto_sync', false);
                    }
                }),
            DateTimePicker::make('published_at'),
            Toggle::make('is_smart')
                ->label('Smart collection')
                ->helperText('Automatically select artwork using approved metadata tags.')
                ->disabled(fn (?Collection $record): bool => (bool) $record?->is_auto_generated)
                ->live(),
            Section::make('Smart collection rules')
                ->description('Choose approved tags manually or let AI suggest a focused rule.')
                ->visible(fn (Get $get): bool => (bool) $get('is_smart'))
                ->columns(2)
                ->schema([
                    Select::make('smart_rules.tag_ids')
                        ->label('Artwork tags')
                        ->options(fn (): array => Tag::query()
                            ->whereHas('artworks', fn (Builder $query): Builder => $query
                                ->where('ai_status', Artwork::AI_STATUS_APPLIED)
                                ->whereNotNull('ai_analyzed_at'))
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->columnSpanFull(),
                    Select::make('smart_rules.match')
                        ->label('Tag matching')
                        ->options(['any' => 'Match any selected tag', 'all' => 'Match every selected tag'])
                        ->default('any')
                        ->native(false),
                    Toggle::make('smart_rules.only_published')
                        ->label('Standalone-published artwork only')
                        ->helperText('Turn this off to include reviewed drafts. If members are public through this collection, synchronization becomes an explicit snapshot refresh.')
                        ->default(true)
                        ->live()
                        ->afterStateUpdated(function (bool $state, Get $get, Set $set): void {
                            if (! $state && (bool) $get('publishes_members')) {
                                $set('auto_sync', false);
                            }
                        }),
                    Toggle::make('smart_rules.only_ai_applied')
                        ->label('AI-approved artwork only')
                        ->helperText('Requires reviewed or automatically applied AI metadata.')
                        ->default(true),
                    Toggle::make('auto_sync')
                        ->label('Keep collection synchronized')
                        ->helperText(fn (Get $get): string => (bool) $get('publishes_members') && ! (bool) $get('smart_rules.only_published')
                            ? 'Disabled for collection-only publication. Use Sync smart collection to review and approve a new membership snapshot.'
                            : 'Automatically updates membership when eligible metadata changes.')
                        ->default(true)
                        ->disabled(fn (Get $get): bool => (bool) $get('publishes_members') && ! (bool) $get('smart_rules.only_published'))
                        ->dehydrated(),
                ])
                ->columnSpanFull(),
            JournalPlanningFields::make(PostMediaType::Collection),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('title')->searchable()->sortable(),
                TextColumn::make('artworks_count')->counts('artworks')->label('Artwork')->sortable(),
                TextColumn::make('is_auto_generated')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (bool $state, Collection $record): string => $state ? 'Automatic' : ($record->is_smart ? 'Smart' : 'Manual'))
                    ->color(fn (bool $state, Collection $record): string => $state ? 'success' : ($record->is_smart ? 'info' : 'gray')),
                TextColumn::make('sort_order')->sortable(),
                IconColumn::make('featured')->boolean(),
                IconColumn::make('published')->boolean(),
                IconColumn::make('publishes_members')->label('Members public')->boolean(),
                TextColumn::make('last_synced_at')->label('Synced')->since()->placeholder('Never')->toggleable(),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('syncSmartCollection')
                        ->label('Sync smart collection')
                        ->icon('heroicon-o-arrow-path')
                        ->visible(fn (Collection $record): bool => $record->is_smart)
                        ->requiresConfirmation()
                        ->modalDescription(fn (Collection $record): string => $record->publishes_members && ! (bool) data_get($record->smart_rules, 'only_published', true)
                            ? 'Replaces the reviewed membership snapshot now. Newly matched drafts become public through this collection but remain off All artwork. Later AI metadata changes cannot update this snapshot automatically.'
                            : 'Refreshes membership from the current smart rules.')
                        ->action(function (Collection $record): void {
                            $before = $record->artworks()->pluck('artworks.id');
                            $count = app(SmartCollectionService::class)->sync($record, explicit: true);
                            $after = $record->artworks()->pluck('artworks.id');

                            Notification::make()
                                ->success()
                                ->title($count.' artwork matched')
                                ->body($after->diff($before)->count().' added; '.$before->diff($after)->count().' removed. '
                                    .($record->publishes_members && ! (bool) data_get($record->smart_rules, 'only_published', true)
                                        ? 'This public membership is now a fixed snapshot.'
                                        : 'Live synchronization remains available for standalone-published artwork.'))
                                ->send();
                        }),
                    Action::make('suggestSmartRules')
                        ->label('Suggest rules with AI')
                        ->icon('heroicon-o-sparkles')
                        ->color('info')
                        ->requiresConfirmation()
                        ->modalDescription(fn (Collection $record): string => $record->publishes_members
                            ? 'AI will suggest tags, but this confirmed action is the publication gate. Any newly matched drafts become public only through this collection, and the resulting membership is kept as a fixed snapshot.'
                            : 'AI will suggest approved tags and immediately refresh this collection membership. Member artwork is not made public by this collection.')
                        ->visible(fn (Collection $record): bool => $record->is_smart)
                        ->action(function (Collection $record): void {
                            $suggestion = app(SmartRuleAiService::class)->suggest(
                                'artwork collection',
                                $record->title,
                                $record->description,
                                Tag::query()
                                    ->whereHas('artworks', fn (Builder $query): Builder => $query
                                        ->where('ai_status', Artwork::AI_STATUS_APPLIED)
                                        ->whereNotNull('ai_analyzed_at'))
                                    ->orderBy('name')
                                    ->get(),
                            );
                            $rules = array_replace($record->smart_rules ?? [], [
                                'tag_ids' => $suggestion['tag_ids'],
                                'match' => 'any',
                                'only_analyzed' => true,
                                'only_ai_applied' => true,
                                'ai_explanation' => $suggestion['explanation'],
                                'ai_model' => $suggestion['model'],
                            ]);
                            $record->forceFill(['smart_rules' => $rules])->saveQuietly();
                            $count = app(SmartCollectionService::class)->sync($record, explicit: true);

                            Notification::make()->success()->title('AI rules suggested')->body($suggestion['explanation'].' '.$count.' artwork matched.')->send();
                        }),
                    Action::make('keepAsCustom')
                        ->label('Keep as custom smart collection')
                        ->icon('heroicon-o-lock-open')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalDescription('Stops the automatic collection generator from replacing or removing this collection. Its tag rules and explicit Sync smart collection action remain available.')
                        ->visible(fn (Collection $record): bool => $record->is_auto_generated)
                        ->action(function (Collection $record): void {
                            $record->forceFill([
                                'is_auto_generated' => false,
                                'auto_generation_key' => null,
                            ])->saveQuietly();

                            Notification::make()->success()->title('Collection is now custom')->send();
                        }),
                    CreateJournalDraftAction::make()->allowPrivateSources(),
                    EditAction::make(),
                    DeleteAction::make(),
                ])->icon('heroicon-m-ellipsis-horizontal')->tooltip('Collection actions'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCollections::route('/'),
        ];
    }
}
