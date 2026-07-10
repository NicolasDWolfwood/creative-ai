<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collections', function (Blueprint $table) {
            $table->boolean('is_auto_generated')->default(false)->index()->after('is_smart');
            $table->string('auto_generation_key')->nullable()->unique()->after('is_auto_generated');
        });
    }

    public function down(): void
    {
        Schema::table('collections', function (Blueprint $table) {
            $table->dropUnique(['auto_generation_key']);
            $table->dropColumn(['is_auto_generated', 'auto_generation_key']);
        });
    }
};
