<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ImageVariantService
{
    public function __construct(
        protected AiSettings $aiSettings,
        protected PrivateMediaService $privateMedia,
        protected ArtworkSignatureRenderer $signatureRenderer,
    ) {}

    /**
     * @param  array<string, mixed>  $recipe
     * @return array{
     *     image_path:string,
     *     display_path:string,
     *     thumb_path:string,
     *     width:int,
     *     height:int,
     *     public_width:int,
     *     public_height:int,
     *     signature_resolved_tone:string|null,
     *     signature_review_recommended:bool
     * }
     */
    public function createVariants(
        string $sourcePath,
        int $artworkId,
        string $generationToken,
        array $recipe,
        string $recipeFingerprint,
    ): array {
        $sourceDisk = $this->privateMedia->sourceDisk($sourcePath);
        $disk = Storage::disk('local');

        if (! $sourceDisk->exists($sourcePath)) {
            throw new RuntimeException("Image not found in media storage: {$sourcePath}");
        }

        $absoluteSource = $sourceDisk->path($sourcePath);
        [$width, $height, $type] = getimagesize($absoluteSource) ?: [null, null, null];

        if (! $width || ! $height || ! $type) {
            throw new RuntimeException("Unable to inspect image: {$sourcePath}");
        }

        $this->guardSourcePixels($width, $height, $sourcePath);

        $paths = $this->variantPaths(
            $artworkId,
            $generationToken,
            $recipeFingerprint,
        );

        if (in_array($recipe['treatment'] ?? null, ['automatic', 'black', 'white'], true)) {
            $signaturePath = (string) ($recipe['signature_path'] ?? '');

            if ($signaturePath !== '') {
                $signatureDisk = $this->privateMedia->sourceDisk($signaturePath);
                $recipe['signature_path'] = $signatureDisk->path($signaturePath);
            }
        } else {
            $recipe['signature_path'] = null;
        }

        $rendered = $this->signatureRenderer->render($absoluteSource, $recipe);

        try {
            $this->writeAtomically($disk->path($paths['image_path']), $rendered['large_jpeg']);
            $this->writeAtomically($disk->path($paths['display_path']), $rendered['display_jpeg']);
            $this->writeAtomically($disk->path($paths['thumb_path']), $rendered['thumb_jpeg']);
        } catch (\Throwable $exception) {
            $disk->delete(array_values($paths));

            throw $exception;
        }

        [$publicWidth, $publicHeight] = getimagesizefromstring($rendered['large_jpeg']) ?: [0, 0];

        return [
            ...$paths,
            'width' => $rendered['width'],
            'height' => $rendered['height'],
            'public_width' => $publicWidth,
            'public_height' => $publicHeight,
            'signature_resolved_tone' => $rendered['resolved_tone'],
            'signature_review_recommended' => $rendered['review_recommended'],
        ];
    }

    /**
     * The active recipe and attempt token make every output immutable. A stale
     * job can write only its own unreachable candidates.
     *
     * @return array{image_path:string, display_path:string, thumb_path:string}
     */
    public function variantPaths(int $artworkId, string $generationToken, string $recipeFingerprint): array
    {
        $recipeKey = substr($recipeFingerprint, 0, 16);
        $tokenKey = str_replace('-', '', $generationToken);
        $filename = "{$artworkId}-{$recipeKey}-{$tokenKey}.jpg";

        return [
            'image_path' => "artworks/large/{$filename}",
            'display_path' => "artworks/display/{$filename}",
            'thumb_path' => "artworks/thumbs/{$filename}",
        ];
    }

    /**
     * @return array{data_url:string, width:int, height:int, bytes:int}
     */
    public function createAnalysisImageData(string $sourcePath): array
    {
        $disk = $this->privateMedia->sourceDisk($sourcePath);

        if (! $disk->exists($sourcePath)) {
            throw new RuntimeException("Image not found in media storage: {$sourcePath}");
        }

        $absoluteSource = $disk->path($sourcePath);
        [$width, $height, $type] = getimagesize($absoluteSource) ?: [null, null, null];

        if (! $width || ! $height || ! $type) {
            throw new RuntimeException("Unable to inspect image: {$sourcePath}");
        }

        $this->guardSourcePixels($width, $height, $sourcePath);

        $jpeg = $this->resizeToJpegString(
            $absoluteSource,
            $type,
            $this->aiSettings->imageMaxWidth(),
            $this->aiSettings->imageJpegQuality(),
        );

        [$analysisWidth, $analysisHeight] = getimagesizefromstring($jpeg) ?: [0, 0];

        return [
            'data_url' => 'data:image/jpeg;base64,'.base64_encode($jpeg),
            'width' => $analysisWidth,
            'height' => $analysisHeight,
            'bytes' => strlen($jpeg),
        ];
    }

    protected function writeAtomically(string $destination, string $contents): void
    {
        $directory = dirname($destination);

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create image variant directory: {$directory}");
        }

        $temporary = tempnam($directory, '.variant-');

        if ($temporary === false) {
            throw new RuntimeException("Unable to create temporary image variant: {$destination}");
        }

        try {
            if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
                throw new RuntimeException("Unable to write image variant: {$destination}");
            }

            @chmod($temporary, 0664);

            if (! rename($temporary, $destination)) {
                throw new RuntimeException("Unable to publish image variant: {$destination}");
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    protected function guardSourcePixels(int $width, int $height, string $sourcePath): void
    {
        $maximum = (int) config('creative_ai.image_variants.max_source_pixels', 20_000_000);

        if ($maximum > 0 && $width > intdiv($maximum, $height)) {
            throw new RuntimeException(
                "Image dimensions exceed the configured safe pixel limit ({$maximum}): {$sourcePath}",
            );
        }
    }

    protected function resizeToJpegString(string $source, int $type, int $maxWidth, int $quality): string
    {
        $canvas = $this->resizedCanvas($source, $type, $maxWidth);

        ob_start();
        imagejpeg($canvas, null, max(1, min(100, $quality)));
        $jpeg = ob_get_clean();

        imagedestroy($canvas);

        if ($jpeg === false) {
            throw new RuntimeException("Unable to encode resized JPEG: {$source}");
        }

        return $jpeg;
    }

    protected function resizedCanvas(string $source, int $type, int $maxWidth): \GdImage
    {
        $image = $this->createImage($source, $type);
        $sourceWidth = imagesx($image);
        $sourceHeight = imagesy($image);

        $targetWidth = min($sourceWidth, max(1, $maxWidth));
        $targetHeight = (int) round($sourceHeight * ($targetWidth / $sourceWidth));

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 12, 13, 16));

        imagecopyresampled(
            $canvas,
            $image,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $sourceWidth,
            $sourceHeight,
        );

        imagedestroy($image);

        return $canvas;
    }

    protected function createImage(string $source, int $type): \GdImage
    {
        $image = match ($type) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($source),
            IMAGETYPE_PNG => imagecreatefrompng($source),
            IMAGETYPE_WEBP => imagecreatefromwebp($source),
            default => throw new RuntimeException('Only JPEG, PNG, and WebP images are supported.'),
        };

        if (! $image) {
            throw new RuntimeException("Unable to decode image: {$source}");
        }

        return $image;
    }
}
