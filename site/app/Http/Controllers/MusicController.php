<?php

namespace App\Http\Controllers;

use App\Models\Album;
use App\Models\Track;
use App\Services\CrossMediaRecommendationService;
use App\Services\PublicMediaService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MusicController extends Controller
{
    public function index(Request $request, PublicMediaService $media)
    {
        $search = Str::of((string) $request->query('q'))->squish()->limit(100, '')->toString();
        $tracks = Track::query()->published()->with(['album', 'coverArtwork', 'tags'])
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->whereRaw('LOWER(title) LIKE ?', ['%'.strtolower($search).'%'])
                ->orWhereRaw('LOWER(artist) LIKE ?', ['%'.strtolower($search).'%'])))
            ->when($request->filled('artist'), fn ($q) => $q->where('artist', $request->artist))
            ->when($request->filled('tag'), fn ($q) => $q->whereHas('tags', fn ($q) => $q->where('slug', $request->tag)))
            ->latest()->paginate(24)->withQueryString();
        $libraryAlbums = $media->albums();
        $albums = $search === '' ? $libraryAlbums : $libraryAlbums
            ->filter(fn ($album): bool => Str::contains(
                Str::lower(implode(' ', [$album->title, $album->artist, $album->album_artist])),
                Str::lower($search),
            ))
            ->values();
        $playlists = $media->playlists();

        return view('music.index', compact('tracks', 'albums', 'search') + ['playerPayload' => $media->playerPayload($playlists, $libraryAlbums)]);
    }

    public function album(Album $album, PublicMediaService $media)
    {
        abort_unless($album->isPubliclyPublished(), 404);
        $album->load([
            'tracks' => fn ($query) => $query->published()->with(['tags', 'coverArtwork']),
            'coverArtwork',
        ]);

        return view('music.album', compact('album') + ['playerPayload' => $media->libraryPlayerPayload()]);
    }

    public function track(Track $track, PublicMediaService $media, CrossMediaRecommendationService $recommendations)
    {
        abort_unless($track->isPubliclyPublished(), 404);
        $track->load(['album', 'tags', 'coverArtwork']);
        $playlist = ['id' => 'track-'.$track->id, 'type' => 'track', 'title' => $track->title, 'tracks' => [app(PublicMediaService::class)->trackPayload($track)]];

        return view('music.track', [
            'track' => $track,
            'artworks' => $recommendations->artworksForTrack($track),
            'playerPayload' => [...$media->libraryPlayerPayload(), $playlist],
        ]);
    }
}
