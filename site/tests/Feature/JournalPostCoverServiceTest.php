<?php

namespace Tests\Feature;

use App\Models\Artwork;
use App\Models\Post;
use App\Services\JournalPostCoverService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class JournalPostCoverServiceTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_alt_text_only_stale_cover_state_is_rejected_without_copying_or_overwriting_bytes(): void
    {
        $bytes = base64_decode(self::PNG, true);
        Storage::disk('local')->put('artworks/display/stale-source.png', $bytes);
        Storage::disk('local')->put('posts/covers/first.png', $bytes);
        $post = Post::query()->create([
            'title' => 'Cover race',
            'body' => 'A saved Journal body.',
            'cover_image_path' => 'posts/covers/first.png',
            'cover_alt_text' => 'First cover',
        ]);
        $source = Artwork::withoutEvents(fn (): Artwork => Artwork::query()->create([
            'title' => 'Public cover source',
            'slug' => 'public-cover-source',
            'image_path' => 'artworks/originals/stale-source.png',
            'display_path' => 'artworks/display/stale-source.png',
            'published' => true,
            'published_at' => now(),
        ]));
        $connection = $post->mediaItems()->create([
            'position' => 1,
            'artwork_id' => $source->getKey(),
        ]);
        $service = app(JournalPostCoverService::class);
        $staleFingerprint = $service->coverFingerprint($post);
        $post->update([
            'cover_alt_text' => 'Newer alt text for the same cover',
        ]);

        try {
            $service->replaceFromConnection($post, $connection, $staleFingerprint);
            $this->fail('A stale Connections action must not overwrite a newer Journal cover.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('changed before', $exception->getMessage());
        }

        $this->assertSame('posts/covers/first.png', $post->fresh()->cover_image_path);
        $this->assertSame('Newer alt text for the same cover', $post->fresh()->cover_alt_text);
        $this->assertSame(['posts/covers/first.png'], Storage::disk('local')->allFiles('posts/covers'));
        Storage::disk('local')->assertExists('posts/covers/first.png');
    }

    public function test_private_connected_source_cannot_be_promoted_into_a_journal_cover(): void
    {
        $bytes = base64_decode(self::PNG, true);
        Storage::disk('local')->put('artworks/display/private-source.png', $bytes);
        $post = Post::query()->create([
            'title' => 'Private source boundary',
            'body' => 'A saved Journal body.',
        ]);
        $source = Artwork::withoutEvents(fn (): Artwork => Artwork::query()->create([
            'title' => 'Private cover source',
            'slug' => 'private-cover-source',
            'image_path' => 'artworks/originals/private-source.png',
            'display_path' => 'artworks/display/private-source.png',
            'published' => false,
        ]));
        $connection = $post->mediaItems()->create([
            'position' => 1,
            'artwork_id' => $source->getKey(),
        ]);
        $service = app(JournalPostCoverService::class);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Only currently public source artwork');

        $service->replaceFromConnection($post, $connection, $service->coverFingerprint($post));
    }
}
