<?php

namespace App\Console\Commands;

use App\Jobs\GenerateArtworkVariants;
use App\Models\Artwork;
use App\Services\ArtworkMediaCleanupService;
use App\Services\ImageVariantService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

class RegenerateArtworkVariants extends Command
{
    protected $signature = 'creative-ai:artwork-variants:regenerate
        {--all : Regenerate artwork even when both current variants exist}
        {--sync : Generate variants in this process instead of queueing jobs}
        {--stale-after=15 : Requeue missing queued or processing variants after this many minutes}
        {--chunk=100 : Number of artwork records to inspect at a time}';

    protected $description = 'Idempotently generate missing display and thumbnail variants for existing artwork';

    public function handle(
        ImageVariantService $variants,
        ArtworkMediaCleanupService $cleanup,
    ): int {
        $chunk = max(1, min(1000, (int) $this->option('chunk')));
        $staleBefore = now()->subMinutes(max(1, min(1440, (int) $this->option('stale-after'))));
        $all = (bool) $this->option('all');
        $sync = (bool) $this->option('sync');
        $queued = 0;
        $generated = 0;
        $failed = 0;
        $skipped = 0;

        Artwork::query()->orderBy('id')->chunkById($chunk, function ($artworks) use (
            $all,
            $sync,
            $staleBefore,
            $variants,
            $cleanup,
            &$queued,
            &$generated,
            &$failed,
            &$skipped,
        ): void {
            foreach ($artworks as $artwork) {
                if (! Storage::disk('public')->exists($artwork->image_path)) {
                    $this->markMissingOriginal($artwork);
                    $failed++;

                    continue;
                }

                $active = in_array($artwork->variant_status, [
                    Artwork::VARIANT_STATUS_QUEUED,
                    Artwork::VARIANT_STATUS_PROCESSING,
                ], true);

                if (
                    ! $all
                    && $active
                    && ($artwork->variant_started_at ?: $artwork->variant_queued_at)?->isAfter($staleBefore)
                ) {
                    $skipped++;

                    continue;
                }

                if (
                    ! $all
                    && ! $active
                    && $artwork->variant_status !== Artwork::VARIANT_STATUS_FAILED
                    && $artwork->imageVariantsExist()
                ) {
                    if ($artwork->variant_status !== Artwork::VARIANT_STATUS_READY) {
                        $artwork->forceFill([
                            'variant_status' => Artwork::VARIANT_STATUS_READY,
                            'variant_error' => null,
                        ])->saveQuietly();
                    }

                    $skipped++;

                    continue;
                }

                if (! $sync) {
                    if (GenerateArtworkVariants::dispatchFor($artwork)) {
                        $queued++;
                    } else {
                        $skipped++;
                    }

                    continue;
                }

                $job = GenerateArtworkVariants::prepareFor($artwork);

                if (! $job) {
                    $skipped++;

                    continue;
                }

                try {
                    $job->handle($variants, $cleanup);
                } catch (Throwable $exception) {
                    $job->failed($exception);
                }

                if ($artwork->refresh()->variant_status === Artwork::VARIANT_STATUS_READY) {
                    $generated++;
                } else {
                    $failed++;
                }
            }
        });

        $this->components->info(
            "Artwork variants: {$generated} generated, {$queued} queued, {$skipped} unchanged, {$failed} failed.",
        );

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    protected function markMissingOriginal(Artwork $artwork): void
    {
        $message = 'The original artwork image is missing from public storage.';

        if (
            $artwork->variant_status === Artwork::VARIANT_STATUS_FAILED
            && $artwork->variant_error === $message
        ) {
            return;
        }

        $artwork->forceFill([
            'variant_status' => Artwork::VARIANT_STATUS_FAILED,
            'variant_error' => $message,
            'variant_started_at' => null,
        ])->saveQuietly();
    }
}
