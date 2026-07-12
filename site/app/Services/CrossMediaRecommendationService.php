<?php

namespace App\Services;

use App\Models\Artwork;
use App\Models\Track;
use Illuminate\Support\Collection;

class CrossMediaRecommendationService
{
    public function artworksForTrack(Track $track, int $limit = 8): Collection
    {
        $ids = $track->tags()->pluck('tags.id');

        return Artwork::query()->published()->with('tags')->withCount(['tags as match_count' => fn ($q) => $q->whereIn('tags.id', $ids)])
            ->when($ids->isNotEmpty(), fn ($q) => $q->whereHas('tags', fn ($q) => $q->whereIn('tags.id', $ids)))
            ->orderByDesc('match_count')->orderByDesc('featured')->limit($limit)->get();
    }

    public function tracksForArtwork(Artwork $artwork, int $limit = 8): Collection
    {
        $ids = $artwork->tags()->pluck('tags.id');

        return Track::query()->publiclyAvailable()->with(['album', 'tags'])->when($ids->isNotEmpty(), fn ($q) => $q->whereHas('tags', fn ($q) => $q->whereIn('tags.id', $ids)))
            ->withCount(['tags as match_count' => fn ($q) => $q->whereIn('tags.id', $ids)])->orderByDesc('match_count')->limit($limit)->get();
    }
}
