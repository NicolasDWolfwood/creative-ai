<?php

namespace App\Jobs;

use App\Models\Artwork;
use App\Services\ArtworkAiMetadataService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

class AnalyzeArtworkWithAi implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 180;

    public function __construct(
        public int $artworkId,
        public string $queueToken,
        public bool $force = false,
        public bool $applyAfterAnalysis = false,
        string $queue = 'ai',
    ) {
        $this->onQueue($queue);
    }

    public static function dispatchFor(
        Artwork $artwork,
        bool $force = false,
        bool $applyAfterAnalysis = false,
        string $queue = 'ai',
    ): string {
        $queueToken = (string) Str::uuid();

        $artwork->forceFill([
            'ai_status' => Artwork::AI_STATUS_QUEUED,
            'ai_queue_token' => $queueToken,
            'ai_apply_after_analysis' => $applyAfterAnalysis,
            'ai_error' => null,
            'ai_queued_at' => now(),
            'ai_started_at' => null,
        ])->saveQuietly();

        self::dispatch($artwork->getKey(), $queueToken, $force, $applyAfterAnalysis, $queue);

        return $queueToken;
    }

    public function handle(ArtworkAiMetadataService $service): void
    {
        $artwork = Artwork::query()->findOrFail($this->artworkId);

        if (! hash_equals((string) $artwork->ai_queue_token, $this->queueToken)) {
            return;
        }

        if (
            $this->applyAfterAnalysis
            && $artwork->ai_apply_after_analysis
            && filled($artwork->ai_suggestion)
            && in_array($artwork->ai_status, [Artwork::AI_STATUS_READY, Artwork::AI_STATUS_APPLIED], true)
        ) {
            $this->applyStoredSuggestion($artwork, $service);

            return;
        }

        if (! in_array($artwork->ai_status, [
            Artwork::AI_STATUS_QUEUED,
            Artwork::AI_STATUS_PROCESSING,
        ], true)) {
            return;
        }

        $artwork->forceFill([
            'ai_status' => Artwork::AI_STATUS_PROCESSING,
            'ai_model' => $service->model(),
            'ai_error' => null,
            'ai_started_at' => $artwork->ai_started_at ?: now(),
        ])->saveQuietly();

        $suggestion = $service->analyze($artwork);

        $artwork->refresh();

        if (! hash_equals((string) $artwork->ai_queue_token, $this->queueToken)) {
            return;
        }

        $artwork->forceFill([
            'ai_status' => Artwork::AI_STATUS_READY,
            'ai_queue_token' => $this->applyAfterAnalysis ? $this->queueToken : null,
            'ai_apply_after_analysis' => $this->applyAfterAnalysis,
            'ai_suggestion' => $suggestion,
            'ai_model' => $service->model(),
            'ai_error' => null,
            'ai_started_at' => null,
            'ai_analyzed_at' => now(),
        ])->saveQuietly();

        if ($this->applyAfterAnalysis) {
            $this->applyStoredSuggestion($artwork->refresh(), $service);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $artwork = Artwork::query()->find($this->artworkId);

        if (! $artwork) {
            return;
        }

        if (! hash_equals((string) $artwork->ai_queue_token, $this->queueToken)) {
            return;
        }

        $artwork->forceFill([
            'ai_status' => Artwork::AI_STATUS_FAILED,
            'ai_queue_token' => null,
            'ai_apply_after_analysis' => $this->applyAfterAnalysis,
            'ai_error' => Str::of($exception?->getMessage() ?: 'AI analysis failed.')->squish()->limit(1000, '')->toString(),
            'ai_started_at' => null,
        ])->saveQuietly();
    }

    protected function applyStoredSuggestion(Artwork $artwork, ArtworkAiMetadataService $service): void
    {
        $service->applySuggestion($artwork, preserveQueueState: true);

        $artwork->forceFill([
            'ai_queue_token' => null,
            'ai_apply_after_analysis' => false,
            'ai_started_at' => null,
        ])->saveQuietly();
    }
}
