<?php

namespace App\Observers;

use App\Models\Playlist;
use App\Services\SmartPlaylistService;

class PlaylistObserver
{
    public function saved(Playlist $playlist): void
    {
        if ($playlist->is_smart && $playlist->auto_sync) {
            app(SmartPlaylistService::class)->sync($playlist);
        }
    }
}
