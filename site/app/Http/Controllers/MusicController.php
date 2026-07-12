<?php

namespace App\Http\Controllers;

use App\Models\Album;
use App\Models\Track;
use App\Services\CrossMediaRecommendationService;
use App\Services\PublicMediaService;
use Illuminate\Http\Request;

class MusicController extends Controller
{
    public function index(Request $request, PublicMediaService $media)
    {
        $tracks = Track::query()->published()->with(['album', 'coverArtwork', 'tags'])
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($q) => $q->where('title', 'like', '%'.$request->q.'%')->orWhere('artist', 'like', '%'.$request->q.'%')))
            ->when($request->filled('artist'), fn ($q) => $q->where('artist', $request->artist))
            ->when($request->filled('tag'), fn ($q) => $q->whereHas('tags', fn ($q) => $q->where('slug', $request->tag)))
            ->latest()->paginate(24)->withQueryString();
        $albums = $media->albums();
        $playlists = $media->playlists();

        return view('music.index', compact('tracks', 'albums') + ['playerPayload' => $media->playerPayload($playlists, $albums)]);
    }

    public function album(Album $album, PublicMediaService $media)
    {
        abort_unless($album->published, 404);
        $album->load([
            'tracks' => fn ($query) => $query->published()->with(['tags', 'coverArtwork']),
            'coverArtwork',
        ]);

        return view('music.album', compact('album') + ['playerPayload' => $media->libraryPlayerPayload()]);
    }

    public function track(Track $track, PublicMediaService $media, CrossMediaRecommendationService $recommendations)
    {
        abort_unless($track->published, 404);
        $track->load(['album', 'tags', 'coverArtwork']);
        $playlist = ['id' => 'track-'.$track->id, 'type' => 'track', 'title' => $track->title, 'tracks' => [app(PublicMediaService::class)->trackPayload($track)]];

        return view('music.track', [
            'track' => $track,
            'artworks' => $recommendations->artworksForTrack($track),
            'playerPayload' => [...$media->libraryPlayerPayload(), $playlist],
        ]);
    }
}
