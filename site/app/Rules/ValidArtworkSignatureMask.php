<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use SplFileInfo;

class ValidArtworkSignatureMask implements ValidationRule
{
    public const MANAGED_PREFIX = 'artwork-signatures/assets/';

    public const MAX_PIXELS = 5_000_000;

    public const MAX_BYTES = 5 * 1024 * 1024;

    /**
     * @return array{width:int,height:int,sha256:string}
     */
    public static function inspect(mixed $value): array
    {
        $path = self::absolutePath($value);
        $bytes = filesize($path);

        if (! is_int($bytes) || $bytes < 1 || $bytes > self::MAX_BYTES) {
            throw new RuntimeException('The signature PNG may not exceed 5 MiB.');
        }

        $dimensions = @getimagesize($path);

        if (! is_array($dimensions)
            || (int) ($dimensions[2] ?? 0) !== IMAGETYPE_PNG
            || empty($dimensions[0])
            || empty($dimensions[1])) {
            throw new RuntimeException('The signature must be a valid PNG image.');
        }

        $width = (int) $dimensions[0];
        $height = (int) $dimensions[1];

        if ($height <= 0 || $width > intdiv(self::MAX_PIXELS, $height)) {
            throw new RuntimeException('The signature may not exceed 5 megapixels.');
        }

        $image = @imagecreatefrompng($path);

        if (! $image instanceof \GdImage) {
            throw new RuntimeException('The signature PNG could not be decoded.');
        }

        $hasVisiblePixel = false;
        $hasTransparentPixel = false;
        $touchesEdge = false;

        try {
            for ($y = 0; $y < $height; $y++) {
                for ($x = 0; $x < $width; $x++) {
                    $color = imagecolorsforindex($image, imagecolorat($image, $x, $y));
                    $alpha = (int) ($color['alpha'] ?? 0);

                    if ($alpha >= 127) {
                        $hasTransparentPixel = true;

                        continue;
                    }

                    $hasVisiblePixel = true;

                    if ($x === 0 || $y === 0 || $x === ($width - 1) || $y === ($height - 1)) {
                        $touchesEdge = true;
                    }
                }
            }
        } finally {
            imagedestroy($image);
        }

        if (! $hasVisiblePixel) {
            throw new RuntimeException('The signature PNG must contain visible signature pixels.');
        }

        if (! $hasTransparentPixel) {
            throw new RuntimeException('The signature PNG must have a transparent background.');
        }

        if ($touchesEdge) {
            throw new RuntimeException('Leave transparent padding around every edge of the signature.');
        }

        $sha256 = hash_file('sha256', $path);

        if (! is_string($sha256)) {
            throw new RuntimeException('The signature PNG could not be fingerprinted.');
        }

        return [
            'width' => $width,
            'height' => $height,
            'sha256' => $sha256,
        ];
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            self::inspect($value);
        } catch (RuntimeException $exception) {
            $fail($exception->getMessage());
        }
    }

    protected static function absolutePath(mixed $value): string
    {
        if ($value instanceof UploadedFile || $value instanceof SplFileInfo) {
            $path = $value->getRealPath();

            if (! is_string($path) || $path === '' || ! is_file($path) || ! is_readable($path)) {
                throw new RuntimeException('The signature PNG could not be read.');
            }

            return $path;
        }

        if (! is_string($value)
            || ! preg_match(
                '/\Aartwork-signatures\/assets\/[A-Za-z0-9][A-Za-z0-9._-]*\.png\z/Di',
                $value,
            )) {
            throw new RuntimeException('The signature must use managed private signature storage.');
        }

        $disk = Storage::disk('local');

        if (! $disk->exists($value)) {
            throw new RuntimeException('The saved signature PNG is missing.');
        }

        $path = realpath($disk->path($value));
        $root = realpath($disk->path(''));

        if (! is_string($path)
            || ! is_string($root)
            || ! str_starts_with($path, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)
            || ! is_file($path)
            || ! is_readable($path)) {
            throw new RuntimeException('The signature must use managed private signature storage.');
        }

        return $path;
    }
}
