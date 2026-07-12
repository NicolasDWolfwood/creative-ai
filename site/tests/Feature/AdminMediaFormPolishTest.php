<?php

namespace Tests\Feature;

use App\Filament\Resources\Artworks\Pages\ManageArtworks;
use App\Filament\Resources\Tracks\Pages\ManageTracks;
use App\Models\Artwork;
use App\Models\Track;
use App\Models\User;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Js;
use Livewire\Livewire;
use Tests\TestCase;

class AdminMediaFormPolishTest extends TestCase
{
    use RefreshDatabase;

    public function test_album_track_published_toggle_uses_the_stacked_layout(): void
    {
        $source = file_get_contents(app_path('Filament/Resources/Albums/AlbumResource.php'));

        $this->assertStringContainsString("Toggle::make('published')->inline(false)", $source);
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
