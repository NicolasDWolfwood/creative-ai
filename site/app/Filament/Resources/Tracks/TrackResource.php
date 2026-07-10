<?php

namespace App\Filament\Resources\Tracks;

use App\Filament\Resources\Tracks\Pages\ManageTracks;
use App\Models\Track;
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
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TrackResource extends Resource
{
    protected static ?string $model = Track::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-musical-note';

    protected static string|\UnitEnum|null $navigationGroup = 'Music';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->required()->maxLength(255),
            TextInput::make('artist')->maxLength(255),
            TextInput::make('slug')->maxLength(255)->helperText('Leave empty to generate from artist and title.'),
            Select::make('cover_artwork_id')
                ->relationship('coverArtwork', 'title')
                ->searchable()
                ->preload(),
            FileUpload::make('audio_path')
                ->label('Audio')
                ->disk('public')
                ->directory('tracks/audio')
                ->visibility('public')
                ->acceptedFileTypes(['audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/ogg', 'audio/mp4', 'audio/x-m4a'])
                ->maxSize(102400)
                ->storeFileNamesIn('original_filename')
                ->downloadable()
                ->required(),
            Textarea::make('description')->rows(3)->columnSpanFull(),
            TextInput::make('duration_seconds')->numeric(),
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
                TextColumn::make('artist')->searchable()->sortable(),
                TextColumn::make('duration_seconds')->label('Duration')->sortable(),
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
            'index' => ManageTracks::route('/'),
        ];
    }
}
