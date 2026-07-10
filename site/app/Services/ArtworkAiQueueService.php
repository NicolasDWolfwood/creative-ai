<?php

namespace App\Services;

use App\Jobs\AnalyzeArtworkWithAi;
use App\Models\Artwork;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class ArtworkAiQueueService
{
    public const DEFAULT_QUEUE = 'ai';

    public const HIGH_PRIORITY_QUEUE = 'ai-high';

    public function queue(Artwork $artwork, bool $priority = false): void
    {
        AnalyzeArtworkWithAi::dispatchFor(
            $artwork,
            force: true,
            queue: $priority ? self::HIGH_PRIORITY_QUEUE : self::DEFAULT_QUEUE,
        );
    }

    public function prioritize(Artwork $artwork): void
    {
        if ($artwork->ai_status !== Artwork::AI_STATUS_QUEUED) {
            return;
        }

        $this->queue($artwork, priority: true);
    }

    public function retry(Artwork $artwork): void
    {
        if ($artwork->ai_status !== Artwork::AI_STATUS_FAILED) {
            return;
        }

        $this->queue($artwork);
    }

    public function cancelQueued(Artwork $artwork): bool
    {
        if ($artwork->ai_status !== Artwork::AI_STATUS_QUEUED) {
            return false;
        }

        $artwork->forceFill([
            'ai_status' => Artwork::AI_STATUS_IDLE,
            'ai_queue_token' => null,
            'ai_error' => null,
            'ai_queued_at' => null,
            'ai_started_at' => null,
        ])->saveQuietly();

        return true;
    }

    public function cancelQueuedRecords(EloquentCollection $records): int
    {
        return $records
            ->filter(fn (Artwork $artwork): bool => $this->cancelQueued($artwork))
            ->count();
    }

    public function clearQueued(): int
    {
        return Artwork::query()
            ->where('ai_status', Artwork::AI_STATUS_QUEUED)
            ->update([
                'ai_status' => Artwork::AI_STATUS_IDLE,
                'ai_queue_token' => null,
                'ai_error' => null,
                'ai_queued_at' => null,
                'ai_started_at' => null,
                'updated_at' => now(),
            ]);
    }

    public function retryFailed(): int
    {
        $records = Artwork::query()
            ->where('ai_status', Artwork::AI_STATUS_FAILED)
            ->get();

        foreach ($records as $artwork) {
            $this->queue($artwork);
        }

        return $records->count();
    }
}
