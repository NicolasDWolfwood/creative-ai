<?php

namespace Tests\Feature;

use App\Enums\PostMediaType;
use App\Models\Album;
use App\Models\Artwork;
use App\Models\Collection;
use App\Models\Playlist;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\Tag;
use App\Models\Track;
use App\Services\PostConnectionService;
use App\Services\PostWorkflowService;
use App\Services\PublicStoryConnections;
use App\Services\SharedTagPageService;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PostConnectionsDomainTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_connections_are_validated_ordered_atomic_and_touch_public_content_only_when_changed(): void
    {
        Carbon::setTestNow('2026-07-13 10:00:00 UTC');
        $post = $this->makePost();
        $tag = Tag::query()->create(['name' => 'connected', 'slug' => 'connected']);
        $media = $this->mediaRecords();
        $service = app(PostConnectionService::class);
        $initialTimestamp = $post->public_content_updated_at;

        Carbon::setTestNow(now()->addMinute());
        $service->syncTags($post, [$tag->id]);
        $tagTimestamp = $post->refresh()->public_content_updated_at;
        $this->assertTrue($tagTimestamp->greaterThan($initialTimestamp));
        $this->assertSame([$tag->id], $post->tags()->pluck('tags.id')->all());

        Carbon::setTestNow(now()->addMinute());
        $references = [
            ['type' => PostMediaType::Track, 'id' => $media['track']->id],
            ['type' => 'artwork', 'id' => $media['artwork']->id],
            ['type' => 'collection', 'id' => $media['collection']->id],
            ['type' => 'album', 'id' => $media['album']->id],
            ['type' => 'playlist', 'id' => $media['playlist']->id],
        ];
        $service->syncMedia($post, $references);

        $this->assertSame(
            ['track', 'artwork', 'collection', 'album', 'playlist'],
            $post->mediaItems()->get()->map(fn (PostMedia $item): ?string => $item->type()?->value)->all(),
        );
        $this->assertSame([1, 2, 3, 4, 5], $post->mediaItems()->pluck('position')->all());
        $mediaTimestamp = $post->refresh()->public_content_updated_at;
        $this->assertTrue($mediaTimestamp->greaterThan($tagTimestamp));

        Carbon::setTestNow(now()->addHour());
        $service->syncTags($post, [$tag->id]);
        $service->syncMedia($post, $references);
        $this->assertTrue($post->refresh()->public_content_updated_at->equalTo($mediaTimestamp));

        try {
            $service->syncMedia($post, [
                ['type' => 'artwork', 'id' => $media['artwork']->id],
                ['type' => 'artwork', 'id' => $media['artwork']->id],
            ]);
            $this->fail('Duplicate connections should be rejected.');
        } catch (DomainException) {
            $this->assertCount(5, $post->mediaItems()->get());
        }

        try {
            $service->syncMedia($post, [['type' => 'track', 'id' => 999999]]);
            $this->fail('Missing media should be rejected.');
        } catch (DomainException) {
            $this->assertCount(5, $post->mediaItems()->get());
        }

        $service->syncMedia($post, []);
        $this->assertDatabaseCount('post_media', 0);
        foreach ($media as $record) {
            $this->assertTrue($record->fresh()->exists);
        }
    }

    public function test_public_connections_fail_closed_for_private_posts_media_and_malformed_rows(): void
    {
        $publicPost = $this->publish($this->makePost(['slug' => 'public-story']));
        $privatePost = $this->makePost(['slug' => 'private-story']);
        $publicArtwork = $this->artwork(['slug' => 'public-art']);
        $futureArtwork = $this->artwork([
            'slug' => 'future-art',
            'published_at' => now()->addDay(),
        ]);
        $connections = app(PostConnectionService::class);

        $connections->syncMedia($publicPost, [
            ['type' => 'artwork', 'id' => $futureArtwork->id],
            ['type' => 'artwork', 'id' => $publicArtwork->id],
        ]);
        $connections->syncMedia($privatePost, [
            ['type' => 'artwork', 'id' => $publicArtwork->id],
        ]);

        $public = app(PublicStoryConnections::class);
        $this->assertSame(
            [$publicArtwork->id],
            $public->mediaForPost($publicPost)->map(fn (PostMedia $item): int => $item->artwork_id)->all(),
        );
        $this->assertSame(
            [$publicPost->id],
            $public->postsForMedia($publicArtwork)->pluck('id')->all(),
        );
        $this->assertTrue($public->postsForMedia($futureArtwork)->isEmpty());
        $this->assertTrue($public->mediaForPost($privatePost)->isEmpty());

        DB::table('post_media')->insert([
            'post_id' => $publicPost->id,
            'position' => 20,
            'artwork_id' => $this->artwork(['slug' => 'malformed-artwork'])->id,
            'collection_id' => Collection::query()->create([
                'title' => 'Malformed collection',
                'slug' => 'malformed-collection',
                'published' => true,
            ])->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertCount(1, $public->mediaForPost($publicPost));
    }

    public function test_connection_foreign_keys_cascade_without_deleting_unrelated_sources(): void
    {
        $post = $this->makePost();
        $tag = Tag::query()->create(['name' => 'cascade', 'slug' => 'cascade']);
        $artwork = $this->artwork(['slug' => 'cascade-artwork']);
        $service = app(PostConnectionService::class);
        $service->syncTags($post, [$tag->id]);
        $service->syncMedia($post, [['type' => 'artwork', 'id' => $artwork->id]]);

        $artwork->delete();

        $this->assertDatabaseMissing('post_media', [
            'post_id' => $post->id,
            'artwork_id' => $artwork->id,
        ]);
        $this->assertDatabaseHas('post_tag', [
            'post_id' => $post->id,
            'tag_id' => $tag->id,
        ]);

        $post->delete();

        $this->assertDatabaseHas('post_tag', [
            'post_id' => $post->id,
            'tag_id' => $tag->id,
        ]);

        $post->forceDelete();

        $this->assertDatabaseMissing('post_tag', [
            'post_id' => $post->id,
            'tag_id' => $tag->id,
        ]);
        $this->assertDatabaseHas('tags', ['id' => $tag->id]);
    }

    public function test_stale_connection_snapshots_cannot_overwrite_newer_edits(): void
    {
        $post = $this->makePost();
        $firstTag = Tag::query()->create(['name' => 'first', 'slug' => 'first']);
        $secondTag = Tag::query()->create(['name' => 'second', 'slug' => 'second']);
        $firstArtwork = $this->artwork(['slug' => 'first-artwork']);
        $secondArtwork = $this->artwork(['slug' => 'second-artwork']);
        $service = app(PostConnectionService::class);

        $service->syncTags($post, [$firstTag->id]);
        $staleTagIds = [$firstTag->id];
        $service->syncTags($post, [$firstTag->id, $secondTag->id]);

        try {
            $service->syncTags($post, [], $staleTagIds);
            $this->fail('A stale tag snapshot should not overwrite newer tags.');
        } catch (DomainException) {
            $this->assertSame(
                [$firstTag->id, $secondTag->id],
                $post->tags()->pluck('tags.id')->sort()->values()->all(),
            );
        }

        $service->syncMedia($post, [['type' => 'artwork', 'id' => $firstArtwork->id]]);
        $staleMediaItemIds = $post->mediaItems()->pluck('id')->all();
        $service->syncMedia($post, [
            ['type' => 'artwork', 'id' => $firstArtwork->id],
            ['type' => 'artwork', 'id' => $secondArtwork->id],
        ]);

        try {
            $service->syncMedia($post, [], $staleMediaItemIds);
            $this->fail('A stale media snapshot should not overwrite newer connections.');
        } catch (DomainException) {
            $this->assertSame(
                [$firstArtwork->id, $secondArtwork->id],
                $post->mediaItems()->pluck('artwork_id')->all(),
            );
        }
    }

    public function test_publication_visibility_changes_touch_reciprocal_media_timestamps(): void
    {
        Carbon::setTestNow('2026-07-13 12:00:00 UTC');
        $post = $this->makePost();
        $artwork = $this->artwork(['slug' => 'timestamp-artwork']);
        $service = app(PostConnectionService::class);
        $service->syncMedia($post, [['type' => 'artwork', 'id' => $artwork->id]]);
        $initialTimestamp = $artwork->refresh()->updated_at;

        Carbon::setTestNow(now()->addMinute());
        $published = $this->publish($post);
        $publishedTimestamp = $artwork->refresh()->updated_at;

        $this->assertTrue($publishedTimestamp->gt($initialTimestamp));

        Carbon::setTestNow(now()->addMinute());
        app(PostWorkflowService::class)->unpublish($published);

        $this->assertTrue($artwork->refresh()->updated_at->gt($publishedTimestamp));
    }

    public function test_shared_tags_are_direct_for_posts_artwork_and_tracks_and_derived_for_public_containers(): void
    {
        $tag = Tag::query()->create(['name' => 'liminal', 'slug' => 'liminal']);
        $post = $this->publish($this->makePost(['slug' => 'liminal-story']));
        $artwork = $this->artwork(['slug' => 'liminal-art']);
        $collection = Collection::query()->create([
            'title' => 'Liminal collection',
            'slug' => 'liminal-collection',
            'published' => true,
        ]);
        $album = Album::query()->create([
            'title' => 'Liminal album',
            'slug' => 'liminal-album',
            'published' => true,
        ]);
        $playlist = Playlist::query()->create([
            'title' => 'Liminal playlist',
            'slug' => 'liminal-playlist',
            'published' => true,
        ]);
        $track = Track::query()->create([
            'album_id' => $album->id,
            'title' => 'Liminal track',
            'slug' => 'liminal-track',
            'audio_path' => 'tracks/liminal.mp3',
            'standalone_published' => false,
        ]);

        app(PostConnectionService::class)->syncTags($post, [$tag->id]);
        $artwork->tags()->attach($tag, ['category' => 'mood']);
        $track->tags()->attach($tag, ['category' => 'mood']);
        $collection->artworks()->attach($artwork);
        $playlist->tracks()->attach($track, ['position' => 1]);

        $content = app(SharedTagPageService::class)->contentFor($tag);

        $this->assertTrue($content['posts']->contains($post));
        $this->assertTrue($content['artworks']->contains($artwork));
        $this->assertTrue($content['collections']->contains($collection));
        $this->assertTrue($content['albums']->contains($album));
        $this->assertTrue($content['playlists']->contains($playlist));
        $this->assertTrue($content['tracks']->contains($track));
        $this->assertTrue(app(SharedTagPageService::class)->publicTags()->contains($tag));

        $album->update(['published_at' => now()->addDay()]);
        $this->assertFalse(app(SharedTagPageService::class)->contentFor($tag)['albums']->contains($album));
    }

    /** @return array<string, Model> */
    private function mediaRecords(): array
    {
        return [
            'artwork' => $this->artwork(['slug' => 'connected-art']),
            'collection' => Collection::query()->create(['title' => 'Connected collection', 'slug' => 'connected-collection']),
            'album' => Album::query()->create(['title' => 'Connected album', 'slug' => 'connected-album']),
            'playlist' => Playlist::query()->create(['title' => 'Connected playlist', 'slug' => 'connected-playlist']),
            'track' => Track::query()->create([
                'title' => 'Connected track',
                'slug' => 'connected-track',
                'audio_path' => 'tracks/connected.mp3',
            ]),
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function makePost(array $attributes = []): Post
    {
        return Post::query()->create(array_replace([
            'title' => 'Connected Journal story',
            'slug' => 'connected-story-'.str()->uuid(),
            'excerpt' => 'A concise public summary.',
            'body' => 'A complete story about connected creative work.',
        ], $attributes));
    }

    private function publish(Post $post): Post
    {
        $workflow = app(PostWorkflowService::class);

        return $workflow->publishNow($workflow->markReady($post));
    }

    /** @param array<string, mixed> $attributes */
    private function artwork(array $attributes = []): Artwork
    {
        return Artwork::query()->create(array_replace([
            'title' => 'Connected artwork',
            'slug' => 'connected-artwork-'.str()->uuid(),
            'image_path' => 'artworks/connected.jpg',
            'published' => true,
        ], $attributes));
    }
}
