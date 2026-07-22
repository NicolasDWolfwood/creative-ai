<?php

namespace Tests\Feature;

use App\Filament\Resources\Artworks\Pages\ManageArtworks;
use App\Models\Artwork;
use App\Models\Collection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminArtworkCollectionCurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_artwork_library_filters_on_current_pivot_membership_not_the_legacy_column(): void
    {
        $collection = Collection::query()->create(['title' => 'Current membership']);
        $member = $this->artwork('Current member');
        $member->collections()->attach($collection);
        $uncollected = $this->artwork('No membership');
        $legacyOnly = $this->artwork('Legacy column only', ['collection_id' => $collection->id]);

        $component = Livewire::actingAs(User::factory()->admin()->create())
            ->test(ManageArtworks::class)
            ->filterTable('collection_membership', false)
            ->assertCanSeeTableRecords([$uncollected, $legacyOnly])
            ->assertCanNotSeeTableRecords([$member]);

        $component
            ->filterTable('collection_membership', true)
            ->assertCanSeeTableRecords([$member])
            ->assertCanNotSeeTableRecords([$uncollected, $legacyOnly]);
    }

    public function test_bulk_action_creates_a_private_manual_collection_from_selected_uncollected_artwork(): void
    {
        $existing = Collection::query()->create(['title' => 'Existing collection']);
        $uncollected = $this->artwork('Ready to collect', [
            'published' => true,
            'featured' => true,
        ]);
        $alreadyCollected = $this->artwork('Already collected');
        $alreadyCollected->collections()->attach($existing);

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(ManageArtworks::class)
            ->mountTableBulkAction('createDraftCollectionFromSelected', [$uncollected, $alreadyCollected])
            ->setTableBulkActionData([
                'title' => 'Needs a home',
                'description' => 'A deliberate private curation pass.',
            ])
            ->callMountedTableBulkAction()
            ->assertHasNoTableActionErrors();

        $collection = Collection::query()->where('title', 'Needs a home')->firstOrFail();

        $this->assertFalse($collection->published);
        $this->assertFalse($collection->featured);
        $this->assertFalse($collection->publishes_members);
        $this->assertFalse($collection->is_smart);
        $this->assertFalse($collection->is_auto_generated);
        $this->assertEqualsCanonicalizing(
            [$uncollected->id],
            $collection->artworks()->pluck('artworks.id')->all(),
        );
        $this->assertTrue($uncollected->refresh()->published);
        $this->assertTrue($uncollected->featured);
        $this->assertEqualsCanonicalizing(
            [$existing->id],
            $alreadyCollected->collections()->pluck('collections.id')->all(),
        );
    }

    /** @param array<string, mixed> $attributes */
    private function artwork(string $title, array $attributes = []): Artwork
    {
        return Artwork::withoutEvents(fn (): Artwork => Artwork::query()->create(array_replace([
            'title' => $title,
            'slug' => str($title)->slug()->toString(),
            'image_path' => 'artworks/originals/'.str($title)->slug().'.jpg',
            'published' => false,
            'featured' => false,
        ], $attributes)));
    }
}
