<?php

namespace App\Filament\Resources\Posts;

use App\Filament\Resources\Posts\Pages\ManagePosts;
use App\Models\Post;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class PostResource extends Resource
{
    protected static ?string $model = Post::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-newspaper';

    protected static string|\UnitEnum|null $navigationGroup = 'Publishing';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Journal posts';

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Story')
                ->columns(2)
                ->schema([
                    TextInput::make('title')->required()->maxLength(255)->columnSpanFull(),
                    TextInput::make('slug')->maxLength(255)->helperText('Leave empty to generate from the title.'),
                    FileUpload::make('cover_image_path')
                        ->label('Cover image')
                        ->disk('local')
                        ->directory('posts/covers')
                        ->visibility('private')
                        ->image()
                        ->imageEditor()
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                        ->maxSize(25600),
                    Textarea::make('excerpt')->rows(3)->maxLength(500)->columnSpanFull(),
                    MarkdownEditor::make('body')
                        ->label('Post content')
                        ->required()
                        ->columnSpanFull(),
                ])
                ->columnSpanFull(),
            Section::make('Publishing')
                ->columns(3)
                ->schema([
                    Toggle::make('published')->default(false),
                    Toggle::make('featured')->default(false),
                    DateTimePicker::make('published_at')->label('Publish date'),
                ])
                ->columnSpanFull(),
            Section::make('Search and sharing')
                ->description('Optional overrides. Empty fields use the post title and excerpt.')
                ->columns(2)
                ->schema([
                    TextInput::make('seo_title')->maxLength(70),
                    Textarea::make('seo_description')->rows(2)->maxLength(320),
                ])
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('published_at', 'desc')
            ->columns([
                ImageColumn::make('cover_image_path')->getStateUsing(fn (Post $record): ?string => $record->cover_url)->square()->label('Cover'),
                TextColumn::make('title')->searchable()->sortable()->description(fn (Post $record): string => $record->summary)->wrap(),
                TextColumn::make('published_at')->date('M j, Y')->sortable()->label('Published'),
                IconColumn::make('featured')->boolean(),
                IconColumn::make('published')->boolean()->label('Live'),
            ])
            ->filters([
                TernaryFilter::make('published'),
                TernaryFilter::make('featured'),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    DeleteAction::make(),
                ])->icon('heroicon-m-ellipsis-horizontal')->tooltip('Post actions'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManagePosts::route('/')];
    }
}
