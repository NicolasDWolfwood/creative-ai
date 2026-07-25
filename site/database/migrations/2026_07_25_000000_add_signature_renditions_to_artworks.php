<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('artworks', function (Blueprint $table) {
            $table->string('master_path')->nullable()->after('image_path');
            $table->string('signature_mode', 20)->default('embedded')->index()->after('master_path');
            $table->string('signature_position', 20)->nullable()->after('signature_mode');
            $table->string('signature_active_treatment', 20)->nullable()->default('embedded')->after('signature_position');
            $table->string('signature_resolved_tone', 10)->nullable()->after('signature_active_treatment');
            $table->string('signature_resolved_position', 20)->nullable()->after('signature_resolved_tone');
            $table->boolean('signature_review_recommended')->default(false)->after('signature_resolved_position');
            $table->string('signature_recipe_fingerprint', 64)->nullable()->after('signature_review_recommended');
            $table->string('signature_settings_revision', 64)->nullable()->after('signature_recipe_fingerprint');
            $table->unsignedInteger('public_width')->nullable()->after('height');
            $table->unsignedInteger('public_height')->nullable()->after('public_width');
        });

        // Every pre-migration image already has the artist's signature baked
        // into it. Keep its public and derived paths untouched and explicitly
        // exempt it from another overlay.
        DB::table('artworks')->update([
            'master_path' => DB::raw('image_path'),
            'signature_mode' => 'embedded',
            'signature_active_treatment' => 'embedded',
        ]);

        Schema::table('artworks', function (Blueprint $table) {
            // A new clean master must never occupy image_path. That column now
            // points only at a public-safe large rendition and stays null until
            // the first complete rendition set is activated.
            $table->string('image_path')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('artworks')->whereNull('image_path')->exists()) {
            throw new RuntimeException(
                'Cannot remove artwork signature fields while an artwork has no backward-compatible public rendition.',
            );
        }

        if (DB::table('artworks')
            ->where(function ($query): void {
                $query
                    ->whereNull('master_path')
                    ->orWhereColumn('master_path', '!=', 'image_path')
                    ->orWhere('signature_mode', '!=', 'embedded');
            })
            ->exists()) {
            throw new RuntimeException(
                'Cannot remove artwork signature fields after private master rendering has been used.',
            );
        }

        Schema::table('artworks', function (Blueprint $table) {
            $table->dropIndex(['signature_mode']);
            $table->dropColumn([
                'master_path',
                'signature_mode',
                'signature_position',
                'signature_active_treatment',
                'signature_resolved_tone',
                'signature_resolved_position',
                'signature_review_recommended',
                'signature_recipe_fingerprint',
                'signature_settings_revision',
                'public_width',
                'public_height',
            ]);
            $table->string('image_path')->nullable(false)->change();
        });
    }
};
