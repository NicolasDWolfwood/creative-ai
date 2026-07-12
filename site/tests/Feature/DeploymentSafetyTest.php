<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\Artwork;
use App\Models\Collection;
use App\Models\Playlist;
use App\Models\SiteSetting;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class DeploymentSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_staging_responses_are_not_indexable(): void
    {
        config()->set('creative_ai.allow_indexing', false);

        $this->get('/')
            ->assertOk()
            ->assertHeader('Content-Security-Policy')
            ->assertHeader('Permissions-Policy')
            ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->assertSee('noindex,nofollow,noarchive', escape: false);

        $this->get('/robots.txt')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->assertSee('Disallow: /', escape: false);
    }

    public function test_production_robots_uses_the_configured_canonical_url(): void
    {
        config()->set('app.url', 'https://www.example.com');
        config()->set('creative_ai.allow_indexing', true);

        $this->get('/robots.txt')
            ->assertOk()
            ->assertHeaderMissing('X-Robots-Tag')
            ->assertSee('Disallow: /admin', escape: false)
            ->assertSee('Sitemap: https://www.example.com/sitemap.xml', escape: false);
    }

    public function test_structured_data_cannot_close_its_script_element(): void
    {
        SiteSetting::query()->create([
            'key' => 'home_intro',
            'value' => [
                'title' => 'Creative-Ai',
                'body' => '</script><script>window.compromised = true</script>',
            ],
        ]);

        $this->get('/')
            ->assertOk()
            ->assertDontSee('</script><script>window.compromised = true</script>', escape: false)
            ->assertSee('window.compromised', escape: false);
    }

    public function test_future_dated_media_is_excluded_from_public_scopes(): void
    {
        Storage::fake('public');
        Queue::fake();
        $future = now()->addDay();

        $artwork = Artwork::query()->create(['title' => 'Future artwork', 'slug' => 'future-artwork', 'image_path' => 'future.jpg', 'published' => true, 'published_at' => $future]);
        $collection = Collection::query()->create(['title' => 'Future collection', 'slug' => 'future-collection', 'published' => true, 'published_at' => $future]);
        $track = Track::query()->create(['title' => 'Future track', 'slug' => 'future-track', 'audio_path' => 'future.mp3', 'published' => true, 'published_at' => $future]);
        $album = Album::query()->create(['title' => 'Future album', 'slug' => 'future-album', 'published' => true, 'published_at' => $future]);
        $albumTrack = Track::query()->create(['title' => 'Future album track', 'slug' => 'future-album-track', 'album_id' => $album->id, 'audio_path' => 'future-album-track.mp3', 'published' => false]);
        $playlist = Playlist::query()->create(['title' => 'Future playlist', 'slug' => 'future-playlist', 'published' => true, 'published_at' => $future]);

        foreach ([$artwork, $collection, $track, $album, $playlist] as $record) {
            $this->assertFalse($record->newQuery()->published()->whereKey($record)->exists());
            $this->assertFalse($record->isPubliclyPublished());
        }

        $this->get(route('collections.show', $collection))->assertNotFound();
        $this->get(route('music.albums.show', $album))->assertNotFound();
        $this->get(route('music.tracks.show', $track))->assertNotFound();
        $this->assertFalse($albumTrack->refresh()->standalone_published);
        $this->assertFalse($albumTrack->isPubliclyAvailable());
        $this->get(route('music.tracks.show', $albumTrack))->assertNotFound();
    }

    public function test_private_media_routes_enforce_publication_and_allow_administrators(): void
    {
        Storage::fake('local');
        Queue::fake();
        Storage::disk('local')->put('artworks/originals/draft.jpg', 'draft-image');
        Storage::disk('local')->put('tracks/audio/draft.mp3', 'draft-audio');
        $artwork = Artwork::query()->create(['title' => 'Draft artwork', 'slug' => 'draft-artwork', 'image_path' => 'artworks/originals/draft.jpg', 'published' => false]);
        $track = Track::query()->create(['title' => 'Draft track', 'slug' => 'draft-track', 'audio_path' => 'tracks/audio/draft.mp3', 'published' => false]);

        $this->assertStringContainsString('/artworks/draft-artwork/image', $artwork->public_image_url);
        $this->get($artwork->public_image_url)->assertNotFound();
        $this->get(route('media.artworks.show', [$artwork, 'variant' => 'original']))->assertNotFound();
        $this->get(route('media.tracks.audio', $track))->assertNotFound();

        $this->actingAs(User::factory()->admin()->create())
            ->get($artwork->public_image_url)
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->get(route('media.tracks.audio', $track))->assertOk();

        $artwork->forceFill(['published' => true, 'published_at' => now()])->saveQuietly();
        $this->get($artwork->public_image_url)
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=86400, public');
    }

    public function test_album_publication_controls_anonymous_audio_access_without_changing_track_publication(): void
    {
        Storage::fake('local');
        Queue::fake();
        Storage::disk('local')->put('tracks/audio/album-only.mp3', 'album-audio');
        Storage::disk('local')->put('tracks/audio/scheduled.mp3', 'scheduled-audio');

        $album = Album::query()->create(['title' => 'Listening Session', 'published' => false]);
        $albumOnly = Track::query()->create([
            'title' => 'Album Only',
            'slug' => 'album-only',
            'album_id' => $album->id,
            'audio_path' => 'tracks/audio/album-only.mp3',
            'published' => false,
        ]);

        $this->get(route('media.tracks.audio', $albumOnly))->assertNotFound();

        $album->update(['published' => true]);

        $this->assertFalse($albumOnly->refresh()->standalone_published);
        $this->get(route('media.tracks.audio', $albumOnly))
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-cache, private');

        $album->update(['published' => false]);

        $this->get(route('media.tracks.audio', $albumOnly))->assertNotFound();

        $albumOnly->update(['standalone_published' => true, 'standalone_published_at' => now()]);

        $this->get(route('media.tracks.audio', $albumOnly))
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-cache, private');

        $scheduledAlbum = Album::query()->create([
            'title' => 'Scheduled Session',
            'published' => true,
            'published_at' => now()->addDay(),
        ]);
        $scheduled = Track::query()->create([
            'title' => 'Scheduled Album Track',
            'slug' => 'scheduled-album-track',
            'album_id' => $scheduledAlbum->id,
            'audio_path' => 'tracks/audio/scheduled.mp3',
            'published' => false,
        ]);

        $this->assertFalse($scheduled->refresh()->standalone_published);
        $this->get(route('media.tracks.audio', $scheduled))->assertNotFound();
    }

    public function test_signed_private_storage_previews_also_require_an_administrator_session(): void
    {
        $root = storage_path('framework/testing/private-preview-'.str()->uuid());
        config()->set('filesystems.disks.local.root', $root);
        Storage::forgetDisk('local');
        $path = 'artworks/originals/private-preview.jpg';
        Storage::disk('local')->put($path, 'private-preview');
        $signedUrl = Storage::disk('local')->temporaryUrl($path, now()->addMinutes(5));

        try {
            $this->get($signedUrl)->assertNotFound();

            $this->actingAs(User::factory()->create())
                ->get($signedUrl)
                ->assertNotFound();

            $this->actingAs(User::factory()->admin()->create())
                ->get($signedUrl)
                ->assertOk()
                ->assertStreamedContent('private-preview');

            $this->get(parse_url($signedUrl, PHP_URL_PATH))->assertForbidden();
        } finally {
            Storage::forgetDisk('local');
            File::deleteDirectory($root);
        }
    }

    public function test_existing_originals_and_audio_can_be_privatized_idempotently(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        Storage::disk('public')->put('artworks/originals/existing.jpg', 'existing-image');
        Storage::disk('public')->put('tracks/audio/existing.mp3', 'existing-audio');
        Artwork::withoutEvents(fn (): Artwork => Artwork::query()->create(['title' => 'Existing artwork', 'slug' => 'existing-artwork', 'image_path' => 'artworks/originals/existing.jpg']));
        Track::withoutEvents(fn (): Track => Track::query()->create(['title' => 'Existing track', 'slug' => 'existing-track', 'audio_path' => 'tracks/audio/existing.mp3']));

        $this->artisan('creative-ai:media:privatize')->assertSuccessful();
        Storage::disk('local')->assertExists(['artworks/originals/existing.jpg', 'tracks/audio/existing.mp3']);
        Storage::disk('public')->assertMissing(['artworks/originals/existing.jpg', 'tracks/audio/existing.mp3']);

        $this->artisan('creative-ai:media:privatize')->assertSuccessful();
    }

    public function test_livewire_and_php_allow_the_advertised_track_upload_size(): void
    {
        $maxTrackSize = config('creative_ai.uploads.max_track_size_kb');

        $this->assertSame(102_400, $maxTrackSize);
        $this->assertContains('max:'.$maxTrackSize, config('livewire.temporary_file_upload.rules'));

        $phpUploadConfig = parse_ini_file(base_path('docker/uploads.ini'));

        $this->assertSame('100M', $phpUploadConfig['upload_max_filesize']);
        $this->assertSame('105M', $phpUploadConfig['post_max_size']);
    }

    public function test_real_wav_detection_is_allowed_for_audio_uploads(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'creative-ai-wav-');
        $samples = str_repeat("\0", 1764);
        $wave = 'RIFF'.pack('V', 36 + strlen($samples)).'WAVE';
        $wave .= 'fmt '.pack('VvvVVvv', 16, 1, 2, 44100, 176400, 4, 16);
        $wave .= 'data'.pack('V', strlen($samples)).$samples;
        file_put_contents($path, $wave);

        try {
            $detected = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
            $accepted = config('creative_ai.uploads.track_mime_types');

            $this->assertSame('audio/x-wav', $detected);
            $this->assertContains($detected, $accepted);
            $this->assertContains('audio/wav', $accepted);
            $this->assertContains('audio/wave', $accepted);
            $this->assertContains('audio/vnd.wave', $accepted);
            $upload = new UploadedFile($path, 'sample.wav', null, null, true);
            $validator = Validator::make(['audio' => $upload], ['audio' => 'mimetypes:'.implode(',', $accepted)]);
            $this->assertTrue($validator->passes(), $validator->errors()->first('audio'));
        } finally {
            @unlink($path);
        }
    }
}
