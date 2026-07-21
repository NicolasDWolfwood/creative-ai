<?php

namespace App\Filament\Resources\PostTemplates;

use App\Filament\Resources\PostTemplates\Pages\CreatePostTemplate;
use App\Filament\Resources\PostTemplates\Pages\EditPostTemplate;
use App\Filament\Resources\PostTemplates\Pages\ListPostTemplates;
use App\Models\PostTemplate;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class PostTemplateResource extends Resource
{
    protected static ?string $model = PostTemplate::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-duplicate';

    protected static string|\UnitEnum|null $navigationGroup = 'Publishing';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Journal templates';

    protected static ?string $modelLabel = 'Journal template';

    protected static ?string $pluralModelLabel = 'Journal templates';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Template')
                ->description('Reusable starting material for a new private Journal draft. Templates never publish a post or change a connected source.')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Template name')
                        ->helperText('Used only to identify this template in the administrator panel.')
                        ->required()
                        ->mutateStateForValidationUsing(fn (?string $state): string => Str::of((string) $state)->squish()->toString())
                        ->dehydrateStateUsing(fn (?string $state): string => Str::of((string) $state)->squish()->toString())
                        ->unique(ignoreRecord: true)
                        ->maxLength(255),
                    Toggle::make('is_active')
                        ->label('Available for new drafts')
                        ->helperText('Inactive templates remain stored but are hidden from Create Journal draft actions.')
                        ->default(true)
                        ->inline(false),
                    TextInput::make('title')
                        ->label('Starting title')
                        ->helperText('Optional. Safe placeholders: {{ source_title }} and {{ source_type }}.')
                        ->maxLength(255)
                        ->columnSpanFull(),
                    Textarea::make('excerpt')
                        ->label('Starting excerpt')
                        ->helperText('Optional. Safe placeholders: {{ source_title }} and {{ source_type }}.')
                        ->rows(3)
                        ->maxLength(500)
                        ->columnSpanFull(),
                    MarkdownEditor::make('body')
                        ->label('Starting post content')
                        ->helperText('Optional Markdown. Only {{ source_title }} and {{ source_type }} are replaced; Blade and arbitrary expressions are never evaluated.')
                        ->columnSpanFull(),
                    Textarea::make('editorial_brief')
                        ->label('Starting private editorial brief')
                        ->helperText('Private planning guidance copied into the draft. Safe placeholders: {{ source_title }} and {{ source_type }}.')
                        ->rows(5)
                        ->columnSpanFull(),
                    Select::make('tags')
                        ->label('Default shared tags')
                        ->relationship(
                            'tags',
                            'name',
                            modifyQueryUsing: fn (Builder $query): Builder => $query->where(function (Builder $query): void {
                                $query
                                    ->whereHas('posts', fn (Builder $query) => $query->published())
                                    ->orWhereHas('artworks', fn (Builder $query) => $query->publiclyAvailable())
                                    ->orWhereHas('tracks', fn (Builder $query) => $query->publiclyAvailable());
                            }),
                        )
                        ->helperText('Only tags already used by public archive content can be selected. They are copied to each new draft; existing source and tag records are not changed.')
                        ->multiple()
                        ->searchable()
                        ->optionsLimit(50)
                        ->columnSpanFull(),
                ])
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('title')->label('Starting title')->placeholder('Uses source title')->wrap(),
                TextColumn::make('tags.name')->label('Default tags')->badge()->limitList(4),
                IconColumn::make('is_active')->label('Available')->boolean()->sortable(),
                TextColumn::make('updated_at')->label('Updated')->since()->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->modalDescription('Deletes this template only. Existing Journal drafts and connected sources are not changed.'),
            ])
            ->toolbarActions([
                DeleteBulkAction::make()
                    ->modalDescription('Deletes only the selected templates. Existing Journal drafts and connected sources are not changed.'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPostTemplates::route('/'),
            'create' => CreatePostTemplate::route('/create'),
            'edit' => EditPostTemplate::route('/{record}/edit'),
        ];
    }
}
