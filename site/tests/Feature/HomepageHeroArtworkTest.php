<?php

namespace Tests\Feature;

use App\Models\Artwork;
use App\Models\Tag;
use App\Services\HomepageHeroArtworkService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HomepageHeroArtworkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-07-22 12:00:00 UTC');
        Cache::clear();
        Storage::fake('local');
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_featured_only_artwork_can_supply_the_homepage_display_without_becoming_public_elsewhere(): void
    {
        $artwork = $this->artwork('Homepage only', featured: true, description: 'A narrow public highlight.');

        $home = $this->get(route('home'));

        $home
            ->assertOk()
            ->assertViewHas('heroArtwork', fn (?Artwork $hero): bool => $hero?->is($artwork) === true)
            ->assertViewHas('heroImageUrl', $artwork->homepage_display_url)
            ->assertViewHas('totalArtworkCount', 0)
            ->assertViewHas('seo', fn (array $seo): bool => ($seo['image'] ?? null) === url($artwork->homepage_display_url))
            ->assertSee($artwork->homepage_display_url, escape: false)
            ->assertSee('Homepage only')
            ->assertSee('No published artwork yet.');

        $this->get($artwork->homepage_display_url)
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-cache, private');

        foreach ([$artwork->public_image_url, $artwork->display_url, $artwork->thumb_url] as $genericUrl) {
            $this->get($genericUrl)->assertNotFound();
        }

        $this->get(route('artworks.show', $artwork))->assertNotFound();
        $this->get(route('gallery'))
            ->assertOk()
            ->assertDontSee('data-gallery-artwork-id="'.$artwork->getKey().'"', escape: false);
        $this->get(route('sitemap'))
            ->assertOk()
            ->assertDontSee(route('artworks.show', $artwork), escape: false);
    }

    public function test_homepage_rotation_uses_featured_or_standalone_published_artwork_and_is_stable_for_a_day(): void
    {
        $featured = $this->artwork('Featured draft', featured: true);
        $published = $this->artwork('Published work', published: true);
        $selector = app(HomepageHeroArtworkService::class);

        $first = $selector->select();
        $sameDay = $selector->select();

        $this->assertNotNull($first);
        $this->assertTrue($first->is($featured) || $first->is($published));
        $this->assertTrue($sameDay?->is($first));
        $this->assertSame(
            $first->getKey(),
            Cache::get('showcase.homepage-hero.v1:2026-07-22'),
        );

        $this->travel(1)->day();

        $nextDay = $selector->select();

        $this->assertNotNull($nextDay);
        $this->assertFalse($nextDay->is($first));
        $this->assertEqualsCanonicalizing(
            [$featured->getKey(), $published->getKey()],
            [$first->getKey(), $nextDay->getKey()],
        );
    }

    public function test_cached_selection_stays_stable_when_candidates_are_added_and_reselects_when_it_becomes_invalid(): void
    {
        $this->artwork('First candidate', featured: true);
        $this->artwork('Second candidate', published: true);
        $selector = app(HomepageHeroArtworkService::class);
        $selected = $selector->select();
        $added = $this->artwork('Added candidate', featured: true);

        $this->assertTrue($selector->select()?->is($selected));

        $selected->forceFill(['featured' => false, 'published' => false])->saveQuietly();
        $afterEligibilityChange = $selector->select();

        $this->assertNotNull($afterEligibilityChange);
        $this->assertFalse($afterEligibilityChange->is($selected));

        Storage::disk('local')->delete($afterEligibilityChange->image_path);
        $afterFileRemoval = $selector->select();

        $this->assertNotNull($afterFileRemoval);
        $this->assertFalse($afterFileRemoval->is($afterEligibilityChange));
        $this->assertFalse($afterFileRemoval->is($selected));
        $this->assertTrue($afterFileRemoval->isHomepageHeroEligible());
        $this->assertTrue($afterFileRemoval->hasAvailableImage());
        $this->assertTrue($afterFileRemoval->is($added)
            || in_array($afterFileRemoval->title, ['First candidate', 'Second candidate'], true));
    }

    public function test_homepage_skips_a_thumb_only_candidate_and_serves_a_display_capable_one(): void
    {
        $thumbOnly = $this->artwork('Thumb only', featured: true);
        $thumbPath = 'artworks/thumbs/thumb-only.jpg';
        Storage::disk('local')->put($thumbPath, 'thumb-only-image');
        Storage::disk('local')->delete($thumbOnly->image_path);
        $thumbOnly->forceFill(['thumb_path' => $thumbPath])->saveQuietly();
        $displayCapable = $this->artwork('Display capable', published: true);
        Cache::put('showcase.homepage-hero.v1:2026-07-22', $thumbOnly->getKey(), now()->addDay());

        $this->assertTrue($thumbOnly->hasAvailableImage());
        $this->assertFalse($thumbOnly->hasAvailableDisplayImage());

        $selected = app(HomepageHeroArtworkService::class)->select();

        $this->assertTrue($selected?->is($displayCapable));
        $this->get(route('home'))
            ->assertOk()
            ->assertViewHas('heroArtwork', fn (?Artwork $hero): bool => $hero?->is($displayCapable) === true);
        $this->get($displayCapable->homepage_display_url)->assertOk();
        $this->get($thumbOnly->homepage_display_url)->assertNotFound();
    }

    public function test_future_date_embargoes_featured_homepage_media_until_due_without_negative_caching(): void
    {
        $future = $this->artwork('Future feature', featured: true, publishedAt: now()->addHour());

        $this->assertFalse($future->isHomepageHeroEligible());
        $this->assertFalse(Artwork::query()->homepageHeroEligible()->whereKey($future)->exists());
        $this->get(route('home'))->assertViewHas('heroArtwork', null);
        $this->get($future->homepage_display_url)->assertNotFound();

        $this->travel(2)->hours();

        $this->assertTrue($future->refresh()->isHomepageHeroEligible());
        $this->assertTrue(Artwork::query()->homepageHeroEligible()->whereKey($future)->exists());
        $this->get(route('home'))
            ->assertOk()
            ->assertViewHas('heroArtwork', fn (?Artwork $hero): bool => $hero?->is($future) === true);
        $this->get($future->homepage_display_url)->assertOk();
    }

    public function test_tag_filtered_homepage_and_gallery_keep_their_published_context_hero(): void
    {
        $featuredOnly = $this->artwork('Global feature', featured: true, sortOrder: 100);
        $tagged = $this->artwork('Tagged published', published: true, sortOrder: 1);
        $tag = Tag::query()->create(['name' => 'Quiet', 'slug' => 'quiet']);
        $tagged->tags()->attach($tag, ['category' => 'mood']);

        $this->get(route('home', ['tag' => $tag->slug]))
            ->assertOk()
            ->assertViewHas('heroArtwork', fn (?Artwork $hero): bool => $hero?->is($tagged) === true)
            ->assertViewHas('heroImageUrl', $tagged->display_url)
            ->assertDontSee($featuredOnly->homepage_display_url, escape: false);

        $this->get(route('gallery'))
            ->assertOk()
            ->assertViewHas('heroArtwork', fn (?Artwork $hero): bool => $hero?->is($tagged) === true)
            ->assertViewHas('heroImageUrl', $tagged->display_url);
    }

    private function artwork(
        string $title,
        bool $featured = false,
        bool $published = false,
        mixed $publishedAt = null,
        ?string $description = null,
        int $sortOrder = 0,
    ): Artwork {
        $slug = str($title)->slug()->toString();
        $path = 'artworks/originals/'.$slug.'.jpg';
        Storage::disk('local')->put($path, 'image-'.$slug);

        return Artwork::withoutEvents(fn (): Artwork => Artwork::query()->create([
            'title' => $title,
            'slug' => $slug,
            'description' => $description,
            'image_path' => $path,
            'featured' => $featured,
            'published' => $published,
            'published_at' => $publishedAt,
            'sort_order' => $sortOrder,
        ]));
    }
}
