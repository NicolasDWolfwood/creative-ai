<?php

namespace App\Filament\Resources\Collections;

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
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
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
            FileUpload::make('hero_image_path')
                ->label('Hero image')
                ->disk('public')
                ->directory('collections/heroes')
                ->visibility('public')
                ->image()
                ->imageEditor()
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                ->maxSize(25600)
                ->openable()
                ->downloadable(),
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
            Toggle::make('published')->default(true),
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
                    Toggle::make('smart_rules.only_published')->label('Published artwork only')->default(true),
                    Toggle::make('smart_rules.only_ai_applied')
                        ->label('AI-approved artwork only')
                        ->helperText('Requires reviewed or automatically applied AI metadata.')
                        ->default(true),
                    Toggle::make('auto_sync')->label('Keep collection synchronized')->default(true),
                ])
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                ImageColumn::make('hero_image_path')->disk('public')->square()->label('Hero'),
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
                TextColumn::make('last_synced_at')->label('Synced')->since()->placeholder('Never')->toggleable(),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('syncSmartCollection')
                        ->label('Sync smart collection')
                        ->icon('heroicon-o-arrow-path')
                        ->visible(fn (Collection $record): bool => $record->is_smart)
                        ->action(function (Collection $record): void {
                            $count = app(SmartCollectionService::class)->sync($record);
                            Notification::make()->success()->title($count.' artwork matched')->send();
                        }),
                    Action::make('suggestSmartRules')
                        ->label('Suggest rules with AI')
                        ->icon('heroicon-o-sparkles')
                        ->color('info')
                        ->requiresConfirmation()
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
                            $count = app(SmartCollectionService::class)->sync($record);

                            Notification::make()->success()->title('AI rules suggested')->body($suggestion['explanation'].' '.$count.' artwork matched.')->send();
                        }),
                    Action::make('keepAsCustom')
                        ->label('Keep as custom smart collection')
                        ->icon('heroicon-o-lock-open')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalDescription('Stops the automatic collection generator from replacing or removing this collection. Tag-based synchronization stays enabled.')
                        ->visible(fn (Collection $record): bool => $record->is_auto_generated)
                        ->action(function (Collection $record): void {
                            $record->forceFill([
                                'is_auto_generated' => false,
                                'auto_generation_key' => null,
                            ])->saveQuietly();

                            Notification::make()->success()->title('Collection is now custom')->send();
                        }),
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
