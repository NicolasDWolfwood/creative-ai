<?php

namespace App\Filament\Resources\Collections;

use App\Filament\Resources\Collections\Pages\ManageCollections;
use App\Models\Collection;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

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
            Textarea::make('description')->rows(4)->columnSpanFull(),
            TextInput::make('sort_order')->numeric()->default(0),
            Toggle::make('featured'),
            Toggle::make('published')->default(true),
            DateTimePicker::make('published_at'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                ImageColumn::make('hero_image_path')->disk('public')->square()->label('Hero'),
                TextColumn::make('title')->searchable()->sortable(),
                TextColumn::make('slug')->searchable(),
                TextColumn::make('sort_order')->sortable(),
                IconColumn::make('featured')->boolean(),
                IconColumn::make('published')->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
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
