<?php

namespace App\Jobs;

use App\Models\Track;
use App\Services\AudioTechnicalAnalysisService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class AnalyzeTrackAudio implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $trackId) {}

    public function handle(AudioTechnicalAnalysisService $service): void
    {
        if ($track = Track::find($this->trackId)) {
            $service->analyze($track);
        }
    }
}
