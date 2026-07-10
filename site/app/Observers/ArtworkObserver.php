<?php

namespace App\Observers;

use App\Jobs\AnalyzeArtworkWithAi;
use App\Models\Artwork;
use App\Services\AiSettings;
use App\Services\ImageVariantService;
use Throwable;

class ArtworkObserver
{
    public function saved(Artwork $artwork): void
    {
        if (! $artwork->wasChanged('image_path') || blank($artwork->image_path)) {
            return;
        }

        try {
            $variants = app(ImageVariantService::class)->createVariants($artwork->image_path);
        } catch (Throwable $exception) {
            report($exception);

            return;
        }

        $artwork->forceFill($variants)->saveQuietly();

        if (app(AiSettings::class)->autoAnalyzeUploads()) {
            AnalyzeArtworkWithAi::dispatchFor($artwork, force: true);
        }
    }
}
