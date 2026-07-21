<?php

namespace Tests\Feature;

use App\Enums\PostMediaType;
use App\Models\Album;
use App\Models\Artwork;
use App\Models\Collection;
use App\Models\Playlist;
use App\Models\Track;
use App\Services\CollectionCoverService;
use App\Services\JournalSourceImageResolver;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class JournalSourceImageResolverTest extends TestCase
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

    public function test_artwork_uses_the_available_display_copy_authorized_thumbnail_and_meaningful_alt_text(): void
    {
        $artwork = $this->artwork('Source artwork', true, [
            'alt_text' => '  A luminous shape above dark water.  ',
            'display_path' => 'artworks/display/source.jpg',
            'thumb_path' => 'artworks/thumbs/source.jpg',
        ]);
        $this->store('artworks/display/source.jpg');
        $this->store('artworks/thumbs/source.jpg');

        $candidate = app(JournalSourceImageResolver::class)->resolve($artwork);

        $this->assertNotNull($candidate);
        $this->assertSame('artworks/display/source.jpg', $candidate->sourcePath);
        $this->assertSame($artwork->thumb_url, $candidate->thumbnailUrl);
        $this->assertSame('A luminous shape above dark water.', $candidate->altText);
        $this->assertSame(PostMediaType::Artwork, $candidate->sourceType);
        $this->assertSame($artwork->getKey(), $candidate->sourceId);

        $artwork->forceFill(['published' => false])->saveQuietly();
        $this->assertNull(app(JournalSourceImageResolver::class)->resolve($artwork->refresh()));
    }

    public function test_collection_reuses_its_public_featured_cover_selection(): void
    {
        $collection = Collection::query()->create([
            'title' => 'Public collection',
            'published' => true,
        ]);
        $unfeatured = $this->artwork('Unfeatured work', true);
        $featured = $this->artwork('Featured work', true, [
            'featured' => true,
            'alt_text' => 'Layered red and gold forms.',
            'display_path' => 'artworks/display/featured.jpg',
        ]);
        $this->store($unfeatured->image_path);
        $this->store($featured->image_path);
        $this->store($featured->display_path);
        $collection->artworks()->attach([$unfeatured->getKey(), $featured->getKey()]);

        // An unconstrained eager load must not make the unfeatured record eligible.
        $collection->load('artworks');
        $candidate = app(JournalSourceImageResolver::class)->resolve($collection);

        $this->assertNotNull($candidate);
        $this->assertSame($featured->display_path, $candidate->sourcePath);
        $this->assertSame($featured->thumb_url, $candidate->thumbnailUrl);
        $this->assertSame('Layered red and gold forms.', $candidate->altText);
        $this->assertSame(PostMediaType::Collection, $candidate->sourceType);
        $this->assertSame($collection->getKey(), $candidate->sourceId);
    }

    public function test_collection_cover_matches_the_full_public_showcase_assignment(): void
    {
        $targetCollection = Collection::query()->create([
            'title' => 'Target public collection',
            'published' => true,
            'featured' => true,
            'sort_order' => 10,
        ]);
        $shared = $this->artwork('Shared featured work', true, ['featured' => true]);
        $alternate = $this->artwork('Alternate featured work', true, ['featured' => true]);
        $this->store($shared->image_path);
        $this->store($alternate->image_path);
        $targetCollection->artworks()->attach([$shared->getKey(), $alternate->getKey()]);
        $selector = app(CollectionCoverService::class);
        $isolated = $selector
            ->select(new EloquentCollection([$targetCollection]))
            ->get($targetCollection->getKey());
        $otherCollection = Collection::query()->create([
            'title' => 'Other public collection',
            'published' => true,
            'sort_order' => 20,
        ]);
        $otherCollection->artworks()->attach($isolated);

        $publicCollections = Collection::query()
            ->published()
            ->orderByDesc('featured')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $expected = $selector->select($publicCollections)
            ->get($targetCollection->getKey());
        $candidate = app(JournalSourceImageResolver::class)->resolve($targetCollection);

        $this->assertNotNull($isolated);
        $this->assertNotNull($expected);
        $this->assertNotNull($candidate);
        $this->assertNotSame($isolated->getKey(), $expected->getKey());
        $this->assertSame($expected->availableDisplayPath(), $candidate->sourcePath);
    }

    public function test_album_respects_the_current_cover_preference_without_exposing_raw_paths(): void
    {
        $artwork = $this->artwork('Library cover', true, [
            'alt_text' => 'Blue lines crossing a pale circle.',
            'display_path' => 'artworks/display/album-cover.jpg',
        ]);
        $this->store($artwork->display_path);
        $this->store('albums/embedded/cover.png');
        $album = Album::query()->create([
            'title' => 'Source Album',
            'cover_artwork_id' => $artwork->getKey(),
            'embedded_cover_path' => 'albums/embedded/cover.png',
            'cover_preference' => 'auto',
            'published' => true,
        ]);
        $resolver = app(JournalSourceImageResolver::class);

        $artworkCandidate = $resolver->resolve($album);
        $this->assertNotNull($artworkCandidate);
        $this->assertSame($artwork->display_path, $artworkCandidate->sourcePath);
        $this->assertSame($artwork->thumb_url, $artworkCandidate->thumbnailUrl);
        $this->assertSame(PostMediaType::Album, $artworkCandidate->sourceType);

        $album->update(['cover_preference' => 'embedded']);
        $embeddedCandidate = $resolver->resolve($album->refresh());
        $this->assertNotNull($embeddedCandidate);
        $this->assertSame('albums/embedded/cover.png', $embeddedCandidate->sourcePath);
        $this->assertSame('Cover of Source Album', $embeddedCandidate->altText);
        $this->assertStringStartsWith(route('media.albums.embedded-cover', $album), $embeddedCandidate->thumbnailUrl);
        $this->assertStringNotContainsString('/storage/', $embeddedCandidate->thumbnailUrl);

        $album->update(['cover_preference' => 'none']);
        $this->assertNull($resolver->resolve($album->refresh()));
    }

    public function test_playlist_and_track_resolve_only_their_existing_public_cover_paths(): void
    {
        $artwork = $this->artwork('Music artwork', true, [
            'display_path' => 'artworks/display/music.jpg',
        ]);
        $this->store($artwork->display_path);
        $playlist = Playlist::query()->create([
            'title' => 'Public playlist',
            'cover_artwork_id' => $artwork->getKey(),
            'published' => true,
        ]);
        $album = Album::query()->create([
            'title' => 'Public album',
            'embedded_cover_path' => 'albums/embedded/public.png',
            'cover_preference' => 'embedded',
            'published' => true,
        ]);
        $this->store($album->embedded_cover_path);
        $track = Track::withoutEvents(fn (): Track => Track::query()->create([
            'album_id' => $album->getKey(),
            'title' => 'Inherited album track',
            'slug' => 'inherited-album-track',
            'audio_path' => 'tracks/inherited.mp3',
            'standalone_published' => false,
        ]));
        $resolver = app(JournalSourceImageResolver::class);

        $playlistCandidate = $resolver->resolve($playlist);
        $this->assertNotNull($playlistCandidate);
        $this->assertSame($artwork->display_path, $playlistCandidate->sourcePath);
        $this->assertSame(PostMediaType::Playlist, $playlistCandidate->sourceType);

        $trackCandidate = $resolver->resolve($track);
        $this->assertNotNull($trackCandidate);
        $this->assertSame($album->embedded_cover_path, $trackCandidate->sourcePath);
        $this->assertSame(PostMediaType::Track, $trackCandidate->sourceType);
        $this->assertSame($track->getKey(), $trackCandidate->sourceId);

        $track->update(['cover_artwork_id' => $artwork->getKey()]);
        $directCandidate = $resolver->resolve($track->refresh());
        $this->assertNotNull($directCandidate);
        $this->assertSame($artwork->display_path, $directCandidate->sourcePath);
    }

    public function test_missing_or_non_public_source_bytes_fail_closed(): void
    {
        $missing = $this->artwork('Missing artwork', true);
        $future = $this->artwork('Future artwork', true, [
            'published_at' => now()->addDay(),
        ]);
        $this->store($future->image_path);
        $resolver = app(JournalSourceImageResolver::class);

        $this->assertNull($resolver->resolve($missing));
        $this->assertNull($resolver->resolve($future));
    }

    /** @param array<string, mixed> $attributes */
    private function artwork(string $title, bool $published, array $attributes = []): Artwork
    {
        static $sequence = 0;
        $sequence++;

        return Artwork::withoutEvents(fn (): Artwork => Artwork::query()->create(array_replace([
            'title' => $title,
            'slug' => 'resolver-artwork-'.$sequence,
            'image_path' => 'artworks/originals/resolver-'.$sequence.'.png',
            'published' => $published,
            'published_at' => null,
        ], $attributes)));
    }

    private function store(string $path): void
    {
        Storage::disk('local')->put($path, base64_decode(self::PNG, true));
    }
}
