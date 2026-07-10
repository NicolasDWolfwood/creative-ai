<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ImageVariantService
{
    public function __construct(
        protected AiSettings $aiSettings,
    ) {}

    /**
     * @return array{display_path:string, thumb_path:string, width:int, height:int}
     */
    public function createVariants(string $sourcePath): array
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

        $baseName = pathinfo($sourcePath, PATHINFO_FILENAME);
        $displayPath = "artworks/display/{$baseName}.jpg";
        $thumbPath = "artworks/thumbs/{$baseName}.jpg";

        $this->resizeToJpeg($absoluteSource, $type, $disk->path($displayPath), config('creative_ai.image_variants.display', 1600));
        $this->resizeToJpeg($absoluteSource, $type, $disk->path($thumbPath), config('creative_ai.image_variants.thumb', 720));

        return [
            'display_path' => $displayPath,
            'thumb_path' => $thumbPath,
            'width' => $width,
            'height' => $height,
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

    protected function resizeToJpeg(string $source, int $type, string $destination, int $maxWidth): void
    {
        $directory = dirname($destination);

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents($destination, $this->resizeToJpegString($source, $type, $maxWidth, 86));
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
