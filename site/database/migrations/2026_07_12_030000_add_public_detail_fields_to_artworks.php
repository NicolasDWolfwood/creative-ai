<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('artworks', function (Blueprint $table) {
            $table->text('process_notes')->nullable()->after('prompt');
            $table->index(
                ['published', 'sort_order', 'created_at', 'id'],
                'artworks_public_archive_order',
            );
        });
    }

    public function down(): void
    {
        Schema::table('artworks', function (Blueprint $table) {
            $table->dropIndex('artworks_public_archive_order');
            $table->dropColumn('process_notes');
        });
    }
};
