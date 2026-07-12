<?php

namespace App\Jobs;

use App\Models\Artwork;
use App\Services\ArtworkMediaCleanupService;
use App\Services\ImageVariantService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Str;
use Throwable;

class GenerateArtworkVariants implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    /**
     * @param  array<int, string|null>  $obsoletePaths
     */
    public function __construct(
        public int $artworkId,
        public string $sourcePath,
        public string $generationToken,
        public array $obsoletePaths = [],
        public bool $analyzeAfterGeneration = false,
    ) {
        $this->onQueue('default');
    }

    /** @param array<int, string|null> $obsoletePaths */
    public static function dispatchFor(
        Artwork $artwork,
        array $obsoletePaths = [],
        bool $analyzeAfterGeneration = false,
    ): ?string {
        $job = self::prepareFor($artwork, $obsoletePaths, $analyzeAfterGeneration);

        if (! $job) {
            return null;
        }

        dispatch($job)->afterCommit();

        return $job->generationToken;
    }

    /** @param array<int, string|null> $obsoletePaths */
    public static function prepareFor(
        Artwork $artwork,
        array $obsoletePaths = [],
        bool $analyzeAfterGeneration = false,
    ): ?self {
        if (blank($artwork->image_path)) {
            return null;
        }

        $generationToken = (string) Str::uuid();
        $queuedAt = now();
        $obsoletePaths = array_values(array_unique(array_filter([
            ...$obsoletePaths,
            $artwork->display_path,
            $artwork->thumb_path,
        ], 'is_string')));
        $updated = Artwork::query()
            ->whereKey($artwork->getKey())
            ->where('image_path', $artwork->image_path)
            ->update([
                'variant_status' => Artwork::VARIANT_STATUS_QUEUED,
                'variant_generation_token' => $generationToken,
                'variant_error' => null,
                'variant_queued_at' => $queuedAt,
                'variant_started_at' => null,
                'updated_at' => $queuedAt,
            ]);

        if ($updated === 0) {
            return null;
        }

        $artwork->forceFill([
            'variant_status' => Artwork::VARIANT_STATUS_QUEUED,
            'variant_generation_token' => $generationToken,
            'variant_error' => null,
            'variant_queued_at' => $queuedAt,
            'variant_started_at' => null,
        ]);

        return new self(
            $artwork->getKey(),
            $artwork->image_path,
            $generationToken,
            $obsoletePaths,
            $analyzeAfterGeneration,
        );
    }

    /** @return array<int, WithoutOverlapping> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("artwork-variants:{$this->artworkId}:{$this->generationToken}"))
                ->releaseAfter(5)
                ->expireAfter($this->timeout + 30),
        ];
    }

    public function handle(
        ImageVariantService $variants,
        ArtworkMediaCleanupService $cleanup,
    ): void {
        $startedAt = now();
        $claimed = Artwork::query()
            ->whereKey($this->artworkId)
            ->where('image_path', $this->sourcePath)
            ->where('variant_generation_token', $this->generationToken)
            ->whereIn('variant_status', [
                Artwork::VARIANT_STATUS_QUEUED,
                Artwork::VARIANT_STATUS_PROCESSING,
            ])
            ->update([
                'variant_status' => Artwork::VARIANT_STATUS_PROCESSING,
                'variant_error' => null,
                'variant_started_at' => $startedAt,
                'updated_at' => $startedAt,
            ]);

        if ($claimed === 0) {
            $cleanup->deleteUnreferenced([
                ...array_values($this->candidatePaths($variants)),
                ...$this->obsoletePaths,
            ]);

            return;
        }

        $generated = $variants->createVariants(
            $this->sourcePath,
            $this->artworkId,
            $this->generationToken,
        );
        $generatedAt = now();
        $updated = Artwork::query()
            ->whereKey($this->artworkId)
            ->where('image_path', $this->sourcePath)
            ->where('variant_generation_token', $this->generationToken)
            ->update([
                ...$generated,
                'variant_status' => Artwork::VARIANT_STATUS_READY,
                'variant_error' => null,
                'variant_started_at' => null,
                'variants_generated_at' => $generatedAt,
                'updated_at' => $generatedAt,
            ]);

        if ($updated === 0) {
            $cleanup->deleteUnreferenced([
                ...array_values($generated),
                ...$this->obsoletePaths,
            ]);

            return;
        }

        $artwork = Artwork::query()->find($this->artworkId);

        if ($this->analyzeAfterGeneration && $this->isCurrentGeneration($artwork)) {
            try {
                AnalyzeArtworkWithAi::dispatchFor($artwork, force: true);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        $cleanup->deleteUnreferenced($this->obsoletePaths);
    }

    public function failed(?Throwable $exception): void
    {
        $failedAt = now();
        $error = Str::of($exception?->getMessage() ?: 'Image variant generation failed.')
            ->squish()
            ->limit(1000, '')
            ->toString();
        Artwork::query()
            ->whereKey($this->artworkId)
            ->where('image_path', $this->sourcePath)
            ->where('variant_generation_token', $this->generationToken)
            ->where('variant_status', '!=', Artwork::VARIANT_STATUS_READY)
            ->update([
                'variant_status' => Artwork::VARIANT_STATUS_FAILED,
                'variant_error' => $error,
                'variant_started_at' => null,
                'updated_at' => $failedAt,
            ]);

        DeleteArtworkMedia::dispatch([
            ...array_values($this->candidatePaths(app(ImageVariantService::class))),
            ...$this->obsoletePaths,
        ])->afterCommit();
    }

    /** @return array{display_path:string, thumb_path:string} */
    protected function candidatePaths(ImageVariantService $variants): array
    {
        return $variants->variantPaths(
            $this->sourcePath,
            $this->artworkId,
            $this->generationToken,
        );
    }

    protected function isCurrentGeneration(?Artwork $artwork): bool
    {
        return $artwork !== null
            && hash_equals((string) $artwork->image_path, $this->sourcePath)
            && hash_equals((string) $artwork->variant_generation_token, $this->generationToken)
            && $artwork->variant_status === Artwork::VARIANT_STATUS_READY;
    }
}
