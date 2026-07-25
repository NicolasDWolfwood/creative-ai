<?php

namespace Tests\Feature;

use App\Filament\Pages\ArtworkSignatureConfiguration;
use App\Filament\Resources\SiteSettings\SiteSettingResource;
use App\Models\SiteSetting;
use App\Models\User;
use App\Rules\ValidArtworkSignatureMask;
use App\Services\ArtworkSignatureSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class ArtworkSignatureSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_defaults_are_safe_and_a_valid_private_mask_produces_a_stable_content_revision(): void
    {
        $settings = app(ArtworkSignatureSettings::class);
        $defaults = $settings->current();

        $this->assertFalse($settings->hasAsset());
        $this->assertSame('automatic', $defaults['default_mode']);
        $this->assertSame('bottom_right', $defaults['default_position']);
        $this->assertSame(1200, $defaults['scale_bp']);
        $this->assertSame(300, $defaults['inset_bp']);
        $this->assertSame(10000, $defaults['opacity_bp']);

        $firstPath = $this->storeMask('first.png');
        $saved = $settings->save([
            'asset_path' => $firstPath,
            'default_mode' => 'automatic',
            'default_position' => 'bottom_right',
            'scale_bp' => 1200,
            'inset_bp' => 300,
            'opacity_bp' => 10000,
        ]);
        $firstRevision = $saved['revision'];

        $this->assertTrue($settings->hasAsset());
        $this->assertSame(hash_file('sha256', Storage::disk('local')->path($firstPath)), $saved['asset_sha256']);
        $this->assertSame(80, $saved['asset_width']);
        $this->assertSame(100, $saved['asset_height']);

        $secondPath = 'artwork-signatures/assets/same-bytes.png';
        Storage::disk('local')->put($secondPath, Storage::disk('local')->get($firstPath));
        $sameBytes = $settings->save(['asset_path' => $secondPath]);
        $this->assertSame($firstRevision, $sameBytes['revision']);

        $changed = $settings->save(['scale_bp' => 1300]);
        $this->assertNotSame($firstRevision, $changed['revision']);
    }

    public function test_mask_validation_rejects_opaque_edge_touching_and_unmanaged_files(): void
    {
        $opaque = $this->storeMask('opaque.png', opaque: true);
        $edge = $this->storeMask('edge.png', touchesEdge: true);

        foreach ([
            $opaque => 'transparent background',
            $edge => 'transparent padding',
            'artworks/masters/not-a-signature.png' => 'managed private signature storage',
        ] as $path => $message) {
            try {
                ValidArtworkSignatureMask::inspect($path);
                $this->fail("{$path} should be rejected.");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString($message, $exception->getMessage());
            }
        }
    }

    public function test_settings_save_revalidates_the_stored_asset_path_and_bytes(): void
    {
        $this->expectException(ValidationException::class);

        app(ArtworkSignatureSettings::class)->save([
            'asset_path' => $this->storeMask('opaque-save.png', opaque: true),
            'default_mode' => 'automatic',
            'default_position' => 'bottom_right',
            'scale_bp' => 1200,
            'inset_bp' => 300,
            'opacity_bp' => 10000,
        ]);
    }

    public function test_configuration_is_admin_only_and_reserved_from_generic_site_settings(): void
    {
        SiteSetting::query()->create([
            'key' => ArtworkSignatureSettings::SETTING_KEY,
            'value' => ['default_mode' => 'automatic'],
        ]);
        SiteSetting::query()->create([
            'key' => 'home_intro',
            'value' => ['title' => 'Home'],
        ]);

        $this->actingAs(User::factory()->create());
        $this->assertFalse(ArtworkSignatureConfiguration::canAccess());

        $administrator = User::factory()->admin()->create();
        Livewire::actingAs($administrator)
            ->test(ArtworkSignatureConfiguration::class)
            ->assertOk()
            ->assertSee('Artwork signatures')
            ->assertSee('No validated signature asset is saved yet.');

        $this->assertSame(
            ['home_intro'],
            SiteSettingResource::getEloquentQuery()->pluck('key')->all(),
        );
    }

    protected function storeMask(
        string $filename,
        bool $opaque = false,
        bool $touchesEdge = false,
    ): string {
        $path = 'artwork-signatures/assets/'.$filename;
        $image = imagecreatetruecolor(80, 100);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill(
            $image,
            0,
            0,
            imagecolorallocatealpha($image, 255, 255, 255, $opaque ? 0 : 127),
        );
        $ink = imagecolorallocatealpha($image, 20, 100, 220, 0);
        imagefilledrectangle($image, $touchesEdge ? 0 : 20, 20, 45, 78, $ink);
        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);
        $this->assertIsString($png);
        Storage::disk('local')->put($path, $png);

        return $path;
    }
}
