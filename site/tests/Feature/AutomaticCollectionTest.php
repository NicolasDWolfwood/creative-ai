<?php

namespace Tests\Feature;

use App\Filament\Resources\Collections\Pages\ManageCollections;
use App\Models\Artwork;
use App\Models\Collection;
use App\Models\Tag;
use App\Models\User;
use App\Services\AiSettings;
use App\Services\ArtworkAiMetadataService;
use App\Services\AutomaticCollectionService;
use App\Services\SmartCollectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class AutomaticCollectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
    }

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
            $this->assertTrue($collection->publishes_members);
            $this->assertFalse($collection->auto_sync);
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

    public function test_manual_general_tags_can_supply_a_broad_automatic_collection_theme(): void
    {
        $wildlife = Tag::query()->create(['name' => 'wildlife']);
        $artworks = collect([
            $this->taggedArtwork('silver antlers', Artwork::AI_STATUS_APPLIED),
            $this->taggedArtwork('moonlit paws', Artwork::AI_STATUS_APPLIED),
            $this->taggedArtwork('woodland gaze', Artwork::AI_STATUS_APPLIED),
        ]);

        $artworks->each(fn (Artwork $artwork) => $artwork->tags()->attach($wildlife, ['category' => 'other']));

        $result = app(AutomaticCollectionService::class)->maintain(target: 1, minimumArtwork: 3);
        $collection = Collection::query()->where('auto_generation_key', 'animals')->firstOrFail();

        $this->assertSame(1, $result['collection_count']);
        $this->assertEqualsCanonicalizing(
            $artworks->pluck('id')->all(),
            $collection->artworks()->pluck('artworks.id')->all(),
        );
        $this->assertContains($wildlife->id, data_get($collection->smart_rules, 'tag_ids'));
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
        $this->assertTrue($collection->publishes_members);
        $this->assertFalse($collection->auto_sync);
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
                'No suitable AI-approved artwork tag is shared by at least 2 eligible artworks.',
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

    public function test_explicit_generation_can_publish_a_snapshot_of_ai_approved_drafts_inside_collections(): void
    {
        $first = $this->taggedArtwork('portrait', Artwork::AI_STATUS_APPLIED, ['published' => false]);
        $second = $this->taggedArtwork('portrait', Artwork::AI_STATUS_APPLIED, ['published' => false]);
        $future = $this->taggedArtwork('portrait', Artwork::AI_STATUS_APPLIED, [
            'published' => true,
            'published_at' => now()->addDay(),
        ]);
        $futureDraft = $this->taggedArtwork('portrait', Artwork::AI_STATUS_APPLIED, [
            'published' => false,
            'published_at' => now()->addDay(),
        ]);
        $missing = $this->taggedArtwork(
            'portrait',
            Artwork::AI_STATUS_APPLIED,
            ['published' => false],
            storeMedia: false,
        );

        $result = app(AutomaticCollectionService::class)->maintain(
            target: 1,
            minimumArtwork: 2,
            published: true,
            publishesMembers: true,
        );
        $collection = Collection::query()->where('is_auto_generated', true)->sole();

        $this->assertTrue($collection->publishes_members);
        $this->assertFalse($collection->auto_sync);
        $this->assertFalse((bool) data_get($collection->smart_rules, 'only_published', true));
        $this->assertTrue((bool) data_get($collection->smart_rules, 'exclude_future_scheduled'));
        $this->assertTrue((bool) data_get($collection->smart_rules, 'only_with_available_media'));
        $this->assertEqualsCanonicalizing(
            [$first->id, $second->id],
            $collection->artworks()->pluck('artworks.id')->all(),
        );
        $this->assertFalse($first->refresh()->published);
        $this->assertFalse($second->refresh()->published);
        $this->assertFalse($collection->artworks()->whereKey($future->id)->exists());
        $this->assertFalse($collection->artworks()->whereKey($futureDraft->id)->exists());
        $this->assertFalse($collection->artworks()->whereKey($missing->id)->exists());
        $this->assertSame(2, $result['memberships_added']);
        $this->assertSame(0, $result['memberships_removed']);
        $this->assertSame(2, $result['publicly_visible']);
        $this->assertSame(2, $result['collection_only']);
    }

    public function test_ai_metadata_application_cannot_silently_change_a_public_collection_snapshot(): void
    {
        $existing = $this->taggedArtwork('shared dream', Artwork::AI_STATUS_APPLIED, ['published' => false]);
        $tag = Tag::query()->where('slug', 'shared-dream')->sole();
        $collection = Collection::query()->create([
            'title' => 'Reviewed dreams',
            'published' => true,
            'publishes_members' => true,
            'is_smart' => true,
            'auto_sync' => true,
            'smart_rules' => [
                'tag_ids' => [$tag->id],
                'match' => 'any',
                'only_published' => false,
                'exclude_future_scheduled' => true,
                'only_with_available_media' => true,
                'only_ai_applied' => true,
            ],
        ]);
        $collection->artworks()->attach($existing);
        $candidate = $this->suggestedArtwork(
            'New private dream',
            ['shared dream'],
            attributes: ['published' => false],
        );

        app(ArtworkAiMetadataService::class)->applySuggestion($candidate);

        $this->assertSame(Artwork::AI_STATUS_APPLIED, $candidate->refresh()->ai_status);
        $this->assertFalse($collection->refresh()->auto_sync);
        $this->assertEquals([$existing->id], $collection->artworks()->pluck('artworks.id')->all());
        $this->assertFalse($collection->artworks()->whereKey($candidate->id)->exists());
        $this->assertFalse($candidate->published);
    }

    public function test_live_auto_sync_remains_available_when_rules_only_select_standalone_published_artwork(): void
    {
        $artwork = $this->taggedArtwork('neon portrait', Artwork::AI_STATUS_APPLIED);
        $tag = Tag::query()->where('slug', 'neon-portrait')->sole();
        $collection = Collection::query()->create([
            'title' => 'Live published set',
            'published' => true,
            'publishes_members' => true,
            'is_smart' => true,
            'auto_sync' => true,
            'smart_rules' => [
                'tag_ids' => [$tag->id],
                'match' => 'any',
                'only_published' => true,
                'only_ai_applied' => true,
            ],
        ]);

        app(SmartCollectionService::class)->syncAutomatic();

        $this->assertTrue($collection->refresh()->auto_sync);
        $this->assertTrue($collection->artworks()->whereKey($artwork->id)->exists());
    }

    public function test_manual_public_snapshot_excludes_future_scheduled_artwork_without_an_optional_rule_flag(): void
    {
        $currentDraft = $this->taggedArtwork('quiet city', Artwork::AI_STATUS_APPLIED, [
            'published' => false,
        ]);
        $futureStandalone = $this->taggedArtwork('quiet city', Artwork::AI_STATUS_APPLIED, [
            'published' => true,
            'published_at' => now()->addDay(),
        ]);
        $futureDraft = $this->taggedArtwork('quiet city', Artwork::AI_STATUS_APPLIED, [
            'published' => false,
            'published_at' => now()->addDay(),
        ]);
        $tag = Tag::query()->where('slug', 'quiet-city')->sole();
        $collection = Collection::query()->create([
            'title' => 'Quiet City',
            'published' => true,
            'publishes_members' => true,
            'is_smart' => true,
            'auto_sync' => false,
            'smart_rules' => [
                'tag_ids' => [$tag->id],
                'match' => 'any',
                'only_published' => false,
                'only_ai_applied' => true,
            ],
        ]);

        app(SmartCollectionService::class)->sync($collection, explicit: true);

        $this->assertEquals([$currentDraft->id], $collection->artworks()->pluck('artworks.id')->all());
        $this->assertFalse($collection->artworks()->whereKey($futureStandalone->id)->exists());
        $this->assertFalse($collection->artworks()->whereKey($futureDraft->id)->exists());
    }

    public function test_enabling_member_publication_freezes_the_existing_live_set_and_honors_future_dates(): void
    {
        $currentDraft = $this->taggedArtwork('safe snapshot', Artwork::AI_STATUS_APPLIED, [
            'published' => false,
        ]);
        $futureDraft = $this->taggedArtwork('safe snapshot', Artwork::AI_STATUS_APPLIED, [
            'published' => false,
            'published_at' => now()->addDay(),
        ]);
        $tag = Tag::query()->where('slug', 'safe-snapshot')->sole();
        $collection = Collection::query()->create([
            'title' => 'Safe Snapshot',
            'published' => true,
            'publishes_members' => false,
            'is_smart' => true,
            'auto_sync' => true,
            'smart_rules' => [
                'tag_ids' => [$tag->id],
                'match' => 'any',
                'only_published' => false,
                'only_ai_applied' => true,
            ],
        ]);

        $this->assertTrue($collection->artworks()->whereKey($currentDraft->id)->exists());
        $this->assertTrue($collection->artworks()->whereKey($futureDraft->id)->exists());

        $collection->update([
            'publishes_members' => true,
            'auto_sync' => false,
        ]);

        $this->assertFalse($collection->refresh()->auto_sync);
        $this->assertTrue($collection->artworks()->whereKey($currentDraft->id)->exists());
        $this->assertTrue($collection->artworks()->whereKey($futureDraft->id)->exists());
        $this->assertTrue($currentDraft->refresh()->isPubliclyAvailable());
        $this->assertFalse($futureDraft->refresh()->isPubliclyAvailable());

        $this->travel(2)->days();

        $this->assertTrue($futureDraft->refresh()->isPubliclyAvailable());
    }

    public function test_live_published_only_membership_tracks_standalone_publication_and_schedule_changes(): void
    {
        $artwork = $this->taggedArtwork('living archive', Artwork::AI_STATUS_APPLIED, [
            'published' => false,
        ]);
        $tag = Tag::query()->where('slug', 'living-archive')->sole();
        $collection = Collection::query()->create([
            'title' => 'Living Archive',
            'published' => true,
            'publishes_members' => true,
            'is_smart' => true,
            'auto_sync' => true,
            'smart_rules' => [
                'tag_ids' => [$tag->id],
                'match' => 'any',
                'only_published' => true,
                'only_ai_applied' => true,
            ],
        ]);

        $this->assertFalse($collection->artworks()->whereKey($artwork->id)->exists());

        $artwork->update([
            'published' => true,
            'published_at' => now()->subMinute(),
        ]);

        $this->assertTrue($collection->artworks()->whereKey($artwork->id)->exists());
        $this->assertTrue($artwork->refresh()->isPubliclyAvailable());

        $artwork->update(['published_at' => now()->addDay()]);

        $this->assertTrue($collection->artworks()->whereKey($artwork->id)->exists());
        $this->assertFalse($artwork->refresh()->isPubliclyAvailable());

        $this->travel(2)->days();

        $this->assertTrue($artwork->refresh()->isPubliclyAvailable());

        $artwork->update(['published' => false]);

        $this->assertFalse($collection->artworks()->whereKey($artwork->id)->exists());
        $this->assertFalse($artwork->refresh()->isPubliclyAvailable());
    }

    public function test_stale_live_published_only_membership_cannot_keep_unpublished_artwork_public(): void
    {
        $artwork = $this->taggedArtwork('fail closed archive', Artwork::AI_STATUS_APPLIED);
        $tag = Tag::query()->where('slug', 'fail-closed-archive')->sole();
        $collection = Collection::query()->create([
            'title' => 'Fail-closed archive',
            'published' => true,
            'publishes_members' => true,
            'is_smart' => true,
            'auto_sync' => true,
            'smart_rules' => [
                'tag_ids' => [$tag->id],
                'match' => 'any',
                'only_published' => true,
                'only_ai_applied' => true,
            ],
        ]);

        $this->assertTrue($collection->artworks()->whereKey($artwork)->exists());
        $this->assertTrue($artwork->refresh()->isPubliclyAvailable());

        Artwork::withoutEvents(fn (): bool => $artwork->update(['published' => false]));

        $this->assertTrue($collection->artworks()->whereKey($artwork)->exists());
        $this->assertFalse($artwork->refresh()->isPubliclyAvailable());
        $this->assertFalse(Artwork::query()->publiclyAvailable()->whereKey($artwork)->exists());
        $this->get(route('artworks.show', $artwork))->assertNotFound();
        $this->get($artwork->thumb_url)->assertNotFound();
    }

    public function test_only_an_explicit_refresh_adds_new_matches_to_a_public_snapshot(): void
    {
        $first = $this->taggedArtwork('portrait', Artwork::AI_STATUS_APPLIED, ['published' => false]);
        $second = $this->taggedArtwork('portrait', Artwork::AI_STATUS_APPLIED, ['published' => false]);
        app(AutomaticCollectionService::class)->maintain(target: 1, minimumArtwork: 2);
        $collection = Collection::query()->where('is_auto_generated', true)->sole();
        $third = $this->taggedArtwork('portrait', Artwork::AI_STATUS_APPLIED, ['published' => false]);

        $this->assertFalse($collection->artworks()->whereKey($third->id)->exists());
        $this->assertNull(app(AutomaticCollectionService::class)->refreshExisting(sync: false));
        $this->assertFalse($collection->artworks()->whereKey($third->id)->exists());

        $result = app(AutomaticCollectionService::class)->refreshExisting(sync: true);

        $this->assertNotNull($result);
        $this->assertSame(1, $result['memberships_added']);
        $this->assertSame(0, $result['memberships_removed']);
        $this->assertEqualsCanonicalizing(
            [$first->id, $second->id, $third->id],
            $collection->artworks()->pluck('artworks.id')->all(),
        );
        $this->assertFalse($collection->refresh()->auto_sync);
    }

    public function test_refresh_preserves_an_existing_choice_not_to_publish_members(): void
    {
        $this->taggedArtwork('portrait', Artwork::AI_STATUS_APPLIED, ['published' => false]);
        $this->taggedArtwork('portrait', Artwork::AI_STATUS_APPLIED, ['published' => false]);
        app(AutomaticCollectionService::class)->maintain(
            target: 1,
            minimumArtwork: 2,
            publishesMembers: false,
        );
        $collection = Collection::query()->where('is_auto_generated', true)->sole();

        $this->assertFalse($collection->publishes_members);
        $this->assertTrue($collection->auto_sync);

        $this->taggedArtwork('portrait', Artwork::AI_STATUS_APPLIED, ['published' => false]);
        app(AutomaticCollectionService::class)->refreshExisting();

        $this->assertFalse($collection->refresh()->publishes_members);
        $this->assertTrue($collection->auto_sync);
        $this->assertCount(3, $collection->artworks);
    }

    public function test_generation_impact_counts_visibility_granted_by_another_collection(): void
    {
        $visibleElsewhere = $this->taggedArtwork('portrait', Artwork::AI_STATUS_APPLIED, ['published' => false]);
        $private = $this->taggedArtwork('portrait', Artwork::AI_STATUS_APPLIED, ['published' => false]);
        $grantingCollection = Collection::query()->create([
            'title' => 'Existing public grant',
            'published' => true,
            'publishes_members' => true,
        ]);
        $grantingCollection->artworks()->attach($visibleElsewhere);

        $result = app(AutomaticCollectionService::class)->maintain(
            target: 1,
            minimumArtwork: 2,
            publishesMembers: false,
        );

        $this->assertSame(1, $result['publicly_visible']);
        $this->assertSame(1, $result['collection_only']);
        $this->assertTrue($visibleElsewhere->refresh()->isPubliclyAvailable());
        $this->assertFalse($private->refresh()->isPubliclyAvailable());
    }

    public function test_refresh_impact_is_measured_after_all_overlapping_generated_grants_are_revoked(): void
    {
        $artwork = $this->taggedArtwork('portrait', Artwork::AI_STATUS_APPLIED, ['published' => false]);
        $nature = Tag::query()->create([
            'name' => 'Forest',
            'slug' => 'forest',
        ]);
        $artwork->tags()->attach($nature, ['category' => 'subject']);

        $initial = app(AutomaticCollectionService::class)->maintain(
            target: 2,
            minimumArtwork: 1,
            publishesMembers: true,
        );

        $this->assertSame(2, $initial['collection_count']);
        $this->assertSame(1, $initial['publicly_visible']);
        $this->assertTrue($artwork->refresh()->isPubliclyAvailable());

        $refreshed = app(AutomaticCollectionService::class)->maintain(
            target: 2,
            minimumArtwork: 1,
            publishesMembers: false,
        );

        $this->assertSame(2, $refreshed['collection_count']);
        $this->assertSame(0, $refreshed['publicly_visible']);
        $this->assertSame(0, $refreshed['collection_only']);
        $this->assertSame([0, 0], collect($refreshed['collections'])->pluck('visible')->all());
        $this->assertFalse($artwork->refresh()->isPubliclyAvailable());
    }

    public function test_collection_admin_generation_action_is_the_explicit_member_publication_gate(): void
    {
        $first = $this->taggedArtwork('portrait', Artwork::AI_STATUS_APPLIED, ['published' => false]);
        $second = $this->taggedArtwork('portrait', Artwork::AI_STATUS_APPLIED, ['published' => false]);

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(ManageCollections::class)
            ->callAction('generateAutomaticCollections', [
                'target_count' => 1,
                'minimum_artwork' => 2,
                'published' => true,
            ])
            ->assertHasNoActionErrors();

        $collection = Collection::query()->where('is_auto_generated', true)->sole();

        $this->assertTrue($collection->publishes_members);
        $this->assertFalse($collection->auto_sync);
        $this->assertEqualsCanonicalizing(
            [$first->id, $second->id],
            $collection->artworks()->pluck('artworks.id')->all(),
        );
    }

    /** @param array<string, mixed> $attributes */
    protected function taggedArtwork(string $tagName, string $status, array $attributes = [], bool $storeMedia = true): Artwork
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

        if ($storeMedia) {
            Storage::disk('local')->put($artwork->image_path, 'test image');
        }

        return $artwork;
    }

    /**
     * @param  array<int, string>  $tags
     * @param  array<int, string>  $styleTags
     */
    protected function suggestedArtwork(string $title, array $tags, array $styleTags = [], array $attributes = []): Artwork
    {
        static $sequence = 0;
        $sequence++;

        $artwork = Artwork::withoutEvents(fn (): Artwork => Artwork::query()->create(array_replace([
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
        ], $attributes)));
        Storage::disk('local')->put($artwork->image_path, 'test image');

        return $artwork;
    }
}
