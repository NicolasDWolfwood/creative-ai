<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('artworks', function (Blueprint $table) {
            $table->string('ai_queue_token')->nullable()->index()->after('ai_status');
            $table->timestamp('ai_queued_at')->nullable()->after('ai_error');
            $table->timestamp('ai_started_at')->nullable()->after('ai_queued_at');
        });
    }

    public function down(): void
    {
        Schema::table('artworks', function (Blueprint $table) {
            $table->dropColumn([
                'ai_queue_token',
                'ai_queued_at',
                'ai_started_at',
            ]);
        });
    }
};
