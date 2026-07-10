<?php

namespace App\Services;

use App\Jobs\AnalyzeArtworkWithAi;
use App\Models\Artwork;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class ArtworkAiQueueService
{
    public const DEFAULT_QUEUE = 'ai';

    public const HIGH_PRIORITY_QUEUE = 'ai-high';

    public function queue(Artwork $artwork, bool $priority = false, bool $applyAfterAnalysis = false): void
    {
        AnalyzeArtworkWithAi::dispatchFor(
            $artwork,
            force: true,
            applyAfterAnalysis: $applyAfterAnalysis,
            queue: $priority ? self::HIGH_PRIORITY_QUEUE : self::DEFAULT_QUEUE,
        );
    }

    public function prioritize(Artwork $artwork): void
    {
        if ($artwork->ai_status !== Artwork::AI_STATUS_QUEUED) {
            return;
        }

        $this->queue(
            $artwork,
            priority: true,
            applyAfterAnalysis: (bool) $artwork->ai_apply_after_analysis,
        );
    }

    public function retry(Artwork $artwork): void
    {
        if ($artwork->ai_status !== Artwork::AI_STATUS_FAILED) {
            return;
        }

        $this->queue($artwork, applyAfterAnalysis: (bool) $artwork->ai_apply_after_analysis);
    }

    public function cancelQueued(Artwork $artwork): bool
    {
        if ($artwork->ai_status !== Artwork::AI_STATUS_QUEUED) {
            return false;
        }

        $artwork->forceFill([
            'ai_status' => Artwork::AI_STATUS_IDLE,
            'ai_queue_token' => null,
            'ai_apply_after_analysis' => false,
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
                'ai_apply_after_analysis' => false,
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
            $this->retry($artwork);
        }

        return $records->count();
    }

    /**
     * Queue records that have never completed analysis. Existing queued and
     * processing records are deliberately excluded to prevent duplicate work.
     *
     * @param  array<int, string>  $statuses
     */
    public function queuePending(
        array $statuses = [Artwork::AI_STATUS_IDLE, Artwork::AI_STATUS_FAILED],
        int $limit = 0,
        bool $applyAfterAnalysis = false,
    ): int {
        $query = Artwork::query()
            ->whereIn('ai_status', $statuses)
            ->whereNull('ai_analyzed_at')
            ->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $records = $query->get();

        foreach ($records as $artwork) {
            $this->queue($artwork, applyAfterAnalysis: $applyAfterAnalysis);
        }

        return $records->count();
    }
}
