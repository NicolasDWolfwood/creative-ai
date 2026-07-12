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
            $table->string('variant_status', 20)->default('pending')->index();
            $table->uuid('variant_generation_token')->nullable()->index();
            $table->text('variant_error')->nullable();
            $table->timestamp('variant_queued_at')->nullable();
            $table->timestamp('variant_started_at')->nullable();
            $table->timestamp('variants_generated_at')->nullable();
        });

        DB::table('artworks')
            ->whereNotNull('display_path')
            ->whereNotNull('thumb_path')
            ->update(['variant_status' => 'ready']);
    }

    public function down(): void
    {
        Schema::table('artworks', function (Blueprint $table) {
            $table->dropIndex(['variant_status']);
            $table->dropIndex(['variant_generation_token']);
            $table->dropColumn([
                'variant_status',
                'variant_generation_token',
                'variant_error',
                'variant_queued_at',
                'variant_started_at',
                'variants_generated_at',
            ]);
        });
    }
};
