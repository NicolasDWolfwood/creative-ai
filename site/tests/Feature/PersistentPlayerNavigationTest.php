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
            ->assertSee('href="'.route('collections.show', $collection).'" data-reveal wire:navigate', false);
    }

    public function test_player_javascript_is_singleton_and_rebinds_after_livewire_navigation(): void
    {
        $source = file_get_contents(resource_path('js/app.js'));

        $this->assertStringNotContainsString('new Audio()', $source);
        $this->assertStringContainsString("document.addEventListener('livewire:navigated', setupPage)", $source);
        $this->assertStringContainsString('player.updateLibrary(window.creativeAi || {})', $source);
        $this->assertStringContainsString('pageController = new AbortController()', $source);
        $this->assertStringContainsString('player.bindPageControls(signal)', $source);
    }
}
