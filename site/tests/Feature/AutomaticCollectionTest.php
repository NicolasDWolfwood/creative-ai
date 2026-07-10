<?php

namespace Tests\Feature;

use App\Models\Artwork;
use App\Models\Collection;
use App\Models\Tag;
use App\Services\AiSettings;
use App\Services\AutomaticCollectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
        $this->assertSame(3, $result['count']);

        Http::assertSent(fn ($request): bool => $request->url() === 'http://ollama.test:11434/api/chat'
            && str_contains((string) data_get($request->data(), 'messages.0.content'), 'sports-car')
            && data_get($request->data(), 'format.properties.tag_slugs.type') === 'array');
    }

    protected function taggedArtwork(string $tagName, string $status): Artwork
    {
        static $sequence = 0;
        $sequence++;
        $tag = Tag::query()->firstOrCreate(
            ['slug' => str($tagName)->slug()->toString()],
            ['name' => $tagName],
        );
        $artwork = Artwork::withoutEvents(fn (): Artwork => Artwork::query()->create([
            'title' => 'Artwork '.$sequence,
            'slug' => 'artwork-'.$sequence,
            'image_path' => 'artworks/originals/'.$sequence.'.jpg',
            'published' => true,
            'ai_status' => $status,
            'ai_analyzed_at' => $status === Artwork::AI_STATUS_IDLE ? null : now(),
        ]));
        $artwork->tags()->attach($tag, ['category' => 'subject']);

        return $artwork;
    }
}
