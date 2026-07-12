<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $publishedAt = now();

        DB::table('albums')
            ->where('published', true)
            ->whereNull('published_at')
            ->update([
                'published_at' => $publishedAt,
                'updated_at' => $publishedAt,
            ]);

        DB::table('tracks')
            ->where('published', false)
            ->whereIn('album_id', function ($query): void {
                $query->select('id')
                    ->from('albums')
                    ->where('published', true);
            })
            ->update([
                'published' => true,
                'published_at' => $publishedAt,
                'updated_at' => $publishedAt,
            ]);
    }

    public function down(): void
    {
        // Publication is deliberately not reversed because tracks may have been
        // independently published after this reconciliation ran.
    }
};
