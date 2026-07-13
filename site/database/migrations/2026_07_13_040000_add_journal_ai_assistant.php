<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->string('cover_alt_text', 500)->nullable()->change();
        });

        Schema::table('post_ai_runs', function (Blueprint $table) {
            $table->json('application_manifest')->nullable()->after('structured_result');
        });
    }

    public function down(): void
    {
        if (DB::table('post_ai_runs')->whereNotNull('application_manifest')->exists()) {
            throw new RuntimeException('Cannot remove the Journal AI assistant audit column after a result has been applied.');
        }

        if (DB::table('posts')->whereRaw('LENGTH(cover_alt_text) > 255')->exists()) {
            throw new RuntimeException('Cannot shrink Journal cover alternative text while values longer than 255 characters exist.');
        }

        Schema::table('post_ai_runs', function (Blueprint $table) {
            $table->dropColumn('application_manifest');
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->string('cover_alt_text')->nullable()->change();
        });
    }
};
