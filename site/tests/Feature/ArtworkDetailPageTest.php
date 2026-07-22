<?php

namespace Tests\Feature;

use App\Models\Artwork;
use App\Models\Collection;
use App\Models\Tag;
use App\Models\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ArtworkDetailPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_published_artwork_has_a_durable_detail_page_with_context_navigation_and_music(): void
    {
        Queue::fake();
        $archiveTime = Carbon::parse('2026-06-01 12:00:00');
        $next = $this->artwork(['title' => 'Archive Next', 'slug' => 'archive-next']);
        $artwork = $this->artwork([
            'title' => 'Chromatic Memory',
            'slug' => 'chromatic-memory',
            'description' => 'A layered study in blue and gold.',
            'alt_text' => 'Blue and gold geometric forms overlap on a dark field.',
            'prompt' => 'Layer translucent geometry over a nocturnal field.',
            'process_notes' => 'Built through three composition passes and a final color study.',
            'width' => 1200,
            'height' => 800,
        ]);
        $previous = $this->artwork(['title' => 'Archive Previous', 'slug' => 'archive-previous']);

        foreach ([$next, $artwork, $previous] as $record) {
            $record->forceFill(['created_at' => $archiveTime, 'updated_at' => $archiveTime])->saveQuietly();
        }

        $draftNeighbor = $this->artwork([
            'title' => 'Draft Neighbor',
            'slug' => 'draft-neighbor',
            'published' => false,
        ]);
        $draftNeighbor->forceFill(['created_at' => $archiveTime])->saveQuietly();
        $scheduledNeighbor = $this->artwork([
            'title' => 'Scheduled Neighbor',
            'slug' => 'scheduled-neighbor',
            'published_at' => now()->addDay(),
        ]);
        $scheduledNeighbor->forceFill(['created_at' => $archiveTime])->saveQuietly();
        $collectionOnly = $this->artwork([
            'title' => 'Collection-only Neighbor',
            'slug' => 'collection-only-neighbor',
            'published' => false,
        ]);
        $collectionOnly->forceFill(['created_at' => $archiveTime])->saveQuietly();
        $featuredOnly = $this->artwork([
            'title' => 'Featured-only Neighbor',
            'slug' => 'featured-only-neighbor',
            'published' => false,
            'featured' => true,
        ]);
        $featuredOnly->forceFill(['created_at' => $archiveTime])->saveQuietly();
        $publicationGrant = Collection::query()->create([
            'title' => 'Collection Publication Grant',
            'slug' => 'collection-publication-grant',
            'published' => true,
            'publishes_members' => true,
        ]);
        $publicationGrant->artworks()->attach($collectionOnly);

        $collection = Collection::query()->create([
            'title' => 'Color Studies',
            'slug' => 'color-studies',
            'published' => true,
        ]);
        $draftCollection = Collection::query()->create([
            'title' => 'Private Studies',
            'slug' => 'private-studies',
            'published' => false,
        ]);
        $artwork->collections()->attach([$collection->id, $draftCollection->id]);

        $tag = Tag::query()->create(['name' => 'dreamlike', 'slug' => 'dreamlike']);
        $artwork->tags()->attach($tag, ['category' => 'mood']);
        $track = Track::query()->create([
            'title' => 'Slow Refraction',
            'slug' => 'slow-refraction',
            'artist' => 'Creative-Ai Studio',
            'audio_path' => 'tracks/audio/slow-refraction.mp3',
            'standalone_published' => true,
        ]);
        $track->tags()->attach($tag, ['category' => 'mood']);

        $response = $this->get(route('artworks.show', $artwork));

        $response
            ->assertOk()
            ->assertViewHas('previousArtwork', fn (?Artwork $record): bool => $record?->is($previous) === true)
            ->assertViewHas('nextArtwork', fn (?Artwork $record): bool => $record?->is($next) === true)
            ->assertViewHas('tracks', fn ($tracks): bool => $tracks->contains($track))
            ->assertViewHas('playerPayload', fn (array $payload): bool => collect($payload)->contains(
                fn (array $source): bool => $source['id'] === 'artwork-'.$artwork->id.'-recommendations'
                    && collect($source['tracks'])->contains('id', $track->id),
            ))
            ->assertSee('Chromatic Memory')
            ->assertSee('Layer translucent geometry over a nocturnal field.')
            ->assertSee('Built through three composition passes and a final color study.')
            ->assertSee('dreamlike')
            ->assertSee('Color Studies')
            ->assertDontSee('Private Studies')
            ->assertSee(route('collections.show', $collection), false)
            ->assertSee(route('artworks.show', $previous), false)
            ->assertSee(route('artworks.show', $next), false)
            ->assertSee(route('music.tracks.show', $track), false)
            ->assertSee('data-playlist-id="artwork-'.$artwork->id.'-recommendations"', false)
            ->assertSee('data-play-track-id="'.$track->id.'"', false)
            ->assertSee('data-queue-track-id="'.$track->id.'"', false);

        $main = $this->mainContent($response->getContent());
        $viewerMatched = preg_match(
            '/<section\b(?=[^>]*\bdata-artwork-viewer\b)[^>]*>/',
            $main,
            $viewerMatches,
            PREG_OFFSET_CAPTURE,
        );
        $detailsPosition = strpos($main, 'data-artwork-details');

        $this->assertSame(1, $viewerMatched, 'The detail page must expose a stable artwork viewer element.');
        $this->assertNotFalse($detailsPosition, 'The below-image title and description need a stable details hook.');
        $viewerPosition = $viewerMatches[0][1] ?? false;
        $this->assertNotFalse($viewerPosition, 'The artwork viewer needs a stable position in the page.');
        $this->assertLessThan($detailsPosition, $viewerPosition, 'The artwork viewer must render before its title and description.');

        $viewerMarkup = substr($main, $viewerPosition, $detailsPosition - $viewerPosition);
        $viewerOpeningTag = $viewerMatches[0][0] ?? '';
        $technicalMetaPosition = strpos($main, 'class="artwork-technical-meta"', $detailsPosition);
        $this->assertNotFalse($technicalMetaPosition, 'Expected technical metadata after the artwork title and description.');
        $detailsMarkup = substr($main, $detailsPosition, $technicalMetaPosition - $detailsPosition);
        $this->assertStringContainsString('id="artwork-viewer"', $viewerOpeningTag);
        $this->assertStringContainsString('tabindex="-1"', $viewerOpeningTag);
        $this->assertStringContainsString('aria-label="Artwork viewer for Chromatic Memory"', $viewerOpeningTag);
        $this->assertStringContainsString($artwork->display_url, $viewerMarkup);
        $this->assertStringContainsString('<nav class="artwork-browser-navigation" aria-label="Browse all published artwork">', $viewerMarkup);
        $this->assertStringContainsString('<h1>Chromatic Memory</h1>', $detailsMarkup);
        $this->assertStringContainsString('A layered study in blue and gold.', $detailsMarkup);

        $previousLink = $this->navigationAnchor($viewerMarkup, 'data-artwork-previous');
        $this->assertStringContainsString('href="'.route('artworks.show', $previous).'"', $previousLink);
        $this->assertStringContainsString('rel="prev"', $previousLink);
        $this->assertStringContainsString('aria-label="Previous artwork: Archive Previous"', $previousLink);
        $this->assertStringContainsString('aria-keyshortcuts="ArrowLeft"', $previousLink);
        $this->assertStringContainsString('wire:navigate', $previousLink);

        $nextLink = $this->navigationAnchor($viewerMarkup, 'data-artwork-next');
        $this->assertStringContainsString('href="'.route('artworks.show', $next).'"', $nextLink);
        $this->assertStringContainsString('rel="next"', $nextLink);
        $this->assertStringContainsString('aria-label="Next artwork: Archive Next"', $nextLink);
        $this->assertStringContainsString('aria-keyshortcuts="ArrowRight"', $nextLink);
        $this->assertStringContainsString('wire:navigate', $nextLink);

        foreach ([$draftNeighbor, $scheduledNeighbor, $collectionOnly, $featuredOnly] as $excludedArtwork) {
            $this->assertStringNotContainsString(route('artworks.show', $excludedArtwork), $main);
        }
    }

    public function test_artwork_navigation_has_explicit_non_wrapping_archive_boundaries(): void
    {
        Queue::fake();
        $first = $this->artwork([
            'title' => 'First Published Frame',
            'slug' => 'first-published-frame',
            'sort_order' => 20,
        ]);
        $last = $this->artwork([
            'title' => 'Last Published Frame',
            'slug' => 'last-published-frame',
            'sort_order' => 10,
        ]);

        $firstResponse = $this->get(route('artworks.show', $first));
        $firstResponse
            ->assertOk()
            ->assertViewHas('previousArtwork', fn (?Artwork $record): bool => $record === null)
            ->assertViewHas('nextArtwork', fn (?Artwork $record): bool => $record?->is($last) === true);
        $firstMain = $this->mainContent($firstResponse->getContent());
        $this->assertStringNotContainsString('data-artwork-previous', $firstMain);
        $this->assertSame(1, substr_count($firstMain, 'data-artwork-next'));
        $this->assertMatchesRegularExpression(
            '/<div\b(?=[^>]*\bdata-artwork-boundary="start")(?=[^>]*\baria-disabled="true")[^>]*>/',
            $firstMain,
        );
        $this->assertStringContainsString('Start of published archive', $firstMain);

        $lastResponse = $this->get(route('artworks.show', $last));
        $lastResponse
            ->assertOk()
            ->assertViewHas('previousArtwork', fn (?Artwork $record): bool => $record?->is($first) === true)
            ->assertViewHas('nextArtwork', fn (?Artwork $record): bool => $record === null);
        $lastMain = $this->mainContent($lastResponse->getContent());
        $this->assertSame(1, substr_count($lastMain, 'data-artwork-previous'));
        $this->assertStringNotContainsString('data-artwork-next', $lastMain);
        $this->assertMatchesRegularExpression(
            '/<div\b(?=[^>]*\bdata-artwork-boundary="end")(?=[^>]*\baria-disabled="true")[^>]*>/',
            $lastMain,
        );
        $this->assertStringContainsString('End of published archive', $lastMain);
    }

    public function test_draft_and_scheduled_artwork_pages_are_not_public(): void
    {
        Queue::fake();
        $draft = $this->artwork(['title' => 'Private Draft', 'slug' => 'private-draft', 'published' => false]);
        $scheduled = $this->artwork([
            'title' => 'Tomorrow Image',
            'slug' => 'tomorrow-image',
            'published_at' => now()->addDay(),
        ]);

        $this->get(route('artworks.show', $draft))->assertNotFound();
        $this->get(route('artworks.show', $scheduled))->assertNotFound();
    }

    public function test_artwork_page_supplies_safe_visual_artwork_and_image_object_metadata(): void
    {
        Queue::fake();
        $artwork = $this->artwork([
            'title' => 'Metadata Study',
            'slug' => 'metadata-study',
            'description' => '</script><script>window.artworkCompromised = true</script>',
            'alt_text' => 'An abstract metadata study.',
            'width' => 1600,
            'height' => 900,
            'published_at' => now()->subMinute(),
        ]);

        $response = $this->get(route('artworks.show', $artwork));

        $response
            ->assertOk()
            ->assertViewHas('seo', fn (array $seo): bool => $seo['canonical'] === route('artworks.show', $artwork)
                && $seo['title'] === 'Metadata Study | Creative-Ai')
            ->assertViewHas('structured_data', function (array $data) use ($artwork): bool {
                $graph = collect($data['@graph'] ?? [])->keyBy('@type');

                return $graph->has('VisualArtwork')
                    && $graph->has('ImageObject')
                    && $graph->get('VisualArtwork')['url'] === route('artworks.show', $artwork)
                    && $graph->get('ImageObject')['contentUrl'] === url($artwork->public_image_url);
            })
            ->assertSee('<link rel="canonical" href="'.route('artworks.show', $artwork).'">', false)
            ->assertSee('VisualArtwork')
            ->assertSee('ImageObject')
            ->assertDontSee('</script><script>window.artworkCompromised = true</script>', false);

        $renderedGraph = collect($this->decodeStructuredData($response)['@graph'] ?? [])->keyBy('@type');

        $this->assertTrue($renderedGraph->has('VisualArtwork'));
        $this->assertTrue($renderedGraph->has('ImageObject'));
    }

    public function test_sitemap_includes_only_currently_published_artwork_pages(): void
    {
        Queue::fake();
        $published = $this->artwork(['title' => 'Public Sitemap Art', 'slug' => 'public-sitemap-art']);
        $draft = $this->artwork([
            'title' => 'Draft Sitemap Art',
            'slug' => 'draft-sitemap-art',
            'published' => false,
        ]);
        $scheduled = $this->artwork([
            'title' => 'Scheduled Sitemap Art',
            'slug' => 'scheduled-sitemap-art',
            'published_at' => now()->addDay(),
        ]);

        $this->get(route('sitemap'))
            ->assertOk()
            ->assertSee(route('artworks.show', $published), false)
            ->assertDontSee(route('artworks.show', $draft), false)
            ->assertDontSee(route('artworks.show', $scheduled), false);
    }

    /** @param array<string, mixed> $attributes */
    private function artwork(array $attributes): Artwork
    {
        return Artwork::query()->create(array_replace([
            'image_path' => 'artworks/originals/'.str()->uuid().'.jpg',
            'sort_order' => 10,
            'published' => true,
        ], $attributes));
    }

    private function mainContent(string $html): string
    {
        $matched = preg_match('/<main\b[^>]*>(.*?)<\/main>/s', $html, $matches);

        $this->assertSame(1, $matched, 'Expected the response to contain the public main element.');

        return $matches[1] ?? '';
    }

    private function navigationAnchor(string $html, string $hook): string
    {
        $matched = preg_match(
            '/<a\b(?=[^>]*\b'.preg_quote($hook, '/').'\b)[^>]*>/',
            $html,
            $matches,
        );

        $this->assertSame(1, $matched, 'Expected an artwork navigation anchor with '.$hook.'.');

        return $matches[0] ?? '';
    }
}
