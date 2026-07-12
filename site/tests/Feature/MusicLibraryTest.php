<?php

namespace Tests\Feature;

use App\Filament\Resources\Albums\Pages\ManageAlbums;
use App\Filament\Resources\Playlists\Pages\ManagePlaylists;
use App\Filament\Resources\Tracks\Pages\ManageTracks;
use App\Jobs\AnalyzeTrackMetadata;
use App\Models\Album;
use App\Models\Artwork;
use App\Models\Playlist;
use App\Models\Tag;
use App\Models\Track;
use App\Models\User;
use App\Services\AlbumImportService;
use App\Services\AudioMetadataService;
use App\Services\MusicArtworkSuggestionService;
use App\Services\PublicMediaService;
use App\Services\TrackAiMetadataService;
use App\Services\TrackAiQueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class MusicLibraryTest extends TestCase
{
    use RefreshDatabase;

    public function test_filename_fallback_understands_common_audio_naming_patterns(): void
    {
        $service = app(AudioMetadataService::class);

        $this->assertSame(['artist' => 'Artist', 'title' => 'Title'], $service->fromFilename('Artist - Title.mp3'));
        $this->assertSame(['title' => 'Title', 'track_number' => 1], $service->fromFilename('01 - Title.mp3'));
        $this->assertSame(
            ['artist' => 'Artist', 'album' => 'Album', 'track_number' => 2, 'title' => 'Title'],
            $service->fromFilename('Artist - Album - 02 - Title.mp3'),
        );
    }

    public function test_bulk_import_uses_filename_metadata_and_keeps_tracks_for_review(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('tracks/audio/one.mp3', 'not-real-audio');
        Storage::disk('public')->put('tracks/audio/two.mp3', 'not-real-audio');
        $album = Album::query()->create(['title' => 'Review Album', 'artist' => 'Studio']);

        $tracks = app(AlbumImportService::class)->import(
            ['tracks/audio/one.mp3', 'tracks/audio/two.mp3'],
            ['tracks/audio/one.mp3' => 'Studio - First Light.mp3', 'tracks/audio/two.mp3' => '02 - Second Light.mp3'],
            $album->id,
        );

        $this->assertSame(['First Light', 'Second Light'], $tracks->pluck('title')->all());
        $this->assertSame([null, 2], $tracks->pluck('track_number')->all());
        $this->assertTrue($tracks->every(fn (Track $track): bool => ! $track->published && $track->metadata_reviewed_at === null));
        $this->assertSame(2, $album->tracks()->count());
    }

    public function test_bulk_import_groups_different_track_artists_by_normalized_album_name(): void
    {
        Storage::fake('public');
        Queue::fake();
        Storage::disk('public')->put('tracks/audio/first.mp3', 'not-real-audio');
        Storage::disk('public')->put('tracks/audio/second.mp3', 'not-real-audio');

        $tracks = app(AlbumImportService::class)->import(
            ['tracks/audio/first.mp3', 'tracks/audio/second.mp3'],
            [
                'tracks/audio/first.mp3' => 'Artist One - Shared Album - 01 - First Song.mp3',
                'tracks/audio/second.mp3' => 'Artist Two - Shared Album - 02 - Second Song.mp3',
            ],
        );

        $this->assertSame(1, Album::query()->count());
        $this->assertSame(1, $tracks->pluck('album_id')->unique()->count());
        $this->assertSame(['First Song', 'Second Song'], Album::query()->firstOrFail()->tracks->pluck('title')->all());
        $this->assertNotNull(Album::query()->firstOrFail()->import_key);
    }

    public function test_audio_import_never_overwrites_manually_supplied_fields(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('tracks/audio/manual.mp3', 'not-real-audio');

        $track = Track::query()->create([
            'title' => 'Manual title', 'artist' => 'Manual artist', 'audio_path' => 'tracks/audio/manual.mp3',
            'original_filename' => 'Detected Artist - Detected Title.mp3', 'duration_seconds' => 42,
        ]);

        $this->assertSame('Manual title', $track->title);
        $this->assertSame('Manual artist', $track->artist);
        $this->assertSame(42, $track->duration_seconds);
        $this->assertSame('Detected Title', data_get($track->metadata, 'audio_import.title'));
    }

    public function test_single_track_import_can_fill_a_blank_title_and_slug(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('tracks/audio/automatic.mp3', 'not-real-audio');

        $track = Track::query()->create([
            'title' => '', 'audio_path' => 'tracks/audio/automatic.mp3',
            'original_filename' => 'Automatic Artist - Automatic Title.mp3',
        ]);

        $this->assertSame('Automatic Title', $track->title);
        $this->assertSame('Automatic Artist', $track->artist);
        $this->assertSame('automatic-title', $track->slug);
    }

    public function test_artwork_suggestions_rank_shared_tags_and_prefer_featured_ties(): void
    {
        Storage::fake('public');
        Queue::fake();
        $mood = Tag::query()->create(['name' => 'calm']);
        $style = Tag::query()->create(['name' => 'surreal']);
        $track = Track::query()->create(['title' => 'Song', 'audio_path' => 'tracks/audio/missing.mp3']);
        $track->tags()->attach([$mood->id => ['category' => 'mood'], $style->id => ['category' => 'style']]);
        $best = $this->artwork('Best Match', true);
        $best->tags()->attach([$mood->id => ['category' => 'mood'], $style->id => ['category' => 'style']]);
        $other = $this->artwork('Other Match');
        $other->tags()->attach($mood->id, ['category' => 'mood']);

        $matches = app(MusicArtworkSuggestionService::class)->forTrack($track);

        $this->assertSame([$best->id, $other->id], $matches->pluck('artwork.id')->all());
        $this->assertGreaterThan($matches[1]['score'], $matches[0]['score']);
    }

    public function test_album_track_and_manual_playlist_order_are_intrinsic_and_public(): void
    {
        Storage::fake('public');
        $album = Album::query()->create(['title' => 'Ordered Album', 'published' => true]);
        $second = Track::query()->create(['album_id' => $album->id, 'title' => 'Second', 'audio_path' => 'tracks/audio/2.mp3', 'track_number' => 2, 'published' => true]);
        $first = Track::query()->create(['album_id' => $album->id, 'title' => 'First', 'audio_path' => 'tracks/audio/1.mp3', 'track_number' => 1, 'published' => true]);
        $playlist = Playlist::query()->create(['title' => 'Manual order', 'published' => true]);
        $playlist->entries()->createMany([['track_id' => $second->id, 'position' => 1], ['track_id' => $first->id, 'position' => 2]]);

        $this->assertSame(['First', 'Second'], $album->tracks->pluck('title')->all());
        $this->assertSame(['Second', 'First'], $playlist->tracks->pluck('title')->all());
        $payload = app(PublicMediaService::class)->playerPayload(collect([$playlist]), collect([$album]));
        $this->assertSame(['album-'.$album->id, 'playlist-'.$playlist->id], array_column($payload, 'id'));
        $this->assertSame(['album', 'playlist'], array_column($payload, 'type'));
        $playerSource = file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString("album: 'Albums', playlist: 'Playlists'", $playerSource);
        $this->assertStringContainsString("document.createElement('optgroup')", $playerSource);
    }

    public function test_music_admin_pages_render_the_import_album_and_playlist_workflows(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ManageTracks::class)->assertSuccessful();
        Livewire::test(ManageAlbums::class)->assertSuccessful();
        Livewire::test(ManagePlaylists::class)->assertSuccessful();
    }

    public function test_track_bulk_actions_queue_ai_and_mark_metadata_reviewed(): void
    {
        Storage::fake('public');
        Queue::fake();
        $this->actingAs(User::factory()->admin()->create());
        $first = Track::query()->create(['title' => 'First', 'audio_path' => 'tracks/audio/first.mp3']);
        $second = Track::query()->create(['title' => 'Second', 'audio_path' => 'tracks/audio/second.mp3']);

        Livewire::test(ManageTracks::class)
            ->callTableBulkAction('generateSelectedTags', [$first, $second]);

        Queue::assertPushed(AnalyzeTrackMetadata::class, 2);
        Queue::assertPushed(AnalyzeTrackMetadata::class, fn (AnalyzeTrackMetadata $job): bool => $job->trackId === $first->id);

        Livewire::test(ManageTracks::class)
            ->callTableBulkAction('markSelectedMetadataReviewed', [$first, $second]);

        $this->assertNotNull($first->refresh()->metadata_reviewed_at);
        $this->assertNotNull($second->refresh()->metadata_reviewed_at);
    }

    public function test_pending_track_analysis_is_queued_and_ready_suggestions_are_applied_explicitly(): void
    {
        Storage::fake('public');
        Queue::fake();
        $pending = Track::query()->create(['title' => 'Pending', 'audio_path' => 'tracks/audio/pending.mp3']);
        $ready = Track::query()->create([
            'title' => 'Ready', 'audio_path' => 'tracks/audio/ready.mp3',
            'ai_status' => Track::AI_STATUS_READY,
            'ai_suggestion' => ['genre_tags' => ['ambient'], 'mood_tags' => ['calm'], 'tags' => ['dreamlike']],
        ]);

        $count = app(TrackAiQueueService::class)->queuePending([Track::AI_STATUS_IDLE]);

        $this->assertSame(1, $count);
        $this->assertSame(Track::AI_STATUS_QUEUED, $pending->refresh()->ai_status);
        Queue::assertPushed(AnalyzeTrackMetadata::class, 1);
        $this->assertSame(0, $ready->tags()->count());

        $this->assertSame(1, app(TrackAiMetadataService::class)->applyReadySuggestions());
        $this->assertSame(Track::AI_STATUS_APPLIED, $ready->refresh()->ai_status);
        $this->assertEqualsCanonicalizing(['ambient', 'calm', 'dreamlike'], $ready->tags()->pluck('name')->all());
    }

    public function test_bulk_artwork_matching_applies_best_matches_without_replacing_manual_covers(): void
    {
        Storage::fake('public');
        Queue::fake();
        $this->actingAs(User::factory()->admin()->create());
        $tag = Tag::query()->create(['name' => 'dreamlike']);
        $suggested = $this->artwork('Suggested');
        $suggested->tags()->attach($tag->id, ['category' => 'mood']);
        $manual = $this->artwork('Manual');
        $first = Track::query()->create(['title' => 'First', 'audio_path' => 'tracks/audio/first.mp3']);
        $second = Track::query()->create(['title' => 'Second', 'audio_path' => 'tracks/audio/second.mp3', 'cover_artwork_id' => $manual->id]);
        $first->tags()->attach($tag->id, ['category' => 'mood']);
        $second->tags()->attach($tag->id, ['category' => 'mood']);

        Livewire::test(ManageTracks::class)
            ->mountTableBulkAction('applySuggestedArtwork', [$first, $second])
            ->setTableBulkActionData(['replace_existing' => false])
            ->callMountedTableBulkAction();

        $this->assertSame($suggested->id, $first->refresh()->cover_artwork_id);
        $this->assertSame($manual->id, $second->refresh()->cover_artwork_id);
    }

    protected function artwork(string $title, bool $featured = false): Artwork
    {
        $path = 'artworks/originals/'.str()->uuid().'.jpg';
        Storage::disk('public')->put($path, 'image');

        return Artwork::query()->create(['title' => $title, 'image_path' => $path, 'published' => true, 'featured' => $featured]);
    }
}
