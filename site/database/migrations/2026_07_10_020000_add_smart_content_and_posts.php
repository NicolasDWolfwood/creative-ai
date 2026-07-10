<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collections', function (Blueprint $table) {
            $table->boolean('is_smart')->default(false)->index();
            $table->json('smart_rules')->nullable();
            $table->boolean('auto_sync')->default(true);
            $table->timestamp('last_synced_at')->nullable();
        });

        Schema::create('artwork_collection', function (Blueprint $table) {
            $table->id();
            $table->foreignId('artwork_id')->constrained()->cascadeOnDelete();
            $table->foreignId('collection_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['artwork_id', 'collection_id']);
        });

        DB::table('artworks')
            ->whereNotNull('collection_id')
            ->orderBy('id')
            ->each(function (object $artwork): void {
                DB::table('artwork_collection')->insertOrIgnore([
                    'artwork_id' => $artwork->id,
                    'collection_id' => $artwork->collection_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

        Schema::create('track_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('track_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->string('category')->default('other')->index();
            $table->timestamps();

            $table->unique(['track_id', 'tag_id', 'category']);
        });

        Schema::table('tracks', function (Blueprint $table) {
            $table->string('ai_model')->nullable();
            $table->timestamp('ai_analyzed_at')->nullable();
        });

        Schema::table('playlists', function (Blueprint $table) {
            $table->boolean('is_smart')->default(false)->index();
            $table->json('smart_rules')->nullable();
            $table->boolean('auto_sync')->default(true);
            $table->timestamp('last_synced_at')->nullable();
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('excerpt')->nullable();
            $table->longText('body');
            $table->string('cover_image_path')->nullable();
            $table->string('seo_title')->nullable();
            $table->string('seo_description', 320)->nullable();
            $table->boolean('featured')->default(false)->index();
            $table->boolean('published')->default(false)->index();
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posts');

        Schema::table('playlists', function (Blueprint $table) {
            $table->dropColumn(['is_smart', 'smart_rules', 'auto_sync', 'last_synced_at']);
        });

        Schema::table('tracks', function (Blueprint $table) {
            $table->dropColumn(['ai_model', 'ai_analyzed_at']);
        });

        Schema::dropIfExists('track_tag');
        Schema::dropIfExists('artwork_collection');

        Schema::table('collections', function (Blueprint $table) {
            $table->dropColumn(['is_smart', 'smart_rules', 'auto_sync', 'last_synced_at']);
        });
    }
};
