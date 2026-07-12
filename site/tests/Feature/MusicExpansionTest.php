<?php

namespace Tests\Feature;

use App\Filament\Resources\Tracks\Pages\ManageTracks;
use App\Jobs\AnalyzeTrackAudio;
use App\Models\Album;
use App\Models\Artwork;
use App\Models\Playlist;
use App\Models\Tag;
use App\Models\Track;
use App\Models\User;
use App\Services\AlbumPublishingService;
use App\Services\AudioTechnicalAnalysisService;
use App\Services\SmartPlaylistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class MusicExpansionTest extends TestCase
{
    use RefreshDatabase;

    public function test_audio_upload_queues_technical_analysis_and_waveform_is_compact(): void
    {
        Queue::fake();
        Storage::fake('public');
        Storage::disk('public')->put('tracks/test.mp3', 'audio');
        $track = Track::create(['title' => 'Signal', 'audio_path' => 'tracks/test.mp3']);
        Queue::assertPushed(
            AnalyzeTrackAudio::class,
            fn ($job) => $job->trackId === $track->id && $job->afterCommit === true,
        );

        $pcm = pack('s*', ...array_fill(0, 240, 16384));
        $waveform = app(AudioTechnicalAnalysisService::class)->waveform($pcm, 12);
        $this->assertCount(12, $waveform);
        $this->assertSame(50, $waveform[0]);
    }

    public function test_richer_smart_rules_filter_order_limit_and_can_preserve_a_snapshot(): void
    {
        Queue::fake();
        $album = Album::create(['title' => 'Night']);
        Track::create(['title' => 'Long', 'artist' => 'Composer', 'album_id' => $album->id, 'audio_path' => 'long.mp3', 'duration_seconds' => 300, 'release_year' => 2025, 'published' => true, 'health_status' => 'healthy']);
        Track::create(['title' => 'Short', 'artist' => 'Composer', 'album_id' => $album->id, 'audio_path' => 'short.mp3', 'duration_seconds' => 90, 'release_year' => 2025, 'published' => true, 'health_status' => 'healthy']);
        Track::create(['title' => 'Other', 'artist' => 'Other', 'audio_path' => 'other.mp3', 'duration_seconds' => 60, 'published' => true]);
        $playlist = Playlist::create(['title' => 'Smart', 'is_smart' => true, 'smart_rules' => ['artist' => 'Composer', 'album_ids' => [$album->id], 'max_duration' => 240, 'order' => 'duration', 'max_tracks' => 1]]);

        $this->assertSame(1, app(SmartPlaylistService::class)->sync($playlist));
        $this->assertSame('Short', $playlist->tracks()->first()->title);
        $playlist->update(['is_smart' => false, 'auto_sync' => false]);
        Track::create(['title' => 'New', 'artist' => 'Composer', 'album_id' => $album->id, 'audio_path' => 'new.mp3', 'duration_seconds' => 30, 'published' => true]);
        $this->assertSame(['Short'], $playlist->tracks()->pluck('title')->all());
    }

    public function test_public_music_pages_and_cross_media_recommendations_are_available(): void
    {
        Queue::fake();
        Storage::fake('public');
        $tag = Tag::create(['name' => 'dreamlike', 'slug' => 'dreamlike']);
        $album = Album::create(['title' => 'Dreams', 'slug' => 'dreams', 'published' => true]);
        $track = Track::create(['title' => 'Drift', 'slug' => 'drift', 'artist' => 'Studio', 'album_id' => $album->id, 'audio_path' => 'tracks/drift.mp3', 'published' => true, 'waveform' => [10, 50, 20]]);
        $track->tags()->attach($tag, ['category' => 'mood']);
        $artwork = Artwork::create(['title' => 'Dream Image', 'slug' => 'dream-image', 'image_path' => 'art.jpg', 'published' => true]);
        $artwork->tags()->attach($tag, ['category' => 'mood']);

        $this->get(route('music.index'))->assertOk()->assertSee('Drift');
        $this->get(route('music.albums.show', $album))->assertOk()->assertSee('Dreams')->assertSee('Queue');
        $this->get(route('music.tracks.show', $track))
            ->assertOk()
            ->assertSee('Dream Image')
            ->assertSee('waveform')
            ->assertSee('album-'.$album->id, false)
            ->assertSee('track-'.$track->id, false);
        $this->get(route('posts.index'))->assertOk()->assertSee('album-'.$album->id, false);
    }

    public function test_album_cover_preference_can_explicitly_choose_embedded_or_none(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('covers/embedded.jpg', 'image');
        $album = Album::create(['title' => 'Choice', 'embedded_cover_path' => 'covers/embedded.jpg', 'cover_preference' => 'embedded']);
        $this->assertStringStartsWith(route('media.albums.embedded-cover', $album), $album->cover_url);
        $album->update(['cover_preference' => 'none']);
        $this->assertNull($album->fresh()->cover_url);
    }

    public function test_attention_reasons_are_visible_in_the_track_table_and_editor(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->admin()->create());
        $track = Track::create(['title' => 'Needs a cover', 'audio_path' => 'tracks/needs-cover.mp3']);
        $track->forceFill([
            'analysis_status' => 'ready',
            'health_status' => 'attention',
            'health_issues' => ['Cover artwork is missing', 'Artist is missing'],
            'audio_codec' => 'mp3',
            'bitrate' => 320000,
            'sample_rate' => 44100,
            'channels' => 2,
            'analyzed_at' => now(),
        ])->saveQuietly();

        Livewire::test(ManageTracks::class)
            ->assertSee('Cover artwork is missing')
            ->assertTableActionExists('edit');

        $this->assertSame('Cover artwork is missing · Artist is missing', $track->healthExplanation());
        $this->assertStringContainsString('MP3 · 320 kbps · 44.1 kHz · 2 channels', $track->technicalSummary());
    }

    public function test_publishing_an_album_publishes_its_tracks_and_future_album_tracks(): void
    {
        Queue::fake();
        $album = Album::create(['title' => 'Complete Release', 'published' => false]);
        $first = Track::create(['title' => 'First', 'album_id' => $album->id, 'audio_path' => 'tracks/first.wav', 'published' => false]);
        $second = Track::create(['title' => 'Second', 'album_id' => $album->id, 'audio_path' => 'tracks/second.wav', 'published' => false]);

        $album->update(['published' => true]);

        $this->assertTrue($first->refresh()->published);
        $this->assertTrue($second->refresh()->published);
        $this->assertNotNull($album->refresh()->published_at);
        $this->assertNotNull($first->published_at);

        $future = Track::create(['title' => 'Future', 'album_id' => $album->id, 'audio_path' => 'tracks/future.wav', 'published' => false]);
        $this->assertTrue($future->published);

        $album->update(['published' => false]);
        $this->assertTrue($first->refresh()->published);
        $this->assertSame(0, app(AlbumPublishingService::class)->publish($album->refresh()));
        $this->assertTrue($album->refresh()->published);
    }

    public function test_legacy_published_albums_are_backfilled_and_reconciled_on_later_saves(): void
    {
        Queue::fake();
        $album = Album::create(['title' => 'Legacy Release', 'published' => false]);
        $track = Track::create(['title' => 'Legacy Track', 'album_id' => $album->id, 'audio_path' => 'tracks/legacy.wav', 'published' => false]);
        $album->forceFill(['published' => true, 'published_at' => null])->saveQuietly();

        $this->assertFalse($track->refresh()->published);

        $migration = require database_path('migrations/2026_07_12_000000_reconcile_published_album_tracks.php');
        $migration->up();

        $this->assertNotNull($album->refresh()->published_at);
        $this->assertTrue($track->refresh()->published);
        $this->assertNotNull($track->published_at);

        $laterAlbum = Album::create(['title' => 'Later Legacy Release', 'published' => false]);
        $laterTrack = Track::create(['title' => 'Later Legacy Track', 'album_id' => $laterAlbum->id, 'audio_path' => 'tracks/later-legacy.wav', 'published' => false]);
        $laterAlbum->forceFill(['published' => true, 'published_at' => null])->saveQuietly();
        $laterAlbum->update(['description' => 'Reconciled when this existing album is saved.']);

        $this->assertNotNull($laterAlbum->refresh()->published_at);
        $this->assertTrue($laterTrack->refresh()->published);
    }

    public function test_album_detail_never_exposes_unpublished_tracks_from_inconsistent_legacy_data(): void
    {
        Queue::fake();
        $album = Album::create(['title' => 'Public Shell', 'published' => false]);
        Track::create(['title' => 'Hidden Legacy Track', 'album_id' => $album->id, 'audio_path' => 'tracks/hidden.wav', 'published' => false]);
        $album->forceFill(['published' => true, 'published_at' => now()])->saveQuietly();

        $this->get(route('music.albums.show', $album))
            ->assertOk()
            ->assertDontSee('Hidden Legacy Track');
    }
}
