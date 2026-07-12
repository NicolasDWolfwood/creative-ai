<?php

namespace App\Observers;

use App\Models\Album;
use App\Services\SmartPlaylistService;
use App\Services\TrackPublicationService;

class AlbumObserver
{
    public function saving(Album $album): void
    {
        if ($album->published && ! $album->published_at) {
            $album->published_at = now();
        }
    }

    public function saved(Album $album): void
    {
        if ($album->wasChanged(['published', 'published_at'])) {
            app(TrackPublicationService::class)->syncAlbum($album);
            app(SmartPlaylistService::class)->syncAutomatic();
        }
    }

    public function deleting(Album $album): void
    {
        app(TrackPublicationService::class)->syncAlbumRemoval($album);
    }

    public function deleted(Album $album): void
    {
        app(SmartPlaylistService::class)->syncAutomatic();
    }
}
