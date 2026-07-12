<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('albums', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cover_artwork_id')->nullable()->constrained('artworks')->nullOnDelete();
            $table->string('title');
            $table->string('artist')->nullable();
            $table->string('album_artist')->nullable();
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('embedded_cover_path')->nullable();
            $table->unsignedSmallInteger('release_year')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('featured')->default(false)->index();
            $table->boolean('published')->default(false)->index();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::table('tracks', function (Blueprint $table) {
            $table->foreignId('album_id')->nullable()->after('cover_artwork_id')->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('disc_number')->nullable()->after('duration_seconds');
            $table->unsignedSmallInteger('track_number')->nullable()->after('disc_number');
            $table->unsignedSmallInteger('release_year')->nullable()->after('track_number');
            $table->timestamp('metadata_reviewed_at')->nullable()->after('metadata');
        });
    }

    public function down(): void
    {
        Schema::table('tracks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('album_id');
            $table->dropColumn(['disc_number', 'track_number', 'release_year', 'metadata_reviewed_at']);
        });

        Schema::dropIfExists('albums');
    }
};
