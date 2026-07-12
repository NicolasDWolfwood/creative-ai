<?php

namespace App\Observers;

use App\Jobs\AnalyzeTrackAudio;
use App\Models\Track;
use App\Services\AudioMetadataService;
use App\Services\SmartPlaylistService;
use App\Services\TrackPublicationService;

class TrackObserver
{
    public function saving(Track $track): void
    {
        if ($track->isDirty('audio_path')) {
            app(AudioMetadataService::class)->apply($track);
        }

        app(TrackPublicationService::class)->prepareForSave($track);
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
        $track->markTechnicalAnalysisPending();
        AnalyzeTrackAudio::dispatch($track->id)->afterCommit();
    }
}
