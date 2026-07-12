<?php

namespace Tests\Feature;

use App\Models\Artwork;
use App\Models\Collection;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GalleryCursorPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_archive_has_stable_cursor_pages_with_durable_artwork_links_and_an_accessible_fallback(): void
    {
        $createdAt = now()->subDay()->startOfSecond();
        $tag = Tag::query()->create(['name' => 'archive']);
        $artworks = collect();

        foreach (range(1, 55) as $sequence) {
            $artwork = Artwork::withoutEvents(fn (): Artwork => Artwork::query()->create([
                'title' => 'Archive Frame '.$sequence,
                'slug' => 'archive-frame-'.$sequence,
                'image_path' => 'artworks/originals/archive-frame-'.$sequence.'.jpg',
                'published' => true,
                'sort_order' => 10,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]));
            $artwork->tags()->attach($tag, ['category' => 'subject']);
            $artworks->push($artwork);
        }

        $firstResponse = $this->get(route('gallery', ['tag' => $tag->slug]));
        $firstResponse
            ->assertOk()
            ->assertSee('data-gallery-results', false)
            ->assertSee('data-gallery-pagination', false)
            ->assertSee('data-gallery-load-status aria-live="polite" aria-atomic="true"', false)
            ->assertSee('data-gallery-load-more', false)
            ->assertSee('href="'.route('artworks.show', $artworks->last()).'"', false)
            ->assertSee('aria-label="Quick view Archive Frame 55"', false);

        $firstIds = $this->artworkIds($firstResponse->getContent());
        $nextPageUrl = $this->nextPageUrl($firstResponse->getContent());

        $this->assertCount(48, $firstIds);
        $this->assertSame($artworks->sortByDesc('id')->take(48)->pluck('id')->all(), $firstIds);
        $this->assertStringContainsString('tag=archive', $nextPageUrl);
        $this->assertStringEndsWith('#gallery', $nextPageUrl);

        $secondResponse = $this->get($nextPageUrl);
        $secondIds = $this->artworkIds($secondResponse->getContent());

        $this->assertCount(7, $secondIds);
        $this->assertCount(55, array_unique([...$firstIds, ...$secondIds]));
        $this->assertSame($artworks->sortByDesc('id')->skip(48)->pluck('id')->all(), $secondIds);
        $secondResponse->assertDontSee('data-gallery-load-more', false);
    }

    public function test_collection_cursor_links_keep_the_collection_tag_and_gallery_anchor(): void
    {
        $collection = Collection::query()->create(['title' => 'Quiet Archive', 'published' => true]);
        $tag = Tag::query()->create(['name' => 'quiet']);

        foreach (range(1, 49) as $sequence) {
            $artwork = Artwork::withoutEvents(fn (): Artwork => Artwork::query()->create([
                'title' => 'Quiet Frame '.$sequence,
                'slug' => 'quiet-frame-'.$sequence,
                'image_path' => 'artworks/originals/quiet-frame-'.$sequence.'.jpg',
                'published' => true,
            ]));
            $collection->artworks()->attach($artwork);
            $artwork->tags()->attach($tag, ['category' => 'mood']);
        }

        $response = $this->get(route('collections.show', $collection).'?tag='.$tag->slug);
        $nextPageUrl = $this->nextPageUrl($response->getContent());

        $response->assertOk()->assertSee('48 of 49 frames loaded');
        $this->assertStringStartsWith(route('collections.show', $collection), $nextPageUrl);
        $this->assertStringContainsString('tag=quiet', $nextPageUrl);
        $this->assertStringEndsWith('#gallery', $nextPageUrl);
    }

    public function test_cursor_loading_is_progressively_enhanced_and_rebinds_dynamic_lightbox_items(): void
    {
        $source = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString('function setupGalleryPagination(signal)', $source);
        $this->assertStringContainsString('fetch(link.href, {', $source);
        $this->assertStringContainsString('new DOMParser().parseFromString', $source);
        $this->assertStringContainsString('results.append(...nextItems)', $source);
        $this->assertStringContainsString("document.addEventListener('click', (event) => {", $source);
        $this->assertStringContainsString("event.target.closest('[data-lightbox]')", $source);
        $this->assertStringContainsString("firstNewItem.querySelector('.art-tile-link')?.focus", $source);
    }

    /** @return array<int, int> */
    private function artworkIds(string $html): array
    {
        preg_match_all('/data-gallery-artwork-id="(\d+)"/', $html, $matches);

        return array_map('intval', $matches[1]);
    }

    private function nextPageUrl(string $html): string
    {
        preg_match('/href="([^"]+)" data-gallery-load-more/', $html, $matches);

        $this->assertArrayHasKey(1, $matches, 'Expected the gallery to render a load-more URL.');

        return html_entity_decode($matches[1], ENT_QUOTES);
    }
}
