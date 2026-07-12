<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('playlists', function (Blueprint $table): void {
            $table->boolean('is_auto_generated')->default(false)->index()->after('is_smart');
            $table->string('auto_generation_key')->nullable()->unique()->after('is_auto_generated');
        });

        Schema::table('albums', function (Blueprint $table): void {
            $table->string('import_key', 64)->nullable()->unique()->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('albums', function (Blueprint $table): void {
            $table->dropUnique(['import_key']);
            $table->dropColumn('import_key');
        });

        Schema::table('playlists', function (Blueprint $table): void {
            $table->dropUnique(['auto_generation_key']);
            $table->dropColumn(['is_auto_generated', 'auto_generation_key']);
        });
    }
};
