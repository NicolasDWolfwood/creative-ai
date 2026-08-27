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
            ->assertSee('data-playlist-trigger aria-label="Choose an album, playlist, or track" aria-expanded="false" aria-controls="player-library-options"', false)
            ->assertSee('data-playlist-menu role="region" aria-label="Albums, playlists, and tracks"', false)
            ->assertSee('data-track-trigger aria-label="Choose a track" aria-expanded="false" aria-controls="player-track-options"', false)
            ->assertSee('data-track-menu role="region" aria-label="Tracks in the selected music source"', false)
            ->assertSeeInOrder(['data-playlist-picker', 'data-track-picker', 'data-visualizer'], false)
            ->assertDontSee('data-playlist-select', false)
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
        $this->assertStringContainsString("document.addEventListener('click'", $source);
        $this->assertStringContainsString("event.key === 'Escape'", $source);
        $this->assertStringContainsString("['ArrowDown', 'ArrowUp', 'Home', 'End']", $source);
        $this->assertStringContainsString("image.addEventListener('error', () => image.remove()", $source);
        $this->assertStringContainsString('if (index === this.playlistIndex)', $source);
        $this->assertStringContainsString('renderTracks()', $source);
        $this->assertStringContainsString('option.dataset.trackIndex = String(index)', $source);
        $this->assertStringContainsString('this.syncTrackSelection();', $source);
        $this->assertStringContainsString('this.elements.trackPicker.hidden = !isAlbum', $source);
        $this->assertStringContainsString('trackSequence(track, index)', $source);
        $this->assertStringContainsString("copy.className = 'player-track-copy'", $source);
        $this->assertStringContainsString('const restoreFocus = this.elements.trackMenu.contains(document.activeElement)', $source);
        $this->assertStringContainsString('const trackPickerHadFocus = this.elements.trackPicker.contains(document.activeElement)', $source);
        $this->assertStringContainsString('const activeTrackWasRemoved = hadTrack', $source);
        $this->assertStringContainsString('if (!points.length) { this.drawIdleVisualizer(); return; }', $source);

        $selectTrackStart = strpos($source, '    selectTrack(index) {');
        $selectPlaylistStart = strpos($source, '    selectPlaylist(index) {', $selectTrackStart ?: 0);
        $this->assertNotFalse($selectTrackStart);
        $this->assertNotFalse($selectPlaylistStart);
        $selectTrackSource = substr($source, $selectTrackStart, $selectPlaylistStart - $selectTrackStart);
        $guard = strpos($selectTrackSource, 'if (index === this.trackIndex)');
        $load = strpos($selectTrackSource, 'this.loadTrack(false);');
        $this->assertNotFalse($guard);
        $this->assertNotFalse($load);
        $this->assertStringContainsString('return;', substr($selectTrackSource, $guard, $load - $guard));
    }

    public function test_artwork_keyboard_navigation_is_scoped_guarded_and_rebound_after_livewire_navigation(): void
    {
        $source = file_get_contents(resource_path('js/app.js'));
        $navigationStart = strpos($source, 'function setupArtworkNavigation(signal)');
        $followingFunction = strpos($source, 'function focusArtworkViewer()', $navigationStart ?: 0);

        $this->assertNotFalse($navigationStart, 'Expected a dedicated artwork navigation setup function.');
        $this->assertNotFalse($followingFunction, 'Expected artwork navigation to hand off to the viewer focus helper.');
        $navigationSource = substr($source, $navigationStart, $followingFunction - $navigationStart);

        $this->assertStringContainsString("document.querySelector('[data-artwork-viewer]')", $navigationSource);
        $this->assertStringContainsString('a[data-artwork-previous][href]', $navigationSource);
        $this->assertStringContainsString('a[data-artwork-next][href]', $navigationSource);
        $this->assertStringContainsString("event.key !== 'ArrowLeft' && event.key !== 'ArrowRight'", $navigationSource);
        $this->assertStringContainsString('event.defaultPrevented || event.repeat || event.isComposing', $navigationSource);
        $this->assertStringContainsString('event.altKey || event.ctrlKey || event.metaKey || event.shiftKey', $navigationSource);
        $this->assertStringContainsString('input, textarea, select, option, button', $navigationSource);
        $this->assertStringContainsString('[role="textbox"]', $navigationSource);
        $this->assertStringContainsString('[role="slider"]', $navigationSource);
        $this->assertStringContainsString('audio, video', $navigationSource);
        $this->assertStringContainsString('target.isContentEditable', $navigationSource);
        $this->assertStringContainsString('dialog[open]', $navigationSource);
        $this->assertStringContainsString("classList.contains('lightbox-open')", $navigationSource);
        $this->assertStringContainsString('event.preventDefault()', $navigationSource);
        $this->assertStringContainsString('artworkNavigationPending = true', $navigationSource);
        $this->assertStringContainsString('link.click()', $navigationSource);
        $this->assertMatchesRegularExpression(
            "/document\\.addEventListener\\('keydown',[\\s\\S]*?\\}, \\{ signal \\}\\);/",
            $navigationSource,
        );

        $this->assertStringContainsString('setupArtworkNavigation(signal);', $source);
        $this->assertStringContainsString('shouldFocusArtworkViewer = artworkNavigationPending && !restoringHistory', $source);
        $this->assertStringContainsString('artworkNavigationPending = false', $source);
        $this->assertMatchesRegularExpression(
            '/else if \(shouldFocusArtworkViewer\) \{\s*focusArtworkViewer\(\);\s*\}/',
            $source,
        );
        $this->assertStringContainsString("viewer.scrollIntoView({ behavior: 'instant', block: 'start' })", $source);
        $this->assertStringContainsString('viewer.focus({ preventScroll: true })', $source);
    }

    public function test_lightbox_enhances_image_links_and_copy_deterrence_stays_scoped_to_artwork_previews(): void
    {
        $source = file_get_contents(resource_path('js/app.js'));
        $lightboxStart = strpos($source, 'function setupLightbox(signal)');
        $deterrenceStart = strpos($source, 'function setupArtworkPreviewDeterrence(signal)');
        $navigationStart = strpos($source, 'let artworkNavigationPending', $deterrenceStart ?: 0);

        $this->assertNotFalse($lightboxStart);
        $this->assertNotFalse($deterrenceStart);
        $this->assertNotFalse($navigationStart);

        $lightboxSource = substr($source, $lightboxStart, $deterrenceStart - $lightboxStart);
        $this->assertStringContainsString('trigger instanceof HTMLAnchorElement', $lightboxSource);
        $this->assertStringContainsString('event.altKey || event.ctrlKey || event.metaKey || event.shiftKey', $lightboxSource);
        $this->assertStringContainsString('event.preventDefault()', $lightboxSource);
        $this->assertStringContainsString('availableItems.length > 1', $lightboxSource);
        $this->assertStringContainsString('control.hidden = !hasMultipleItems', $lightboxSource);
        $this->assertStringContainsString('control.disabled = !hasMultipleItems', $lightboxSource);
        $this->assertStringContainsString('candidate.dataset.full === trigger.dataset.full && candidate.dataset.detail', $lightboxSource);
        $this->assertStringContainsString('return trigger === preferred', $lightboxSource);
        $this->assertStringContainsString('const show = (index, source = null)', $lightboxSource);
        $this->assertStringContainsString('show(Math.max(0, index), trigger)', $lightboxSource);
        $this->assertStringContainsString("const detailUrl = trigger.dataset.detail || availableItems[activeIndex]?.dataset.detail || ''", $lightboxSource);
        $this->assertStringContainsString('document.activeElement === detail', $lightboxSource);
        $this->assertStringContainsString('closeControl?.focus()', $lightboxSource);
        $this->assertStringContainsString('detail.hidden = !detailUrl', $lightboxSource);
        $this->assertStringContainsString('detail.href = detailUrl', $lightboxSource);
        $this->assertStringContainsString("detail.removeAttribute('href')", $lightboxSource);

        $layout = file_get_contents(resource_path('views/layouts/public.blade.php'));
        $this->assertMatchesRegularExpression(
            '/<button\b(?=[^>]*\bdata-lightbox-prev\b)(?=[^>]*\bhidden\b)(?=[^>]*\bdisabled\b)[^>]*>/',
            $layout,
        );
        $this->assertMatchesRegularExpression(
            '/<button\b(?=[^>]*\bdata-lightbox-next\b)(?=[^>]*\bhidden\b)(?=[^>]*\bdisabled\b)[^>]*>/',
            $layout,
        );

        $deterrenceSource = substr($source, $deterrenceStart, $navigationStart - $deterrenceStart);
        $this->assertStringContainsString("closest('[data-artwork-preview-image]')", $deterrenceSource);
        $this->assertStringContainsString("document.addEventListener('contextmenu'", $deterrenceSource);
        $this->assertStringContainsString("document.addEventListener('dragstart'", $deterrenceSource);
        $this->assertStringContainsString('event.preventDefault()', $deterrenceSource);
        $this->assertStringContainsString('setupArtworkPreviewDeterrence(signal);', $source);

        $styles = file_get_contents(resource_path('css/app.css'));
        $this->assertStringContainsString('.lightbox-prev[hidden]', $styles);
        $this->assertStringContainsString('.lightbox-next[hidden]', $styles);
        $this->assertMatchesRegularExpression('/\.lightbox figure \{[^}]*grid-column: 2;/s', $styles);
        $this->assertMatchesRegularExpression('/@media \(max-width: 760px\).*?\.lightbox figure \{[^}]*grid-column: 1;/s', $styles);
        $this->assertStringContainsString('[data-artwork-preview-image]', $styles);
        $this->assertStringContainsString('-webkit-touch-callout: none', $styles);
        $this->assertStringContainsString('-webkit-user-drag: none', $styles);
        $this->assertStringContainsString('user-select: none', $styles);
    }

    public function test_music_search_has_an_accessible_name_feedback_and_empty_states(): void
    {
        $this->get(route('music.index', ['q' => 'nothing']))
            ->assertOk()
            ->assertSee('role="search"', false)
            ->assertSee('<label for="music-search">Search albums, playlists, tracks, or artists</label>', false)
            ->assertSee('class="search-summary" role="status"', false)
            ->assertSee('No published albums match this search.')
            ->assertSee('No published playlists match this search.')
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
