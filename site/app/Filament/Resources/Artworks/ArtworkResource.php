<?php

namespace App\Filament\Resources\Artworks;

use App\Filament\Resources\Artworks\Pages\ManageArtworks;
use App\Models\Artwork;
use App\Services\ArtworkAiMetadataService;
use App\Services\ArtworkAiQueueService;
use Filament\Actions\Action;
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
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

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
            TextInput::make('slug')->maxLength(255)->helperText('Leave empty to generate from the title.'),
            Select::make('collection_id')
                ->relationship('collection', 'title')
                ->searchable()
                ->preload(),
            FileUpload::make('image_path')
                ->label('Image')
                ->disk('public')
                ->directory('artworks/originals')
                ->visibility('public')
                ->image()
                ->imageEditor()
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                ->maxSize(25600)
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
                ])
                ->columns(2)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll('5s')
            ->defaultSort('sort_order', 'desc')
            ->columns([
                ImageColumn::make('thumb_path')->disk('public')->square()->label('Preview'),
                TextColumn::make('title')->searchable()->sortable(),
                TextColumn::make('collection.title')->sortable(),
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
                TextColumn::make('sort_order')->sortable(),
                IconColumn::make('featured')->boolean(),
                IconColumn::make('published')->boolean(),
                TextColumn::make('updated_at')->dateTime()->sortable(),
            ])
            ->recordActions([
                Action::make('analyzeWithAi')
                    ->label('Analyze with AI')
                    ->icon('heroicon-o-sparkles')
                    ->color('info')
                    ->requiresConfirmation()
                    ->action(function (Artwork $record): void {
                        app(ArtworkAiQueueService::class)->queue($record);

                        Notification::make()
                            ->title('Artwork queued for AI analysis.')
                            ->success()
                            ->send();
                    }),
                Action::make('applyAiSuggestion')
                    ->label('Apply AI Suggestions')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Artwork $record): bool => filled($record->ai_suggestion))
                    ->action(function (Artwork $record): void {
                        app(ArtworkAiMetadataService::class)->applySuggestion($record);

                        Notification::make()
                            ->title('AI suggestions applied.')
                            ->success()
                            ->send();
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('analyzeSelectedWithAi')
                        ->label('Analyze selected with AI')
                        ->icon('heroicon-o-sparkles')
                        ->color('info')
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->action(function (EloquentCollection $records): void {
                            $records->each(fn (Artwork $record) => app(ArtworkAiQueueService::class)->queue($record));

                            Notification::make()
                                ->title($records->count().' artwork queued for AI analysis.')
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
