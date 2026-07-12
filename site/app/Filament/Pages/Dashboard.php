<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Artworks\ArtworkResource;
use App\Filament\Resources\Collections\CollectionResource;
use App\Filament\Resources\Playlists\PlaylistResource;
use App\Filament\Resources\Tracks\TrackResource;
use App\Models\Artwork;
use App\Models\Collection;
use App\Models\Playlist;
use App\Models\Track;
use App\Services\StoryOpportunityService;
use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class Dashboard extends BaseDashboard
{
    protected string $view = 'filament.pages.dashboard';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $navigationLabel = 'Studio';

    protected static ?int $navigationSort = -100;

    protected static string $routePath = '/';

    public function getTitle(): string|Htmlable
    {
        return 'Studio';
    }

    /**
     * @return array<int, array{label: string, value: int, href: string, accent: string}>
     */
    public function getStats(): array
    {
        return [
            [
                'label' => 'Published artworks',
                'value' => Artwork::query()->published()->count(),
                'href' => ArtworkResource::getUrl(),
                'accent' => 'teal',
            ],
            [
                'label' => 'Music tracks',
                'value' => Track::query()->count(),
                'href' => TrackResource::getUrl(),
                'accent' => 'amber',
            ],
            [
                'label' => 'Playlists',
                'value' => Playlist::query()->count(),
                'href' => PlaylistResource::getUrl(),
                'accent' => 'rose',
            ],
            [
                'label' => 'Collections',
                'value' => Collection::query()->count(),
                'href' => CollectionResource::getUrl(),
                'accent' => 'steel',
            ],
            [
                'label' => 'Story opportunities',
                'value' => app(StoryOpportunityService::class)->count(),
                'href' => StoryOpportunities::getUrl(),
                'accent' => 'teal',
            ],
        ];
    }

    /**
     * @return EloquentCollection<int, Artwork>
     */
    public function getFeaturedArtworks(): EloquentCollection
    {
        return Artwork::query()
            ->published()
            ->latest()
            ->limit(8)
            ->get();
    }

    /**
     * @return EloquentCollection<int, Track>
     */
    public function getRecentTracks(): EloquentCollection
    {
        return Track::query()
            ->published()
            ->latest()
            ->limit(6)
            ->get();
    }
}
