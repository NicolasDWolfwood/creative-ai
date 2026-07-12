<?php

namespace App\Filament\Resources\Playlists\Pages;

use App\Filament\Resources\Playlists\PlaylistResource;
use App\Models\Playlist;
use App\Services\AutomaticPlaylistService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Throwable;

class ManagePlaylists extends ManageRecords
{
    protected static string $resource = PlaylistResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generateAutomaticPlaylists')
                ->label(fn (): string => Playlist::query()->where('is_auto_generated', true)->exists() ? 'Refresh automatic' : 'Generate automatic')
                ->icon('heroicon-o-bolt')
                ->color('info')
                ->schema([
                    Select::make('target_count')
                        ->label('Automatic playlists')
                        ->options(collect(range(1, AutomaticPlaylistService::MAX_AUTOMATIC_PLAYLISTS))->mapWithKeys(fn (int $count): array => [$count => $count.' playlist'.($count === 1 ? '' : 's')])->all())
                        ->default(AutomaticPlaylistService::DEFAULT_TARGET)
                        ->required()
                        ->native(false),
                    TextInput::make('minimum_tracks')
                        ->label('Minimum matching publicly playable tracks')
                        ->numeric()->minValue(1)->maxValue(500)
                        ->default(AutomaticPlaylistService::DEFAULT_MINIMUM_TRACKS)
                        ->required(),
                    Toggle::make('published')->label('Publish generated playlists')->default(true),
                ])
                ->requiresConfirmation()
                ->modalDescription('Builds a managed set from recurring genres and moods on publicly playable tracks. Manual and custom smart playlists are never changed.')
                ->action(function (array $data): void {
                    $result = app(AutomaticPlaylistService::class)->maintain(
                        target: (int) $data['target_count'],
                        minimumTracks: (int) $data['minimum_tracks'],
                        published: (bool) $data['published'],
                    );
                    $summary = collect($result['playlists'])->map(fn (array $playlist): string => $playlist['title'].' ('.$playlist['count'].')')->implode(', ');
                    Notification::make()->success()
                        ->title($result['playlist_count'].' automatic playlist'.($result['playlist_count'] === 1 ? '' : 's').' ready')
                        ->body($summary ?: 'No music theme met the minimum track threshold.')
                        ->send();
                }),
            Action::make('createPlaylistWithAi')
                ->label('Create with AI')
                ->icon('heroicon-o-sparkles')
                ->color('success')
                ->schema([
                    Textarea::make('guidance')->label('Mood, genre, or listening direction')
                        ->helperText('Optional. For example: calm focus, cinematic tension, or energetic electronic music.')
                        ->rows(3)->maxLength(500),
                    TextInput::make('minimum_tracks')->label('Minimum matching publicly playable tracks')
                        ->numeric()->minValue(1)->maxValue(500)
                        ->default(AutomaticPlaylistService::DEFAULT_MINIMUM_TRACKS)->required(),
                    Toggle::make('published')->label('Publish immediately')->default(true),
                ])
                ->requiresConfirmation()
                ->modalDescription('Your configured AI provider will choose existing track tags, create a smart playlist, and keep it synchronized.')
                ->action(function (array $data): void {
                    try {
                        $result = app(AutomaticPlaylistService::class)->createWithAi(
                            guidance: $data['guidance'] ?? null,
                            minimumTracks: (int) $data['minimum_tracks'],
                            published: (bool) $data['published'],
                        );
                    } catch (Throwable $exception) {
                        Notification::make()->danger()->title('AI playlist could not be created')
                            ->body(str($exception->getMessage())->squish()->limit(300, '')->toString())->send();

                        return;
                    }

                    Notification::make()->success()->title($result['playlist']->title.' created')
                        ->body($result['explanation'].' '.$result['count'].' tracks matched.')->send();
                }),
            CreateAction::make()->label('New playlist'),
        ];
    }
}
