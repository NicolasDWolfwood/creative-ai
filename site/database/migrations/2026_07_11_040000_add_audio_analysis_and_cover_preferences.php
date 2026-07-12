<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracks', function (Blueprint $table): void {
            $table->string('analysis_status')->default('pending')->index();
            $table->text('analysis_error')->nullable();
            $table->timestamp('analyzed_at')->nullable();
            $table->string('audio_hash', 64)->nullable()->index();
            $table->string('audio_codec')->nullable();
            $table->unsignedInteger('bitrate')->nullable();
            $table->unsignedInteger('sample_rate')->nullable();
            $table->unsignedTinyInteger('channels')->nullable();
            $table->json('waveform')->nullable();
            $table->string('health_status')->default('unknown')->index();
            $table->json('health_issues')->nullable();
        });
        Schema::table('albums', fn (Blueprint $table) => $table->string('cover_preference')->default('auto'));
    }

    public function down(): void
    {
        Schema::table('tracks', function (Blueprint $table): void {
            $table->dropIndex(['analysis_status']);
            $table->dropIndex(['audio_hash']);
            $table->dropIndex(['health_status']);
            $table->dropColumn(['analysis_status', 'analysis_error', 'analyzed_at', 'audio_hash', 'audio_codec', 'bitrate', 'sample_rate', 'channels', 'waveform', 'health_status', 'health_issues']);
        });
        Schema::table('albums', fn (Blueprint $table) => $table->dropColumn('cover_preference'));
    }
};
