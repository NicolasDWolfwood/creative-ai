<?php

namespace App\Jobs;

use App\Models\Artwork;
use App\Services\ArtworkMediaCleanupService;
use App\Services\ArtworkSignatureSettings;
use App\Services\ImageVariantService;
use App\Services\PrivateMediaService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class GenerateArtworkVariants implements ShouldQueue
{
    use Queueable;

    /**
     * Lock releases count as attempts. This budget exceeds the complete lock
     * expiry window so the newest recipe cannot fail merely because an older
     * same-artwork render is still finishing.
     */
    public int $tries = 50;

    /** Genuine rendering exceptions still fail promptly. */
    public int $maxExceptions = 2;

    public int $timeout = 180;

    /**
     * @param  array<int, string|null>  $obsoletePaths
     * @param  array<string, mixed>  $recipe
     */
    public function __construct(
        public int $artworkId,
        public string $sourcePath,
        public string $generationToken,
        public array $obsoletePaths = [],
        public bool $analyzeAfterGeneration = false,
        public array $recipe = [],
        public string $recipeFingerprint = '',
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
        $sourcePath = $artwork->masterPath();

        if (blank($sourcePath)) {
            return null;
        }

        if (blank($artwork->master_path)
            && $artwork->signature_mode === Artwork::SIGNATURE_MODE_EMBEDDED
            && hash_equals((string) $artwork->image_path, $sourcePath)) {
            Artwork::query()
                ->whereKey($artwork->getKey())
                ->whereNull('master_path')
                ->where('image_path', $sourcePath)
                ->update([
                    'master_path' => $sourcePath,
                    'signature_active_treatment' => 'embedded',
                ]);
            $artwork->forceFill([
                'master_path' => $sourcePath,
                'signature_active_treatment' => 'embedded',
            ]);
        }

        $media = app(PrivateMediaService::class);
        $sourceDisk = $media->sourceDisk($sourcePath);

        if (! $sourceDisk->exists($sourcePath)) {
            return null;
        }

        $sourceHash = hash_file('sha256', $sourceDisk->path($sourcePath));

        if (! is_string($sourceHash)) {
            throw new RuntimeException("Unable to fingerprint artwork master: {$sourcePath}");
        }

        $recipe = app(ArtworkSignatureSettings::class)->recipeFor($artwork);
        $recipe['source_sha256'] = $sourceHash;
        $recipeFingerprint = self::fingerprint($recipe);
        $generationToken = (string) Str::uuid();
        $queuedAt = now();
        $obsoletePaths = array_values(array_unique(array_filter([
            ...$obsoletePaths,
            $artwork->image_path,
            $artwork->display_path,
            $artwork->thumb_path,
        ], 'is_string')));
        $updated = Artwork::query()
            ->whereKey($artwork->getKey())
            ->where('master_path', $sourcePath)
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
            $sourcePath,
            $generationToken,
            $obsoletePaths,
            $analyzeAfterGeneration,
            $recipe,
            $recipeFingerprint,
        );
    }

    /** @return array<int, WithoutOverlapping> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("artwork-variants:{$this->artworkId}"))
                ->releaseAfter(5)
                ->expireAfter($this->timeout + 30),
        ];
    }

    public function handle(
        ImageVariantService $variants,
        ArtworkMediaCleanupService $cleanup,
    ): void {
        if ($this->recipe === [] || $this->recipeFingerprint === '') {
            throw new RuntimeException('This image job predates the signature recipe contract and must be requeued.');
        }

        if (! hash_equals($this->recipeFingerprint, self::fingerprint($this->recipe))) {
            throw new RuntimeException('The queued artwork rendition recipe fingerprint is invalid.');
        }

        $startedAt = now();
        $claimed = Artwork::query()
            ->whereKey($this->artworkId)
            ->where('master_path', $this->sourcePath)
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
            $cleanup->deleteUnreferenced(array_values($this->candidatePaths($variants)));

            return;
        }

        $generated = $variants->createVariants(
            $this->sourcePath,
            $this->artworkId,
            $this->generationToken,
            $this->recipe,
            $this->recipeFingerprint,
        );
        $generatedAt = now();
        $updated = Artwork::query()
            ->whereKey($this->artworkId)
            ->where('master_path', $this->sourcePath)
            ->where('variant_generation_token', $this->generationToken)
            ->update([
                'image_path' => $generated['image_path'],
                'display_path' => $generated['display_path'],
                'thumb_path' => $generated['thumb_path'],
                'width' => $generated['width'],
                'height' => $generated['height'],
                'public_width' => $generated['public_width'],
                'public_height' => $generated['public_height'],
                'signature_active_treatment' => $this->activeTreatment(),
                'signature_resolved_tone' => $generated['signature_resolved_tone'],
                'signature_resolved_position' => $this->recipe['corner'],
                'signature_review_recommended' => $generated['signature_review_recommended'],
                'signature_recipe_fingerprint' => $this->recipeFingerprint,
                'signature_settings_revision' => $this->recipe['settings_revision'] ?? null,
                'variant_status' => Artwork::VARIANT_STATUS_READY,
                'variant_error' => null,
                'variant_started_at' => null,
                'variants_generated_at' => $generatedAt,
                'updated_at' => $generatedAt,
            ]);

        if ($updated === 0) {
            $cleanup->deleteUnreferenced($this->generatedPaths($generated));

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

        // Superseded masters and renditions remain recoverable until the new
        // complete set has been activated by the conditional update above.
        $cleanup->deleteUnreferenced($this->obsoletePaths);
    }

    public function failed(?Throwable $exception): void
    {
        $failedAt = now();
        $error = Str::of($exception?->getMessage() ?: 'Image rendition generation failed.')
            ->squish()
            ->limit(1000, '')
            ->toString();
        Artwork::query()
            ->whereKey($this->artworkId)
            ->where('master_path', $this->sourcePath)
            ->where('variant_generation_token', $this->generationToken)
            ->where('variant_status', '!=', Artwork::VARIANT_STATUS_READY)
            ->update([
                'variant_status' => Artwork::VARIANT_STATUS_FAILED,
                'variant_error' => $error,
                'variant_started_at' => null,
                'updated_at' => $failedAt,
            ]);

        // Never delete obsolete paths here: they may be the last active signed
        // set or the old master needed to recover from a failed replacement.
        DeleteArtworkMedia::dispatch(
            array_values($this->candidatePaths(app(ImageVariantService::class))),
        )->afterCommit();
    }

    /**
     * The recipe fingerprint deliberately excludes storage paths and the
     * settings revision wrapper. Source and signature byte hashes remain part
     * of the canonical payload.
     *
     * @param  array<string, mixed>  $recipe
     */
    public static function fingerprint(array $recipe): string
    {
        unset($recipe['signature_path'], $recipe['settings_revision']);
        $canonical = self::canonicalize($recipe);
        $json = json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return hash('sha256', $json);
    }

    /** @return array{image_path:string, display_path:string, thumb_path:string} */
    protected function candidatePaths(ImageVariantService $variants): array
    {
        return $variants->variantPaths(
            $this->artworkId,
            $this->generationToken,
            $this->recipeFingerprint,
        );
    }

    /** @param array<string, mixed> $generated
     * @return array<int, string>
     */
    protected function generatedPaths(array $generated): array
    {
        return [
            $generated['image_path'],
            $generated['display_path'],
            $generated['thumb_path'],
        ];
    }

    protected function activeTreatment(): string
    {
        return match ($this->recipe['treatment'] ?? null) {
            Artwork::SIGNATURE_MODE_EMBEDDED => 'embedded',
            Artwork::SIGNATURE_MODE_NONE => 'unsigned',
            default => 'signed',
        };
    }

    protected function isCurrentGeneration(?Artwork $artwork): bool
    {
        return $artwork !== null
            && hash_equals($artwork->masterPath(), $this->sourcePath)
            && hash_equals((string) $artwork->variant_generation_token, $this->generationToken)
            && hash_equals((string) $artwork->signature_recipe_fingerprint, $this->recipeFingerprint)
            && $artwork->variant_status === Artwork::VARIANT_STATUS_READY;
    }

    protected static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }

        return $value;
    }
}
