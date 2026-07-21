<?php

namespace Tests\Feature;

use App\Models\Artwork;
use App\Models\Collection;
use App\Services\CollectionCoverService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CollectionMemberPublicationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_collection_publication_is_an_explicit_scheduled_and_revocable_availability_grant(): void
    {
        $artwork = $this->artwork('Collection only');
        $disabled = $this->collection('Disabled grant', publishesMembers: false);
        $future = $this->collection('Future grant', publishedAt: now()->addDay());
        $first = $this->collection('First grant');
        $second = $this->collection('Second grant');
        $artwork->collections()->attach([$disabled->id, $future->id]);

        $this->assertFalse($disabled->publishes_members);
        $this->assertFalse($artwork->isPubliclyPublished());
        $this->assertFalse($artwork->isPubliclyAvailable());
        $this->assertFalse(Artwork::query()->publiclyAvailable()->whereKey($artwork)->exists());

        $artwork->collections()->attach([$first->id, $second->id]);

        $this->assertTrue($artwork->isPubliclyAvailable());
        $this->assertTrue(Artwork::query()->publiclyAvailable()->whereKey($artwork)->exists());
        $this->assertFalse($artwork->refresh()->published);

        $first->update(['published' => false]);

        $this->assertTrue($artwork->isPubliclyAvailable());

        $second->update(['published' => false]);

        $this->assertFalse($artwork->isPubliclyAvailable());
        $this->assertFalse(Artwork::query()->publiclyAvailable()->whereKey($artwork)->exists());
    }

    public function test_collection_only_artwork_is_public_in_its_collection_but_not_the_global_archive(): void
    {
        $collection = $this->collection('Public room');
        $inherited = $this->artwork('Inside the room');
        $standalone = $this->artwork('Standalone work', published: true);
        $collection->artworks()->attach($inherited);

        $home = $this->get(route('home'));

        $home
            ->assertOk()
            ->assertViewHas('totalArtworkCount', 1)
            ->assertSee('data-cover-artwork-id="'.$inherited->id.'"', false)
            ->assertDontSee('data-gallery-artwork-id="'.$inherited->id.'"', false)
            ->assertSee('data-gallery-artwork-id="'.$standalone->id.'"', false);

        $this->get(route('gallery'))
            ->assertOk()
            ->assertViewHas('archiveArtworkCount', 1)
            ->assertDontSee('data-gallery-artwork-id="'.$inherited->id.'"', false)
            ->assertSee('data-gallery-artwork-id="'.$standalone->id.'"', false);

        $collectionNeighbor = $this->artwork('Another room work');
        $collection->artworks()->attach($collectionNeighbor);
        $contextUrl = route('artworks.show', [
            'artwork' => $inherited,
            'collection' => $collection->slug,
        ]);

        $this->get(route('collections.show', $collection))
            ->assertOk()
            ->assertViewHas('archiveArtworkCount', 2)
            ->assertSee('data-gallery-artwork-id="'.$inherited->id.'"', false)
            ->assertSee('data-gallery-artwork-id="'.$collectionNeighbor->id.'"', false)
            ->assertSee('href="'.$contextUrl.'"', false)
            ->assertDontSee('data-gallery-artwork-id="'.$standalone->id.'"', false);

        $directDetail = $this->get(route('artworks.show', $inherited));
        $directDetail
            ->assertOk()
            ->assertViewHas('collectionContext', fn (?Collection $context): bool => $context?->is($collection) === true)
            ->assertViewHas('previousArtwork', fn (?Artwork $artwork): bool => $artwork?->is($collectionNeighbor) === true)
            ->assertViewHas('nextArtwork', fn (?Artwork $artwork): bool => $artwork === null);
        $renderedGraph = collect($this->decodeStructuredData($directDetail)['@graph'] ?? [])->keyBy('@type');
        $this->assertSame(
            $collection->published_at->toIso8601String(),
            $renderedGraph->get('VisualArtwork')['datePublished'] ?? null,
        );

        $this->get($contextUrl)
            ->assertOk()
            ->assertSee('<link rel="canonical" href="'.route('artworks.show', $inherited).'">', false)
            ->assertSee(route('collections.show', $collection).'#gallery', false)
            ->assertSee(route('artworks.show', [
                'artwork' => $collectionNeighbor,
                'collection' => $collection->slug,
            ]), false);

        $this->get(route('artworks.show', [
            'artwork' => $inherited,
            'collection' => 'not-a-real-collection',
        ]))->assertNotFound();

        foreach ([$inherited->public_image_url, $inherited->display_url, $inherited->thumb_url] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-cache, private');
        }

        $this->get(route('sitemap'))
            ->assertOk()
            ->assertSee(route('artworks.show', $inherited), false)
            ->assertSee(route('artworks.show', $standalone), false);

        $collection->update(['published' => false]);

        $this->assertFalse($inherited->isPubliclyAvailable());
        $this->get(route('artworks.show', $inherited))->assertNotFound();
        $this->get($inherited->public_image_url)->assertNotFound();
        $this->get($inherited->thumb_url)->assertNotFound();
        $this->assertFalse($inherited->refresh()->published);
    }

    public function test_a_collection_grant_honors_an_artwork_future_date_without_needing_a_due_time_resync(): void
    {
        $collection = $this->collection('Scheduled room');
        $artwork = $this->artwork('Scheduled collection member');
        $artwork->update(['published_at' => now()->addHour()]);
        $collection->artworks()->attach($artwork);

        $this->assertFalse($artwork->refresh()->isPubliclyAvailable());
        $this->assertFalse(Artwork::query()->publiclyAvailable()->whereKey($artwork)->exists());
        $this->get(route('collections.show', $collection))
            ->assertOk()
            ->assertDontSee('data-gallery-artwork-id="'.$artwork->id.'"', false);
        $this->get(route('artworks.show', $artwork))->assertNotFound();
        $this->get($artwork->thumb_url)->assertNotFound();

        $this->travel(2)->hours();

        $this->assertTrue($artwork->refresh()->isPubliclyAvailable());
        $this->assertTrue(Artwork::query()->publiclyAvailable()->whereKey($artwork)->exists());
        $this->get(route('collections.show', $collection))
            ->assertOk()
            ->assertSee('data-gallery-artwork-id="'.$artwork->id.'"', false);
        $detail = $this->get(route('artworks.show', $artwork));
        $detail->assertOk();
        $renderedGraph = collect($this->decodeStructuredData($detail)['@graph'] ?? [])->keyBy('@type');
        $this->assertSame(
            $artwork->published_at->toIso8601String(),
            $renderedGraph->get('VisualArtwork')['datePublished'] ?? null,
        );
        $this->get($artwork->thumb_url)->assertOk();
    }

    public function test_collection_cover_prefers_featured_artwork_and_falls_back_to_any_usable_visible_member(): void
    {
        $collection = $this->collection('Cover room');
        $fallback = $this->artwork('Fallback cover');
        $featured = $this->artwork('Featured cover', featured: true);
        $collection->artworks()->attach([$fallback->id, $featured->id]);

        $this->assertSame($featured->id, $this->coverFor($collection)?->id);

        $collection->artworks()->detach($featured);

        $this->assertSame($fallback->id, $this->coverFor($collection)?->id);
        $this->assertFalse($fallback->refresh()->published);
    }

    private function coverFor(Collection $collection): ?Artwork
    {
        $collections = Collection::query()->whereKey($collection)->get();

        return app(CollectionCoverService::class)
            ->select($collections, CarbonImmutable::parse('2026-07-22'))
            ->get($collection->getKey());
    }

    private function artwork(string $title, bool $published = false, bool $featured = false): Artwork
    {
        $slug = str($title)->slug()->toString();
        $path = 'artworks/originals/'.$slug.'.jpg';
        Storage::disk('local')->put($path, 'image-'.$slug);

        return Artwork::withoutEvents(fn (): Artwork => Artwork::query()->create([
            'title' => $title,
            'slug' => $slug,
            'image_path' => $path,
            'published' => $published,
            'published_at' => $published ? now()->subMinute() : null,
            'featured' => $featured,
        ]));
    }

    private function collection(
        string $title,
        bool $publishesMembers = true,
        mixed $publishedAt = null,
    ): Collection {
        return Collection::query()->create([
            'title' => $title,
            'slug' => str($title)->slug()->toString(),
            'published' => true,
            'published_at' => $publishedAt ?: now()->subMinute(),
            'publishes_members' => $publishesMembers,
        ]);
    }
}
