<?php

namespace App\Models;

use App\Enums\PostAiOperation;
use App\Enums\PostAiRunStatus;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class PostAiRun extends Model
{
    /** @var list<string> */
    public const IMMUTABLE_PROVENANCE_FIELDS = [
        'post_id',
        'requester_id',
        'retry_of_id',
        'source_revision_id',
        'operation',
        'source_hash',
        'context_hash',
        'request_hash',
        'context_manifest',
        'external_processing',
        'acknowledged_by_user_id',
        'acknowledged_at',
        'provider',
        'model',
        'normalized_endpoint',
        'provider_profile_hash',
        'credential_hmac',
        'generation_options',
        'prompt_version',
        'prompt_hash',
        'schema_version',
        'schema_hash',
        'queued_at',
    ];

    protected $fillable = [
        'post_id',
        'requester_id',
        'retry_of_id',
        'source_revision_id',
        'acknowledged_by_user_id',
        'applied_by_user_id',
        'applied_revision_id',
        'operation',
        'status',
        'queue_token',
        'queue_name',
        'queue_priority',
        'source_hash',
        'context_hash',
        'request_hash',
        'context_manifest',
        'external_processing',
        'acknowledged_at',
        'provider',
        'model',
        'normalized_endpoint',
        'provider_profile_hash',
        'credential_hmac',
        'generation_options',
        'prompt_version',
        'prompt_hash',
        'schema_version',
        'schema_hash',
        'structured_result',
        'application_manifest',
        'error_category',
        'error_message',
        'stale_reason',
        'queued_at',
        'started_at',
        'lease_expires_at',
        'completed_at',
        'cancelled_at',
        'dismissed_at',
        'applied_at',
        'duration_ms',
        'provider_request_id',
        'input_tokens',
        'output_tokens',
    ];

    protected $hidden = [
        'queue_token',
        'source_hash',
        'context_hash',
        'request_hash',
        'context_manifest',
        'normalized_endpoint',
        'provider_profile_hash',
        'credential_hmac',
        'generation_options',
        'prompt_hash',
        'schema_hash',
        'structured_result',
        'application_manifest',
        'error_message',
        'stale_reason',
        'provider_request_id',
    ];

    protected function casts(): array
    {
        return [
            'operation' => PostAiOperation::class,
            'status' => PostAiRunStatus::class,
            'queue_priority' => 'integer',
            'context_manifest' => 'array',
            'external_processing' => 'boolean',
            'generation_options' => 'array',
            'structured_result' => 'array',
            'application_manifest' => 'array',
            'acknowledged_at' => 'datetime',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'lease_expires_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'dismissed_at' => 'datetime',
            'applied_at' => 'datetime',
            'duration_ms' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (PostAiRun $run): void {
            $post = Post::query()->withTrashed()->find($run->post_id);

            if ($post === null || $post->trashed()) {
                throw new DomainException('Journal AI cannot use a missing or trashed post.');
            }

            if ($run->retry_of_id !== null) {
                $parent = self::query()->find($run->retry_of_id);

                if (
                    $parent === null
                    || (int) $parent->post_id !== (int) $run->post_id
                    || $parent->operation !== $run->operation
                ) {
                    throw new DomainException('A Journal AI retry must reference the same post and operation.');
                }
            }

            if ($run->source_revision_id !== null) {
                $revision = PostRevision::query()->find($run->source_revision_id);

                if ($revision === null || (int) $revision->post_id !== (int) $run->post_id) {
                    throw new DomainException('The Journal AI source revision must belong to the same post.');
                }
            }

            if ($run->status === PostAiRunStatus::Applied
                || collect([
                    'application_manifest',
                    'applied_by_user_id',
                    'applied_revision_id',
                    'applied_at',
                ])->contains(fn (string $field): bool => $run->getAttribute($field) !== null)) {
                throw new LogicException('A Journal AI run must be created without application provenance.');
            }
        });

        static::updating(function (PostAiRun $run): void {
            if ($run->isDirty(self::IMMUTABLE_PROVENANCE_FIELDS)) {
                throw new LogicException('Journal AI request provenance is immutable.');
            }

            if ($run->isDirty('structured_result') && $run->getRawOriginal('structured_result') !== null) {
                throw new LogicException('A stored Journal AI result is immutable.');
            }

            $applicationFields = [
                'application_manifest',
                'applied_by_user_id',
                'applied_revision_id',
                'applied_at',
            ];
            $applicationIsDirty = collect($applicationFields)
                ->contains(fn (string $field): bool => $run->isDirty($field));
            $isEnteringApplied = $run->isDirty('status') && $run->status === PostAiRunStatus::Applied;

            foreach ($applicationFields as $field) {
                if ($run->isDirty($field) && $run->getRawOriginal($field) !== null) {
                    throw new LogicException('Journal AI application provenance is immutable.');
                }
            }

            if ($applicationIsDirty || $isEnteringApplied) {
                if (
                    $run->getRawOriginal('status') !== PostAiRunStatus::Ready->value
                    || $run->status !== PostAiRunStatus::Applied
                    || collect($applicationFields)->contains(fn (string $field): bool => ! $run->isDirty($field))
                    || ! is_array($run->application_manifest)
                    || $run->applied_by_user_id === null
                    || $run->applied_revision_id === null
                    || $run->applied_at === null
                ) {
                    throw new LogicException('Journal AI application provenance requires an atomic applied transition.');
                }
            }

            if ($run->getRawOriginal('status') === PostAiRunStatus::Applied->value && $run->isDirty('status')) {
                throw new LogicException('An applied Journal AI run is immutable.');
            }
        });

        static::deleting(fn (): never => throw new LogicException('Journal AI runs are retained until their post is permanently deleted.'));
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class)->withTrashed();
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function retryOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'retry_of_id');
    }

    public function retries(): HasMany
    {
        return $this->hasMany(self::class, 'retry_of_id');
    }

    public function sourceRevision(): BelongsTo
    {
        return $this->belongsTo(PostRevision::class, 'source_revision_id');
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by_user_id');
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by_user_id');
    }

    public function appliedRevision(): BelongsTo
    {
        return $this->belongsTo(PostRevision::class, 'applied_revision_id');
    }
}
