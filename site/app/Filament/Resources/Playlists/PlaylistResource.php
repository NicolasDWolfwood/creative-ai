<?php

namespace App\Filament\Resources\Playlists;

use App\Filament\Resources\Playlists\Pages\ManagePlaylists;
use App\Models\Playlist;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PlaylistResource extends Resource
{
    protected static ?string $model = Playlist::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-queue-list';

    protected static string|\UnitEnum|null $navigationGroup = 'Music';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->required()->maxLength(255),
            TextInput::make('slug')->maxLength(255)->helperText('Leave empty to generate from the title.'),
            Select::make('cover_artwork_id')
                ->relationship('coverArtwork', 'title')
                ->searchable()
                ->preload(),
            Select::make('tracks')
                ->relationship('tracks', 'title')
                ->multiple()
                ->searchable()
                ->preload()
                ->helperText('Use Playlist entries to fine-tune ordering.'),
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
                TextColumn::make('title')->searchable()->sortable(),
                TextColumn::make('tracks_count')->counts('tracks')->label('Tracks')->sortable(),
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
            'index' => ManagePlaylists::route('/'),
        ];
    }
}
