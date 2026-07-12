<?php

namespace App\Jobs;

use App\Models\Track;
use App\Services\TrackAiMetadataService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

class AnalyzeTrackMetadata implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 180;

    public function __construct(public int $trackId, public bool $applyImmediately = false)
    {
        $this->onQueue('ai');
    }

    public static function dispatchFor(Track $track, bool $applyImmediately = false): void
    {
        $track->forceFill(['ai_status' => Track::AI_STATUS_QUEUED, 'ai_error' => null])->saveQuietly();
        self::dispatch($track->id, $applyImmediately);
    }

    public function handle(TrackAiMetadataService $metadata): void
    {
        $track = Track::query()->find($this->trackId);

        if ($track) {
            $track->forceFill(['ai_status' => Track::AI_STATUS_PROCESSING, 'ai_error' => null])->saveQuietly();
            $track->forceFill([
                'ai_status' => Track::AI_STATUS_READY,
                'ai_suggestion' => $metadata->analyze($track),
                'ai_analyzed_at' => now(),
                'ai_error' => null,
            ])->saveQuietly();

            if ($this->applyImmediately) {
                $metadata->applySuggestion($track->refresh());
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        Track::query()->whereKey($this->trackId)->update([
            'ai_status' => Track::AI_STATUS_FAILED,
            'ai_error' => Str::of($exception?->getMessage() ?: 'AI analysis failed.')->squish()->limit(1000, '')->toString(),
        ]);
    }
}
