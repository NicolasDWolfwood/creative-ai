<?php

namespace App\Services;

use GdImage;
use RuntimeException;

class ArtworkSignatureRenderer
{
    public const RENDERER_VERSION = 'gd-signature-v1';

    private const MAX_SIGNATURE_PIXELS = 5_000_000;

    private const CONTRAST_HISTOGRAM_SCALE = 1000;

    private const CONTRAST_HISTOGRAM_MAX = 21_000;

    private const LUMINANCE_HISTOGRAM_MAX = 4095;

    /**
     * @param  array<string, mixed>  $recipe
     * @return array{
     *     large_jpeg:string,
     *     display_jpeg:string,
     *     thumb_jpeg:string,
     *     width:int,
     *     height:int,
     *     resolved_tone:string|null,
     *     review_recommended:bool
     * }
     */
    public function render(string $sourceAbsolutePath, array $recipe): array
    {
        $recipe = $this->validateRecipe($sourceAbsolutePath, $recipe);
        $source = null;
        $signatureMask = null;

        try {
            [$source, $width, $height] = $this->loadSource($sourceAbsolutePath);

            if ($this->treatmentNeedsSignature($recipe['treatment'])) {
                $signatureMask = $this->loadSignatureMask($recipe['signature_path']);
            }

            $large = $this->renderRendition(
                $source,
                $signatureMask,
                $recipe,
                $recipe['outputs']['large'],
                resolveTone: true,
            );
            $resolvedTone = $large['resolved_tone'];

            $display = $this->renderRendition(
                $source,
                $signatureMask,
                $recipe,
                $recipe['outputs']['display'],
                resolvedTone: $resolvedTone,
            );
            $thumb = $this->renderRendition(
                $source,
                $signatureMask,
                $recipe,
                $recipe['outputs']['thumb'],
                resolvedTone: $resolvedTone,
            );

            return [
                'large_jpeg' => $large['jpeg'],
                'display_jpeg' => $display['jpeg'],
                'thumb_jpeg' => $thumb['jpeg'],
                'width' => $width,
                'height' => $height,
                'resolved_tone' => $resolvedTone,
                'review_recommended' => $large['review_recommended'],
            ];
        } finally {
            if ($signatureMask instanceof GdImage) {
                imagedestroy($signatureMask);
            }

            if ($source instanceof GdImage) {
                imagedestroy($source);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $recipe
     * @return array<string, mixed>
     */
    private function validateRecipe(string $sourceAbsolutePath, array $recipe): array
    {
        if (($recipe['renderer'] ?? null) !== self::RENDERER_VERSION) {
            throw new RuntimeException('The artwork signature renderer version is unsupported.');
        }

        $this->assertAbsoluteFile($sourceAbsolutePath, 'artwork master');
        $sourceSha256 = $this->validatedSha256($recipe['source_sha256'] ?? null, 'artwork master');
        $this->assertFileHash($sourceAbsolutePath, $sourceSha256, 'artwork master');

        $treatment = (string) ($recipe['treatment'] ?? '');

        if (! in_array($treatment, ['automatic', 'black', 'white', 'embedded', 'none'], true)) {
            throw new RuntimeException('The artwork signature treatment is invalid.');
        }

        $corner = (string) ($recipe['corner'] ?? '');

        if (! in_array($corner, ['top_left', 'top_right', 'bottom_left', 'bottom_right'], true)) {
            throw new RuntimeException('The artwork signature corner is invalid.');
        }

        $scaleBasisPoints = $this->boundedInteger(
            $recipe['scale_bp'] ?? null,
            1,
            10_000,
            'signature scale',
        );
        $insetBasisPoints = $this->boundedInteger(
            $recipe['inset_bp'] ?? null,
            0,
            5_000,
            'signature inset',
        );
        $opacityBasisPoints = $this->boundedInteger(
            $recipe['opacity_bp'] ?? null,
            1,
            10_000,
            'signature opacity',
        );
        $jpegQuality = $this->boundedInteger(
            $recipe['jpeg_quality'] ?? null,
            1,
            100,
            'JPEG quality',
        );
        $matte = $this->validatedMatte($recipe['matte_rgb'] ?? null);
        $outputs = $this->validatedOutputs($recipe['outputs'] ?? null);
        $signaturePath = $recipe['signature_path'] ?? null;
        $signatureSha256 = $recipe['signature_sha256'] ?? null;

        if ($this->treatmentNeedsSignature($treatment)) {
            if (! is_string($signaturePath) || $signaturePath === '') {
                throw new RuntimeException('A private PNG signature asset is required for this treatment.');
            }

            $this->assertAbsoluteFile($signaturePath, 'signature asset');
            $signatureSha256 = $this->validatedSha256($signatureSha256, 'signature asset');
            $this->assertFileHash($signaturePath, $signatureSha256, 'signature asset');
        } else {
            $signaturePath = null;
            $signatureSha256 = null;
        }

        return [
            'renderer' => self::RENDERER_VERSION,
            'source_sha256' => $sourceSha256,
            'signature_path' => $signaturePath,
            'signature_sha256' => $signatureSha256,
            'treatment' => $treatment,
            'corner' => $corner,
            'scale_bp' => $scaleBasisPoints,
            'inset_bp' => $insetBasisPoints,
            'opacity_bp' => $opacityBasisPoints,
            'matte_rgb' => $matte,
            'jpeg_quality' => $jpegQuality,
            'outputs' => $outputs,
        ];
    }

    /**
     * @return array{large:int, display:int, thumb:int}
     */
    private function validatedOutputs(mixed $value): array
    {
        if (! is_array($value)) {
            throw new RuntimeException('Artwork rendition outputs must be an array.');
        }

        $resolved = [];

        if (array_key_exists('large', $value)
            || array_key_exists('display', $value)
            || array_key_exists('thumb', $value)) {
            foreach (['large', 'display', 'thumb'] as $name) {
                $candidate = $value[$name] ?? null;

                if (is_array($candidate)) {
                    $candidate = $candidate['max_long_edge'] ?? null;
                }

                $resolved[$name] = $this->boundedInteger(
                    $candidate,
                    1,
                    16_384,
                    "{$name} rendition size",
                );
            }
        } else {
            foreach ($value as $entry) {
                if (! is_array($entry)) {
                    throw new RuntimeException('Each artwork rendition output must be an array.');
                }

                $name = (string) ($entry['name'] ?? '');

                if (! in_array($name, ['large', 'display', 'thumb'], true) || isset($resolved[$name])) {
                    throw new RuntimeException('Artwork rendition output names must be unique large, display, and thumb entries.');
                }

                $resolved[$name] = $this->boundedInteger(
                    $entry['max_long_edge'] ?? null,
                    1,
                    16_384,
                    "{$name} rendition size",
                );
            }

            if (array_keys($resolved) !== ['large', 'display', 'thumb']) {
                foreach (['large', 'display', 'thumb'] as $name) {
                    if (! array_key_exists($name, $resolved)) {
                        throw new RuntimeException('Artwork rendition outputs must include large, display, and thumb.');
                    }
                }

                $resolved = [
                    'large' => $resolved['large'],
                    'display' => $resolved['display'],
                    'thumb' => $resolved['thumb'],
                ];
            }
        }

        if ($resolved['large'] < $resolved['display'] || $resolved['display'] < $resolved['thumb']) {
            throw new RuntimeException('Artwork rendition sizes must descend from large to display to thumb.');
        }

        return $resolved;
    }

    /** @return array{0:GdImage, 1:int, 2:int} */
    private function loadSource(string $path): array
    {
        [$width, $height, $type] = @getimagesize($path) ?: [null, null, null];

        if (! is_int($width) || ! is_int($height) || ! is_int($type) || $width < 1 || $height < 1) {
            throw new RuntimeException('Unable to inspect the artwork master image.');
        }

        $maximum = (int) config('creative_ai.image_variants.max_source_pixels', 20_000_000);

        if ($maximum > 0 && $width > intdiv($maximum, $height)) {
            throw new RuntimeException('The artwork master exceeds the configured safe pixel limit.');
        }

        $image = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => @imagecreatefromwebp($path),
            default => false,
        };

        if (! $image instanceof GdImage) {
            throw new RuntimeException('Only JPEG, PNG, and WebP artwork masters are supported.');
        }

        return [$image, $width, $height];
    }

    private function loadSignatureMask(string $path): GdImage
    {
        [$width, $height, $type] = @getimagesize($path) ?: [null, null, null];

        if ($type !== IMAGETYPE_PNG || ! is_int($width) || ! is_int($height) || $width < 1 || $height < 1) {
            throw new RuntimeException('The signature asset must be a readable PNG.');
        }

        if ($width > intdiv(self::MAX_SIGNATURE_PIXELS, $height)) {
            throw new RuntimeException('The signature asset exceeds the safe pixel limit.');
        }

        $source = @imagecreatefrompng($path);

        if (! $source instanceof GdImage) {
            throw new RuntimeException('Unable to decode the signature PNG.');
        }

        try {
            if (! imageistruecolor($source) && ! imagepalettetotruecolor($source)) {
                throw new RuntimeException('Unable to normalize the signature PNG.');
            }

            imagealphablending($source, false);
            imagesavealpha($source, true);

            $minimumX = $width;
            $minimumY = $height;
            $maximumX = -1;
            $maximumY = -1;
            $hasVisiblePixel = false;
            $hasFullyTransparentPixel = false;
            $touchesOuterEdge = false;

            for ($y = 0; $y < $height; $y++) {
                for ($x = 0; $x < $width; $x++) {
                    $alpha = $this->alphaAt($source, $x, $y);

                    if ($alpha === 127) {
                        $hasFullyTransparentPixel = true;

                        continue;
                    }

                    $hasVisiblePixel = true;
                    $minimumX = min($minimumX, $x);
                    $minimumY = min($minimumY, $y);
                    $maximumX = max($maximumX, $x);
                    $maximumY = max($maximumY, $y);
                    $touchesOuterEdge = $touchesOuterEdge
                        || $x === 0
                        || $y === 0
                        || $x === $width - 1
                        || $y === $height - 1;
                }
            }

            if (! $hasVisiblePixel) {
                throw new RuntimeException('The signature PNG contains no visible mark.');
            }

            if (! $hasFullyTransparentPixel) {
                throw new RuntimeException('The signature PNG must have a transparent background.');
            }

            if ($touchesOuterEdge) {
                throw new RuntimeException('The signature mark touches the PNG outer edge.');
            }

            $maskWidth = $maximumX - $minimumX + 1;
            $maskHeight = $maximumY - $minimumY + 1;
            $mask = imagecreatetruecolor($maskWidth, $maskHeight);

            if (! $mask instanceof GdImage) {
                throw new RuntimeException('Unable to allocate the normalized signature mask.');
            }

            imagealphablending($mask, false);
            imagesavealpha($mask, true);
            $transparent = imagecolorallocatealpha($mask, 0, 0, 0, 127);
            imagefill($mask, 0, 0, $transparent);
            $alphaColors = [];

            for ($y = 0; $y < $maskHeight; $y++) {
                for ($x = 0; $x < $maskWidth; $x++) {
                    $alpha = $this->alphaAt($source, $minimumX + $x, $minimumY + $y);
                    $alphaColors[$alpha] ??= imagecolorallocatealpha($mask, 0, 0, 0, $alpha);
                    imagesetpixel($mask, $x, $y, $alphaColors[$alpha]);
                }
            }

            return $mask;
        } finally {
            imagedestroy($source);
        }
    }

    /**
     * @param  array<string, mixed>  $recipe
     * @return array{jpeg:string, resolved_tone:string|null, review_recommended:bool}
     */
    private function renderRendition(
        GdImage $source,
        ?GdImage $signatureMask,
        array $recipe,
        int $maximumLongEdge,
        bool $resolveTone = false,
        ?string $resolvedTone = null,
    ): array {
        $canvas = $this->resizedCanvas($source, $maximumLongEdge, $recipe['matte_rgb']);
        $scaledMask = null;
        $reviewRecommended = false;

        try {
            if ($this->treatmentNeedsSignature($recipe['treatment'])) {
                if (! $signatureMask instanceof GdImage) {
                    throw new RuntimeException('The normalized signature mask is unavailable.');
                }

                [$scaledMask, $destinationX, $destinationY] = $this->placedMask(
                    $signatureMask,
                    imagesx($canvas),
                    imagesy($canvas),
                    $recipe,
                );

                if ($resolveTone) {
                    [
                        'tone' => $resolvedTone,
                        'review_recommended' => $reviewRecommended,
                    ] = $this->resolveTone(
                        $canvas,
                        $scaledMask,
                        $destinationX,
                        $destinationY,
                        $recipe['treatment'],
                        $recipe['opacity_bp'],
                    );
                }

                if (! in_array($resolvedTone, ['black', 'white'], true)) {
                    throw new RuntimeException('The signature tone could not be resolved.');
                }

                $this->compositeMask(
                    $canvas,
                    $scaledMask,
                    $destinationX,
                    $destinationY,
                    $resolvedTone,
                    $recipe['opacity_bp'],
                );
            }

            return [
                'jpeg' => $this->encodeJpeg($canvas, $recipe['jpeg_quality']),
                'resolved_tone' => $resolvedTone,
                'review_recommended' => $reviewRecommended,
            ];
        } finally {
            if ($scaledMask instanceof GdImage) {
                imagedestroy($scaledMask);
            }

            imagedestroy($canvas);
        }
    }

    /** @param array{0:int, 1:int, 2:int} $matte */
    private function resizedCanvas(GdImage $source, int $maximumLongEdge, array $matte): GdImage
    {
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $sourceLongEdge = max($sourceWidth, $sourceHeight);
        $targetLongEdge = min($sourceLongEdge, $maximumLongEdge);

        if ($sourceWidth >= $sourceHeight) {
            $targetWidth = $targetLongEdge;
            $targetHeight = max(1, $this->roundPositive($sourceHeight * ($targetLongEdge / $sourceWidth)));
        } else {
            $targetHeight = $targetLongEdge;
            $targetWidth = max(1, $this->roundPositive($sourceWidth * ($targetLongEdge / $sourceHeight)));
        }

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);

        if (! $canvas instanceof GdImage) {
            throw new RuntimeException('Unable to allocate an artwork rendition canvas.');
        }

        imagealphablending($canvas, true);
        $background = imagecolorallocate($canvas, $matte[0], $matte[1], $matte[2]);
        imagefill($canvas, 0, 0, $background);

        if (! imagecopyresampled(
            $canvas,
            $source,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $sourceWidth,
            $sourceHeight,
        )) {
            imagedestroy($canvas);

            throw new RuntimeException('Unable to resize the artwork master.');
        }

        return $canvas;
    }

    /**
     * @param  array<string, mixed>  $recipe
     * @return array{0:GdImage, 1:int, 2:int}
     */
    private function placedMask(GdImage $mask, int $canvasWidth, int $canvasHeight, array $recipe): array
    {
        $maskWidth = imagesx($mask);
        $maskHeight = imagesy($mask);
        $shortEdge = min($canvasWidth, $canvasHeight);
        $desiredLongEdge = max(
            1,
            $this->roundPositive($shortEdge * ($recipe['scale_bp'] / 10_000)),
        );
        $inset = $this->roundPositive($shortEdge * ($recipe['inset_bp'] / 10_000));
        $availableWidth = $canvasWidth - (2 * $inset);
        $availableHeight = $canvasHeight - (2 * $inset);

        if ($availableWidth < 1 || $availableHeight < 1) {
            throw new RuntimeException('The signature inset leaves no usable artwork area.');
        }

        if ($maskWidth >= $maskHeight) {
            $targetWidth = $desiredLongEdge;
            $targetHeight = max(1, $this->roundPositive($maskHeight * ($desiredLongEdge / $maskWidth)));
        } else {
            $targetHeight = $desiredLongEdge;
            $targetWidth = max(1, $this->roundPositive($maskWidth * ($desiredLongEdge / $maskHeight)));
        }

        $fit = min(1, $availableWidth / $targetWidth, $availableHeight / $targetHeight);

        if ($fit < 1) {
            $targetWidth = max(1, (int) floor($targetWidth * $fit));
            $targetHeight = max(1, (int) floor($targetHeight * $fit));
        }

        $scaled = imagecreatetruecolor($targetWidth, $targetHeight);

        if (! $scaled instanceof GdImage) {
            throw new RuntimeException('Unable to allocate a scaled signature mask.');
        }

        imagealphablending($scaled, false);
        imagesavealpha($scaled, true);
        $transparent = imagecolorallocatealpha($scaled, 0, 0, 0, 127);
        imagefill($scaled, 0, 0, $transparent);

        if (! imagecopyresampled(
            $scaled,
            $mask,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $maskWidth,
            $maskHeight,
        )) {
            imagedestroy($scaled);

            throw new RuntimeException('Unable to resize the signature mask.');
        }

        [$destinationX, $destinationY] = match ($recipe['corner']) {
            'top_left' => [$inset, $inset],
            'top_right' => [$canvasWidth - $inset - $targetWidth, $inset],
            'bottom_left' => [$inset, $canvasHeight - $inset - $targetHeight],
            'bottom_right' => [
                $canvasWidth - $inset - $targetWidth,
                $canvasHeight - $inset - $targetHeight,
            ],
        };

        return [$scaled, $destinationX, $destinationY];
    }

    /**
     * @return array{tone:string, review_recommended:bool}
     */
    private function resolveTone(
        GdImage $canvas,
        GdImage $mask,
        int $destinationX,
        int $destinationY,
        string $treatment,
        int $opacityBasisPoints,
    ): array {
        $blackHistogram = array_fill(0, self::CONTRAST_HISTOGRAM_MAX + 1, 0);
        $whiteHistogram = array_fill(0, self::CONTRAST_HISTOGRAM_MAX + 1, 0);
        $luminanceHistogram = array_fill(0, self::LUMINANCE_HISTOGRAM_MAX + 1, 0);
        $totalWeight = 0;
        $blackWeightedSum = 0.0;
        $whiteWeightedSum = 0.0;

        for ($y = 0; $y < imagesy($mask); $y++) {
            for ($x = 0; $x < imagesx($mask); $x++) {
                $weight = 127 - $this->alphaAt($mask, $x, $y);

                if ($weight < 1) {
                    continue;
                }

                [$red, $green, $blue] = $this->rgbAt(
                    $canvas,
                    $destinationX + $x,
                    $destinationY + $y,
                );
                $backgroundLuminance = $this->relativeLuminance($red, $green, $blue);
                $blackContrast = $this->candidateContrast(
                    $red,
                    $green,
                    $blue,
                    0,
                    $opacityBasisPoints,
                    $backgroundLuminance,
                );
                $whiteContrast = $this->candidateContrast(
                    $red,
                    $green,
                    $blue,
                    255,
                    $opacityBasisPoints,
                    $backgroundLuminance,
                );
                $blackBin = min(
                    self::CONTRAST_HISTOGRAM_MAX,
                    $this->roundPositive($blackContrast * self::CONTRAST_HISTOGRAM_SCALE),
                );
                $whiteBin = min(
                    self::CONTRAST_HISTOGRAM_MAX,
                    $this->roundPositive($whiteContrast * self::CONTRAST_HISTOGRAM_SCALE),
                );
                $luminanceBin = min(
                    self::LUMINANCE_HISTOGRAM_MAX,
                    $this->roundPositive($backgroundLuminance * self::LUMINANCE_HISTOGRAM_MAX),
                );

                $blackHistogram[$blackBin] += $weight;
                $whiteHistogram[$whiteBin] += $weight;
                $luminanceHistogram[$luminanceBin] += $weight;
                $totalWeight += $weight;
                $blackWeightedSum += $blackContrast * $weight;
                $whiteWeightedSum += $whiteContrast * $weight;
            }
        }

        if ($totalWeight < 1) {
            throw new RuntimeException('The scaled signature mask contains no visible mark.');
        }

        $blackLowContrast = $this->weightedPercentile(
            $blackHistogram,
            $totalWeight,
            0.20,
        ) / self::CONTRAST_HISTOGRAM_SCALE;
        $whiteLowContrast = $this->weightedPercentile(
            $whiteHistogram,
            $totalWeight,
            0.20,
        ) / self::CONTRAST_HISTOGRAM_SCALE;
        $blackMean = $blackWeightedSum / $totalWeight;
        $whiteMean = $whiteWeightedSum / $totalWeight;

        $tone = match ($treatment) {
            'black' => 'black',
            'white' => 'white',
            default => $this->automaticTone(
                $blackLowContrast,
                $whiteLowContrast,
                $blackMean,
                $whiteMean,
            ),
        };
        $selectedLowContrast = $tone === 'black' ? $blackLowContrast : $whiteLowContrast;
        $luminanceLow = $this->weightedPercentile(
            $luminanceHistogram,
            $totalWeight,
            0.10,
        ) / self::LUMINANCE_HISTOGRAM_MAX;
        $luminanceHigh = $this->weightedPercentile(
            $luminanceHistogram,
            $totalWeight,
            0.90,
        ) / self::LUMINANCE_HISTOGRAM_MAX;

        return [
            'tone' => $tone,
            'review_recommended' => $selectedLowContrast < 3.0
                || ($luminanceHigh - $luminanceLow) > 0.55,
        ];
    }

    private function automaticTone(
        float $blackLowContrast,
        float $whiteLowContrast,
        float $blackMean,
        float $whiteMean,
    ): string {
        if ($blackLowContrast > $whiteLowContrast) {
            return 'black';
        }

        if ($whiteLowContrast > $blackLowContrast) {
            return 'white';
        }

        return $whiteMean > $blackMean ? 'white' : 'black';
    }

    private function compositeMask(
        GdImage $canvas,
        GdImage $mask,
        int $destinationX,
        int $destinationY,
        string $tone,
        int $opacityBasisPoints,
    ): void {
        $toneChannel = $tone === 'white' ? 255 : 0;
        $denominator = 127 * 10_000;
        $halfDenominator = intdiv($denominator, 2);

        for ($y = 0; $y < imagesy($mask); $y++) {
            for ($x = 0; $x < imagesx($mask); $x++) {
                $coverage = 127 - $this->alphaAt($mask, $x, $y);

                if ($coverage < 1) {
                    continue;
                }

                $effectiveAlpha = $coverage * $opacityBasisPoints;
                [$red, $green, $blue] = $this->rgbAt(
                    $canvas,
                    $destinationX + $x,
                    $destinationY + $y,
                );
                $red = intdiv(
                    ($red * ($denominator - $effectiveAlpha))
                    + ($toneChannel * $effectiveAlpha)
                    + $halfDenominator,
                    $denominator,
                );
                $green = intdiv(
                    ($green * ($denominator - $effectiveAlpha))
                    + ($toneChannel * $effectiveAlpha)
                    + $halfDenominator,
                    $denominator,
                );
                $blue = intdiv(
                    ($blue * ($denominator - $effectiveAlpha))
                    + ($toneChannel * $effectiveAlpha)
                    + $halfDenominator,
                    $denominator,
                );
                imagesetpixel(
                    $canvas,
                    $destinationX + $x,
                    $destinationY + $y,
                    ($red << 16) | ($green << 8) | $blue,
                );
            }
        }
    }

    private function candidateContrast(
        int $red,
        int $green,
        int $blue,
        int $toneChannel,
        int $opacityBasisPoints,
        float $backgroundLuminance,
    ): float {
        $inverseOpacity = 10_000 - $opacityBasisPoints;
        $candidateRed = intdiv(($red * $inverseOpacity) + ($toneChannel * $opacityBasisPoints) + 5000, 10_000);
        $candidateGreen = intdiv(($green * $inverseOpacity) + ($toneChannel * $opacityBasisPoints) + 5000, 10_000);
        $candidateBlue = intdiv(($blue * $inverseOpacity) + ($toneChannel * $opacityBasisPoints) + 5000, 10_000);
        $candidateLuminance = $this->relativeLuminance(
            $candidateRed,
            $candidateGreen,
            $candidateBlue,
        );

        return (max($backgroundLuminance, $candidateLuminance) + 0.05)
            / (min($backgroundLuminance, $candidateLuminance) + 0.05);
    }

    private function relativeLuminance(int $red, int $green, int $blue): float
    {
        return (0.2126 * $this->linearChannel($red))
            + (0.7152 * $this->linearChannel($green))
            + (0.0722 * $this->linearChannel($blue));
    }

    private function linearChannel(int $channel): float
    {
        $value = $channel / 255;

        return $value <= 0.04045
            ? $value / 12.92
            : (($value + 0.055) / 1.055) ** 2.4;
    }

    /** @param array<int, int> $histogram */
    private function weightedPercentile(array $histogram, int $totalWeight, float $percentile): int
    {
        $target = max(1, (int) ceil($totalWeight * $percentile));
        $cumulative = 0;

        foreach ($histogram as $value => $weight) {
            $cumulative += $weight;

            if ($cumulative >= $target) {
                return $value;
            }
        }

        return array_key_last($histogram);
    }

    private function encodeJpeg(GdImage $canvas, int $quality): string
    {
        ob_start();

        try {
            $encoded = imagejpeg($canvas, null, $quality);
            $jpeg = ob_get_clean();
        } catch (\Throwable $exception) {
            ob_end_clean();

            throw $exception;
        }

        if (! $encoded || ! is_string($jpeg)) {
            throw new RuntimeException('Unable to encode an artwork rendition as JPEG.');
        }

        return $jpeg;
    }

    private function treatmentNeedsSignature(string $treatment): bool
    {
        return in_array($treatment, ['automatic', 'black', 'white'], true);
    }

    private function assertAbsoluteFile(string $path, string $label): void
    {
        if (! str_starts_with($path, DIRECTORY_SEPARATOR) || ! is_file($path)) {
            throw new RuntimeException("The {$label} file is unavailable.");
        }
    }

    private function assertFileHash(string $path, string $expectedSha256, string $label): void
    {
        $actualSha256 = @hash_file('sha256', $path);

        if (! is_string($actualSha256) || ! hash_equals($expectedSha256, $actualSha256)) {
            throw new RuntimeException("The {$label} checksum does not match its queued recipe.");
        }
    }

    private function validatedSha256(mixed $value, string $label): string
    {
        $sha256 = strtolower((string) $value);

        if (! preg_match('/^[a-f0-9]{64}$/', $sha256)) {
            throw new RuntimeException("The {$label} checksum is invalid.");
        }

        return $sha256;
    }

    /** @return array{0:int, 1:int, 2:int} */
    private function validatedMatte(mixed $value): array
    {
        if (! is_array($value) || count($value) !== 3 || array_keys($value) !== [0, 1, 2]) {
            throw new RuntimeException('The artwork rendition matte must contain three RGB channels.');
        }

        return [
            $this->boundedInteger($value[0], 0, 255, 'matte red channel'),
            $this->boundedInteger($value[1], 0, 255, 'matte green channel'),
            $this->boundedInteger($value[2], 0, 255, 'matte blue channel'),
        ];
    }

    private function boundedInteger(mixed $value, int $minimum, int $maximum, string $label): int
    {
        if (is_int($value)) {
            $integer = $value;
        } elseif (is_string($value) && preg_match('/^-?\d+$/', $value)) {
            $integer = (int) $value;
        } else {
            throw new RuntimeException("The {$label} must be an integer.");
        }

        if ($integer < $minimum || $integer > $maximum) {
            throw new RuntimeException("The {$label} is outside the supported range.");
        }

        return $integer;
    }

    private function alphaAt(GdImage $image, int $x, int $y): int
    {
        return (imagecolorat($image, $x, $y) >> 24) & 0x7F;
    }

    /** @return array{0:int, 1:int, 2:int} */
    private function rgbAt(GdImage $image, int $x, int $y): array
    {
        $color = imagecolorat($image, $x, $y);

        return [
            ($color >> 16) & 0xFF,
            ($color >> 8) & 0xFF,
            $color & 0xFF,
        ];
    }

    private function roundPositive(float $value): int
    {
        return (int) floor($value + 0.5);
    }
}
