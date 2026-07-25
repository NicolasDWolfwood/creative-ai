<?php

namespace Tests\Feature;

use App\Jobs\GenerateArtworkVariants;
use App\Models\Artwork;
use App\Models\User;
use App\Services\ArtworkMediaCleanupService;
use App\Services\ArtworkSignatureSettings;
use App\Services\ImageVariantService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class ArtworkSignaturePipelineTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh');
        Storage::fake('local');
        Storage::fake('public');
        Queue::fake();
    }

    public function test_automatic_pipeline_keeps_master_private_and_atomically_activates_three_signed_renditions(): void
    {
        $this->configureSignature();
        $masterPath = $this->storeImage('portrait.jpg', 1000, 3000);
        $masterHash = hash_file('sha256', Storage::disk('local')->path($masterPath));
        $artwork = Artwork::query()->create([
            'title' => 'Private Portrait',
            'master_path' => $masterPath,
            'signature_mode' => Artwork::SIGNATURE_MODE_AUTOMATIC,
            'published' => true,
            'published_at' => now(),
        ]);

        $this->assertNull($artwork->image_path);
        $this->assertFalse($artwork->hasPublicRenditions());
        $this->assertFalse(Artwork::query()->publiclyAvailable()->whereKey($artwork)->exists());
        $this->get(route('artworks.image', $artwork))->assertNotFound();
        $this->get(route('media.artworks.show', [$artwork, 'variant' => 'master']))->assertNotFound();

        $administrator = User::factory()->admin()->create();
        $this->actingAs($administrator)
            ->get(route('media.artworks.show', [$artwork, 'variant' => 'master']))
            ->assertOk()
            ->assertHeader('Cache-Control');

        $job = $this->queuedGenerationFor($masterPath);
        $this->runGeneration($job);
        $artwork->refresh();

        $this->assertSame(Artwork::VARIANT_STATUS_READY, $artwork->variant_status);
        $this->assertSame('signed', $artwork->signature_active_treatment);
        $this->assertContains($artwork->signature_resolved_tone, ['black', 'white']);
        $this->assertSame(
            app(ArtworkSignatureSettings::class)->revision(),
            $artwork->signature_settings_revision,
        );
        $this->assertTrue($artwork->hasPublicRenditions());
        $this->assertTrue(Artwork::query()->publiclyAvailable()->whereKey($artwork)->exists());
        $this->assertTrue(Artwork::query()->homepageHeroEligible()->whereKey($artwork)->exists());
        Storage::disk('local')->assertExists([
            $masterPath,
            $artwork->image_path,
            $artwork->display_path,
            $artwork->thumb_path,
        ]);
        $this->assertSame($masterHash, hash_file('sha256', Storage::disk('local')->path($masterPath)));
        $this->assertSame([853, 2560], array_slice(
            getimagesize(Storage::disk('local')->path($artwork->image_path)),
            0,
            2,
        ));
        $this->assertSame([533, 1600], array_slice(
            getimagesize(Storage::disk('local')->path($artwork->display_path)),
            0,
            2,
        ));
        $this->assertSame([240, 720], array_slice(
            getimagesize(Storage::disk('local')->path($artwork->thumb_path)),
            0,
            2,
        ));

        auth()->logout();
        $this->get(route('artworks.image', $artwork))->assertOk();
        $this->get(route('media.artworks.show', [$artwork, 'variant' => 'master']))->assertNotFound();
    }

    public function test_failed_refresh_retains_previous_signed_set_and_never_deletes_it(): void
    {
        $this->configureSignature();
        $masterPath = $this->storeImage('refresh.jpg', 900, 600);
        $artwork = Artwork::query()->create([
            'title' => 'Refresh Safely',
            'master_path' => $masterPath,
            'signature_mode' => Artwork::SIGNATURE_MODE_AUTOMATIC,
            'published' => true,
        ]);
        $this->runGeneration($this->queuedGenerationFor($masterPath));
        $artwork->refresh();
        $activePaths = [$artwork->image_path, $artwork->display_path, $artwork->thumb_path];

        $job = GenerateArtworkVariants::prepareFor($artwork);
        $this->assertInstanceOf(GenerateArtworkVariants::class, $job);
        $job->recipe['signature_sha256'] = str_repeat('0', 64);

        try {
            $this->runGeneration($job);
            $this->fail('A mismatched signature hash must fail.');
        } catch (RuntimeException $exception) {
            $job->failed($exception);
        }

        $artwork->refresh();
        $this->assertSame(Artwork::VARIANT_STATUS_FAILED, $artwork->variant_status);
        $this->assertSame($activePaths, [
            $artwork->image_path,
            $artwork->display_path,
            $artwork->thumb_path,
        ]);
        $this->assertTrue($artwork->hasPublicRenditions());
        Storage::disk('local')->assertExists($activePaths);
        $this->get(route('artworks.image', $artwork))->assertOk();
    }

    public function test_unsigned_rendition_is_hidden_immediately_when_signature_becomes_required(): void
    {
        $this->configureSignature();
        $masterPath = $this->storeImage('unsigned.jpg', 800, 600);
        $artwork = Artwork::query()->create([
            'title' => 'Intentional Unsigned',
            'master_path' => $masterPath,
            'signature_mode' => Artwork::SIGNATURE_MODE_NONE,
            'published' => true,
        ]);
        $this->runGeneration($this->queuedGenerationFor($masterPath));
        $artwork->refresh();

        $this->assertSame('unsigned', $artwork->signature_active_treatment);
        $this->assertTrue($artwork->hasPublicRenditions());
        $this->get(route('artworks.image', $artwork))->assertOk();

        $artwork->signature_mode = Artwork::SIGNATURE_MODE_AUTOMATIC;
        $artwork->save();
        $artwork->refresh();

        $this->assertSame('unsigned', $artwork->signature_active_treatment);
        $this->assertFalse($artwork->hasPublicRenditions());
        $this->assertFalse(Artwork::query()->publiclyAvailable()->whereKey($artwork)->exists());
        $this->get(route('artworks.image', $artwork))->assertNotFound();
    }

    public function test_same_artwork_lock_contention_has_enough_retries_for_the_newest_job_to_claim(): void
    {
        $first = new GenerateArtworkVariants(42, 'artworks/masters/first.jpg', 'first-token');
        $newest = new GenerateArtworkVariants(42, 'artworks/masters/current.jpg', 'current-token');
        $firstMiddleware = $first->middleware()[0];
        $newestMiddleware = $newest->middleware()[0];
        $lockKey = $firstMiddleware->getLockKey($first);

        $this->assertSame($lockKey, $newestMiddleware->getLockKey($newest));
        $this->assertGreaterThan(
            (int) ceil($newestMiddleware->expiresAfter / $newestMiddleware->releaseAfter),
            $newest->tries,
        );
        $this->assertSame(2, $newest->maxExceptions);

        $lock = Cache::lock($lockKey, $firstMiddleware->expiresAfter);
        $this->assertTrue($lock->get());
        $handled = false;

        try {
            $newestMiddleware->handle($newest, function () use (&$handled): void {
                $handled = true;
            });
            $this->assertFalse($handled);
        } finally {
            $lock->release();
        }

        $newestMiddleware->handle($newest, function () use (&$handled): void {
            $handled = true;
        });
        $this->assertTrue($handled);
    }

    protected function configureSignature(): void
    {
        $path = 'artwork-signatures/assets/test-mask.png';
        $image = imagecreatetruecolor(80, 100);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        $ink = imagecolorallocatealpha($image, 240, 40, 120, 0);
        imagefilledellipse($image, 40, 45, 18, 64, $ink);
        imageline($image, 28, 78, 55, 18, $ink);
        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);
        $this->assertIsString($png);
        Storage::disk('local')->put($path, $png);

        app(ArtworkSignatureSettings::class)->save([
            'asset_path' => $path,
            'default_mode' => Artwork::SIGNATURE_MODE_AUTOMATIC,
            'default_position' => 'bottom_right',
            'scale_bp' => 1200,
            'inset_bp' => 300,
            'opacity_bp' => 10000,
        ]);
    }

    protected function storeImage(string $filename, int $width, int $height): string
    {
        $path = 'artworks/masters/'.$filename;
        Storage::disk('local')->put(
            $path,
            UploadedFile::fake()->image($filename, $width, $height)->getContent(),
        );

        return $path;
    }

    protected function queuedGenerationFor(string $sourcePath): GenerateArtworkVariants
    {
        $queuedJob = null;
        Queue::assertPushed(
            GenerateArtworkVariants::class,
            function (GenerateArtworkVariants $job) use ($sourcePath, &$queuedJob): bool {
                if ($job->sourcePath !== $sourcePath) {
                    return false;
                }

                $queuedJob = $job;

                return true;
            },
        );
        $this->assertInstanceOf(GenerateArtworkVariants::class, $queuedJob);

        return $queuedJob;
    }

    protected function runGeneration(GenerateArtworkVariants $job): void
    {
        $job->handle(
            app(ImageVariantService::class),
            app(ArtworkMediaCleanupService::class),
        );
    }
}
