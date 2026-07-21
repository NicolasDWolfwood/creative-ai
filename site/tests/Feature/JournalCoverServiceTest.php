<?php

namespace Tests\Feature;

use App\Data\JournalSourceImage;
use App\Enums\PostMediaType;
use App\Models\Post;
use App\Services\JournalCoverService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class JournalCoverServiceTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_it_copies_and_verifies_private_source_bytes_into_an_independent_private_cover(): void
    {
        $bytes = base64_decode(self::PNG, true);
        Storage::disk('local')->put('artworks/display/source.png', $bytes);

        $path = app(JournalCoverService::class)->copy($this->candidate('artworks/display/source.png'));

        $this->assertMatchesRegularExpression(
            '/\Aposts\/covers\/source-artwork-42-[0-9a-f-]{36}\.png\z/',
            $path,
        );
        $this->assertNotSame('artworks/display/source.png', $path);
        Storage::disk('local')->assertExists('artworks/display/source.png');
        Storage::disk('local')->assertExists($path);
        Storage::disk('public')->assertMissing($path);
        $this->assertSame($bytes, Storage::disk('local')->get($path));
    }

    public function test_it_can_copy_a_legacy_public_source_without_publishing_the_journal_snapshot(): void
    {
        Storage::disk('public')->put('artworks/originals/legacy.png', base64_decode(self::PNG, true));

        $path = app(JournalCoverService::class)->copy($this->candidate('artworks/originals/legacy.png'));

        Storage::disk('public')->assertExists('artworks/originals/legacy.png');
        Storage::disk('public')->assertMissing($path);
        Storage::disk('local')->assertExists($path);
    }

    public function test_it_rejects_non_image_bytes_without_leaving_a_partial_cover(): void
    {
        Storage::disk('local')->put('artworks/display/not-an-image.jpg', 'not an image');

        try {
            app(JournalCoverService::class)->copy($this->candidate('artworks/display/not-an-image.jpg'));
            $this->fail('Non-image source bytes should be rejected.');
        } catch (DomainException $exception) {
            $this->assertSame(
                'Journal source artwork must be a safe image no larger than 20 megapixels.',
                $exception->getMessage(),
            );
        }

        $this->assertSame([], Storage::disk('local')->allFiles('posts/covers'));
    }

    public function test_it_rejects_an_image_header_larger_than_twenty_megapixels(): void
    {
        $ihdr = pack('NNCCCCC', 5000, 4001, 8, 2, 0, 0, 0);
        $chunk = 'IHDR'.$ihdr;
        $oversizedPng = "\x89PNG\r\n\x1a\n"
            .pack('N', strlen($ihdr)).$chunk.pack('N', crc32($chunk));
        Storage::disk('local')->put('artworks/display/oversized.png', $oversizedPng);
        $dimensions = getimagesize(Storage::disk('local')->path('artworks/display/oversized.png'));

        $this->assertIsArray($dimensions);
        $this->assertSame([5000, 4001], array_slice($dimensions, 0, 2));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('no larger than 20 megapixels');

        try {
            app(JournalCoverService::class)->copy($this->candidate('artworks/display/oversized.png'));
        } finally {
            $this->assertSame([], Storage::disk('local')->allFiles('posts/covers'));
        }
    }

    public function test_uncommitted_cleanup_is_guarded_and_never_deletes_revision_backed_cover_bytes(): void
    {
        Storage::disk('local')->put('artworks/display/source.png', base64_decode(self::PNG, true));
        $service = app(JournalCoverService::class);
        $uncommitted = $service->copy($this->candidate('artworks/display/source.png'));

        $this->assertTrue($service->discardUncommitted($uncommitted));
        Storage::disk('local')->assertMissing($uncommitted);
        $this->assertFalse($service->discardUncommitted('posts/covers/manual-upload.png'));

        $committed = $service->copy($this->candidate('artworks/display/source.png'));
        $post = Post::query()->create([
            'title' => 'Revision-backed cover',
            'body' => 'Cover history must stay restorable.',
            'cover_image_path' => $committed,
            'cover_alt_text' => 'A small source image.',
        ]);
        $post->update(['cover_image_path' => null, 'cover_alt_text' => null]);

        $this->assertFalse($service->discardUncommitted($committed));
        Storage::disk('local')->assertExists($committed);
        $this->assertTrue($post->revisions()->where(
            'snapshot->content->cover_image_path',
            $committed,
        )->exists());
    }

    private function candidate(string $path): JournalSourceImage
    {
        return new JournalSourceImage(
            sourcePath: $path,
            thumbnailUrl: 'https://example.test/authorized-thumbnail',
            altText: 'Source artwork',
            sourceType: PostMediaType::Artwork,
            sourceId: 42,
        );
    }
}
