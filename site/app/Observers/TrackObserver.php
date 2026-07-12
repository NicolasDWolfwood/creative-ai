<?php

namespace App\Observers;

use App\Jobs\AnalyzeTrackAudio;
use App\Models\Album;
use App\Models\Track;
use App\Services\AudioMetadataService;
use App\Services\SmartPlaylistService;

class TrackObserver
{
    public function saving(Track $track): void
    {
        if ($track->isDirty('audio_path')) {
            app(AudioMetadataService::class)->apply($track);
        }

        if ($track->album_id && ! $track->published) {
            $album = Album::query()->select(['id', 'published', 'published_at'])->find($track->album_id);
            if ($album?->published) {
                $track->published = true;
                $track->published_at ??= $album->published_at ?: now();
            }
        }
    }

    public function saved(Track $track): void
    {
        if ($track->wasChanged('audio_path')) {
            app(AudioMetadataService::class)->syncGenres($track);
        }
        app(SmartPlaylistService::class)->syncAutomatic();
    }

    public function created(Track $track): void
    {
        if (filled($track->audio_path)) {
            $this->queueTechnicalAnalysis($track);
        }
    }

    public function updated(Track $track): void
    {
        if ($track->wasChanged('audio_path')) {
            $this->queueTechnicalAnalysis($track);
        }
    }

    protected function queueTechnicalAnalysis(Track $track): void
    {
        $track->forceFill(['analysis_status' => 'pending', 'health_status' => 'unknown'])->saveQuietly();
        AnalyzeTrackAudio::dispatch($track->id)->afterCommit();
    }
}
