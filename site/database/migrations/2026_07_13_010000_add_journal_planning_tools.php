<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_media', function (Blueprint $table) {
            $table->index('artwork_id', 'post_media_artwork_opportunity_idx');
            $table->index('collection_id', 'post_media_collection_opportunity_idx');
            $table->index('album_id', 'post_media_album_opportunity_idx');
            $table->index('playlist_id', 'post_media_playlist_opportunity_idx');
            $table->index('track_id', 'post_media_track_opportunity_idx');
        });

        Schema::create('post_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('title')->nullable();
            $table->text('excerpt')->nullable();
            $table->longText('body')->nullable();
            $table->longText('editorial_brief')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('post_template_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['post_template_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_template_tag');
        Schema::dropIfExists('post_templates');

        Schema::table('post_media', function (Blueprint $table) {
            $table->dropIndex('post_media_artwork_opportunity_idx');
            $table->dropIndex('post_media_collection_opportunity_idx');
            $table->dropIndex('post_media_album_opportunity_idx');
            $table->dropIndex('post_media_playlist_opportunity_idx');
            $table->dropIndex('post_media_track_opportunity_idx');
        });
    }
};
