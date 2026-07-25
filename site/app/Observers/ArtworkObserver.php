<?php

namespace App\Observers;

use App\Jobs\DeleteArtworkMedia;
use App\Jobs\GenerateArtworkVariants;
use App\Models\Artwork;
use App\Services\AiSettings;
use App\Services\SmartCollectionService;

class ArtworkObserver
{
    public function saving(Artwork $artwork): void
    {
        if ($artwork->exists
            && $artwork->isDirty('image_path')
            && ! $artwork->isDirty('master_path')
            && $artwork->signature_mode === Artwork::SIGNATURE_MODE_EMBEDDED) {
            $artwork->master_path = $artwork->image_path;
            $artwork->signature_active_treatment = 'embedded';
        }

        // Backward-compatible programmatic imports can still provide only the
        // legacy public-safe image path. Capture it on creation, replacement,
        // or an explicit signature conversion. An untouched legacy row can
        // continue using Artwork::masterPath() without unexpectedly queueing
        // image work during an unrelated metadata update.
        if ((! $artwork->exists
                || $artwork->isDirty([
                    'image_path',
                    'signature_mode',
                    'signature_position',
                ]))
            && blank($artwork->master_path)
            && filled($artwork->image_path)
            && ($artwork->signature_mode === Artwork::SIGNATURE_MODE_EMBEDDED
                || $artwork->getOriginal('signature_mode') === Artwork::SIGNATURE_MODE_EMBEDDED)) {
            $artwork->master_path = $artwork->image_path;
            $artwork->signature_active_treatment ??= 'embedded';
        }

        if (blank($artwork->master_path)
            || ($artwork->exists && ! $artwork->isDirty([
                'master_path',
                'signature_mode',
                'signature_position',
            ]))) {
            return;
        }

        // Keep the last complete public-safe set live while its replacement is
        // prepared. The job swaps all active paths in one conditional update.
        $artwork->forceFill([
            'width' => null,
            'height' => null,
            'variant_status' => Artwork::VARIANT_STATUS_PENDING,
            'variant_generation_token' => null,
            'variant_error' => null,
            'variant_queued_at' => null,
            'variant_started_at' => null,
            'variants_generated_at' => null,
        ]);

        if ($artwork->exists && $artwork->isDirty('master_path')) {
            $artwork->forceFill([
                'ai_status' => Artwork::AI_STATUS_IDLE,
                'ai_queue_token' => null,
                'ai_apply_after_analysis' => false,
                'ai_suggestion' => null,
                'ai_model' => null,
                'ai_error' => null,
                'ai_queued_at' => null,
                'ai_started_at' => null,
                'ai_analyzed_at' => null,
            ]);
        }
    }

    public function created(Artwork $artwork): void
    {
        $this->queueVariants($artwork);
    }

    public function updated(Artwork $artwork): void
    {
        if ($artwork->wasChanged([
            'master_path',
            'signature_mode',
            'signature_position',
        ])) {
            $this->queueVariants($artwork);
        }

        if ($artwork->wasChanged(['published', 'published_at'])) {
            app(SmartCollectionService::class)->syncAutomatic();
        }
    }

    public function deleted(Artwork $artwork): void
    {
        DeleteArtworkMedia::dispatch([
            $artwork->master_path,
            $artwork->image_path,
            $artwork->display_path,
            $artwork->thumb_path,
        ])->afterCommit();
    }

    protected function queueVariants(Artwork $artwork): void
    {
        if (blank($artwork->masterPath())) {
            return;
        }

        GenerateArtworkVariants::dispatchFor(
            $artwork,
            obsoletePaths: [
                $artwork->getOriginal('master_path'),
                $artwork->getOriginal('image_path'),
                $artwork->getOriginal('display_path'),
                $artwork->getOriginal('thumb_path'),
            ],
            analyzeAfterGeneration: $artwork->analyzeAfterVariantGeneration
                || app(AiSettings::class)->autoAnalyzeUploads(),
        );
    }
}
