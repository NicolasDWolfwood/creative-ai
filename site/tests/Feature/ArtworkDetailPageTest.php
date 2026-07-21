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

        $this->artwork([
            'title' => 'Draft Neighbor',
            'slug' => 'draft-neighbor',
            'published' => false,
        ])->forceFill(['created_at' => $archiveTime])->saveQuietly();
        $this->artwork([
            'title' => 'Scheduled Neighbor',
            'slug' => 'scheduled-neighbor',
            'published_at' => now()->addDay(),
        ])->forceFill(['created_at' => $archiveTime])->saveQuietly();

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
}
