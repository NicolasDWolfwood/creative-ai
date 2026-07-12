<?php

namespace Tests\Feature;

use App\Models\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    }

    public function test_music_search_has_an_accessible_name_feedback_and_empty_states(): void
    {
        $this->get(route('music.index', ['q' => 'nothing']))
            ->assertOk()
            ->assertSee('role="search"', false)
            ->assertSee('<label for="music-search">Search albums, tracks, or artists</label>', false)
            ->assertSee('class="search-summary" role="status"', false)
            ->assertSee('No published albums match this search.')
            ->assertSee('No published tracks match this search.');
    }
}
