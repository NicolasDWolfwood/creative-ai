<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\Collection;
use App\Models\Playlist;
use App\Models\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PersistentPlayerNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_pages_render_a_persisted_audio_player_and_navigable_collection_links(): void
    {
        $collection = Collection::create([
            'title' => 'Persistent World',
            'slug' => 'persistent-world',
            'published' => true,
        ]);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('x-persist="creative-ai-player"', false)
            ->assertSee('<audio data-player-audio preload="metadata"></audio>', false)
            ->assertSee('class="skip-link" href="#main-content"', false)
            ->assertSee('<main id="main-content" tabindex="-1">', false)
            ->assertSee('aria-controls="primary-navigation"', false)
            ->assertSee('data-shuffle aria-label="Shuffle" aria-pressed="false"', false)
            ->assertSee('data-player-status aria-live="polite"', false)
            ->assertSee('href="'.route('collections.show', $collection).'#gallery"', false)
            ->assertSee('data-reveal wire:navigate', false);
    }

    public function test_player_javascript_is_singleton_and_rebinds_after_livewire_navigation(): void
    {
        $source = file_get_contents(resource_path('js/app.js'));

        $this->assertStringNotContainsString('new Audio()', $source);
        $this->assertStringContainsString("document.addEventListener('livewire:navigated', () => {", $source);
        $this->assertStringContainsString('player.updateLibrary(window.creativeAi || {})', $source);
        $this->assertStringContainsString('pageController = new AbortController()', $source);
        $this->assertStringContainsString('player.bindPageControls(signal)', $source);
        $this->assertStringContainsString("document.addEventListener('livewire:navigate'", $source);
        $this->assertStringContainsString('restoringHistory = Boolean(event.detail?.history)', $source);
        $this->assertStringContainsString('setupPage({ handleHash: !restoringHistory })', $source);
        $this->assertStringContainsString("target.scrollIntoView({ behavior: 'instant', block: 'start' })", $source);
        $this->assertStringContainsString("target.querySelector('[data-gallery-focus-target]')?.focus({ preventScroll: true })", $source);
        $this->assertStringContainsString('setupCollectionSwitcher()', $source);
        $this->assertStringContainsString('rememberNavigationFocus()', $source);
        $this->assertStringContainsString('restoreHistoryFocus()', $source);
        $this->assertStringContainsString('key: focusedLink.dataset.navigationFocusKey || null', $source);
        $this->assertStringContainsString('link.dataset.navigationFocusKey === source.key', $source);
        $this->assertStringContainsString('element.inert = true', $source);
        $this->assertStringContainsString('opener?.isConnected', $source);
        $this->assertStringContainsString("event.key === 'Tab'", $source);
        $this->assertStringContainsString("setAttribute('aria-pressed'", $source);
        $this->assertStringContainsString("setAttribute('aria-label', isPlaying ? 'Pause' : 'Play')", $source);
        $this->assertStringContainsString("localStorage.removeItem('creative-ai-player')", $source);
        $this->assertStringContainsString('this.loadTrack(false);', $source);
        $this->assertStringContainsString('preferredPlaylistId', $source);
    }

    public function test_music_search_has_an_accessible_name_feedback_and_empty_states(): void
    {
        $this->get(route('music.index', ['q' => 'nothing']))
            ->assertOk()
            ->assertSee('role="search"', false)
            ->assertSee('<label for="music-search">Search albums, tracks, or artists</label>', false)
            ->assertSee('class="search-summary" role="status"', false)
            ->assertSee('No published albums match this search.')
            ->assertSee('No standalone tracks match this search.');
    }

    public function test_music_library_keeps_album_tracks_out_of_the_standalone_list_and_searches_their_album(): void
    {
        Queue::fake();
        $album = Album::create(['title' => 'A Quiet Record', 'published' => false]);
        $albumOnly = Track::create([
            'title' => 'Hidden Constellation',
            'artist' => 'Studio',
            'album_id' => $album->id,
            'audio_path' => 'tracks/hidden-constellation.mp3',
            'published' => false,
        ]);
        $standalone = Track::create([
            'title' => 'Independent Signal',
            'artist' => 'Studio',
            'audio_path' => 'tracks/independent-signal.mp3',
            'published' => true,
        ]);
        $album->update(['published' => true]);

        $this->get(route('music.index'))
            ->assertOk()
            ->assertSee('<h2 id="tracks-title">Singles &amp; standalone tracks</h2>', false)
            ->assertViewHas('tracks', fn ($tracks): bool => $tracks->contains($standalone) && ! $tracks->contains($albumOnly));

        $this->get(route('music.index', ['q' => 'Hidden Constellation']))
            ->assertOk()
            ->assertViewHas('albums', fn ($albums): bool => $albums->contains($album))
            ->assertViewHas('tracks', fn ($tracks): bool => ! $tracks->contains($albumOnly));
    }

    public function test_homepage_limits_visible_listening_choices_without_truncating_the_player_library(): void
    {
        Queue::fake();
        $tracks = collect();

        foreach (range(1, 5) as $sequence) {
            $album = Album::create(['title' => 'Album '.$sequence, 'published' => false]);
            $tracks->push(Track::create([
                'title' => 'Album track '.$sequence,
                'album_id' => $album->id,
                'audio_path' => 'tracks/album-'.$sequence.'.mp3',
                'published' => false,
            ]));
            $album->update(['published' => true]);
        }

        foreach (range(1, 3) as $sequence) {
            $playlist = Playlist::create(['title' => 'Session '.$sequence, 'published' => true]);
            $playlist->tracks()->attach($tracks->first(), ['position' => 1]);
        }

        $this->get(route('home'))
            ->assertOk()
            ->assertViewHas('homeAlbums', fn ($albums): bool => $albums->count() === 4)
            ->assertViewHas('homePlaylists', fn ($playlists): bool => $playlists->count() === 2)
            ->assertViewHas('playerPayload', fn (array $payload): bool => count($payload) === 8)
            ->assertSee('Browse the full music library');
    }
}
