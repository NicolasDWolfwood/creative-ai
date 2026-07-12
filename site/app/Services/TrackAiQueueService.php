<?php

namespace App\Services;

use App\Jobs\AnalyzeTrackMetadata;
use App\Models\Track;

class TrackAiQueueService
{
    public function queue(Track $track, bool $applyImmediately = false): void
    {
        AnalyzeTrackMetadata::dispatchFor($track, $applyImmediately);
    }

    /** @param array<int, string> $statuses */
    public function queuePending(array $statuses, int $limit = 0, bool $applyImmediately = false): int
    {
        $query = Track::query()->whereIn('ai_status', $statuses)->orderBy('id');
        if ($limit > 0) {
            $query->limit($limit);
        }
        $tracks = $query->get();
        $tracks->each(fn (Track $track) => $this->queue($track, $applyImmediately));

        return $tracks->count();
    }
}
