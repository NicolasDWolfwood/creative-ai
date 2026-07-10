<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collections', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('hero_image_path')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('featured')->default(false);
            $table->boolean('published')->default(true);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('artworks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->text('prompt')->nullable();
            $table->string('image_path');
            $table->string('display_path')->nullable();
            $table->string('thumb_path')->nullable();
            $table->string('original_filename')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('featured')->default(false);
            $table->boolean('published')->default(true);
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('tracks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cover_artwork_id')->nullable()->constrained('artworks')->nullOnDelete();
            $table->string('title');
            $table->string('artist')->nullable();
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('audio_path');
            $table->string('original_filename')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('featured')->default(false);
            $table->boolean('published')->default(true);
            $table->timestamp('published_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('playlists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cover_artwork_id')->nullable()->constrained('artworks')->nullOnDelete();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('featured')->default(false);
            $table->boolean('published')->default(true);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('playlist_tracks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('playlist_id')->constrained()->cascadeOnDelete();
            $table->foreignId('track_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['playlist_id', 'track_id']);
        });

        Schema::create('site_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_settings');
        Schema::dropIfExists('playlist_tracks');
        Schema::dropIfExists('playlists');
        Schema::dropIfExists('tracks');
        Schema::dropIfExists('artworks');
        Schema::dropIfExists('collections');
    }
};
