<?php

namespace App\Services;

use App\Models\Album;
use App\Models\Track;
use Illuminate\Support\Facades\DB;

class AlbumPublishingService
{
    public function publish(Album $album): int
    {
        $count = DB::transaction(function () use ($album): int {
            if (! $album->published || ! $album->published_at) {
                $album->forceFill([
                    'published' => true,
                    'published_at' => $album->published_at ?: now(),
                ])->saveQuietly();
            }

            return $this->publishTracks($album, syncPlaylists: false);
        });

        app(SmartPlaylistService::class)->syncAutomatic();

        return $count;
    }

    public function publishTracks(Album $album, bool $syncPlaylists = true): int
    {
        $count = Track::query()
            ->where('album_id', $album->id)
            ->where('published', false)
            ->update(['published' => true, 'published_at' => $album->published_at ?: now()]);

        if ($count > 0 && $syncPlaylists) {
            app(SmartPlaylistService::class)->syncAutomatic();
        }

        return $count;
    }
}
