<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('artworks', function (Blueprint $table) {
            $table->boolean('ai_apply_after_analysis')->default(false)->after('ai_queue_token');
        });
    }

    public function down(): void
    {
        Schema::table('artworks', function (Blueprint $table) {
            $table->dropColumn('ai_apply_after_analysis');
        });
    }
};
