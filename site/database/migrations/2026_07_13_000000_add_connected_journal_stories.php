<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['post_id', 'tag_id']);
        });

        Schema::create('post_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->foreignId('artwork_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('collection_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('album_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('playlist_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('track_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['post_id', 'position']);
            $table->unique(['post_id', 'artwork_id']);
            $table->unique(['post_id', 'collection_id']);
            $table->unique(['post_id', 'album_id']);
            $table->unique(['post_id', 'playlist_id']);
            $table->unique(['post_id', 'track_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_media');
        Schema::dropIfExists('post_tag');
    }
};
