<?php

namespace Tests\Feature;

use App\Enums\PostMediaType;
use App\Enums\PostStatus;
use App\Models\Album;
use App\Models\Artwork;
use App\Models\Collection;
use App\Models\Track;
use App\Services\JournalDraftAutomationService;
use App\Services\JournalDraftPlanningService;
use App\Services\PostReadiness;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class JournalDraftAutomationTest extends TestCase
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

    public function test_saved_private_source_can_seed_only_a_private_draft_without_copying_private_artwork(): void
    {
        $source = $this->artwork('Private source', false);
        Storage::disk('local')->put($source->image_path, base64_decode(self::PNG, true));

        $post = app(JournalDraftPlanningService::class)->createFromSavedSource(
            $source,
            useSourceArtwork: true,
        );

        $this->assertSame(PostStatus::Draft, $post->status);
        $this->assertFalse($post->published);
        $this->assertNull($post->cover_image_path);
        $this->assertNull($post->cover_alt_text);
        Storage::disk('local')->assertExists($source->image_path);
        $this->assertSame($source->getKey(), $post->mediaItems->sole()->artwork_id);
        $this->assertArrayHasKey(
            'private_connections',
            app(PostReadiness::class)->evaluate($post)->warnings(),
        );
        $this->assertDatabaseCount('post_ai_runs', 0);
        $this->get(route('posts.show', ['post' => $post->slug]))->assertNotFound();
    }

    public function test_automatic_planning_is_idempotent_while_manual_planning_can_create_another_story(): void
    {
        $source = $this->artwork('Retry-safe source', true);
        $planning = app(JournalDraftPlanningService::class);

        $first = $planning->createIfUnconnected($source);
        $second = $planning->createIfUnconnected($source->fresh());

        $this->assertTrue($first->created);
        $this->assertFalse($second->created);
        $this->assertTrue($second->post->is($first->post));
        $this->assertDatabaseCount('posts', 1);
        $this->assertDatabaseCount('post_media', 1);

        $manual = $planning->createFromPublicSource($source);

        $this->assertFalse($manual->is($first->post));
        $this->assertDatabaseCount('posts', 2);
        $this->assertSame(2, $source->journalMediaItems()->count());
    }

    public function test_public_source_artwork_is_copied_as_a_stable_private_cover_snapshot(): void
    {
        $source = $this->artwork('Cover source', true);
        $source->forceFill([
            'display_path' => 'artworks/display/cover-source.png',
            'alt_text' => 'A small luminous square.',
        ])->saveQuietly();
        $bytes = base64_decode(self::PNG, true);
        Storage::disk('local')->put($source->display_path, $bytes);
        $sourceBefore = $source->fresh()->getRawOriginal();

        $post = app(JournalDraftPlanningService::class)->createFromPublicSource(
            $source,
            useSourceArtwork: true,
        );

        $this->assertNotNull($post->cover_image_path);
        $this->assertNotSame($source->display_path, $post->cover_image_path);
        $this->assertSame('A small luminous square.', $post->cover_alt_text);
        $this->assertSame($bytes, Storage::disk('local')->get($post->cover_image_path));
        $this->assertSame($sourceBefore, $source->fresh()->getRawOriginal());
        $this->assertSame(
            $post->cover_image_path,
            $post->revisions()->where('provenance', 'draft_planning')->firstOrFail()
                ->snapshot['content']['cover_image_path'],
        );

        $source->forceFill(['published' => false])->saveQuietly();

        Storage::disk('local')->assertExists($post->cover_image_path);
        $this->assertSame($bytes, Storage::disk('local')->get($post->cover_image_path));
    }

    public function test_explicit_cover_request_fails_atomically_if_public_source_bytes_disappear(): void
    {
        $source = $this->artwork('Missing requested cover', true);

        try {
            app(JournalDraftPlanningService::class)->createFromPublicSource(
                $source,
                useSourceArtwork: true,
            );
            $this->fail('A specifically requested missing cover should reject the draft atomically.');
        } catch (DomainException $exception) {
            $this->assertSame(
                'The selected source artwork is no longer available. Reload and try again.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseCount('posts', 0);
        $this->assertDatabaseCount('post_media', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('posts/covers'));
    }

    public function test_artwork_batch_creates_one_ordered_story_and_skips_sources_with_existing_plans(): void
    {
        $alreadyPlanned = $this->artwork('Already planned', true);
        $firstNew = $this->artwork('First new source', true);
        $secondNew = $this->artwork('Second new source', false);
        $planning = app(JournalDraftPlanningService::class);
        $planning->createIfUnconnected($alreadyPlanned);

        $result = $planning->createBatchIfUnconnected([
            $alreadyPlanned,
            $firstNew,
            $secondNew,
        ]);

        $this->assertTrue($result->created());
        $this->assertSame(2, $result->connected);
        $this->assertSame(1, $result->skipped);
        $this->assertNotNull($result->post);
        $this->assertSame(PostStatus::Draft, $result->post->status);
        $this->assertSame(
            [$firstNew->getKey(), $secondNew->getKey()],
            $result->post->mediaItems->pluck('artwork_id')->all(),
        );
        $this->assertSame([1, 2], $result->post->mediaItems->pluck('position')->all());
        $this->assertDatabaseCount('posts', 2);
    }

    public function test_automation_excludes_generated_sources_and_album_member_tracks(): void
    {
        $automaticCollection = Collection::query()->create([
            'title' => 'Generated collection',
            'published' => true,
            'is_smart' => true,
            'is_auto_generated' => true,
            'auto_generation_key' => 'generated-test',
        ]);
        $album = Album::query()->create([
            'title' => 'Album story source',
            'published' => true,
        ]);
        $member = Track::withoutEvents(fn (): Track => Track::query()->create([
            'album_id' => $album->getKey(),
            'title' => 'Album member',
            'slug' => 'album-member',
            'audio_path' => 'tracks/audio/member.mp3',
            'standalone_published' => false,
        ]));
        $automation = app(JournalDraftAutomationService::class);

        foreach ([$automaticCollection, $member] as $source) {
            try {
                $automation->createFor($source);
                $this->fail('This automatically maintained or album-member source should be excluded.');
            } catch (DomainException) {
                $this->assertDatabaseCount('posts', 0);
            }
        }

        $standalone = $member->replicate();
        $standalone->forceFill([
            'album_id' => null,
            'title' => 'Standalone source',
            'slug' => 'standalone-source',
            'standalone_published' => true,
        ])->save();

        $result = $automation->createFor($standalone);

        $this->assertTrue($result->created);
        $this->assertSame(PostMediaType::Track, $result->post->mediaItems->sole()->type());
    }

    private function artwork(string $title, bool $published): Artwork
    {
        return Artwork::withoutEvents(fn (): Artwork => Artwork::query()->create([
            'title' => $title,
            'slug' => str($title)->slug()->toString(),
            'image_path' => 'artworks/originals/'.str($title)->slug().'.png',
            'published' => $published,
            'published_at' => $published ? now() : null,
        ]));
    }
}
