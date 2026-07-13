<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_ai_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requester_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('retry_of_id')->nullable()->constrained('post_ai_runs')->nullOnDelete();
            $table->foreignId('source_revision_id')->nullable()->constrained('post_revisions')->nullOnDelete();
            $table->foreignId('acknowledged_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('applied_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('applied_revision_id')->nullable()->constrained('post_revisions')->nullOnDelete();

            $table->string('operation', 32);
            $table->string('status', 24)->default('queued');
            $table->uuid('queue_token')->unique();
            $table->string('queue_name', 64)->default('ai');
            $table->smallInteger('queue_priority')->default(0);

            $table->char('source_hash', 64);
            $table->char('context_hash', 64);
            $table->char('request_hash', 64);
            $table->json('context_manifest');
            $table->boolean('external_processing')->default(false);
            $table->timestamp('acknowledged_at')->nullable();

            $table->string('provider', 64);
            $table->string('model', 255);
            $table->string('normalized_endpoint', 2048);
            $table->char('provider_profile_hash', 64);
            $table->char('credential_hmac', 64)->nullable();
            $table->json('generation_options');
            $table->string('prompt_version', 64);
            $table->char('prompt_hash', 64);
            $table->string('schema_version', 64);
            $table->char('schema_hash', 64);

            $table->json('structured_result')->nullable();
            $table->string('error_category', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->text('stale_reason')->nullable();

            $table->timestamp('queued_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->string('provider_request_id', 191)->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->timestamps();

            $table->index(['post_id', 'id']);
            $table->index(['post_id', 'status']);
            $table->index(['status', 'queue_name', 'queue_priority', 'queued_at'], 'post_ai_runs_queue_idx');
            $table->index(['operation', 'status']);
            $table->index('retry_of_id');
            $table->index('source_revision_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_ai_runs');
    }
};
