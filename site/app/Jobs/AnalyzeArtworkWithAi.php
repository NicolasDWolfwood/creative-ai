<?php

namespace App\Jobs;

use App\Models\Artwork;
use App\Services\ArtworkAiMetadataService;
use App\Services\PrivateMediaService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use RuntimeException;
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
        public string $sourcePath = '',
        public string $sourceSha256 = '',
    ) {
        $this->onQueue($queue);
    }

    public static function dispatchFor(
        Artwork $artwork,
        bool $force = false,
        bool $applyAfterAnalysis = false,
        string $queue = 'ai',
    ): string {
        $sourcePath = $artwork->masterPath();

        if (blank($sourcePath)) {
            throw new RuntimeException('The private artwork master is unavailable for AI analysis.');
        }

        $disk = app(PrivateMediaService::class)->sourceDisk($sourcePath);

        if (! $disk->exists($sourcePath)) {
            throw new RuntimeException('The private artwork master is unavailable for AI analysis.');
        }

        $sourceSha256 = hash_file('sha256', $disk->path($sourcePath));

        if (! is_string($sourceSha256)) {
            throw new RuntimeException('Unable to fingerprint the private artwork master.');
        }

        $queueToken = (string) Str::uuid();

        $artwork->forceFill([
            'ai_status' => Artwork::AI_STATUS_QUEUED,
            'ai_queue_token' => $queueToken,
            'ai_apply_after_analysis' => $applyAfterAnalysis,
            'ai_error' => null,
            'ai_queued_at' => now(),
            'ai_started_at' => null,
        ])->saveQuietly();

        self::dispatch(
            $artwork->getKey(),
            $queueToken,
            $force,
            $applyAfterAnalysis,
            $queue,
            $sourcePath,
            $sourceSha256,
        );

        return $queueToken;
    }

    public function handle(ArtworkAiMetadataService $service): void
    {
        $artwork = Artwork::query()->findOrFail($this->artworkId);

        if (! hash_equals((string) $artwork->ai_queue_token, $this->queueToken)) {
            return;
        }

        // Jobs serialized before source snapshots were introduced still rely
        // on the queue token. A master replacement clears that token, so it is
        // safe to hydrate the missing snapshot for an otherwise-current job.
        if ($this->sourcePath === '' && $this->sourceSha256 === '') {
            $this->captureCurrentSource($artwork);
        }

        if (! $this->isCurrentSource($artwork)) {
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

        $suggestion = $service->analyze($artwork, $this->sourcePath);

        $artwork->refresh();

        if (! hash_equals((string) $artwork->ai_queue_token, $this->queueToken)
            || ! $this->isCurrentSource($artwork)) {
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

    protected function isCurrentSource(Artwork $artwork): bool
    {
        if ($this->sourcePath === '' || $this->sourceSha256 === '') {
            return false;
        }

        if (! hash_equals($artwork->masterPath(), $this->sourcePath)) {
            return false;
        }

        $disk = app(PrivateMediaService::class)->sourceDisk($this->sourcePath);

        return $disk->exists($this->sourcePath)
            && hash_equals(
                $this->sourceSha256,
                (string) hash_file('sha256', $disk->path($this->sourcePath)),
            );
    }

    protected function captureCurrentSource(Artwork $artwork): void
    {
        $sourcePath = $artwork->masterPath();

        if (blank($sourcePath)) {
            return;
        }

        $disk = app(PrivateMediaService::class)->sourceDisk($sourcePath);

        if (! $disk->exists($sourcePath)) {
            return;
        }

        $sourceSha256 = hash_file('sha256', $disk->path($sourcePath));

        if (! is_string($sourceSha256)) {
            return;
        }

        $this->sourcePath = $sourcePath;
        $this->sourceSha256 = $sourceSha256;
    }
}
