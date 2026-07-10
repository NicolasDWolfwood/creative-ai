<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('artworks', function (Blueprint $table) {
            $table->text('alt_text')->nullable()->after('description');
            $table->string('ai_status')->default('idle')->index()->after('metadata');
            $table->json('ai_suggestion')->nullable()->after('ai_status');
            $table->string('ai_model')->nullable()->after('ai_suggestion');
            $table->text('ai_error')->nullable()->after('ai_model');
            $table->timestamp('ai_analyzed_at')->nullable()->after('ai_error');
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('artwork_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('artwork_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->string('category')->default('other')->index();
            $table->timestamps();

            $table->unique(['artwork_id', 'tag_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artwork_tag');
        Schema::dropIfExists('tags');

        Schema::table('artworks', function (Blueprint $table) {
            $table->dropColumn([
                'alt_text',
                'ai_status',
                'ai_suggestion',
                'ai_model',
                'ai_error',
                'ai_analyzed_at',
            ]);
        });
    }
};
