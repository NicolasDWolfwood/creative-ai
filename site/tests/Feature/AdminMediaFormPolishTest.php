<?php

namespace Tests\Feature;

use App\Filament\Resources\Artworks\Pages\ManageArtworks;
use App\Filament\Resources\Tracks\Pages\ManageTracks;
use App\Jobs\AnalyzeTrackAudio;
use App\Models\Album;
use App\Models\Artwork;
use App\Models\Collection as ArtworkCollection;
use App\Models\Tag;
use App\Models\Track;
use App\Models\User;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
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

    public function test_artwork_availability_distinguishes_due_and_scheduled_homepage_features(): void
    {
        $due = Artwork::withoutEvents(fn (): Artwork => Artwork::query()->create([
            'title' => 'Due homepage feature',
            'slug' => 'due-homepage-feature',
            'image_path' => 'artworks/originals/due-homepage-feature.jpg',
            'featured' => true,
            'published' => false,
        ]));
        $scheduled = Artwork::withoutEvents(fn (): Artwork => Artwork::query()->create([
            'title' => 'Scheduled homepage feature',
            'slug' => 'scheduled-homepage-feature',
            'image_path' => 'artworks/originals/scheduled-homepage-feature.jpg',
            'featured' => true,
            'published' => false,
            'published_at' => now()->addDay(),
        ]));

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(ManageArtworks::class)
            ->assertTableColumnFormattedStateSet('published', 'Homepage only', $due)
            ->assertTableColumnFormattedStateSet('published', 'Homepage scheduled', $scheduled);
    }

    public function test_artwork_editor_can_curate_applied_tags_and_only_edits_manual_collections(): void
    {
        Storage::fake('local');
        Queue::fake();
        Storage::disk('local')->put('artworks/originals/tagged.jpg', 'tagged-image');
        $artwork = Artwork::withoutEvents(fn (): Artwork => Artwork::query()->create([
            'title' => 'Tagged artwork',
            'slug' => 'tagged-artwork',
            'image_path' => 'artworks/originals/tagged.jpg',
            'published' => false,
        ]));
        $subject = Tag::query()->create(['name' => 'antlered deer']);
        $glowing = Tag::query()->create(['name' => 'glowing']);
        $misty = Tag::query()->create(['name' => 'misty']);
        $wildlife = Tag::query()->create(['name' => 'wildlife']);
        $artwork->tags()->attach($subject, ['category' => 'subject']);
        $artwork->tags()->attach($glowing, ['category' => 'mood']);
        $artwork->tags()->attach($misty, ['category' => 'mood']);

        $manual = ArtworkCollection::query()->create(['title' => 'Curated by hand']);
        $smart = ArtworkCollection::query()->create([
            'title' => 'Rule-managed smart collection',
            'is_smart' => true,
            'auto_sync' => false,
        ]);
        $liveSmart = ArtworkCollection::query()->create([
            'title' => 'Live broad-tag collection',
            'is_smart' => true,
            'auto_sync' => true,
            'smart_rules' => [
                'tag_ids' => [$wildlife->id],
                'match' => 'any',
                'only_published' => false,
            ],
        ]);
        $automatic = ArtworkCollection::query()->create([
            'title' => 'Generated automatic collection',
            'is_smart' => true,
            'is_auto_generated' => true,
            'auto_generation_key' => 'generated-automatic-test',
            'publishes_members' => true,
            'auto_sync' => false,
            'smart_rules' => ['only_published' => false],
        ]);
        $artwork->collections()->attach([$manual->id, $smart->id, $automatic->id]);

        $livewire = Livewire::actingAs(User::factory()->admin()->create())
            ->test(ManageArtworks::class)
            ->mountTableAction('edit', $artwork)
            ->assertMountedActionModalSee([
                'Applied tags',
                'Subject: antlered deer',
                'Mood: glowing, misty',
                'These persisted tags drive smart and automatic collection membership.',
                'Add or remove tags',
                'Prefer broad, reusable subjects and themes.',
                'Manual collections',
            ]);
        $instance = $livewire->instance();
        $schema = $instance->getSchema($instance->getMountedActionSchemaName());
        $collections = collect($schema->getFlatComponents(withHidden: true))
            ->first(fn ($component): bool => $component instanceof Select && $component->getName() === 'collections');
        $options = $collections->getOptions();
        $tags = collect($schema->getFlatComponents(withHidden: true))
            ->first(fn ($component): bool => $component instanceof Select && $component->getName() === 'tags');

        $this->assertSame('Curated by hand', $options[$manual->id]);
        $this->assertArrayNotHasKey($smart->id, $options);
        $this->assertArrayNotHasKey($automatic->id, $options);
        $this->assertSame([$manual->id], $collections->getState());
        $this->assertEqualsCanonicalizing(
            [$subject->id, $glowing->id, $misty->id],
            array_map('intval', $tags->getState()),
        );

        $livewire
            ->setTableActionData([
                'collections' => [],
            ])
            // Filament's testing helper merges numeric array keys when a
            // multi-select shrinks. Replace this field's state directly so
            // the test matches the browser's complete array update.
            ->set($schema->getStatePath().'.tags', [
                (string) $subject->id,
                (string) $wildlife->id,
            ])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseMissing('artwork_collection', [
            'artwork_id' => $artwork->id,
            'collection_id' => $manual->id,
        ]);
        $this->assertDatabaseHas('artwork_collection', [
            'artwork_id' => $artwork->id,
            'collection_id' => $smart->id,
        ]);
        $this->assertDatabaseHas('artwork_collection', [
            'artwork_id' => $artwork->id,
            'collection_id' => $automatic->id,
        ]);
        $this->assertDatabaseHas('artwork_collection', [
            'artwork_id' => $artwork->id,
            'collection_id' => $liveSmart->id,
        ]);
        $this->assertEqualsCanonicalizing(
            [$subject->id, $wildlife->id],
            $artwork->fresh()->tags()->pluck('tags.id')->all(),
        );
        $this->assertSame('subject', $artwork->fresh()->tags()->whereKey($subject)->firstOrFail()->pivot->category);
        $this->assertSame('other', $artwork->fresh()->tags()->whereKey($wildlife)->firstOrFail()->pivot->category);
        $this->assertSame(2, Tag::query()->whereKey([$glowing->id, $misty->id])->count());
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
