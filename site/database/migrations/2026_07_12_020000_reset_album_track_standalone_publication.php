<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracks', function (Blueprint $table): void {
            $table->boolean('published')->default(false)->change();
            $table->boolean('standalone_published')->default(false)->after('published')->index();
            $table->timestamp('standalone_published_at')->nullable()->after('standalone_published')->index();
        });

        // Album publication used to copy its state onto every member track, so
        // the legacy flag no longer records whether an album member was intended
        // to be a single. Preserve it for rollback compatibility while adopting
        // the explicit standalone flag for all new application behavior.
        DB::table('tracks')
            ->whereNull('album_id')
            ->where('published', true)
            ->update([
                'standalone_published' => true,
                'standalone_published_at' => DB::raw('published_at'),
                'updated_at' => now(),
            ]);

        DB::table('albums')
            ->select(['id', 'published', 'published_at'])
            ->orderBy('id')
            ->get()
            ->each(function (object $album): void {
                DB::table('tracks')
                    ->where('album_id', $album->id)
                    ->update([
                        'published' => (bool) $album->published,
                        'published_at' => $album->published ? $album->published_at : null,
                        'updated_at' => now(),
                    ]);
            });
    }

    public function down(): void
    {
        Schema::table('tracks', function (Blueprint $table): void {
            $table->dropIndex(['standalone_published']);
            $table->dropIndex(['standalone_published_at']);
        });

        Schema::table('tracks', function (Blueprint $table): void {
            $table->boolean('published')->default(true)->change();
            $table->dropColumn(['standalone_published', 'standalone_published_at']);
        });
    }
};
