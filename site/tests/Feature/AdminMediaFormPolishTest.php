<?php

namespace Tests\Feature;

use App\Filament\Resources\Artworks\Pages\ManageArtworks;
use App\Filament\Resources\Tracks\Pages\ManageTracks;
use App\Jobs\AnalyzeTrackAudio;
use App\Models\Album;
use App\Models\Artwork;
use App\Models\Track;
use App\Models\User;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Js;
use Livewire\Livewire;
use Tests\TestCase;

class AdminMediaFormPolishTest extends TestCase
{
    use RefreshDatabase;

    public function test_track_publication_controls_explain_standalone_and_album_availability(): void
    {
        $albumSource = file_get_contents(app_path('Filament/Resources/Albums/AlbumResource.php'));
        $trackSource = file_get_contents(app_path('Filament/Resources/Tracks/TrackResource.php'));

        $this->assertStringContainsString('->label(\'Publish as standalone track\')', $trackSource);
        $this->assertStringContainsString('Toggle::make(\'standalone_published\')', $trackSource);
        $this->assertStringContainsString('DateTimePicker::make(\'standalone_published_at\')', $trackSource);
        $this->assertStringContainsString('Album tracks are already playable when their album is published.', $trackSource);
        $this->assertStringContainsString('list the track separately', $trackSource);
        $this->assertStringContainsString('->label(\'Standalone\')', $albumSource);
        $this->assertStringContainsString('Toggle::make(\'standalone_published\')', $albumSource);
        $this->assertStringContainsString('->inline(false)', $albumSource);
        $this->assertStringContainsString('Publishing the album makes its complete track listing publicly playable.', $albumSource);
        $this->assertStringContainsString('Tracks stay off the standalone list unless enabled individually.', $albumSource);
        $this->assertStringContainsString("->defaultGroup('album.title')", $trackSource);
        $this->assertStringContainsString("SelectFilter::make('album_id')", $trackSource);
        $this->assertStringContainsString("'Singles / no album'", $trackSource);
    }

    public function test_grouped_track_library_shows_every_album_regardless_of_the_saved_page_size(): void
    {
        Queue::fake();
        $firstAlbum = Album::query()->create(['title' => 'Large First Album']);
        $secondAlbum = Album::query()->create(['title' => 'Visible Second Album']);

        foreach (range(1, 11) as $trackNumber) {
            Track::query()->create([
                'album_id' => $firstAlbum->id,
                'title' => 'First album track '.$trackNumber,
                'audio_path' => 'tracks/audio/first-'.$trackNumber.'.mp3',
                'track_number' => $trackNumber,
            ]);
        }

        Track::query()->create([
            'album_id' => $secondAlbum->id,
            'title' => 'Second album track',
            'audio_path' => 'tracks/audio/second.mp3',
            'track_number' => 1,
        ]);

        $component = Livewire::actingAs(User::factory()->admin()->create())
            ->test(ManageTracks::class)
            ->set('tableRecordsPerPage', 10)
            ->assertSee('Large First Album')
            ->assertSee('Visible Second Album');

        $this->assertFalse($component->instance()->getTable()->isPaginated());
        $this->assertSame('5s', $component->instance()->getTable()->getPollingInterval());
        $this->assertCount(12, $component->instance()->getTableRecords());

        $component->set('tableGrouping', null);

        $this->assertTrue($component->instance()->getTable()->isPaginated());
        $this->assertCount(10, $component->instance()->getTableRecords());
    }

    public function test_audio_health_retry_resets_stale_state_and_the_polled_table_reads_worker_updates(): void
    {
        Queue::fake();
        $track = Track::query()->create([
            'title' => 'Retry health analysis',
            'audio_path' => 'tracks/audio/retry-health.mp3',
        ]);
        $track->forceFill([
            'analysis_status' => 'failed',
            'analysis_error' => 'Previous failure',
            'health_status' => 'attention',
            'health_issues' => ['Technical analysis failed'],
        ])->saveQuietly();

        $component = Livewire::actingAs(User::factory()->admin()->create())
            ->test(ManageTracks::class)
            ->callTableAction('analyzeAudio', $track);

        $track->refresh();
        $this->assertSame('pending', $track->analysis_status);
        $this->assertNull($track->analysis_error);
        $this->assertSame('unknown', $track->health_status);
        $this->assertNull($track->health_issues);
        Queue::assertPushed(AnalyzeTrackAudio::class, fn (AnalyzeTrackAudio $job): bool => $job->trackId === $track->id);

        $track->forceFill([
            'analysis_status' => 'ready',
            'health_status' => 'healthy',
            'health_issues' => [],
        ])->saveQuietly();

        $component
            ->call('$refresh')
            ->assertTableColumnStateSet('health_status', 'healthy', $track);
    }

    public function test_artwork_slug_has_a_copyable_public_image_address(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('artworks/originals/draft.jpg', 'draft-image');
        $artwork = Artwork::withoutEvents(fn (): Artwork => Artwork::query()->create([
            'title' => 'Draft artwork',
            'slug' => 'draft-artwork',
            'image_path' => 'artworks/originals/draft.jpg',
            'published' => false,
        ]));

        $livewire = Livewire::actingAs(User::factory()->admin()->create())
            ->test(ManageArtworks::class)
            ->mountTableAction('edit', $artwork);
        $instance = $livewire->instance();
        $schema = $instance->getSchema($instance->getMountedActionSchemaName());
        $slug = collect($schema->getFlatComponents(withHidden: true))
            ->first(fn ($component): bool => $component instanceof TextInput && $component->getName() === 'slug');
        $copy = $slug->getSuffixActions()['copyPublicImageUrl'];

        $this->assertTrue($copy->isVisible());
        $this->assertSame('Copy public image URL', $copy->getLabel());
        $this->assertSame('Copy public image URL', $copy->getTooltip());
        $this->assertStringContainsString(
            (string) Js::from($artwork->public_image_url),
            $copy->getCustomAlpineClickHandler(),
        );
    }

    public function test_track_editor_keeps_the_audio_preview_but_presents_the_original_filename(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('tracks/audio/01GENERATED.wav', 'audio');
        $track = Track::withoutEvents(fn (): Track => Track::query()->create([
            'title' => 'Track',
            'slug' => 'track',
            'audio_path' => 'tracks/audio/01GENERATED.wav',
            'original_filename' => 'Original upload.wav',
        ]));

        $livewire = Livewire::actingAs(User::factory()->admin()->create())
            ->test(ManageTracks::class)
            ->mountTableAction('edit', $track);
        $instance = $livewire->instance();
        $schema = $instance->getSchema($instance->getMountedActionSchemaName());
        $component = collect($schema->getFlatComponents(withHidden: true))
            ->first(fn ($component): bool => $component instanceof FileUpload && $component->getName() === 'audio_path');

        $this->assertSame('ca-track-audio-upload', $component->getExtraAttributes()['class']);
        $this->assertTrue($component->isPreviewable());
        $this->assertSame('Original upload.wav', collect($component->getUploadedFiles())->first()['name']);
        $this->assertStringContainsString(
            'Original upload: Original upload.wav',
            $component->getChildSchema($component::BELOW_CONTENT_SCHEMA_KEY)->toHtmlString(),
        );

        $css = file_get_contents(resource_path('css/filament/admin.css'));

        $this->assertStringContainsString('.ca-track-audio-upload .filepond--file-info-main', $css);
        $this->assertStringContainsString('.ca-track-audio-upload .filepond--file-info-sub', $css);
    }
}
