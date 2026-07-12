<?php

namespace App\Observers;

use App\Models\Album;
use App\Services\AlbumPublishingService;

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
        if ($album->published) {
            app(AlbumPublishingService::class)->publishTracks($album);
        }
    }
}
