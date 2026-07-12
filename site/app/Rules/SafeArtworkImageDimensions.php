<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use SplFileInfo;

class SafeArtworkImageDimensions implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof SplFileInfo) {
            return;
        }

        $path = $value->getRealPath();
        $dimensions = $path ? @getimagesize($path) : false;

        if (! $dimensions || empty($dimensions[0]) || empty($dimensions[1])) {
            return;
        }

        $maximum = (int) config('creative_ai.image_variants.max_source_pixels', 20_000_000);
        $width = (int) $dimensions[0];
        $height = (int) $dimensions[1];

        if ($maximum <= 0 || $width <= intdiv($maximum, $height)) {
            return;
        }

        $megapixels = rtrim(rtrim(number_format($maximum / 1_000_000, 1), '0'), '.');
        $fail("The :attribute may not exceed {$megapixels} megapixels.");
    }
}
