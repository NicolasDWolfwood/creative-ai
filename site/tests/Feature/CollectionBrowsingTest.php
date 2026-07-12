<?php

namespace Tests\Feature;

use App\Models\Artwork;
use App\Models\Collection;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CollectionBrowsingTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_collection_tiles_land_at_the_gallery_without_duplicating_the_compact_switcher(): void
    {
        $collection = $this->collection('Dream Atlas');

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('href="'.route('collections.show', $collection).'#gallery"', false)
            ->assertDontSee('data-collection-switcher', false);
    }

    public function test_collection_view_renders_an_anchored_switcher_with_an_active_collection_and_all_artwork_option(): void
    {
        $selected = $this->collection('Dream Atlas');
        $alternative = $this->collection('Quiet Machines');

        $response = $this->get(route('collections.show', $selected));

        $response
            ->assertOk()
            ->assertSee('id="gallery" aria-labelledby="gallery-title" data-gallery-target', false)
            ->assertSee('id="gallery-title" data-gallery-focus-target tabindex="-1"', false)
            ->assertSee('aria-label="Browse artwork collections" data-collection-switcher', false)
            ->assertSee('href="'.route('gallery').'#gallery"', false)
            ->assertSee('href="'.route('collections.show', $selected).'#gallery"', false)
            ->assertSee('href="'.route('collections.show', $alternative).'#gallery"', false)
            ->assertSee('data-navigation-focus-key="collection-switcher-'.$selected->getKey().'"', false)
            ->assertSee('collection-switcher-current', false)
            ->assertSee('Selected collection')
            ->assertSee($selected->title)
            ->assertSee($selected->description);

        $this->assertMatchesRegularExpression(
            '/class="collection-switcher-link active" href="'.preg_quote(route('collections.show', $selected).'#gallery', '/').'"\s+aria-current="page"\s+data-collection-switcher-item/',
            $response->getContent(),
        );
    }

    public function test_full_archive_marks_all_artwork_as_the_current_switcher_option(): void
    {
        $this->collection('Dream Atlas');

        $response = $this->get(route('gallery'));

        $response->assertOk();
        $response->assertSee('<h2 id="gallery-title" data-gallery-focus-target tabindex="-1">All artwork</h2>', false);

        $this->assertMatchesRegularExpression(
            '/class="collection-switcher-link active" href="'.preg_quote(route('gallery').'#gallery', '/').'"\s+aria-current="page"\s+data-collection-switcher-item/',
            $response->getContent(),
        );
    }

    public function test_collection_tag_filters_keep_the_visitor_at_the_gallery(): void
    {
        $collection = $this->collection('Dream Atlas');
        $tag = Tag::query()->create(['name' => 'Moonlight']);
        $artwork = Artwork::withoutEvents(fn (): Artwork => Artwork::query()->create([
            'title' => 'Silver Path',
            'slug' => 'silver-path',
            'image_path' => 'artworks/originals/silver-path.jpg',
            'published' => true,
        ]));
        $outsideArtwork = Artwork::withoutEvents(fn (): Artwork => Artwork::query()->create([
            'title' => 'Distant Moon',
            'slug' => 'distant-moon',
            'image_path' => 'artworks/originals/distant-moon.jpg',
            'published' => true,
        ]));

        $collection->artworks()->attach($artwork);
        $artwork->tags()->attach($tag, ['category' => 'subject']);
        $outsideArtwork->tags()->attach($tag, ['category' => 'subject']);

        $response = $this->get(route('collections.show', $collection).'?tag='.$tag->slug);

        $response
            ->assertOk()
            ->assertSee('href="'.route('collections.show', $collection).'#gallery"', false)
            ->assertSee('href="'.route('collections.show', $collection).'?tag='.$tag->slug.'#gallery"', false)
            ->assertSee('<h2 id="gallery-title" data-gallery-focus-target tabindex="-1">'.$collection->title.'</h2>', false)
            ->assertSee('Filtered by <strong>'.$tag->name.'</strong>', false)
            ->assertSee('aria-label="Artwork tags" data-tag-filter-strip', false);

        $this->assertMatchesRegularExpression(
            '/href="'.preg_quote(route('collections.show', $collection).'?tag='.$tag->slug.'#gallery', '/').'"\s+aria-current="page"\s+data-navigation-focus-key="tag-filter-'.$tag->getKey().'"\s+wire:navigate>'.$tag->name.'<span>1<\/span>/',
            $response->getContent(),
        );

        $galleryResponse = $this->get(route('gallery', ['tag' => $tag->slug]));

        $galleryResponse->assertOk();

        $this->assertMatchesRegularExpression(
            '/class="collection-switcher-link active" href="'.preg_quote(route('gallery', ['tag' => $tag->slug]).'#gallery', '/').'"\s+aria-current="page"\s+data-collection-switcher-item/',
            $galleryResponse->getContent(),
        );
    }

    public function test_collection_navigation_styles_define_a_header_offset_focus_ring_and_reduced_motion_fallback(): void
    {
        $source = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('scroll-margin-top: calc(var(--header-height) + 12px)', $source);
        $this->assertStringContainsString('.collection-switcher-link:focus-visible', $source);
        $this->assertStringContainsString('#gallery-title:focus-visible', $source);
        $this->assertStringNotContainsString('.gallery-section:focus {', $source);
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $source);
        $this->assertStringContainsString('scroll-behavior: auto !important', $source);
    }

    protected function collection(string $title): Collection
    {
        return Collection::query()->create([
            'title' => $title,
            'description' => $title.' description.',
            'published' => true,
        ]);
    }
}
