<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class ArtworkSignatureMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_signed_artwork_is_backfilled_without_changing_paths_bytes_or_variant_state(): void
    {
        Storage::fake('local');
        $migration = require database_path('migrations/2026_07_25_000000_add_signature_renditions_to_artworks.php');
        $migration->down();

        try {
            $now = now()->startOfSecond();
            $paths = [
                'image_path' => 'artworks/originals/existing.jpg',
                'display_path' => 'artworks/display/existing.jpg',
                'thumb_path' => 'artworks/thumbs/existing.jpg',
            ];

            foreach ($paths as $path) {
                Storage::disk('local')->put($path, 'unchanged-'.$path);
            }

            $artworkId = DB::table('artworks')->insertGetId([
                'title' => 'Existing signed artwork',
                'slug' => 'existing-signed-artwork',
                ...$paths,
                'variant_status' => 'ready',
                'variants_generated_at' => $now,
                'published' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $migration->up();

            $artwork = DB::table('artworks')->where('id', $artworkId)->sole();

            $this->assertSame($paths['image_path'], $artwork->master_path);
            $this->assertSame($paths['image_path'], $artwork->image_path);
            $this->assertSame($paths['display_path'], $artwork->display_path);
            $this->assertSame($paths['thumb_path'], $artwork->thumb_path);
            $this->assertSame('embedded', $artwork->signature_mode);
            $this->assertSame('embedded', $artwork->signature_active_treatment);
            $this->assertNull($artwork->signature_recipe_fingerprint);
            $this->assertNull($artwork->signature_settings_revision);
            $this->assertSame('ready', $artwork->variant_status);
            $this->assertSame($now->toDateTimeString(), $artwork->variants_generated_at);

            foreach ($paths as $path) {
                $this->assertSame('unchanged-'.$path, Storage::disk('local')->get($path));
            }
        } finally {
            if (! Schema::hasColumn('artworks', 'master_path')) {
                $migration->up();
            }
        }
    }

    public function test_rollback_refuses_while_a_first_public_rendition_is_incomplete(): void
    {
        DB::table('artworks')->insert([
            'title' => 'Pending clean master',
            'slug' => 'pending-clean-master',
            'image_path' => null,
            'master_path' => 'artworks/masters/pending.png',
            'signature_mode' => 'automatic',
            'signature_active_treatment' => null,
            'variant_status' => 'pending',
            'published' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_07_25_000000_add_signature_renditions_to_artworks.php');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no backward-compatible public rendition');

        $migration->down();
    }

    public function test_rollback_refuses_to_discard_a_clean_master_reference_after_rendering(): void
    {
        DB::table('artworks')->insert([
            'title' => 'Rendered clean master',
            'slug' => 'rendered-clean-master',
            'image_path' => 'artworks/large/rendered.jpg',
            'master_path' => 'artworks/masters/clean.png',
            'signature_mode' => 'automatic',
            'signature_active_treatment' => 'signed',
            'variant_status' => 'ready',
            'published' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_07_25_000000_add_signature_renditions_to_artworks.php');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('private master rendering has been used');

        $migration->down();
    }
}
