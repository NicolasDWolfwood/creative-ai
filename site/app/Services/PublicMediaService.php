<?php

namespace App\Services;

use App\Models\Album;
use App\Models\Playlist;
use App\Models\Track;
use Illuminate\Support\Collection;

class PublicMediaService
{
    /** @return Collection<int, Playlist> */
    public function playlists(): Collection
    {
        return Playlist::query()
            ->published()
            ->whereHas('tracks', fn ($query) => $query->publiclyAvailable())
            ->with([
                'coverArtwork',
                'tracks' => fn ($query) => $query->publiclyAvailable()->with(['coverArtwork', 'album.coverArtwork']),
            ])
            ->orderByDesc('featured')
            ->orderBy('sort_order')
            ->get();
    }

    /** @return Collection<int, Album> */
    public function albums(): Collection
    {
        return Album::query()
            ->published()
            ->whereHas('tracks', fn ($query) => $query->publiclyAvailable())
            ->with(['coverArtwork', 'tracks' => fn ($query) => $query->publiclyAvailable()->with(['coverArtwork', 'album.coverArtwork'])])
            ->orderByDesc('featured')->orderBy('sort_order')->orderByDesc('release_year')->get();
    }

    /** @return Collection<int, Track> */
    public function standaloneTracks(): Collection
    {
        return Track::query()
            ->published()
            ->with(['coverArtwork', 'album.coverArtwork'])
            ->orderByDesc('featured')
            ->orderBy('sort_order')
            ->orderByDesc('standalone_published_at')
            ->orderBy('title')
            ->get();
    }

    public function libraryPlayerPayload(): array
    {
        return $this->playerPayload($this->playlists(), $this->albums(), $this->standaloneTracks());
    }

    /** @param Collection<int, Playlist> $playlists */
    public function playerPayload(Collection $playlists, ?Collection $albums = null, ?Collection $standaloneTracks = null): array
    {
        $playlistPayload = $playlists
            ->map(fn (Playlist $playlist) => [
                'id' => 'playlist-'.$playlist->id,
                'type' => 'playlist',
                'title' => $playlist->title,
                'description' => $playlist->description,
                'cover' => $playlist->cover_url,
                'tracks' => $playlist->tracks->map(fn ($track) => [
                    ...$this->trackPayload($track), 'cover' => $track->cover_url ?: $playlist->cover_url,
                ])->values(),
            ])
            ->filter(fn (array $playlist) => count($playlist['tracks']) > 0)
            ->values();

        $albumPayload = ($albums ?? collect())->map(fn (Album $album) => [
            'id' => 'album-'.$album->id,
            'type' => 'album',
            'title' => $album->title,
            'description' => $album->description,
            'cover' => $album->cover_url,
            'tracks' => $album->tracks->map(fn (Track $track) => [...$this->trackPayload($track), 'cover' => $track->cover_url ?: $album->cover_url])->values(),
        ])->filter(fn (array $album) => count($album['tracks']) > 0)->values();

        $standalonePayload = collect();

        if ($standaloneTracks?->isNotEmpty()) {
            $standalonePayload->push([
                'id' => 'standalone-tracks',
                'type' => 'track',
                'title' => 'Singles & standalone tracks',
                'description' => 'Tracks released outside the album shelf.',
                'cover' => null,
                'tracks' => $standaloneTracks->map(fn (Track $track) => $this->trackPayload($track))->values(),
            ]);
        }

        return $albumPayload->concat($playlistPayload)->concat($standalonePayload)->values()->all();
    }

    public function trackPayload(Track $track): array
    {
        return ['id' => $track->id, 'title' => $track->title, 'artist' => $track->artist, 'url' => $track->audio_url, 'cover' => $track->cover_url, 'waveform' => $track->waveform ?? [], 'href' => route('music.tracks.show', $track)];
    }
}
