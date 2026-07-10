<?php

namespace App\Services;

use App\Models\Playlist;
use Illuminate\Database\Eloquent\Collection;

class PublicMediaService
{
    /** @return Collection<int, Playlist> */
    public function playlists(): Collection
    {
        return Playlist::query()
            ->published()
            ->with([
                'coverArtwork',
                'tracks' => fn ($query) => $query->published()->with('coverArtwork'),
            ])
            ->orderByDesc('featured')
            ->orderBy('sort_order')
            ->get();
    }

    /** @param Collection<int, Playlist> $playlists */
    public function playerPayload(Collection $playlists): array
    {
        return $playlists
            ->map(fn (Playlist $playlist) => [
                'id' => $playlist->id,
                'title' => $playlist->title,
                'description' => $playlist->description,
                'cover' => $playlist->cover_url,
                'tracks' => $playlist->tracks->map(fn ($track) => [
                    'id' => $track->id,
                    'title' => $track->title,
                    'artist' => $track->artist,
                    'url' => $track->audio_url,
                    'cover' => $track->cover_url ?: $playlist->cover_url,
                ])->values(),
            ])
            ->filter(fn (array $playlist) => count($playlist['tracks']) > 0)
            ->values()
            ->all();
    }
}
