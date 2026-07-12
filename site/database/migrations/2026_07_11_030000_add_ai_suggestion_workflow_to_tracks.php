<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracks', function (Blueprint $table) {
            $table->string('ai_status')->default('idle')->index();
            $table->json('ai_suggestion')->nullable();
            $table->text('ai_error')->nullable();
        });

        DB::table('tracks')->whereNotNull('ai_analyzed_at')->update(['ai_status' => 'applied']);
    }

    public function down(): void
    {
        Schema::table('tracks', function (Blueprint $table) {
            $table->dropColumn(['ai_status', 'ai_suggestion', 'ai_error']);
        });
    }
};
