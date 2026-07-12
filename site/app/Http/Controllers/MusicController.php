<?php

namespace App\Http\Controllers;

use App\Models\Album;
use App\Models\Playlist;
use App\Models\Track;
use App\Services\CrossMediaRecommendationService;
use App\Services\MusicStructuredData;
use App\Services\PublicMediaService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MusicController extends Controller
{
    public function index(Request $request, PublicMediaService $media): View
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
            ->filter(function (Album $album) use ($search): bool {
                $albumTerms = collect([$album->title, $album->artist, $album->album_artist])
                    ->merge($album->tracks->flatMap(fn (Track $track): array => [$track->title, $track->artist]))
                    ->filter()
                    ->implode(' ');

                return Str::contains(Str::lower($albumTerms), Str::lower($search));
            })
            ->values();
        $libraryPlaylists = $media->playlists();
        $playlists = $search === '' ? $libraryPlaylists : $libraryPlaylists
            ->filter(function (Playlist $playlist) use ($search): bool {
                $playlistTerms = collect([$playlist->title, $playlist->description])
                    ->merge($playlist->tracks->flatMap(fn (Track $track): array => [$track->title, $track->artist]))
                    ->filter()
                    ->implode(' ');

                return Str::contains(Str::lower($playlistTerms), Str::lower($search));
            })
            ->values();
        $standaloneTracks = $media->standaloneTracks();
        $musicDescription = 'Explore original albums, playlists, and standalone tracks from the Creative-Ai studio.';

        return view('music.index', [
            'tracks' => $tracks,
            'albums' => $albums,
            'playlists' => $playlists,
            'search' => $search,
            'playerPayload' => $media->playerPayload($libraryPlaylists, $libraryAlbums, $standaloneTracks),
            'seo' => [
                'title' => 'Music | Creative-Ai',
                'description' => $musicDescription,
                'canonical' => route('music.index'),
                'type' => 'website',
            ],
            'structured_data' => [
                '@context' => 'https://schema.org',
                '@type' => 'CollectionPage',
                '@id' => route('music.index').'#collection',
                'name' => 'Music | Creative-Ai',
                'description' => $musicDescription,
                'url' => route('music.index'),
                'creator' => ['@type' => 'Person', 'name' => 'John Reijmer'],
            ],
        ]);
    }

    public function album(
        Album $album,
        PublicMediaService $media,
        MusicStructuredData $structuredData,
    ): View {
        abort_unless($album->isPubliclyPublished(), 404);
        $album->load([
            'tracks' => fn ($query) => $query->publiclyAvailable()->with(['tags', 'coverArtwork']),
            'coverArtwork',
        ]);

        $canonical = route('music.albums.show', $album);

        return view('music.album', [
            'album' => $album,
            'playerPayload' => $media->libraryPlayerPayload(),
            'seo' => [
                'title' => $album->title.' | Creative-Ai',
                'description' => $this->description(
                    $album->description,
                    'Listen to '.$album->title.' in the Creative-Ai music archive.',
                ),
                'image' => $album->cover_url ? url($album->cover_url) : null,
                'canonical' => $canonical,
                'type' => 'music.album',
                'music_release_date' => $album->published_at?->toIso8601String(),
                'music_songs' => $album->tracks
                    ->map(fn (Track $track): string => route('music.tracks.show', $track))
                    ->values()
                    ->all(),
            ],
            'structured_data' => $structuredData->forAlbum($album),
        ]);
    }

    public function playlist(
        Playlist $playlist,
        PublicMediaService $media,
        MusicStructuredData $structuredData,
    ): View {
        abort_unless($playlist->isPubliclyPublished(), 404);
        $playlist->load([
            'coverArtwork',
            'tracks' => fn ($query) => $query
                ->publiclyAvailable()
                ->with(['tags', 'coverArtwork', 'album.coverArtwork']),
        ]);

        $canonical = route('music.playlists.show', $playlist);

        return view('music.playlist', [
            'playlist' => $playlist,
            'playerPayload' => $media->libraryPlayerPayload(),
            'seo' => [
                'title' => $playlist->title.' | Creative-Ai',
                'description' => $this->description(
                    $playlist->description,
                    'Listen to the '.$playlist->title.' playlist in the Creative-Ai music archive.',
                ),
                'image' => $playlist->cover_url ? url($playlist->cover_url) : null,
                'canonical' => $canonical,
                'type' => 'music.playlist',
                'music_songs' => $playlist->tracks
                    ->map(fn (Track $track): string => route('music.tracks.show', $track))
                    ->values()
                    ->all(),
            ],
            'structured_data' => $structuredData->forPlaylist($playlist),
        ]);
    }

    public function track(
        Track $track,
        PublicMediaService $media,
        CrossMediaRecommendationService $recommendations,
        MusicStructuredData $structuredData,
    ): View {
        abort_unless($track->isPubliclyAvailable(), 404);
        $track->load([
            'album',
            'tags',
            'coverArtwork',
            'playlists' => fn ($query) => $query
                ->published()
                ->with('coverArtwork'),
        ]);
        $playlist = ['id' => 'track-'.$track->id, 'type' => 'track', 'title' => $track->title, 'tracks' => [$media->trackPayload($track)]];
        $canonical = route('music.tracks.show', $track);

        return view('music.track', [
            'track' => $track,
            'artworks' => $recommendations->artworksForTrack($track),
            'playlists' => $track->playlists,
            'playerPayload' => [...$media->libraryPlayerPayload(), $playlist],
            'seo' => [
                'title' => $track->title.' | Creative-Ai',
                'description' => $this->description(
                    $track->description,
                    'Listen to '.$track->title.' in the Creative-Ai music archive.',
                ),
                'image' => $track->cover_url ? url($track->cover_url) : null,
                'canonical' => $canonical,
                'type' => 'music.song',
                'audio' => $track->audio_url,
                'music_duration' => $track->duration_seconds,
                'music_album' => $track->album?->isPubliclyPublished()
                    ? route('music.albums.show', $track->album)
                    : null,
                'music_album_disc' => $track->disc_number,
                'music_album_track' => $track->track_number,
            ],
            'structured_data' => $structuredData->forTrack($track),
        ]);
    }

    private function description(?string $description, string $fallback): string
    {
        return Str::of($description ?: $fallback)
            ->stripTags()
            ->squish()
            ->limit(200, '')
            ->toString();
    }
}
