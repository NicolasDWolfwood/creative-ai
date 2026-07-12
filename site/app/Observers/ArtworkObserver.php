<?php

namespace App\Observers;

use App\Jobs\DeleteArtworkMedia;
use App\Jobs\GenerateArtworkVariants;
use App\Models\Artwork;
use App\Services\AiSettings;

class ArtworkObserver
{
    public function saving(Artwork $artwork): void
    {
        if (blank($artwork->image_path) || ($artwork->exists && ! $artwork->isDirty('image_path'))) {
            return;
        }

        $artwork->forceFill([
            'display_path' => null,
            'thumb_path' => null,
            'width' => null,
            'height' => null,
            'variant_status' => Artwork::VARIANT_STATUS_PENDING,
            'variant_generation_token' => null,
            'variant_error' => null,
            'variant_queued_at' => null,
            'variant_started_at' => null,
            'variants_generated_at' => null,
        ]);
    }

    public function created(Artwork $artwork): void
    {
        $this->queueVariants($artwork);
    }

    public function updated(Artwork $artwork): void
    {
        if (! $artwork->wasChanged('image_path')) {
            return;
        }

        $this->queueVariants($artwork);
    }

    public function deleted(Artwork $artwork): void
    {
        DeleteArtworkMedia::dispatch([
            $artwork->image_path,
            $artwork->display_path,
            $artwork->thumb_path,
        ])->afterCommit();
    }

    protected function queueVariants(Artwork $artwork): void
    {
        if (blank($artwork->image_path)) {
            return;
        }

        GenerateArtworkVariants::dispatchFor(
            $artwork,
            obsoletePaths: [
                $artwork->getOriginal('image_path'),
                $artwork->getOriginal('display_path'),
                $artwork->getOriginal('thumb_path'),
            ],
            analyzeAfterGeneration: $artwork->analyzeAfterVariantGeneration
                || app(AiSettings::class)->autoAnalyzeUploads(),
        );
    }
}
