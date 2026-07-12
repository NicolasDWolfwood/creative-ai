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
use App\Services\AudioTechnicalAnalysisService;
use App\Services\PublicMediaService;
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
        $album = Album::create(['title' => 'Dreams', 'slug' => 'dreams', 'published' => false]);
        $track = Track::create(['title' => 'Drift', 'slug' => 'drift', 'artist' => 'Studio', 'album_id' => $album->id, 'audio_path' => 'tracks/drift.mp3', 'published' => false, 'waveform' => [10, 50, 20]]);
        $track->tags()->attach($tag, ['category' => 'mood']);
        $artwork = Artwork::create(['title' => 'Dream Image', 'slug' => 'dream-image', 'image_path' => 'art.jpg', 'published' => true]);
        $artwork->tags()->attach($tag, ['category' => 'mood']);
        $album->update(['published' => true]);

        $this->assertFalse($track->refresh()->standalone_published);
        $this->get(route('music.index'))
            ->assertOk()
            ->assertViewHas('albums', fn ($albums): bool => $albums->contains($album))
            ->assertViewHas('tracks', fn ($tracks): bool => ! $tracks->contains($track));
        $this->get(route('music.albums.show', $album))->assertOk()->assertSee('Dreams')->assertSee('Queue');
        $this->get(route('music.tracks.show', $track))
            ->assertOk()
            ->assertSee('Dream Image')
            ->assertSee('waveform')
            ->assertSee('album-'.$album->id, false)
            ->assertSee('track-'.$track->id, false);
        $this->get(route('posts.index'))->assertOk()->assertSee('album-'.$album->id, false);
    }

    public function test_album_cover_preference_and_track_fallback_respect_album_publication(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('covers/embedded.jpg', 'image');
        $album = Album::create(['title' => 'Choice', 'embedded_cover_path' => 'covers/embedded.jpg', 'cover_preference' => 'embedded', 'published' => false]);
        $track = Track::create(['title' => 'Album Track', 'album_id' => $album->id, 'audio_path' => 'tracks/album-track.mp3', 'published' => false]);
        $this->assertStringStartsWith(route('media.albums.embedded-cover', $album), $album->cover_url);
        $this->assertNull($track->cover_url);
        $this->assertTrue($track->coverChoiceIsConfigured());

        $album->update(['published' => true]);

        $this->assertStringStartsWith(route('media.albums.embedded-cover', $album), $track->refresh()->cover_url);

        $album->update(['cover_preference' => 'none']);
        $this->assertNull($album->fresh()->cover_url);
        $this->assertNull($track->refresh()->cover_url);
        $this->assertTrue($track->coverChoiceIsConfigured());

        $album->update(['cover_preference' => 'artwork']);
        $this->assertFalse($track->refresh()->coverChoiceIsConfigured());

        $album->update(['cover_preference' => 'embedded', 'embedded_cover_path' => null]);
        $this->assertFalse($track->refresh()->coverChoiceIsConfigured());

        $futureAlbum = Album::create([
            'title' => 'Future Choice',
            'embedded_cover_path' => 'covers/embedded.jpg',
            'cover_preference' => 'embedded',
            'published' => true,
            'published_at' => now()->addDay(),
        ]);
        $futureTrack = Track::create(['title' => 'Future Track', 'album_id' => $futureAlbum->id, 'audio_path' => 'tracks/future-track.mp3', 'published' => false]);

        $this->assertNull($futureTrack->cover_url);
        $this->assertTrue($futureTrack->coverChoiceIsConfigured());
    }

    public function test_draft_album_embedded_cover_satisfies_audio_health_without_exposing_a_public_cover(): void
    {
        Queue::fake();
        Storage::fake('local');
        Storage::fake('public');
        Storage::disk('local')->put('albums/covers/draft-cover.jpg', 'cover');

        $samples = pack('s*', ...array_fill(0, 800, 1024));
        $wave = 'RIFF'.pack('V', 36 + strlen($samples)).'WAVE';
        $wave .= 'fmt '.pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16);
        $wave .= 'data'.pack('V', strlen($samples)).$samples;
        Storage::disk('local')->put('tracks/audio/draft-album.wav', $wave);

        $album = Album::create([
            'title' => 'Draft With Embedded Cover',
            'embedded_cover_path' => 'albums/covers/draft-cover.jpg',
            'cover_preference' => 'auto',
            'published' => false,
        ]);
        $track = Track::create([
            'title' => 'Covered Draft Track',
            'artist' => 'Studio',
            'album_id' => $album->id,
            'audio_path' => 'tracks/audio/draft-album.wav',
            'published' => false,
        ]);

        $this->assertNull($track->cover_url);
        $this->assertTrue($track->coverChoiceIsConfigured());

        app(AudioTechnicalAnalysisService::class)->analyze($track);

        $track->refresh();
        $this->assertSame('ready', $track->analysis_status);
        $this->assertSame('healthy', $track->health_status);
        $this->assertSame([], $track->health_issues);
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

    public function test_publishing_an_album_makes_current_and_future_tracks_available_without_publishing_them_individually(): void
    {
        Queue::fake();
        $album = Album::create(['title' => 'Complete Release', 'published' => false]);
        $first = Track::create(['title' => 'First', 'album_id' => $album->id, 'audio_path' => 'tracks/first.wav', 'published' => false]);
        $second = Track::create(['title' => 'Second', 'album_id' => $album->id, 'audio_path' => 'tracks/second.wav', 'published' => false]);

        $this->assertFalse($first->isPubliclyAvailable());
        $album->update(['published' => true]);

        $this->assertFalse($first->refresh()->standalone_published);
        $this->assertFalse($second->refresh()->standalone_published);
        $this->assertNotNull($album->refresh()->published_at);
        $this->assertNull($first->standalone_published_at);
        $this->assertTrue($first->isPubliclyAvailable());
        $this->assertTrue($second->isPubliclyAvailable());

        $future = Track::create(['title' => 'Future', 'album_id' => $album->id, 'audio_path' => 'tracks/future.wav', 'published' => false]);
        $this->assertFalse($future->standalone_published);
        $this->assertTrue($future->isPubliclyAvailable());
        $this->assertSame(
            [$first->id, $second->id, $future->id],
            Track::query()->publiclyAvailable()->orderBy('id')->pluck('id')->all(),
        );
    }

    public function test_unpublishing_an_album_revokes_album_only_tracks_but_not_standalone_tracks(): void
    {
        Queue::fake();
        $album = Album::create(['title' => 'Temporary Release', 'published' => false]);
        $albumOnly = Track::create(['title' => 'Album Only', 'album_id' => $album->id, 'audio_path' => 'tracks/album-only.wav', 'published' => false]);
        $standalone = Track::create(['title' => 'Standalone', 'album_id' => $album->id, 'audio_path' => 'tracks/standalone.wav', 'published' => true]);
        $album->update(['published' => true]);

        $this->assertTrue($albumOnly->refresh()->isPubliclyAvailable());
        $this->assertTrue($standalone->refresh()->isPubliclyAvailable());

        $album->update(['published' => false]);

        $this->assertFalse($albumOnly->refresh()->isPubliclyAvailable());
        $this->assertTrue($standalone->refresh()->isPubliclyAvailable());
        $this->get(route('music.tracks.show', $albumOnly))->assertNotFound();
        $this->get(route('music.tracks.show', $standalone))
            ->assertOk()
            ->assertDontSee(route('music.albums.show', $album), false);
    }

    public function test_scheduled_album_does_not_make_member_tracks_available_early(): void
    {
        Queue::fake();
        $album = Album::create([
            'title' => 'Tomorrow',
            'published' => true,
            'published_at' => now()->addDay(),
        ]);
        $track = Track::create(['title' => 'Too Soon', 'album_id' => $album->id, 'audio_path' => 'tracks/too-soon.wav', 'published' => false]);

        $this->assertFalse($track->refresh()->standalone_published);
        $this->assertFalse($track->isPubliclyAvailable());
        $this->assertFalse(Track::query()->publiclyAvailable()->whereKey($track)->exists());
        $this->get(route('music.tracks.show', $track))->assertNotFound();
    }

    public function test_moving_a_track_with_a_loaded_album_keeps_effective_and_legacy_publication_in_sync(): void
    {
        Queue::fake();
        $publicAlbum = Album::create(['title' => 'Public Source', 'published' => true]);
        $draftAlbum = Album::create(['title' => 'Draft Destination', 'published' => false]);
        $track = Track::create([
            'title' => 'Moving Track',
            'album_id' => $publicAlbum->id,
            'audio_path' => 'tracks/moving-track.wav',
            'standalone_published' => false,
        ]);

        $track->load('album');
        $track->album_id = $draftAlbum->id;
        $track->save();

        $this->assertFalse($track->refresh()->isPubliclyAvailable());
        $this->assertFalse($track->published);
        $this->assertNull($track->published_at);

        $track->load('album');
        $track->album_id = $publicAlbum->id;
        $track->save();

        $this->assertTrue($track->refresh()->isPubliclyAvailable());
        $this->assertTrue($track->published);
        $this->assertNotNull($track->published_at);

        $track->load('album');
        $track->album_id = null;
        $track->save();

        $this->assertFalse($track->refresh()->isPubliclyAvailable());
        $this->assertFalse($track->published);
        $this->assertNull($track->published_at);
    }

    public function test_loose_standalone_tracks_are_available_to_the_global_player(): void
    {
        Queue::fake();
        $track = Track::create([
            'title' => 'Independent Player Track',
            'slug' => 'independent-player-track',
            'audio_path' => 'tracks/independent-player-track.wav',
            'standalone_published' => true,
        ]);

        $payload = collect(app(PublicMediaService::class)->libraryPlayerPayload())->keyBy('id');

        $this->assertTrue($payload->has('standalone-tracks'));
        $this->assertSame([$track->id], collect($payload['standalone-tracks']['tracks'])->pluck('id')->all());
        $this->get(route('music.index'))
            ->assertOk()
            ->assertSee('data-track-source-id="standalone-tracks"', false)
            ->assertSee('aria-label="Add Independent Player Track to queue"', false);
    }

    public function test_cleanup_migration_resets_album_tracks_without_unpublishing_standalone_tracks(): void
    {
        Queue::fake();
        $publishedAt = now()->subDay();
        $migration = require database_path('migrations/2026_07_12_020000_reset_album_track_standalone_publication.php');
        $migration->down();

        $album = Album::withoutEvents(fn (): Album => Album::create([
            'title' => 'Previously Cascaded',
            'slug' => 'previously-cascaded',
            'published' => true,
            'published_at' => $publishedAt,
        ]));
        $albumTrack = Track::withoutEvents(fn (): Track => Track::create([
            'title' => 'Previously Cascaded Track',
            'slug' => 'previously-cascaded-track',
            'album_id' => $album->id,
            'audio_path' => 'tracks/previously-cascaded.wav',
            'published' => true,
            'published_at' => $publishedAt,
        ]));
        $standalone = Track::withoutEvents(fn (): Track => Track::create([
            'title' => 'Independent Single',
            'slug' => 'independent-single',
            'audio_path' => 'tracks/independent.wav',
            'published' => true,
            'published_at' => $publishedAt,
        ]));
        $draftAlbum = Album::withoutEvents(fn (): Album => Album::create([
            'title' => 'Draft Legacy Album',
            'slug' => 'draft-legacy-album',
            'published' => false,
        ]));
        $draftAlbumTrack = Track::withoutEvents(fn (): Track => Track::create([
            'title' => 'Incorrectly Public Draft Member',
            'slug' => 'incorrectly-public-draft-member',
            'album_id' => $draftAlbum->id,
            'audio_path' => 'tracks/incorrectly-public-draft-member.wav',
            'published' => true,
            'published_at' => $publishedAt,
        ]));
        $futureDate = now()->addDay();
        $scheduledAlbum = Album::withoutEvents(fn (): Album => Album::create([
            'title' => 'Scheduled Legacy Album',
            'slug' => 'scheduled-legacy-album',
            'published' => true,
            'published_at' => $futureDate,
        ]));
        $scheduledAlbumTrack = Track::withoutEvents(fn (): Track => Track::create([
            'title' => 'Scheduled Legacy Member',
            'slug' => 'scheduled-legacy-member',
            'album_id' => $scheduledAlbum->id,
            'audio_path' => 'tracks/scheduled-legacy-member.wav',
            'published' => true,
            'published_at' => $publishedAt,
        ]));

        $migration->up();

        $this->assertFalse($albumTrack->refresh()->standalone_published);
        $this->assertTrue($albumTrack->published);
        $this->assertSame($publishedAt->toDateTimeString(), $albumTrack->published_at->toDateTimeString());
        $this->assertTrue($standalone->refresh()->published);
        $this->assertTrue($standalone->standalone_published);
        $this->assertSame($publishedAt->toDateTimeString(), $standalone->standalone_published_at->toDateTimeString());
        $this->assertFalse($draftAlbumTrack->refresh()->standalone_published);
        $this->assertFalse($draftAlbumTrack->published);
        $this->assertNull($draftAlbumTrack->published_at);
        $this->assertFalse($scheduledAlbumTrack->refresh()->standalone_published);
        $this->assertTrue($scheduledAlbumTrack->published);
        $this->assertSame($futureDate->toDateTimeString(), $scheduledAlbumTrack->published_at->toDateTimeString());
    }

    public function test_legacy_publication_mirror_preserves_the_union_of_album_and_standalone_schedules(): void
    {
        Queue::fake();
        $album = Album::create(['title' => 'Current Album', 'published' => true]);
        $standaloneDate = now()->addDay();
        $track = Track::create([
            'title' => 'Scheduled Single',
            'album_id' => $album->id,
            'audio_path' => 'tracks/scheduled-single.wav',
            'published' => true,
            'published_at' => $standaloneDate,
        ]);

        $this->assertTrue($track->refresh()->standalone_published);
        $this->assertSame($standaloneDate->toDateTimeString(), $track->standalone_published_at->toDateTimeString());
        $this->assertFalse($track->isPubliclyPublished());
        $this->assertTrue($track->isPubliclyAvailable());
        $this->assertTrue($this->isLegacyPublic($track));

        $album->update(['published' => false]);
        $track->refresh();

        $this->assertFalse($track->isPubliclyAvailable());
        $this->assertFalse($this->isLegacyPublic($track));
        $this->assertSame($standaloneDate->toDateTimeString(), $track->published_at->toDateTimeString());

        $track->update(['standalone_published_at' => now()->subMinute()]);

        $this->assertTrue($track->refresh()->isPubliclyAvailable());
        $this->assertTrue($this->isLegacyPublic($track));
    }

    public function test_deleting_an_album_keeps_only_deliberate_standalone_tracks_public(): void
    {
        Queue::fake();
        $album = Album::create(['title' => 'Removable Album', 'published' => true]);
        $albumOnly = Track::create([
            'title' => 'Album Only',
            'album_id' => $album->id,
            'audio_path' => 'tracks/delete-album-only.wav',
            'published' => false,
        ]);
        $standalone = Track::create([
            'title' => 'Also a Single',
            'album_id' => $album->id,
            'audio_path' => 'tracks/delete-standalone.wav',
            'standalone_published' => true,
        ]);

        $album->delete();
        $albumOnly->refresh();
        $standalone->refresh();

        $this->assertNull($albumOnly->album_id);
        $this->assertFalse($albumOnly->standalone_published);
        $this->assertFalse($albumOnly->published);
        $this->assertFalse($albumOnly->isPubliclyAvailable());
        $this->assertNull($standalone->album_id);
        $this->assertTrue($standalone->standalone_published);
        $this->assertTrue($standalone->published);
        $this->assertTrue($standalone->isPubliclyAvailable());
    }

    public function test_public_album_lists_unpublished_member_tracks_in_disc_and_track_order(): void
    {
        Queue::fake();
        $album = Album::create(['title' => 'Public Shell', 'published' => false]);
        Track::create(['title' => 'Second Song', 'album_id' => $album->id, 'audio_path' => 'tracks/second.wav', 'disc_number' => 1, 'track_number' => 2, 'published' => false]);
        Track::create(['title' => 'First Song', 'album_id' => $album->id, 'audio_path' => 'tracks/first.wav', 'disc_number' => 1, 'track_number' => 1, 'published' => false]);
        Track::create(['title' => 'Bonus Disc', 'album_id' => $album->id, 'audio_path' => 'tracks/bonus.wav', 'disc_number' => 2, 'track_number' => 1, 'published' => false]);
        $album->update(['published' => true]);

        $this->get(route('music.albums.show', $album))
            ->assertOk()
            ->assertSeeInOrder(['First Song', 'Second Song', 'Bonus Disc']);
    }

    private function isLegacyPublic(Track $track): bool
    {
        return (bool) $track->published
            && (! $track->published_at || $track->published_at->isPast());
    }
}
