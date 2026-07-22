<?php

namespace Tests\Feature;

use App\Models\Artwork;
use App\Models\Collection;
use App\Models\Tag;
use App\Services\ArtworkCollectionCurationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArtworkCollectionCurationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_manual_draft_from_only_selected_artwork_that_is_still_uncollected(): void
    {
        $existingCollection = Collection::query()->create([
            'title' => 'Existing collection',
            'published' => true,
            'publishes_members' => true,
        ]);
        $published = $this->artwork('Published selection', [
            'published' => true,
            'published_at' => now()->subDay(),
            'featured' => true,
            'metadata' => ['source' => 'preserve-me'],
        ]);
        $draft = $this->artwork('Draft selection', [
            'published' => false,
            'published_at' => now()->addDay(),
            'collection_id' => $existingCollection->id,
            'sort_order' => 42,
        ]);
        $alreadyCollected = $this->artwork('Already collected');
        $notSelected = $this->artwork('Not selected');
        $existingCollection->artworks()->attach($alreadyCollected);
        $tag = Tag::query()->create(['name' => 'preserved tag']);
        $draft->tags()->attach($tag, ['category' => 'subject']);
        $publishedBefore = $published->refresh()->getAttributes();
        $draftBefore = $draft->refresh()->getAttributes();
        $publishedWasPublic = $published->isPubliclyAvailable();
        $draftWasPublic = $draft->isPubliclyAvailable();

        $result = app(ArtworkCollectionCurationService::class)->createDraftFromUncollected(
            [$published->id, $draft->id, $alreadyCollected->id, 999999, $published->id],
            'Unsorted work',
            'A private review collection.',
        );

        $collection = $result['collection'];

        $this->assertInstanceOf(Collection::class, $collection);
        $this->assertSame(4, $result['selected']);
        $this->assertSame(2, $result['attached']);
        $this->assertSame(2, $result['skipped']);
        $this->assertSame('Unsorted work', $collection->title);
        $this->assertSame('unsorted-work', $collection->slug);
        $this->assertSame('A private review collection.', $collection->description);
        $this->assertFalse($collection->featured);
        $this->assertFalse($collection->published);
        $this->assertNull($collection->published_at);
        $this->assertFalse($collection->publishes_members);
        $this->assertFalse($collection->is_smart);
        $this->assertFalse($collection->is_auto_generated);
        $this->assertFalse($collection->auto_sync);
        $this->assertEqualsCanonicalizing(
            [$published->id, $draft->id],
            $collection->artworks()->pluck('artworks.id')->all(),
        );
        $this->assertFalse($collection->artworks()->whereKey($alreadyCollected)->exists());
        $this->assertFalse($collection->artworks()->whereKey($notSelected)->exists());
        $this->assertTrue($existingCollection->artworks()->whereKey($alreadyCollected)->exists());
        $this->assertSame($publishedBefore, $published->refresh()->getAttributes());
        $this->assertSame($draftBefore, $draft->refresh()->getAttributes());
        $this->assertSame([$tag->id], $draft->tags()->pluck('tags.id')->all());
        $this->assertSame($publishedWasPublic, $published->isPubliclyAvailable());
        $this->assertSame($draftWasPublic, $draft->isPubliclyAvailable());
    }

    public function test_it_creates_no_collection_when_no_selected_artwork_is_currently_uncollected(): void
    {
        $existingCollection = Collection::query()->create(['title' => 'Existing collection']);
        $alreadyCollected = $this->artwork('Already collected');
        $existingCollection->artworks()->attach($alreadyCollected);

        $result = app(ArtworkCollectionCurationService::class)->createDraftFromUncollected(
            [$alreadyCollected->id, $alreadyCollected->id, 999999],
            'Should not exist',
        );

        $this->assertNull($result['collection']);
        $this->assertSame(2, $result['selected']);
        $this->assertSame(0, $result['attached']);
        $this->assertSame(2, $result['skipped']);
        $this->assertDatabaseCount('collections', 1);
        $this->assertDatabaseCount('artwork_collection', 1);
    }

    public function test_an_empty_selection_creates_nothing(): void
    {
        $result = app(ArtworkCollectionCurationService::class)->createDraftFromUncollected(
            [null, '', 0, -1],
            'Should not exist',
        );

        $this->assertSame([
            'collection' => null,
            'selected' => 0,
            'attached' => 0,
            'skipped' => 0,
        ], $result);
        $this->assertDatabaseCount('collections', 0);
        $this->assertDatabaseCount('artwork_collection', 0);
    }

    /** @param array<string, mixed> $overrides */
    private function artwork(string $title, array $overrides = []): Artwork
    {
        return Artwork::withoutEvents(fn (): Artwork => Artwork::query()->create(array_replace([
            'title' => $title,
            'slug' => str($title)->slug()->toString(),
            'image_path' => 'artworks/originals/'.str($title)->slug().'.jpg',
            'published' => false,
        ], $overrides)));
    }
}
