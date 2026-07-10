<?php

namespace App\Filament\Resources\PlaylistEntries;

use App\Filament\Resources\PlaylistEntries\Pages\ManagePlaylistTracks;
use App\Models\PlaylistTrack;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PlaylistTrackResource extends Resource
{
    protected static ?string $model = PlaylistTrack::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-bars-arrow-down';

    protected static string|\UnitEnum|null $navigationGroup = 'Music';

    protected static ?int $navigationSort = 30;

    protected static ?string $modelLabel = 'playlist entry';

    protected static ?string $pluralModelLabel = 'playlist entries';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('playlist_id')
                ->relationship('playlist', 'title')
                ->required()
                ->searchable()
                ->preload(),
            Select::make('track_id')
                ->relationship('track', 'title')
                ->required()
                ->searchable()
                ->preload(),
            TextInput::make('position')->numeric()->default(0)->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('position')
            ->columns([
                TextColumn::make('playlist.title')->searchable()->sortable(),
                TextColumn::make('track.title')->searchable()->sortable(),
                TextColumn::make('position')->sortable(),
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
            'index' => ManagePlaylistTracks::route('/'),
        ];
    }
}
