<?php

namespace Tests\Feature;

use App\Enums\PostMediaType;
use App\Models\Album;
use App\Models\Artwork;
use App\Models\Collection;
use App\Models\Playlist;
use App\Models\Post;
use App\Models\Tag;
use App\Models\Track;
use App\Services\PostConnectionService;
use App\Services\PostWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicConnectedStoriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_tag_sections_use_independent_cursor_pagination(): void
    {
        Queue::fake();
        $tag = Tag::query()->create(['name' => 'large archive']);
        $artworks = collect(range(1, 13))->map(function (int $index) use ($tag): Artwork {
            $artwork = Artwork::withoutEvents(fn (): Artwork => Artwork::query()->create([
                'title' => 'Archive artwork '.$index,
                'slug' => 'archive-artwork-'.$index,
                'image_path' => 'artworks/originals/archive-'.$index.'.jpg',
                'published' => true,
                'published_at' => now()->subMinute(),
            ]));
            $artwork->tags()->attach($tag, ['category' => 'mood']);

            return $artwork;
        });

        $firstPage = $this->get(route('tags.show', $tag))->assertOk();
        $paginator = $firstPage->viewData('artworks');

        $this->assertCount(12, $paginator);
        $this->assertTrue($paginator->hasMorePages());
        $firstPage->assertSee('More artwork');

        $this->get($paginator->nextPageUrl())
            ->assertOk()
            ->assertSee($artworks->first()->title);
    }

    public function test_public_tag_archive_and_reciprocal_story_pages_fail_closed(): void
    {
        Queue::fake();
        Storage::fake('local');
        Storage::disk('local')->put('artworks/originals/connected.jpg', 'public-art');
        Storage::disk('local')->put('artworks/originals/private.jpg', 'private-art');

        $tag = Tag::query()->create(['name' => 'night signals']);
        $privateTag = Tag::query()->create(['name' => 'private signal']);
        $emptyTag = Tag::query()->create(['name' => 'empty signal']);
        $artwork = $this->artwork('Connected Artwork', 'connected-artwork', true);
        $futureArtwork = $this->artwork('Future Artwork Marker', 'future-artwork-marker', false);
        $futureArtwork->forceFill(['published' => true, 'published_at' => now()->addDay()])->saveQuietly();
        $collection = Collection::query()->create(['title' => 'Connected Collection', 'published' => true]);
        $collection->artworks()->attach($artwork);
        $track = Track::query()->create([
            'title' => 'Connected Track',
            'audio_path' => 'tracks/connected.mp3',
            'standalone_published' => true,
        ]);
        $album = Album::query()->create(['title' => 'Connected Album', 'published' => true]);
        $track->update(['album_id' => $album->id]);
        $playlist = Playlist::query()->create(['title' => 'Connected Playlist', 'published' => true]);
        $playlist->tracks()->attach($track, ['position' => 1]);

        $artwork->tags()->attach($tag, ['category' => 'mood']);
        $track->tags()->attach($tag, ['category' => 'mood']);
        $futureArtwork->tags()->attach($privateTag, ['category' => 'mood']);

        $post = $this->publishPost('Connected Journal Story', 'connected-journal-story');
        $connections = app(PostConnectionService::class);
        $connections->syncTags($post, [$tag->id]);
        $connections->syncMedia($post, [
            ['type' => PostMediaType::Track, 'id' => $track->id],
            ['type' => PostMediaType::Artwork, 'id' => $futureArtwork->id],
            ['type' => PostMediaType::Artwork, 'id' => $artwork->id],
            ['type' => PostMediaType::Playlist, 'id' => $playlist->id],
            ['type' => PostMediaType::Collection, 'id' => $collection->id],
            ['type' => PostMediaType::Album, 'id' => $album->id],
        ]);

        $draft = Post::query()->create([
            'title' => 'Private Draft Story Marker',
            'slug' => 'private-draft-story-marker',
            'body' => 'A private draft.',
        ]);
        $connections->syncTags($draft, [$tag->id, $privateTag->id]);
        $connections->syncMedia($draft, [['type' => PostMediaType::Artwork, 'id' => $artwork->id]]);

        $future = app(PostWorkflowService::class)->schedule(
            app(PostWorkflowService::class)->markReady(Post::query()->create([
                'title' => 'Future Story Marker',
                'slug' => 'future-story-marker',
                'body' => 'A future story.',
            ])),
            now()->addDay(),
        );
        $connections->syncTags($future, [$tag->id]);
        $connections->syncMedia($future, [['type' => PostMediaType::Track, 'id' => $track->id]]);

        $tagUrl = route('tags.show', $tag);
        $this->get($tagUrl)
            ->assertOk()
            ->assertSeeInOrder([
                'Connected Journal Story',
                'Connected Artwork',
                'Connected Collection',
                'Connected Album',
                'Connected Playlist',
                'Connected Track',
            ])
            ->assertDontSee('Private Draft Story Marker')
            ->assertDontSee('Future Story Marker')
            ->assertDontSee('Future Artwork Marker')
            ->assertSee('CollectionPage')
            ->assertSee('ItemList');

        $this->get(route('tags.show', $privateTag))->assertNotFound();
        $this->get(route('tags.show', $emptyTag))->assertNotFound();
        $this->get(route('gallery', ['tag' => $privateTag->slug]))
            ->assertOk()
            ->assertDontSee($privateTag->name);

        $postPage = $this->get(route('posts.show', $post))
            ->assertOk()
            ->assertSee($tagUrl, false)
            ->assertSeeInOrder([
                'Connected Track',
                'Connected Artwork',
                'Connected Playlist',
                'Connected Collection',
                'Connected Album',
            ])
            ->assertDontSee('Future Artwork Marker')
            ->assertSee('keywords')
            ->assertSee('about');

        $this->assertStringNotContainsString('Private Draft Story Marker', $postPage->getContent());

        foreach ([
            route('artworks.show', $artwork),
            route('collections.show', $collection),
            route('music.albums.show', $album),
            route('music.playlists.show', $playlist),
            route('music.tracks.show', $track),
        ] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee('Connected Journal Story')
                ->assertDontSee('Private Draft Story Marker')
                ->assertDontSee('Future Story Marker');
        }

        $this->get(route('artworks.show', $artwork))->assertSee($tagUrl, false)->assertSee('subjectOf');
        $this->get(route('music.albums.show', $album))->assertSee($tagUrl, false)->assertSee('subjectOf');
        $this->get(route('music.playlists.show', $playlist))->assertSee($tagUrl, false)->assertSee('subjectOf');
        $this->get(route('music.tracks.show', $track))->assertSee($tagUrl, false)->assertSee('subjectOf');

        $this->get(route('sitemap'))
            ->assertOk()
            ->assertSee($tagUrl, false)
            ->assertDontSee(route('tags.show', $privateTag), false)
            ->assertDontSee(route('tags.show', $emptyTag), false);
    }

    private function publishPost(string $title, string $slug): Post
    {
        $post = Post::query()->create([
            'title' => $title,
            'slug' => $slug,
            'excerpt' => 'A connected public story.',
            'body' => 'A visible story connecting the public archive.',
        ]);

        return app(PostWorkflowService::class)->publishNow(
            app(PostWorkflowService::class)->markReady($post),
        );
    }

    private function artwork(string $title, string $slug, bool $published): Artwork
    {
        return Artwork::withoutEvents(fn (): Artwork => Artwork::query()->create([
            'title' => $title,
            'slug' => $slug,
            'image_path' => $published ? 'artworks/originals/connected.jpg' : 'artworks/originals/private.jpg',
            'published' => $published,
            'published_at' => $published ? now() : null,
        ]));
    }
}
