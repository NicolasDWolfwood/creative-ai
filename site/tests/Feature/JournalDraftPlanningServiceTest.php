<?php

namespace Tests\Feature;

use App\Enums\PostMediaType;
use App\Enums\PostStatus;
use App\Models\Album;
use App\Models\Artwork;
use App\Models\Collection;
use App\Models\Playlist;
use App\Models\Post;
use App\Models\PostTemplate;
use App\Models\Tag;
use App\Models\Track;
use App\Services\JournalDraftPlanningService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class JournalDraftPlanningServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_it_creates_a_private_connected_draft_from_an_active_template_without_mutating_the_source(): void
    {
        $sourceTag = $this->tag('source');
        $defaultTag = $this->tag('default');
        $privateTag = $this->tag('private');
        $source = $this->artwork('Source Artwork', true);
        $source->tags()->attach($sourceTag, ['category' => 'theme']);
        $this->artwork('Public default tag owner', true)
            ->tags()->attach($defaultTag, ['category' => 'theme']);
        $this->artwork('Private tag owner', false)
            ->tags()->attach($privateTag, ['category' => 'theme']);

        $template = PostTemplate::query()->create([
            'name' => '  Process   note  ',
            'title' => 'Making {{ source_type }}: {{ source_title }}',
            'excerpt' => 'A closer look at {{ source_title }}.',
            'body' => "## {{ source_title }}\n\nWrite the {{ source_type }} process here.",
            'editorial_brief' => 'Explain why {{ source_title }} matters as an {{ source_type }}.',
        ]);
        $template->tags()->attach([$defaultTag->id, $privateTag->id]);
        $sourceBefore = $source->fresh()->getAttributes();

        $service = app(JournalDraftPlanningService::class);
        $post = $service->createFromPublicSource(
            $source,
            $template,
            copySharedTags: true,
        );

        $this->assertSame('Process note', $template->fresh()->name);
        $this->assertSame('Making Artwork: Source Artwork', $post->title);
        $this->assertSame('A closer look at Source Artwork.', $post->excerpt);
        $this->assertSame("## Source Artwork\n\nWrite the Artwork process here.", $post->body);
        $this->assertSame('Explain why Source Artwork matters as an Artwork.', $post->editorial_brief);
        $this->assertSame(PostStatus::Draft, $post->status);
        $this->assertFalse($post->published);
        $this->assertFalse($post->featured);
        $this->assertNull($post->scheduled_at);
        $this->assertNull($post->published_at);
        $this->assertFalse($post->isPubliclyPublished());
        $this->assertFalse(Post::query()->published()->whereKey($post)->exists());
        $this->assertSame(
            collect([$defaultTag->id, $sourceTag->id])->sort()->values()->all(),
            $post->tags->pluck('id')->sort()->values()->all(),
        );

        $connection = $post->mediaItems->sole();
        $this->assertSame(1, $connection->position);
        $this->assertSame(PostMediaType::Artwork, $connection->type());
        $this->assertSame($source->id, $connection->artwork_id);

        $withoutSourceTags = $service->createFromPublicSource($source, $template);
        $this->assertSame([$defaultTag->id], $withoutSourceTags->tags->pluck('id')->all());
        $this->assertSame($sourceBefore, $source->fresh()->getAttributes());
    }

    public function test_source_tag_copying_matches_public_archive_semantics_for_every_supported_source(): void
    {
        $collectionTag = $this->tag('collection-public-child');
        $collectionPrivateTag = $this->tag('collection-private-child');
        $collection = Collection::query()->create([
            'title' => 'Public Collection',
            'published' => true,
        ]);
        $publicCollectionArtwork = $this->artwork('Public collection artwork', true);
        $publicCollectionArtwork->tags()->attach($collectionTag, ['category' => 'theme']);
        $privateCollectionArtwork = $this->artwork('Private collection artwork', false);
        $privateCollectionArtwork->tags()->attach($collectionPrivateTag, ['category' => 'theme']);
        $collection->artworks()->attach([$publicCollectionArtwork->id, $privateCollectionArtwork->id]);

        $albumTag = $this->tag('album-track');
        $album = Album::query()->create([
            'title' => 'Public Album',
            'published' => true,
        ]);
        $albumTrack = $this->track('Album Track', false, $album);
        $albumTrack->tags()->attach($albumTag, ['category' => 'theme']);

        $playlistTag = $this->tag('playlist-public-track');
        $playlistPrivateTag = $this->tag('playlist-private-track');
        $playlist = Playlist::query()->create([
            'title' => 'Public Playlist',
            'published' => true,
        ]);
        $publicPlaylistTrack = $this->track('Public Playlist Track', true);
        $publicPlaylistTrack->tags()->attach($playlistTag, ['category' => 'theme']);
        $privatePlaylistTrack = $this->track('Private Playlist Track', false);
        $privatePlaylistTrack->tags()->attach($playlistPrivateTag, ['category' => 'theme']);
        $playlist->tracks()->attach([
            $publicPlaylistTrack->id => ['position' => 1],
            $privatePlaylistTrack->id => ['position' => 2],
        ]);

        $trackTag = $this->tag('standalone-track');
        $track = $this->track('Public Standalone Track', true);
        $track->tags()->attach($trackTag, ['category' => 'theme']);

        $service = app(JournalDraftPlanningService::class);
        $expectations = [
            [$collection, PostMediaType::Collection, [$collectionTag->id]],
            [$album, PostMediaType::Album, [$albumTag->id]],
            [$playlist, PostMediaType::Playlist, [$playlistTag->id]],
            [$track, PostMediaType::Track, [$trackTag->id]],
        ];

        foreach ($expectations as [$source, $type, $expectedTagIds]) {
            $post = $service->createFromPublicSource($source, copySharedTags: true);
            $connection = $post->mediaItems->sole();

            $this->assertSame('Story: '.$source->title, $post->title);
            $this->assertSame(PostStatus::Draft, $post->status);
            $this->assertSame($type, $connection->type());
            $this->assertSame(1, $connection->position);
            $this->assertSame($expectedTagIds, $post->tags->pluck('id')->all());
        }
    }

    public function test_it_rejects_private_future_missing_and_unsupported_sources_without_creating_a_post(): void
    {
        $sources = [
            $this->artwork('Private Artwork', false),
            Artwork::query()->create([
                'title' => 'Future Artwork',
                'image_path' => 'artworks/future.jpg',
                'published' => true,
                'published_at' => now()->addDay(),
            ]),
            Collection::query()->create(['title' => 'Private Collection', 'published' => false]),
            Album::query()->create(['title' => 'Private Album', 'published' => false]),
            Playlist::query()->create(['title' => 'Private Playlist', 'published' => false]),
            $this->track('Private Track', false),
            $this->tag('unsupported'),
            new Artwork(['title' => 'Unsaved Artwork']),
        ];
        $service = app(JournalDraftPlanningService::class);

        foreach ($sources as $source) {
            try {
                $service->createFromPublicSource($source);
                $this->fail('A private, unsupported, or unsaved source should be rejected.');
            } catch (DomainException) {
                $this->assertDatabaseCount('posts', 0);
                $this->assertDatabaseCount('post_media', 0);
            }
        }
    }

    public function test_inactive_or_invalid_templates_are_rejected_before_any_draft_is_persisted(): void
    {
        $source = $this->artwork('Template Source', true);
        $inactive = PostTemplate::query()->create([
            'name' => 'Inactive template',
            'is_active' => false,
        ]);

        try {
            app(JournalDraftPlanningService::class)->createFromPublicSource($source, $inactive);
            $this->fail('An inactive template should be rejected.');
        } catch (DomainException $exception) {
            $this->assertSame('The selected Journal template is no longer active.', $exception->getMessage());
            $this->assertDatabaseCount('posts', 0);
        }

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('A Journal template needs a name.');

        PostTemplate::query()->create(['name' => '   ']);
    }

    public function test_expanded_template_fields_and_generated_slugs_fit_database_limits(): void
    {
        $source = $this->artwork(str_repeat('S', 30), true);
        $overlong = PostTemplate::query()->create([
            'name' => 'Overlong expansion',
            'title' => str_repeat('T', 230).'{{ source_title }}',
        ]);

        try {
            app(JournalDraftPlanningService::class)->createFromPublicSource($source, $overlong);
            $this->fail('An expanded title beyond the Journal limit should be rejected.');
        } catch (DomainException $exception) {
            $this->assertSame(
                'The generated Journal title is longer than 255 characters. Shorten the source title or template.',
                $exception->getMessage(),
            );
            $this->assertDatabaseCount('posts', 0);
            $this->assertDatabaseCount('post_media', 0);
        }

        $boundary = PostTemplate::query()->create([
            'name' => 'Boundary title',
            'title' => str_repeat('B', 255),
        ]);
        $service = app(JournalDraftPlanningService::class);
        $first = $service->createFromPublicSource($source, $boundary);
        $second = $service->createFromPublicSource($source, $boundary);

        $this->assertSame(255, mb_strlen($first->title));
        $this->assertLessThanOrEqual(255, mb_strlen($first->slug));
        $this->assertLessThanOrEqual(255, mb_strlen($second->slug));
        $this->assertNotSame($first->slug, $second->slug);
    }

    private function tag(string $name): Tag
    {
        return Tag::query()->create(['name' => $name]);
    }

    private function artwork(string $title, bool $published): Artwork
    {
        return Artwork::query()->create([
            'title' => $title,
            'image_path' => 'artworks/'.str()->slug($title).'.jpg',
            'published' => $published,
        ]);
    }

    private function track(string $title, bool $published, ?Album $album = null): Track
    {
        return Track::query()->create([
            'album_id' => $album?->id,
            'title' => $title,
            'audio_path' => 'tracks/'.str()->slug($title).'.mp3',
            'standalone_published' => $published,
        ]);
    }
}
