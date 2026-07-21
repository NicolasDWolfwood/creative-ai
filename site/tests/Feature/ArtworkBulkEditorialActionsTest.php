<?php

namespace Tests\Feature;

use App\Filament\Resources\Artworks\Pages\ManageArtworks;
use App\Models\Artwork;
use App\Models\Collection;
use App\Models\Tag;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ArtworkBulkEditorialActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_publication_actions_handle_dates_collection_grants_and_smart_membership(): void
    {
        $now = CarbonImmutable::parse('2026-07-22 12:00:00', 'UTC');
        $this->travelTo($now);
        $past = $now->subDay();
        $draft = $this->artwork('Bulk draft');
        $scheduled = $this->artwork('Bulk scheduled', published: true, publishedAt: $now->addDay());
        $current = $this->artwork('Bulk current', published: true, publishedAt: $past);
        $futureEmbargo = $this->artwork('Bulk future embargo', published: true, publishedAt: $now->addDays(2));
        $tag = Tag::query()->create(['name' => 'Bulk selection', 'slug' => 'bulk-selection']);

        foreach ([$draft, $scheduled, $current, $futureEmbargo] as $artwork) {
            $artwork->tags()->attach($tag, ['category' => 'subject']);
        }

        $smart = Collection::query()->create([
            'title' => 'Live standalone selection',
            'slug' => 'live-standalone-selection',
            'published' => true,
            'published_at' => $past,
            'publishes_members' => true,
            'is_smart' => true,
            'auto_sync' => true,
            'smart_rules' => [
                'tag_ids' => [$tag->id],
                'match' => 'any',
                'only_published' => true,
            ],
        ]);
        $manualGrant = Collection::query()->create([
            'title' => 'Manual public collection',
            'slug' => 'manual-public-collection',
            'published' => true,
            'published_at' => $past,
            'publishes_members' => true,
        ]);
        $manualGrant->artworks()->attach([$current->id, $futureEmbargo->id]);

        $this->assertFalse($smart->artworks()->whereKey($draft)->exists());
        $this->assertTrue($smart->artworks()->whereKey($scheduled)->exists());
        $this->assertTrue($smart->artworks()->whereKey($current)->exists());
        $this->assertTrue($smart->artworks()->whereKey($futureEmbargo)->exists());

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(ManageArtworks::class)
            ->callTableBulkAction('publishSelectedNow', [$draft, $scheduled, $current])
            ->assertHasNoTableActionErrors();

        $this->assertTrue($draft->refresh()->published);
        $this->assertTrue($draft->published_at->equalTo($now));
        $this->assertTrue($scheduled->refresh()->published);
        $this->assertTrue($scheduled->published_at->equalTo($now));
        $this->assertTrue($current->refresh()->published);
        $this->assertTrue($current->published_at->equalTo($past));
        $this->assertTrue($futureEmbargo->refresh()->published_at->equalTo($now->addDays(2)));
        $this->assertSame(
            3,
            Artwork::query()->published()->whereKey([$draft->id, $scheduled->id, $current->id])->count(),
        );
        $this->assertEqualsCanonicalizing(
            [$draft->id, $scheduled->id, $current->id, $futureEmbargo->id],
            $smart->artworks()->pluck('artworks.id')->all(),
        );

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(ManageArtworks::class)
            ->callTableBulkAction('unpublishSelected', [$draft, $scheduled, $current, $futureEmbargo])
            ->assertHasNoTableActionErrors();

        $this->assertFalse($draft->refresh()->isPubliclyPublished());
        $this->assertTrue($draft->published_at->equalTo($now));
        $this->assertFalse($scheduled->refresh()->isPubliclyPublished());
        $this->assertTrue($scheduled->published_at->equalTo($now));
        $this->assertFalse($current->refresh()->isPubliclyPublished());
        $this->assertTrue($current->published_at->equalTo($past));
        $this->assertFalse($futureEmbargo->refresh()->published);
        $this->assertTrue($futureEmbargo->published_at->equalTo($now->addDays(2)));
        $this->assertSame(0, $smart->artworks()->count());
        $this->assertFalse($draft->isPubliclyAvailable());
        $this->assertTrue($current->isPubliclyAvailable());
        $this->assertFalse($futureEmbargo->isPubliclyAvailable());
    }

    public function test_bulk_feature_actions_never_change_publication_or_collection_membership(): void
    {
        $future = CarbonImmutable::parse('2026-07-24 12:00:00', 'UTC');
        $draft = $this->artwork('Feature draft', publishedAt: $future);
        $published = $this->artwork(
            'Feature published',
            published: true,
            publishedAt: $future->subDays(3),
            featured: true,
        );
        $collection = Collection::query()->create([
            'title' => 'Feature membership',
            'slug' => 'feature-membership',
        ]);
        $collection->artworks()->attach([$draft->id, $published->id]);
        $draftPublication = [$draft->published, $draft->published_at?->toISOString()];
        $publishedPublication = [$published->published, $published->published_at?->toISOString()];

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(ManageArtworks::class)
            ->callTableBulkAction('featureSelected', [$draft, $published])
            ->assertHasNoTableActionErrors();

        $this->assertTrue($draft->refresh()->featured);
        $this->assertTrue($published->refresh()->featured);
        $this->assertSame($draftPublication, [$draft->published, $draft->published_at?->toISOString()]);
        $this->assertSame($publishedPublication, [$published->published, $published->published_at?->toISOString()]);

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(ManageArtworks::class)
            ->callTableBulkAction('unfeatureSelected', [$draft, $published])
            ->assertHasNoTableActionErrors();

        $this->assertFalse($draft->refresh()->featured);
        $this->assertFalse($published->refresh()->featured);
        $this->assertSame($draftPublication, [$draft->published, $draft->published_at?->toISOString()]);
        $this->assertSame($publishedPublication, [$published->published, $published->published_at?->toISOString()]);
        $this->assertEqualsCanonicalizing(
            [$draft->id, $published->id],
            $collection->artworks()->pluck('artworks.id')->all(),
        );
    }

    private function artwork(
        string $title,
        bool $published = false,
        mixed $publishedAt = null,
        bool $featured = false,
    ): Artwork {
        return Artwork::withoutEvents(fn (): Artwork => Artwork::query()->create([
            'title' => $title,
            'slug' => str($title)->slug()->toString(),
            'image_path' => 'artworks/originals/'.str($title)->slug().'.jpg',
            'published' => $published,
            'published_at' => $publishedAt,
            'featured' => $featured,
        ]));
    }
}
