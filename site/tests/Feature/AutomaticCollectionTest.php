<?php

namespace Tests\Feature;

use App\Models\Artwork;
use App\Models\Collection;
use App\Models\Tag;
use App\Services\AiSettings;
use App\Services\ArtworkAiMetadataService;
use App\Services\AutomaticCollectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class AutomaticCollectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_automatic_collections_are_capped_preserve_custom_collections_and_only_match_ai_approved_artwork(): void
    {
        $manual = Collection::query()->create([
            'title' => 'My Handpicked Work',
            'published' => true,
        ]);
        $themes = [
            'portrait',
            'fantasy',
            'sci-fi',
            'forest',
            'character design',
            'cat',
            'sports car',
        ];

        foreach ($themes as $theme) {
            $this->taggedArtwork($theme, Artwork::AI_STATUS_APPLIED);
        }

        $unapprovedPortrait = $this->taggedArtwork('portrait', Artwork::AI_STATUS_READY);
        $result = app(AutomaticCollectionService::class)->maintain(target: 10, minimumArtwork: 1);

        $this->assertSame(AutomaticCollectionService::MAX_AUTOMATIC_COLLECTIONS, $result['collection_count']);
        $this->assertSame(AutomaticCollectionService::MAX_AUTOMATIC_COLLECTIONS, Collection::query()->where('is_auto_generated', true)->count());
        $this->assertTrue(Collection::query()->whereKey($manual->id)->exists());

        Collection::query()->where('is_auto_generated', true)->each(function (Collection $collection) use ($unapprovedPortrait): void {
            $this->assertTrue($collection->is_smart);
            $this->assertNull($collection->hero_image_path);
            $this->assertTrue((bool) data_get($collection->smart_rules, 'only_ai_applied'));
            $this->assertFalse($collection->artworks()->whereKey($unapprovedPortrait->id)->exists());
            $this->assertSame(
                $collection->artworks()->count(),
                $collection->artworks()->where('ai_status', Artwork::AI_STATUS_APPLIED)->count(),
            );
        });

        app(AutomaticCollectionService::class)->maintain(target: 1, minimumArtwork: 1);

        $this->assertSame(1, Collection::query()->where('is_auto_generated', true)->count());
        $this->assertTrue(Collection::query()->whereKey($manual->id)->exists());
    }

    public function test_ai_can_create_a_persistent_custom_smart_collection_from_approved_tags(): void
    {
        app(AiSettings::class)->save([
            'provider' => 'ollama',
            'ollama_base_url' => 'http://ollama.test:11434',
            'ollama_model' => 'qwen-test:latest',
        ]);
        $approved = collect([
            $this->taggedArtwork('sports car', Artwork::AI_STATUS_APPLIED),
            $this->taggedArtwork('sports car', Artwork::AI_STATUS_APPLIED),
            $this->taggedArtwork('hypercar', Artwork::AI_STATUS_APPLIED),
            $this->taggedArtwork('hypercar', Artwork::AI_STATUS_APPLIED),
        ]);
        $unapproved = $this->taggedArtwork('sports car', Artwork::AI_STATUS_READY);

        Http::fake([
            'ollama.test:11434/api/chat' => Http::response([
                'message' => [
                    'content' => json_encode([
                        'title' => 'Road Machines',
                        'description' => 'Performance cars and imagined automotive forms.',
                        'tag_slugs' => ['sports-car', 'hypercar'],
                        'explanation' => 'Groups the recurring automotive subjects.',
                    ]),
                ],
            ]),
        ]);

        $result = app(AutomaticCollectionService::class)->createWithAi(
            guidance: 'Create a collection about cars.',
            minimumArtwork: 2,
        );
        $collection = $result['collection'];

        $this->assertSame('Road Machines', $collection->title);
        $this->assertTrue($collection->is_smart);
        $this->assertFalse($collection->is_auto_generated);
        $this->assertSame('ai_assisted', data_get($collection->smart_rules, 'source'));
        $this->assertTrue((bool) data_get($collection->smart_rules, 'only_ai_applied'));
        $this->assertSame($approved->pluck('id')->sort()->values()->all(), $collection->artworks()->pluck('artworks.id')->sort()->values()->all());
        $this->assertFalse($collection->artworks()->whereKey($unapproved->id)->exists());
        $this->assertSame(4, $result['count']);

        Http::assertSent(fn ($request): bool => $request->url() === 'http://ollama.test:11434/api/chat'
            && str_contains((string) data_get($request->data(), 'messages.0.content'), 'sports-car')
            && data_get($request->data(), 'format.properties.tag_slugs.type') === 'array');
    }

    public function test_applied_ai_suggestions_offer_recurring_specific_tags_and_exclude_rare_or_generic_tags(): void
    {
        app(AiSettings::class)->save([
            'provider' => 'ollama',
            'ollama_base_url' => 'http://ollama.test:11434',
            'ollama_model' => 'qwen-test:latest',
        ]);
        $first = $this->suggestedArtwork(
            title: 'First source',
            tags: ['shared dream', 'rare moon'],
            styleTags: ['digital painting'],
        );
        $second = $this->suggestedArtwork(
            title: 'Second source',
            tags: ['shared dream', 'rare river'],
            styleTags: ['digital painting'],
        );
        $metadata = app(ArtworkAiMetadataService::class);
        $first = $metadata->applySuggestion($first, syncSmartCollections: false);
        $second = $metadata->applySuggestion($second, syncSmartCollections: false);

        Http::fake([
            'ollama.test:11434/api/chat' => Http::response([
                'message' => [
                    'content' => json_encode([
                        'title' => 'Shared Dreams',
                        'description' => 'Recurring dreamlike subjects gathered from the archive.',
                        'tag_slugs' => ['shared-dream'],
                        'explanation' => 'Uses the recurring shared subject.',
                    ]),
                ],
            ]),
        ]);

        $result = app(AutomaticCollectionService::class)->createWithAi('Find recurring dream subjects.');

        $this->assertSame(2, AutomaticCollectionService::DEFAULT_AI_ASSISTED_MINIMUM_ARTWORK);
        $this->assertSame(2, $result['count']);
        $this->assertEqualsCanonicalizing(
            [$first->id, $second->id],
            $result['collection']->artworks()->pluck('artworks.id')->all(),
        );
        Http::assertSent(function ($request): bool {
            $prompt = (string) data_get($request->data(), 'messages.0.content');

            return str_contains($prompt, 'shared-dream | shared dream | 2 artworks')
                && ! str_contains($prompt, 'rare-moon')
                && ! str_contains($prompt, 'rare-river')
                && ! str_contains($prompt, 'digital-painting');
        });
    }

    public function test_ai_collection_fails_before_the_provider_when_no_tag_meets_the_requested_minimum(): void
    {
        $this->taggedArtwork('rare subject', Artwork::AI_STATUS_APPLIED);
        Http::fake();

        try {
            app(AutomaticCollectionService::class)->createWithAi(minimumArtwork: 2);
            $this->fail('A provider request should not be made without a recurring eligible tag.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'No suitable AI-approved artwork tag is shared by at least 2 eligible published artworks.',
                $exception->getMessage(),
            );
        }

        Http::assertNothingSent();
    }

    public function test_future_scheduled_artwork_is_excluded_from_ai_catalog_counts_and_smart_sync(): void
    {
        app(AiSettings::class)->save([
            'provider' => 'ollama',
            'ollama_base_url' => 'http://ollama.test:11434',
            'ollama_model' => 'qwen-test:latest',
        ]);
        $first = $this->taggedArtwork('forest guardian', Artwork::AI_STATUS_APPLIED);
        $second = $this->taggedArtwork('forest guardian', Artwork::AI_STATUS_APPLIED);
        $future = $this->taggedArtwork('forest guardian', Artwork::AI_STATUS_APPLIED, [
            'published_at' => now()->addDay(),
        ]);

        Http::fake([
            'ollama.test:11434/api/chat' => Http::response([
                'message' => [
                    'content' => json_encode([
                        'title' => 'Forest Guardians',
                        'description' => 'Guardians found throughout the published forest archive.',
                        'tag_slugs' => ['forest-guardian'],
                        'explanation' => 'Uses the recurring forest subject.',
                    ]),
                ],
            ]),
        ]);

        $result = app(AutomaticCollectionService::class)->createWithAi(minimumArtwork: 2);

        $this->assertSame(2, $result['count']);
        $this->assertEqualsCanonicalizing(
            [$first->id, $second->id],
            $result['collection']->artworks()->pluck('artworks.id')->all(),
        );
        $this->assertFalse($result['collection']->artworks()->whereKey($future->id)->exists());
        Http::assertSent(fn ($request): bool => str_contains(
            (string) data_get($request->data(), 'messages.0.content'),
            'forest-guardian | forest guardian | 2 artworks',
        ));
    }

    /** @param array<string, mixed> $attributes */
    protected function taggedArtwork(string $tagName, string $status, array $attributes = []): Artwork
    {
        static $sequence = 0;
        $sequence++;
        $tag = Tag::query()->firstOrCreate(
            ['slug' => str($tagName)->slug()->toString()],
            ['name' => $tagName],
        );
        $artwork = Artwork::withoutEvents(fn (): Artwork => Artwork::query()->create(array_replace([
            'title' => 'Artwork '.$sequence,
            'slug' => 'artwork-'.$sequence,
            'image_path' => 'artworks/originals/'.$sequence.'.jpg',
            'published' => true,
            'ai_status' => $status,
            'ai_analyzed_at' => $status === Artwork::AI_STATUS_IDLE ? null : now(),
        ], $attributes)));
        $artwork->tags()->attach($tag, ['category' => 'subject']);

        return $artwork;
    }

    /**
     * @param  array<int, string>  $tags
     * @param  array<int, string>  $styleTags
     */
    protected function suggestedArtwork(string $title, array $tags, array $styleTags = []): Artwork
    {
        static $sequence = 0;
        $sequence++;

        return Artwork::withoutEvents(fn (): Artwork => Artwork::query()->create([
            'title' => $title,
            'slug' => 'suggested-artwork-'.$sequence,
            'image_path' => 'artworks/originals/suggested-'.$sequence.'.jpg',
            'published' => true,
            'ai_status' => Artwork::AI_STATUS_READY,
            'ai_suggestion' => [
                'title' => $title,
                'description' => 'A suggested artwork description.',
                'alt_text' => 'A suggested artwork.',
                'tags' => $tags,
                'style_tags' => $styleTags,
                'mood_tags' => [],
                'color_tags' => [],
                'medium_tags' => [],
                'confidence' => 0.9,
                'content_warning' => '',
            ],
        ]));
    }
}
