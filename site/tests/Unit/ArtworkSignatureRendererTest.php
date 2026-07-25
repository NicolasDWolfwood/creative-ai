<?php

namespace Tests\Unit;

use App\Services\ArtworkSignatureRenderer;
use GdImage;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class ArtworkSignatureRendererTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_automatic_tone_uses_black_on_light_artwork_for_every_rendition(): void
    {
        $source = $this->storeSource('light.png', 100, 100, [255, 255, 255]);
        $signature = $this->storeSignature('signature.png');
        $sourceHash = hash_file('sha256', $source);

        $rendered = app(ArtworkSignatureRenderer::class)->render(
            $source,
            $this->recipe($source, $signature),
        );

        $this->assertSame('black', $rendered['resolved_tone']);
        $this->assertFalse($rendered['review_recommended']);
        $this->assertSame($sourceHash, hash_file('sha256', $source));

        foreach ([
            'large_jpeg' => [100, 80, 80],
            'display_jpeg' => [60, 48, 48],
            'thumb_jpeg' => [30, 24, 24],
        ] as $key => [$size, $sampleX, $sampleY]) {
            $this->assertSame([$size, $size], $this->jpegDimensions($rendered[$key]));
            [$red, $green, $blue] = $this->jpegPixel($rendered[$key], $sampleX, $sampleY);

            $this->assertLessThan(55, $red);
            $this->assertLessThan(55, $green);
            $this->assertLessThan(55, $blue);
        }
    }

    public function test_automatic_tone_uses_white_on_dark_artwork_for_every_rendition(): void
    {
        $source = $this->storeSource('dark.png', 100, 100, [0, 0, 0]);
        $signature = $this->storeSignature('signature.png');

        $rendered = app(ArtworkSignatureRenderer::class)->render(
            $source,
            $this->recipe($source, $signature),
        );

        $this->assertSame('white', $rendered['resolved_tone']);
        $this->assertFalse($rendered['review_recommended']);

        foreach ([
            'large_jpeg' => [80, 80],
            'display_jpeg' => [48, 48],
            'thumb_jpeg' => [24, 24],
        ] as $key => [$sampleX, $sampleY]) {
            [$red, $green, $blue] = $this->jpegPixel($rendered[$key], $sampleX, $sampleY);

            $this->assertGreaterThan(210, $red);
            $this->assertGreaterThan(210, $green);
            $this->assertGreaterThan(210, $blue);
        }
    }

    public function test_alpha_mask_ignores_input_rgb_preserves_holes_and_applies_opacity(): void
    {
        $source = $this->storeSource('opacity.png', 64, 64, [255, 255, 255]);
        $signature = $this->storeSignature('signature-with-hole.png', withHole: true);
        $recipe = $this->recipe($source, $signature, [
            'treatment' => 'black',
            'scale_bp' => 2500,
            'inset_bp' => 1250,
            'opacity_bp' => 5000,
            'outputs' => [
                'large' => 64,
                'display' => 64,
                'thumb' => 64,
            ],
        ]);

        $rendered = app(ArtworkSignatureRenderer::class)->render($source, $recipe);
        [$solidRed, $solidGreen, $solidBlue] = $this->jpegPixel($rendered['large_jpeg'], 42, 42);
        [$holeRed, $holeGreen, $holeBlue] = $this->jpegPixel($rendered['large_jpeg'], 48, 48);
        [$outsideRed, $outsideGreen, $outsideBlue] = $this->jpegPixel($rendered['large_jpeg'], 20, 20);

        $this->assertSame('black', $rendered['resolved_tone']);
        $this->assertGreaterThan(90, $solidRed);
        $this->assertLessThan(170, $solidRed);
        $this->assertLessThan(18, max($solidRed, $solidGreen, $solidBlue) - min($solidRed, $solidGreen, $solidBlue));
        $this->assertGreaterThan(215, min($holeRed, $holeGreen, $holeBlue));
        $this->assertGreaterThan(240, min($outsideRed, $outsideGreen, $outsideBlue));
    }

    public function test_mixed_signature_region_is_deterministic_and_requests_review(): void
    {
        $source = $this->storeSource(
            'mixed.png',
            100,
            100,
            [255, 255, 255],
            function (GdImage $image): void {
                $black = imagecolorallocate($image, 0, 0, 0);
                imagefilledrectangle($image, 80, 70, 89, 89, $black);
            },
        );
        $signature = $this->storeSignature('signature.png');
        $recipe = $this->recipe($source, $signature, [
            'outputs' => [
                'large' => 100,
                'display' => 100,
                'thumb' => 100,
            ],
        ]);

        $rendered = app(ArtworkSignatureRenderer::class)->render($source, $recipe);

        $this->assertSame('black', $rendered['resolved_tone']);
        $this->assertTrue($rendered['review_recommended']);
    }

    public function test_none_treatment_resizes_by_longest_edge_without_requiring_a_signature(): void
    {
        $source = $this->storeSource('portrait.png', 40, 120, [90, 120, 150]);
        $sourceHash = hash_file('sha256', $source);
        $recipe = $this->recipe($source, null, [
            'treatment' => 'none',
            'outputs' => [
                ['name' => 'thumb', 'max_long_edge' => 30],
                ['name' => 'large', 'max_long_edge' => 80],
                ['name' => 'display', 'max_long_edge' => 60],
            ],
        ]);

        $rendered = app(ArtworkSignatureRenderer::class)->render($source, $recipe);

        $this->assertSame([40, 120], [$rendered['width'], $rendered['height']]);
        $this->assertNull($rendered['resolved_tone']);
        $this->assertFalse($rendered['review_recommended']);
        $this->assertSame([27, 80], $this->jpegDimensions($rendered['large_jpeg']));
        $this->assertSame([20, 60], $this->jpegDimensions($rendered['display_jpeg']));
        $this->assertSame([10, 30], $this->jpegDimensions($rendered['thumb_jpeg']));
        $this->assertSame($sourceHash, hash_file('sha256', $source));
    }

    public function test_invalid_signature_alpha_contracts_are_rejected(): void
    {
        $source = $this->storeSource('source.png', 100, 100, [255, 255, 255]);
        $invalid = [
            [$this->storeSignature('empty.png', empty: true), 'no visible mark'],
            [$this->storeSignature('opaque.png', opaque: true), 'transparent background'],
            [$this->storeSignature('edge.png', touchesEdge: true), 'outer edge'],
        ];

        foreach ($invalid as [$signature, $message]) {
            try {
                app(ArtworkSignatureRenderer::class)->render(
                    $source,
                    $this->recipe($source, $signature),
                );
                $this->fail("Expected invalid signature failure containing: {$message}");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString($message, $exception->getMessage());
            }
        }
    }

    public function test_source_and_signature_checksums_are_verified_before_rendering(): void
    {
        $source = $this->storeSource('source.png', 100, 100, [255, 255, 255]);
        $signature = $this->storeSignature('signature.png');
        $recipe = $this->recipe($source, $signature);
        $recipe['source_sha256'] = str_repeat('0', 64);

        try {
            app(ArtworkSignatureRenderer::class)->render($source, $recipe);
            $this->fail('Expected the source checksum mismatch to fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('master checksum', $exception->getMessage());
        }

        $recipe = $this->recipe($source, $signature);
        $recipe['signature_sha256'] = str_repeat('f', 64);

        try {
            app(ArtworkSignatureRenderer::class)->render($source, $recipe);
            $this->fail('Expected the signature checksum mismatch to fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('signature asset checksum', $exception->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function recipe(string $source, ?string $signature, array $overrides = []): array
    {
        return array_replace([
            'renderer' => ArtworkSignatureRenderer::RENDERER_VERSION,
            'source_sha256' => hash_file('sha256', $source),
            'signature_path' => $signature,
            'signature_sha256' => $signature ? hash_file('sha256', $signature) : null,
            'treatment' => $signature ? 'automatic' : 'none',
            'corner' => 'bottom_right',
            'scale_bp' => 2000,
            'inset_bp' => 1000,
            'opacity_bp' => 10_000,
            'matte_rgb' => [12, 13, 16],
            'jpeg_quality' => 100,
            'outputs' => [
                'large' => 100,
                'display' => 60,
                'thumb' => 30,
            ],
        ], $overrides);
    }

    /**
     * @param  array{0:int, 1:int, 2:int}  $rgb
     */
    private function storeSource(
        string $filename,
        int $width,
        int $height,
        array $rgb,
        ?callable $decorate = null,
    ): string {
        $image = imagecreatetruecolor($width, $height);
        $background = imagecolorallocate($image, $rgb[0], $rgb[1], $rgb[2]);
        imagefill($image, 0, 0, $background);
        $decorate?->__invoke($image);
        $path = $this->storePng($filename, $image);
        imagedestroy($image);

        return $path;
    }

    private function storeSignature(
        string $filename,
        bool $withHole = false,
        bool $touchesEdge = false,
        bool $opaque = false,
        bool $empty = false,
    ): string {
        $image = imagecreatetruecolor(12, 12);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefill($image, 0, 0, $transparent);

        if ($opaque) {
            $magenta = imagecolorallocatealpha($image, 255, 0, 180, 0);
            imagefill($image, 0, 0, $magenta);
        } elseif (! $empty) {
            $magenta = imagecolorallocatealpha($image, 255, 0, 180, 0);
            $left = $touchesEdge ? 0 : 2;
            imagefilledrectangle($image, $left, 2, 9, 9, $magenta);

            if ($withHole) {
                imagefilledrectangle($image, 4, 4, 7, 7, $transparent);
            }
        }

        $path = $this->storePng($filename, $image);
        imagedestroy($image);

        return $path;
    }

    private function storePng(string $filename, GdImage $image): string
    {
        ob_start();
        imagepng($image);
        $contents = ob_get_clean();
        $this->assertIsString($contents);
        $relativePath = 'signature-renderer/'.$filename;
        Storage::disk('local')->put($relativePath, $contents);

        return Storage::disk('local')->path($relativePath);
    }

    /** @return array{0:int, 1:int} */
    private function jpegDimensions(string $jpeg): array
    {
        $dimensions = getimagesizefromstring($jpeg);
        $this->assertIsArray($dimensions);

        return [(int) $dimensions[0], (int) $dimensions[1]];
    }

    /** @return array{0:int, 1:int, 2:int} */
    private function jpegPixel(string $jpeg, int $x, int $y): array
    {
        $image = imagecreatefromstring($jpeg);
        $this->assertInstanceOf(GdImage::class, $image);
        $color = imagecolorat($image, $x, $y);
        imagedestroy($image);

        return [
            ($color >> 16) & 0xFF,
            ($color >> 8) & 0xFF,
            $color & 0xFF,
        ];
    }
}
