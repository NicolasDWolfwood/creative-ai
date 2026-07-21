<?php

namespace Tests\Feature;

use App\Models\Artwork;
use App\Models\Collection;
use App\Services\CollectionCoverService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CollectionCoverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_collection_cover_prefers_a_visible_featured_member(): void
    {
        $collection = $this->collection('Eligible work', ['hero_image_path' => 'collections/heroes/legacy.jpg']);
        $unfeatured = $this->artwork('Published but not featured');
        $unpublished = $this->artwork('Featured but not published', featured: true, published: false);
        $eligible = $this->artwork('Published and featured', featured: true);
        $collection->artworks()->attach([$unfeatured->id, $unpublished->id, $eligible->id]);

        $covers = $this->covers([$collection]);

        $this->assertSame($eligible->id, $covers->get($collection->id)?->id);
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('data-cover-artwork-id="'.$eligible->id.'"', escape: false)
            ->assertSee($eligible->thumb_url, escape: false);
    }

    public function test_cover_selection_is_stable_during_a_day_and_rotates_on_the_next_day(): void
    {
        $collection = $this->collection('Daily rotation');
        $first = $this->artwork('First', featured: true);
        $second = $this->artwork('Second', featured: true);
        $collection->artworks()->attach([$first->id, $second->id]);
        $collections = Collection::query()->whereKey($collection->id)->get();
        $day = CarbonImmutable::parse('2026-07-11 09:00:00', 'Europe/Amsterdam');
        $selector = app(CollectionCoverService::class);

        $morningCover = $selector->select($collections, $day)->get($collection->id)?->id;
        $eveningCover = $selector->select($collections, $day->setTime(22, 0))->get($collection->id)?->id;
        $nextDayCover = $selector->select($collections, $day->addDay())->get($collection->id)?->id;

        $this->assertSame($morningCover, $eveningCover);
        $this->assertNotSame($morningCover, $nextDayCover);
    }

    public function test_unique_covers_are_selected_when_overlapping_candidates_allow_it(): void
    {
        $flexible = $this->collection('Flexible');
        $constrained = $this->collection('Constrained');
        $shared = $this->artwork('Shared', featured: true);
        $alternative = $this->artwork('Alternative', featured: true);
        $flexible->artworks()->attach([$shared->id, $alternative->id]);
        $constrained->artworks()->attach($shared->id);

        $covers = $this->covers([$flexible, $constrained]);
        $selectedIds = $covers->map(fn (?Artwork $artwork): ?int => $artwork?->id)->all();

        $this->assertCount(2, array_unique($selectedIds));
        $this->assertSame($shared->id, $covers->get($constrained->id)?->id);
        $this->assertSame($alternative->id, $covers->get($flexible->id)?->id);
    }

    public function test_duplicate_records_for_the_same_source_media_do_not_count_as_unique_covers(): void
    {
        $flexible = $this->collection('Flexible duplicate records');
        $constrained = $this->collection('Constrained duplicate record');
        $shared = $this->artwork('Shared source', featured: true);
        $duplicate = Artwork::withoutEvents(fn (): Artwork => Artwork::query()->create([
            'title' => 'Shared source duplicate',
            'slug' => 'shared-source-duplicate',
            'image_path' => $shared->image_path,
            'featured' => true,
            'published' => true,
        ]));
        $alternative = $this->artwork('Different source', featured: true);
        $flexible->artworks()->attach([$shared->id, $alternative->id]);
        $constrained->artworks()->attach($duplicate->id);

        $covers = $this->covers([$flexible, $constrained]);

        $this->assertSame($alternative->image_path, $covers->get($flexible->id)?->image_path);
        $this->assertSame($shared->image_path, $covers->get($constrained->id)?->image_path);
        $this->assertNotSame(
            $covers->get($flexible->id)?->image_path,
            $covers->get($constrained->id)?->image_path,
        );
    }

    public function test_collection_without_featured_artwork_uses_any_usable_member_as_a_fallback(): void
    {
        $collection = $this->collection('No featured cover', [
            'hero_image_path' => 'collections/heroes/legacy.jpg',
        ]);
        $unfeatured = $this->artwork('Not eligible');
        $collection->artworks()->attach($unfeatured->id);

        $covers = $this->covers([$collection]);

        $this->assertSame($unfeatured->id, $covers->get($collection->id)?->id);
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('data-cover-artwork-id="'.$unfeatured->id.'"', escape: false)
            ->assertSee($unfeatured->thumb_url, escape: false)
            ->assertDontSee('/storage/collections/heroes/legacy.jpg', escape: false)
            ->assertDontSee('data-collection-cover-placeholder', escape: false);
    }

    public function test_featured_artwork_with_no_available_media_uses_the_neutral_placeholder(): void
    {
        $collection = $this->collection('Missing featured media');
        $missing = $this->artwork('Missing file', featured: true, storeMedia: false);
        $collection->artworks()->attach($missing->id);

        $this->assertNull($this->covers([$collection])->get($collection->id));
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('data-collection-cover-placeholder', escape: false)
            ->assertDontSee('data-cover-artwork-id="'.$missing->id.'"', escape: false);
    }

    public function test_cover_candidate_scan_finds_a_usable_fallback_after_a_missing_first_batch(): void
    {
        $collection = $this->collection('Bounded cover candidates');
        $usable = $this->artwork('Old usable fallback');
        $memberIds = [$usable->id];

        for ($index = 1; $index <= CollectionCoverService::CANDIDATE_BATCH_SIZE; $index++) {
            $memberIds[] = $this->artwork('Missing recent fallback '.$index, storeMedia: false)->id;
        }

        $collection->artworks()->attach($memberIds);
        $collections = Collection::query()->whereKey($collection)->get();
        $cover = app(CollectionCoverService::class)
            ->select($collections, CarbonImmutable::parse('2026-07-11'))
            ->get($collection->id);

        $this->assertSame($usable->id, $cover?->id);
    }

    /**
     * @param  array<int, Collection>  $collections
     * @return \Illuminate\Support\Collection<int, Artwork|null>
     */
    protected function covers(array $collections): \Illuminate\Support\Collection
    {
        $models = Collection::query()
            ->whereKey(collect($collections)->pluck('id'))
            ->orderBy('id')
            ->get();

        return app(CollectionCoverService::class)->select($models, CarbonImmutable::parse('2026-07-11'));
    }

    /** @param array<string, mixed> $overrides */
    protected function collection(string $title, array $overrides = []): Collection
    {
        return Collection::query()->create(array_replace([
            'title' => $title,
            'published' => true,
        ], $overrides));
    }

    protected function artwork(
        string $title,
        bool $featured = false,
        bool $published = true,
        bool $storeMedia = true,
    ): Artwork {
        $slug = str($title)->slug()->toString();
        $artwork = Artwork::withoutEvents(fn (): Artwork => Artwork::query()->create([
            'title' => $title,
            'slug' => $slug,
            'image_path' => 'artworks/originals/'.$slug.'.jpg',
            'thumb_path' => 'artworks/thumbs/'.$slug.'.webp',
            'featured' => $featured,
            'published' => $published,
        ]));

        if ($storeMedia) {
            Storage::disk('public')->put($artwork->image_path, 'test image');
        }

        return $artwork;
    }
}
