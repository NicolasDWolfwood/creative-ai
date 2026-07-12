<?php

namespace Tests\Feature;

use App\Enums\PostMediaType;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\StoryOpportunities;
use App\Models\Album;
use App\Models\Artwork;
use App\Models\Collection;
use App\Models\Playlist;
use App\Models\Post;
use App\Models\Track;
use App\Models\User;
use App\Services\StoryOpportunityService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminStoryOpportunitiesTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_counts_only_effectively_public_sources_without_any_journal_connection(): void
    {
        $artwork = $this->artwork('Public artwork');
        $this->artwork('Future artwork', ['published_at' => now()->addHour()]);
        $collection = $this->collection('Public collection');
        $album = $this->album('Public album');
        $playlist = $this->playlist('Already connected playlist');
        $track = $this->track('Album-only public track', ['album_id' => $album->getKey()]);
        $this->track('Private track');

        $post = Post::query()->create([
            'title' => 'Private planning draft',
            'body' => 'This source is already part of a Journal plan.',
        ]);
        $post->mediaItems()->create([
            'position' => 1,
            'playlist_id' => $playlist->getKey(),
        ]);

        $service = app(StoryOpportunityService::class);

        $this->assertSame([
            PostMediaType::Artwork->value => 1,
            PostMediaType::Collection->value => 1,
            PostMediaType::Album->value => 1,
            PostMediaType::Playlist->value => 0,
            PostMediaType::Track->value => 1,
        ], $service->counts());
        $this->assertSame(4, $service->count());
        $this->assertTrue($service->find(PostMediaType::Artwork, $artwork->getKey())?->is($artwork));
        $this->assertTrue($service->find(PostMediaType::Collection, $collection->getKey())?->is($collection));
        $this->assertTrue($service->find(PostMediaType::Track, $track->getKey())?->is($track));
        $this->assertNull($service->find(PostMediaType::Playlist, $playlist->getKey()));
    }

    public function test_union_query_searches_filters_and_paginates_real_media_models(): void
    {
        $this->artwork('Newest visual', ['description' => 'A cobalt landscape']);
        $this->collection('Archive room');
        $this->album('Quiet machinery', ['album_artist' => 'Needle Ensemble']);
        $this->playlist('Evening loop');
        $this->track('Signal path', [
            'artist' => 'Needle Ensemble',
            'standalone_published' => true,
            'standalone_published_at' => now()->subMinute(),
        ]);

        $service = app(StoryOpportunityService::class);
        $page = $service->paginate(perPage: 2);

        $this->assertSame(5, $page->total());
        $this->assertCount(2, $page->items());
        $this->assertContainsOnlyInstancesOf(Model::class, $page->items());

        $searchResults = $service->paginate(search: 'Needle Ensemble');

        $this->assertSame(2, $searchResults->total());
        $this->assertEqualsCanonicalizing(
            [Album::class, Track::class],
            $searchResults->getCollection()->map(fn (Model $model): string => $model::class)->all(),
        );

        $trackResults = $service->paginate(type: PostMediaType::Track);

        $this->assertSame(1, $trackResults->total());
        $this->assertContainsOnlyInstancesOf(Track::class, $trackResults->items());
    }

    public function test_page_is_administrator_only_and_filters_the_bounded_opportunity_list(): void
    {
        $artwork = $this->artwork('Journal seed visual');
        $this->playlist('Journal seed playlist');
        $admin = User::factory()->admin()->create();

        Livewire::actingAs(User::factory()->create())
            ->test(StoryOpportunities::class)
            ->assertForbidden();

        $component = Livewire::actingAs($admin)
            ->test(StoryOpportunities::class)
            ->assertSee('maxlength="'.StoryOpportunityService::SEARCH_LIMIT.'"', escape: false)
            ->assertSee('Journal seed visual')
            ->assertSee('Journal seed playlist')
            ->set('mediaType', PostMediaType::Artwork->value)
            ->assertSee('Journal seed visual')
            ->assertDontSee('Journal seed playlist')
            ->set('search', 'missing source')
            ->assertSee('No public, unconnected sources match these filters')
            ->set('search', '')
            ->assertActionVisible('createJournalDraft', [
                'type' => PostMediaType::Artwork->value,
                'id' => $artwork->getKey(),
            ]);

        $component
            ->callAction('createJournalDraft', [], [
                'type' => PostMediaType::Artwork->value,
                'id' => $artwork->getKey(),
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('post_media', [
            'artwork_id' => $artwork->getKey(),
            'position' => 1,
        ]);
        $this->assertSame(1, Post::query()->where('title', 'Story: Journal seed visual')->count());
    }

    public function test_dashboard_surfaces_the_opportunity_count_and_page_link(): void
    {
        $this->artwork('Dashboard opportunity');

        $component = Livewire::actingAs(User::factory()->admin()->create())
            ->test(Dashboard::class)
            ->assertSee('Story opportunities')
            ->assertSee(StoryOpportunities::getUrl(), escape: false);

        $stat = collect($component->instance()->getStats())
            ->firstWhere('label', 'Story opportunities');

        $this->assertSame(1, $stat['value']);
        $this->assertSame(StoryOpportunities::getUrl(), $stat['href']);
    }

    /** @param array<string, mixed> $attributes */
    private function artwork(string $title, array $attributes = []): Artwork
    {
        return Artwork::withoutEvents(fn (): Artwork => Artwork::query()->create(array_merge([
            'title' => $title,
            'slug' => str($title)->slug()->toString(),
            'image_path' => 'artworks/originals/'.str($title)->slug().'.jpg',
            'published' => true,
            'published_at' => now()->subMinute(),
        ], $attributes)));
    }

    /** @param array<string, mixed> $attributes */
    private function collection(string $title, array $attributes = []): Collection
    {
        return Collection::query()->create(array_merge([
            'title' => $title,
            'slug' => str($title)->slug()->toString(),
            'published' => true,
            'published_at' => now()->subMinute(),
        ], $attributes));
    }

    /** @param array<string, mixed> $attributes */
    private function album(string $title, array $attributes = []): Album
    {
        return Album::query()->create(array_merge([
            'title' => $title,
            'slug' => str($title)->slug()->toString(),
            'published' => true,
            'published_at' => now()->subMinute(),
        ], $attributes));
    }

    /** @param array<string, mixed> $attributes */
    private function playlist(string $title, array $attributes = []): Playlist
    {
        return Playlist::query()->create(array_merge([
            'title' => $title,
            'slug' => str($title)->slug()->toString(),
            'published' => true,
            'published_at' => now()->subMinute(),
        ], $attributes));
    }

    /** @param array<string, mixed> $attributes */
    private function track(string $title, array $attributes = []): Track
    {
        return Track::withoutEvents(fn (): Track => Track::query()->create(array_merge([
            'title' => $title,
            'slug' => str($title)->slug()->toString(),
            'audio_path' => 'tracks/audio/'.str($title)->slug().'.mp3',
            'standalone_published' => false,
            'standalone_published_at' => null,
        ], $attributes)));
    }
}
