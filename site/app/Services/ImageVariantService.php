<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class ImageVariantService
{
    public function __construct(
        protected AiSettings $aiSettings,
    ) {}

    /**
     * @return array{display_path:string, thumb_path:string, width:int, height:int}
     */
    public function createVariants(string $sourcePath, int $artworkId, string $generationToken): array
    {
        $disk = Storage::disk('public');

        if (! $disk->exists($sourcePath)) {
            throw new RuntimeException("Image not found on public disk: {$sourcePath}");
        }

        $absoluteSource = $disk->path($sourcePath);
        [$width, $height, $type] = getimagesize($absoluteSource) ?: [null, null, null];

        if (! $width || ! $height || ! $type) {
            throw new RuntimeException("Unable to inspect image: {$sourcePath}");
        }

        $this->guardSourcePixels($width, $height, $sourcePath);

        ['display_path' => $displayPath, 'thumb_path' => $thumbPath] = $this->variantPaths(
            $sourcePath,
            $artworkId,
            $generationToken,
        );

        $displayJpeg = $this->resizeToJpegString(
            $absoluteSource,
            $type,
            config('creative_ai.image_variants.display', 1600),
            86,
        );
        $thumbJpeg = $this->resizeToJpegString(
            $absoluteSource,
            $type,
            config('creative_ai.image_variants.thumb', 720),
            86,
        );

        try {
            $this->writeAtomically($disk->path($displayPath), $displayJpeg);
            $this->writeAtomically($disk->path($thumbPath), $thumbJpeg);
        } catch (\Throwable $exception) {
            $disk->delete([$displayPath, $thumbPath]);

            throw $exception;
        }

        return [
            'display_path' => $displayPath,
            'thumb_path' => $thumbPath,
            'width' => $width,
            'height' => $height,
        ];
    }

    /**
     * Use both the artwork id and source path so a stale replacement job can
     * never overwrite the variants generated for the current source image.
     *
     * @return array{display_path:string, thumb_path:string}
     */
    public function variantPaths(string $sourcePath, int $artworkId, string $generationToken): array
    {
        $baseName = Str::of(pathinfo($sourcePath, PATHINFO_FILENAME))
            ->slug()
            ->limit(80, '')
            ->value() ?: 'image';
        $sourceKey = substr(hash('sha256', $sourcePath), 0, 12);
        $tokenKey = str_replace('-', '', $generationToken);
        $filename = "{$artworkId}-{$baseName}-{$sourceKey}-{$tokenKey}.jpg";

        return [
            'display_path' => "artworks/display/{$filename}",
            'thumb_path' => "artworks/thumbs/{$filename}",
        ];
    }

    /**
     * @return array{data_url:string, width:int, height:int, bytes:int}
     */
    public function createAnalysisImageData(string $sourcePath): array
    {
        $disk = Storage::disk('public');

        if (! $disk->exists($sourcePath)) {
            throw new RuntimeException("Image not found on public disk: {$sourcePath}");
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
