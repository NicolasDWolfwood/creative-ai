<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeArtworkWithAi;
use App\Jobs\DeleteArtworkMedia;
use App\Jobs\GenerateArtworkVariants;
use App\Models\Artwork;
use App\Rules\SafeArtworkImageDimensions;
use App\Services\AiSettings;
use App\Services\ArtworkMediaCleanupService;
use App\Services\ImageVariantService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ArtworkImageVariantsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh');
        Storage::fake('local');
    }

    public function test_create_queues_aspect_preserving_variants_and_falls_back_to_original_until_ready(): void
    {
        Storage::fake('public');
        Queue::fake();
        $sourcePath = $this->storeImage('landscape.jpg', 1200, 800);

        $artwork = Artwork::query()->create([
            'title' => 'Landscape',
            'image_path' => $sourcePath,
            'published' => true,
        ]);

        $this->assertSame(Artwork::VARIANT_STATUS_QUEUED, $artwork->refresh()->variant_status);
        $this->assertNull($artwork->display_path);
        $this->assertNull($artwork->thumb_path);
        $this->assertTrue($artwork->hasAvailableImage());
        $this->assertStringContainsString(route('media.artworks.show', [$artwork, 'variant' => 'thumb']), $artwork->thumb_url);

        $job = $this->queuedGenerationFor($sourcePath);
        $this->runGeneration($job);
        $artwork->refresh();

        $this->assertSame(Artwork::VARIANT_STATUS_READY, $artwork->variant_status);
        $this->assertNotNull($artwork->variants_generated_at);
        Storage::disk('local')->assertExists([$artwork->display_path, $artwork->thumb_path]);
        $this->assertSame([1200, 800], array_slice(getimagesize(Storage::disk('local')->path($artwork->display_path)), 0, 2));
        $this->assertSame([720, 480], array_slice(getimagesize(Storage::disk('local')->path($artwork->thumb_path)), 0, 2));
        $this->assertSame(1200, $artwork->width);
        $this->assertSame(800, $artwork->height);
    }

    public function test_replacement_clears_paths_stale_job_cannot_overwrite_and_old_files_are_removed(): void
    {
        Storage::fake('public');
        Queue::fake();
        $oldSource = $this->storeImage('old.jpg', 900, 600);
        $artwork = Artwork::query()->create(['title' => 'Replace Me', 'image_path' => $oldSource]);
        $oldJob = $this->queuedGenerationFor($oldSource);
        $this->runGeneration($oldJob);
        $artwork->refresh();
        $oldDisplay = $artwork->display_path;
        $oldThumb = $artwork->thumb_path;
        $newSource = $this->storeImage('new.jpg', 600, 900);

        $artwork->image_path = $newSource;
        $artwork->save();
        $artwork->refresh();

        $this->assertNull($artwork->display_path);
        $this->assertNull($artwork->thumb_path);
        $this->assertStringContainsString(route('media.artworks.show', [$artwork, 'variant' => 'thumb']), $artwork->thumb_url);

        $this->runGeneration($oldJob);
        $this->assertSame(Artwork::VARIANT_STATUS_QUEUED, $artwork->refresh()->variant_status);
        $this->assertNull($artwork->display_path);

        $newJob = $this->queuedGenerationFor($newSource);
        $this->runGeneration($newJob);
        $artwork->refresh();

        $this->assertSame(Artwork::VARIANT_STATUS_READY, $artwork->variant_status);
        $this->assertNotSame($oldDisplay, $artwork->display_path);
        Storage::disk('local')->assertMissing([$oldSource, $oldDisplay, $oldThumb]);
        Storage::disk('local')->assertExists([$newSource, $artwork->display_path, $artwork->thumb_path]);
    }

    public function test_failed_generation_records_error_and_uses_original_fallback(): void
    {
        Storage::fake('public');
        Queue::fake();
        $sourcePath = 'artworks/originals/corrupt.jpg';
        Storage::disk('local')->put($sourcePath, 'not an image');
        $artwork = $this->createWithoutObservers($sourcePath, [
            'variant_status' => Artwork::VARIANT_STATUS_QUEUED,
        ]);
        $job = GenerateArtworkVariants::prepareFor($artwork, analyzeAfterGeneration: true);
        $this->assertInstanceOf(GenerateArtworkVariants::class, $job);

        try {
            $this->runGeneration($job);
            $this->fail('Corrupt source should fail variant generation.');
        } catch (RuntimeException $exception) {
            $job->failed($exception);
        }

        $artwork->refresh();
        $this->assertSame(Artwork::VARIANT_STATUS_FAILED, $artwork->variant_status);
        $this->assertSame($job->generationToken, $artwork->variant_generation_token);
        $this->assertStringContainsString('inspect image', $artwork->variant_error);
        $this->assertStringContainsString(route('media.artworks.show', [$artwork, 'variant' => 'thumb']), $artwork->thumb_url);
        Queue::assertNotPushed(AnalyzeArtworkWithAi::class);
    }

    public function test_oversized_source_header_is_rejected_before_gd_decode(): void
    {
        Storage::fake('public');
        config()->set('creative_ai.image_variants.max_source_pixels', 20_000_000);
        $sourcePath = 'artworks/originals/oversized.png';
        $header = pack('NNCCCCC', 50_000, 50_000, 8, 2, 0, 0, 0);
        $chunk = 'IHDR'.$header;
        Storage::disk('local')->put(
            $sourcePath,
            "\x89PNG\r\n\x1a\n".pack('N', strlen($header)).$chunk.pack('N', crc32($chunk)),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('safe pixel limit');

        app(ImageVariantService::class)->createVariants(
            $sourcePath,
            999,
            '00000000-0000-4000-8000-000000000999',
        );
    }

    public function test_upload_validation_uses_the_same_safe_pixel_limit(): void
    {
        config()->set('creative_ai.image_variants.max_source_pixels', 20_000_000);
        $atLimit = UploadedFile::fake()->createWithContent(
            'at-limit.png',
            $this->pngHeader(5000, 4000),
        );
        $overLimit = UploadedFile::fake()->createWithContent(
            'over-limit.png',
            $this->pngHeader(5001, 4000),
        );

        $this->assertFalse(Validator::make(
            ['image' => $atLimit],
            ['image' => [new SafeArtworkImageDimensions]],
        )->fails());
        $validator = Validator::make(
            ['image' => $overLimit],
            ['image' => [new SafeArtworkImageDimensions]],
        );

        $this->assertTrue($validator->fails());
        $this->assertStringContainsString('20 megapixels', $validator->errors()->first('image'));
    }

    public function test_delete_queues_cleanup_and_removes_original_and_variants(): void
    {
        Storage::fake('public');
        Queue::fake();
        $sourcePath = $this->storeImage('delete.jpg', 800, 600);
        $artwork = Artwork::query()->create(['title' => 'Delete Me', 'image_path' => $sourcePath]);
        $this->runGeneration($this->queuedGenerationFor($sourcePath));
        $artwork->refresh();
        $paths = [$sourcePath, $artwork->display_path, $artwork->thumb_path];

        $artwork->delete();

        $deleteJob = null;
        Queue::assertPushed(DeleteArtworkMedia::class, function (DeleteArtworkMedia $job) use (&$deleteJob): bool {
            $deleteJob = $job;

            return true;
        });
        $this->assertInstanceOf(DeleteArtworkMedia::class, $deleteJob);
        $deleteJob->handle(app(ArtworkMediaCleanupService::class));

        Storage::disk('local')->assertMissing($paths);
    }

    public function test_cleanup_failure_throws_so_the_queue_can_retry(): void
    {
        $path = 'artworks/thumbs/cannot-delete.jpg';
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('delete')->once()->with($path)->andReturn(false);
        $disk->shouldReceive('exists')->twice()->with($path)->andReturn(true);
        Storage::shouldReceive('disk')->once()->with('local')->andReturn($disk);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to delete unreferenced artwork media');

        app(ArtworkMediaCleanupService::class)->deleteUnreferenced([$path]);
    }

    public function test_auto_analysis_is_dispatched_only_after_successful_variants(): void
    {
        Storage::fake('public');
        Queue::fake();
        app(AiSettings::class)->save(['auto_analyze_uploads' => true]);
        $sourcePath = $this->storeImage('analyze.jpg', 800, 600);

        Artwork::query()->create(['title' => 'Analyze Me', 'image_path' => $sourcePath]);

        $job = $this->queuedGenerationFor($sourcePath);
        $this->assertTrue($job->analyzeAfterGeneration);
        Queue::assertNotPushed(AnalyzeArtworkWithAi::class);
        app(AiSettings::class)->save(['auto_analyze_uploads' => false]);

        $this->runGeneration($job);

        Queue::assertPushed(AnalyzeArtworkWithAi::class, 1);
    }

    public function test_new_generation_token_supersedes_a_same_source_job(): void
    {
        Storage::fake('public');
        Queue::fake();
        $sourcePath = $this->storeImage('same-source.jpg', 900, 600);
        $artwork = Artwork::query()->create(['title' => 'Same Source', 'image_path' => $sourcePath]);
        $firstJob = $this->queuedGenerationFor($sourcePath);

        $secondToken = GenerateArtworkVariants::dispatchFor($artwork->refresh());

        $this->assertNotNull($secondToken);
        $this->assertNotSame($firstJob->generationToken, $secondToken);
        $secondJob = $this->queuedGenerationFor($sourcePath, $secondToken);

        $this->runGeneration($firstJob);
        $artwork->refresh();
        $this->assertSame($secondToken, $artwork->variant_generation_token);
        $this->assertSame(Artwork::VARIANT_STATUS_QUEUED, $artwork->variant_status);

        $this->runGeneration($secondJob);
        $artwork->refresh();
        $this->assertSame(Artwork::VARIANT_STATUS_READY, $artwork->variant_status);
        $this->assertStringContainsString(str_replace('-', '', $secondToken), $artwork->thumb_path);
    }

    public function test_backfill_is_idempotent_and_marks_missing_originals(): void
    {
        Storage::fake('public');
        Queue::fake();
        $sourcePath = $this->storeImage('legacy.jpg', 1000, 500);
        $artwork = $this->createWithoutObservers($sourcePath);

        $this->artisan('creative-ai:artwork-variants:regenerate --sync')
            ->expectsOutputToContain('1 generated')
            ->assertSuccessful();
        $artwork->refresh();
        $firstPaths = [$artwork->display_path, $artwork->thumb_path];
        $generatedAt = $artwork->variants_generated_at;

        $this->artisan('creative-ai:artwork-variants:regenerate --sync')
            ->expectsOutputToContain('1 unchanged')
            ->assertSuccessful();
        $artwork->refresh();

        $this->assertSame($firstPaths, [$artwork->display_path, $artwork->thumb_path]);
        $this->assertTrue($generatedAt->equalTo($artwork->variants_generated_at));

        $stuckSource = $this->storeImage('stuck.jpg', 700, 500);
        $stuck = $this->createWithoutObservers($stuckSource, [
            'title' => 'Stuck',
            'slug' => 'stuck',
            'variant_status' => Artwork::VARIANT_STATUS_PROCESSING,
            'variant_generation_token' => '00000000-0000-4000-8000-000000000001',
            'variant_queued_at' => now()->subHour(),
            'variant_started_at' => now()->subHour(),
        ]);

        $this->artisan('creative-ai:artwork-variants:regenerate --sync --stale-after=15')->assertSuccessful();
        $stuck->refresh();
        $this->assertSame(Artwork::VARIANT_STATUS_READY, $stuck->variant_status);
        $this->assertNotSame('00000000-0000-4000-8000-000000000001', $stuck->variant_generation_token);

        $missing = $this->createWithoutObservers('artworks/originals/missing.jpg', [
            'title' => 'Missing',
            'slug' => 'missing',
        ]);

        $this->artisan('creative-ai:artwork-variants:regenerate --sync')->assertFailed();
        $missing->refresh();
        $this->assertSame(Artwork::VARIANT_STATUS_FAILED, $missing->variant_status);
        $this->assertSame('The original artwork image is missing from public storage.', $missing->variant_error);
        $this->assertFalse($missing->hasAvailableImage());
        $updatedAt = $missing->updated_at;

        $this->artisan('creative-ai:artwork-variants:regenerate --sync')->assertFailed();
        $this->assertTrue($updatedAt->equalTo($missing->refresh()->updated_at));
    }

    protected function queuedGenerationFor(
        string $sourcePath,
        ?string $generationToken = null,
    ): GenerateArtworkVariants {
        $queuedJob = null;
        Queue::assertPushed(GenerateArtworkVariants::class, function (GenerateArtworkVariants $job) use (
            $sourcePath,
            $generationToken,
            &$queuedJob,
        ): bool {
            if (
                $job->sourcePath !== $sourcePath
                || ($generationToken !== null && $job->generationToken !== $generationToken)
            ) {
                return false;
            }

            $queuedJob = $job;

            return true;
        });
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

    /** @param array<string, mixed> $attributes */
    protected function createWithoutObservers(string $sourcePath, array $attributes = []): Artwork
    {
        return Artwork::withoutEvents(fn (): Artwork => Artwork::query()->create(array_replace([
            'title' => 'Legacy Artwork',
            'slug' => 'legacy-artwork-'.uniqid(),
            'image_path' => $sourcePath,
            'variant_status' => Artwork::VARIANT_STATUS_PENDING,
        ], $attributes)));
    }

    protected function storeImage(string $filename, int $width, int $height): string
    {
        $path = 'artworks/originals/'.$filename;
        Storage::disk('local')->put($path, UploadedFile::fake()->image($filename, $width, $height)->getContent());

        return $path;
    }

    protected function pngHeader(int $width, int $height): string
    {
        $header = pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0);
        $chunk = 'IHDR'.$header;

        return "\x89PNG\r\n\x1a\n".pack('N', strlen($header)).$chunk.pack('N', crc32($chunk));
    }
}
