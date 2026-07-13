<?php

namespace App\Services;

use App\Data\JournalAiContext;
use App\Enums\PostAiOperation;
use App\Enums\PostAiRunStatus;
use App\Jobs\GenerateJournalAiRun;
use App\Models\Post;
use App\Models\PostAiRun;
use App\Models\User;
use App\Support\CanonicalJson;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class JournalAiRunService
{
    public const DEFAULT_QUEUE = 'ai';

    public const HIGH_PRIORITY_QUEUE = 'ai-high';

    private const QUEUED_LEASE_MINUTES = 15;

    public function __construct(
        private readonly JournalAiContextBuilder $contexts,
        private readonly JournalAiContractRegistry $contracts,
        private readonly AiProviderManager $providers,
    ) {}

    /**
     * Build the exact server-owned request that an administrator must acknowledge.
     *
     * @param  array<string, mixed>  $selection
     */
    public function preview(
        Post $post,
        PostAiOperation $operation,
        array $selection,
        User $actor,
    ): JournalAiRunPreview {
        $this->authorizeRequest($actor, $post);

        return DB::transaction(function () use ($post, $operation, $selection, $actor): JournalAiRunPreview {
            $locked = Post::query()->lockForUpdate()->findOrFail($post->getKey());
            $this->authorizeRequest($actor, $locked);
            $request = $this->buildRequest($locked, $operation, $selection);

            return new JournalAiRunPreview(
                operation: $operation,
                contextManifest: $request['context']->manifest,
                sourceHash: $request['context']->sourceHash,
                contextHash: $request['context']->contextHash,
                providerProfileHash: $request['profile_hash'],
                requestHash: $request['request_hash'],
                provider: $request['profile']->provider,
                model: $request['profile']->model,
                endpoint: $request['profile']->endpoint,
                externalProcessing: $request['profile']->externalProcessing,
            );
        }, 3);
    }

    /**
     * Queue only after the administrator acknowledges hashes from a current preview.
     *
     * @param  array<string, mixed>  $selection
     */
    public function request(
        Post $post,
        PostAiOperation $operation,
        array $selection,
        User $actor,
        string $expectedContextHash,
        string $expectedProviderProfileHash,
        string $expectedRequestHash,
    ): PostAiRun {
        $this->authorizeRequest($actor, $post);

        return DB::transaction(function () use (
            $post,
            $operation,
            $selection,
            $actor,
            $expectedContextHash,
            $expectedProviderProfileHash,
            $expectedRequestHash,
        ): PostAiRun {
            $locked = Post::query()->lockForUpdate()->findOrFail($post->getKey());
            $this->authorizeRequest($actor, $locked);

            return $this->createAcknowledgedRun(
                $locked,
                $operation,
                $selection,
                $actor,
                $expectedContextHash,
                $expectedProviderProfileHash,
                $expectedRequestHash,
            );
        }, 3);
    }

    public function cancel(PostAiRun $run, User $actor): PostAiRun
    {
        $this->authorizeRun($actor, 'cancel', $run);

        return DB::transaction(function () use ($run, $actor): PostAiRun {
            [, $locked] = $this->lockPostThenRun($run);
            $this->authorizeRun($actor, 'cancel', $locked);

            if (! in_array($locked->status, [PostAiRunStatus::Queued, PostAiRunStatus::Processing], true)) {
                throw new DomainException('Only queued or processing Journal AI runs can be cancelled.');
            }

            $now = now();
            $locked->forceFill([
                'status' => PostAiRunStatus::Cancelled,
                'queue_token' => (string) Str::uuid(),
                'lease_expires_at' => null,
                'completed_at' => $now,
                'cancelled_at' => $now,
                'error_category' => null,
                'error_message' => null,
                'stale_reason' => null,
            ])->saveOrFail();

            return $locked->refresh();
        }, 3);
    }

    public function prioritize(PostAiRun $run, User $actor): PostAiRun
    {
        $this->authorizeRun($actor, 'prioritize', $run);

        return DB::transaction(function () use ($run, $actor): PostAiRun {
            [, $locked] = $this->lockPostThenRun($run);
            $this->authorizeRun($actor, 'prioritize', $locked);

            if ($locked->status !== PostAiRunStatus::Queued) {
                throw new DomainException('Only queued Journal AI runs can be prioritized.');
            }

            $token = (string) Str::uuid();
            $locked->forceFill([
                'queue_token' => $token,
                'queue_name' => self::HIGH_PRIORITY_QUEUE,
                'queue_priority' => 100,
                'lease_expires_at' => now()->addMinutes(self::QUEUED_LEASE_MINUTES),
            ])->saveOrFail();

            $this->dispatch($locked, $token, self::HIGH_PRIORITY_QUEUE);

            return $locked->refresh();
        }, 3);
    }

    public function retry(
        PostAiRun $run,
        User $actor,
        string $expectedContextHash,
        string $expectedProviderProfileHash,
        string $expectedRequestHash,
    ): PostAiRun {
        $this->authorizeRun($actor, 'retry', $run);

        return DB::transaction(function () use (
            $run,
            $actor,
            $expectedContextHash,
            $expectedProviderProfileHash,
            $expectedRequestHash,
        ): PostAiRun {
            [$post, $lockedRun] = $this->lockPostThenRun($run);
            $this->authorizeRun($actor, 'retry', $lockedRun);

            if (! in_array($lockedRun->status, [
                PostAiRunStatus::Failed,
                PostAiRunStatus::Stale,
                PostAiRunStatus::Cancelled,
                PostAiRunStatus::Dismissed,
            ], true)) {
                throw new DomainException('Only failed, stale, cancelled, or dismissed Journal AI runs can be retried.');
            }

            $this->authorizeRequest($actor, $post);
            $selection = $lockedRun->context_manifest['selection'] ?? null;

            if (! is_array($selection)) {
                throw new DomainException('The Journal AI run no longer has a valid context selection.');
            }

            return $this->createAcknowledgedRun(
                $post,
                $lockedRun->operation,
                $selection,
                $actor,
                $expectedContextHash,
                $expectedProviderProfileHash,
                $expectedRequestHash,
                retryOf: $lockedRun,
            );
        }, 3);
    }

    public function regenerate(
        PostAiRun $run,
        User $actor,
        string $expectedContextHash,
        string $expectedProviderProfileHash,
        string $expectedRequestHash,
    ): PostAiRun {
        $this->authorizeRun($actor, 'retry', $run);

        return DB::transaction(function () use (
            $run,
            $actor,
            $expectedContextHash,
            $expectedProviderProfileHash,
            $expectedRequestHash,
        ): PostAiRun {
            [$post, $lockedRun] = $this->lockPostThenRun($run);
            $this->authorizeRun($actor, 'retry', $lockedRun);

            if (! in_array($lockedRun->status, [PostAiRunStatus::Ready, PostAiRunStatus::Applied], true)) {
                throw new DomainException('Only ready or applied Journal AI results can be regenerated.');
            }

            $this->authorizeRequest($actor, $post);
            $selection = $lockedRun->context_manifest['selection'] ?? null;

            if (! is_array($selection)) {
                throw new DomainException('The Journal AI run no longer has a valid context selection.');
            }

            $child = $this->createAcknowledgedRun(
                $post,
                $lockedRun->operation,
                $selection,
                $actor,
                $expectedContextHash,
                $expectedProviderProfileHash,
                $expectedRequestHash,
                retryOf: $lockedRun,
            );

            if ($lockedRun->status === PostAiRunStatus::Ready) {
                $lockedRun->forceFill([
                    'status' => PostAiRunStatus::Dismissed,
                    'dismissed_at' => now(),
                ])->saveOrFail();
            }

            return $child;
        }, 3);
    }

    public function dismiss(PostAiRun $run, User $actor): PostAiRun
    {
        $this->authorizeRun($actor, 'dismiss', $run);

        return DB::transaction(function () use ($run, $actor): PostAiRun {
            [, $locked] = $this->lockPostThenRun($run);
            $this->authorizeRun($actor, 'dismiss', $locked);

            if ($locked->status !== PostAiRunStatus::Ready) {
                throw new DomainException('Only ready Journal AI runs can be dismissed.');
            }

            $locked->forceFill([
                'status' => PostAiRunStatus::Dismissed,
                'dismissed_at' => now(),
            ])->saveOrFail();

            return $locked->refresh();
        }, 3);
    }

    public function recoverStale(User $actor, int $limit = 50): int
    {
        Gate::forUser($actor)->authorize('recover', PostAiRun::class);
        $limit = max(1, min(100, $limit));

        return DB::transaction(function () use ($limit): int {
            $runs = PostAiRun::query()
                ->whereIn('status', [PostAiRunStatus::Queued->value, PostAiRunStatus::Processing->value])
                ->whereNotNull('lease_expires_at')
                ->where('lease_expires_at', '<=', now())
                ->orderBy('lease_expires_at')
                ->orderBy('id')
                ->limit($limit)
                ->lockForUpdate()
                ->get();

            foreach ($runs as $run) {
                $wasProcessing = $run->status === PostAiRunStatus::Processing;
                $run->forceFill([
                    'status' => PostAiRunStatus::Stale,
                    'queue_token' => (string) Str::uuid(),
                    'lease_expires_at' => null,
                    'completed_at' => now(),
                    'error_category' => 'internal',
                    'error_message' => $wasProcessing
                        ? 'The Journal AI worker did not finish within its processing lease.'
                        : 'The Journal AI request waited too long in the queue.',
                    'stale_reason' => $wasProcessing ? 'worker_lease_expired' : 'queue_expired',
                ])->saveOrFail();
            }

            return $runs->count();
        }, 3);
    }

    public function invalidateForPostTrash(Post $post): int
    {
        if (! $post->exists) {
            return 0;
        }

        return DB::transaction(function () use ($post): int {
            $lockedPost = Post::query()->withTrashed()->lockForUpdate()->find($post->getKey());

            if ($lockedPost === null) {
                return 0;
            }

            $runs = PostAiRun::query()
                ->whereBelongsTo($lockedPost)
                ->whereIn('status', [
                    PostAiRunStatus::Queued->value,
                    PostAiRunStatus::Processing->value,
                    PostAiRunStatus::Ready->value,
                ])
                ->lockForUpdate()
                ->get();

            foreach ($runs as $run) {
                $now = now();
                $ready = $run->status === PostAiRunStatus::Ready;
                $run->forceFill([
                    'status' => $ready ? PostAiRunStatus::Stale : PostAiRunStatus::Cancelled,
                    'queue_token' => (string) Str::uuid(),
                    'lease_expires_at' => null,
                    'completed_at' => $run->completed_at ?: $now,
                    'cancelled_at' => $ready ? null : $now,
                    'error_category' => $ready ? 'source_changed' : null,
                    'error_message' => $ready ? 'The Journal post was moved to Trash.' : null,
                    'stale_reason' => $ready ? 'post_trashed' : null,
                ])->saveOrFail();
            }

            return $runs->count();
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $selection
     */
    private function createAcknowledgedRun(
        Post $post,
        PostAiOperation $operation,
        array $selection,
        User $actor,
        string $expectedContextHash,
        string $expectedProviderProfileHash,
        string $expectedRequestHash,
        ?PostAiRun $retryOf = null,
    ): PostAiRun {
        $request = $this->buildRequest($post, $operation, $selection);

        if (
            ! $this->hashMatches($expectedContextHash, $request['context']->contextHash)
            || ! $this->hashMatches($expectedProviderProfileHash, $request['profile_hash'])
            || ! $this->hashMatches($expectedRequestHash, $request['request_hash'])
        ) {
            throw new DomainException('The Journal AI context or destination changed. Review and acknowledge the updated request before queueing it.');
        }

        $now = now();
        $token = (string) Str::uuid();
        $profile = $request['profile'];
        $contract = $request['contract'];
        $sourceRevisionId = $post->revisions()->latest('id')->value('id');
        $run = PostAiRun::query()->create([
            'post_id' => $post->getKey(),
            'requester_id' => $actor->getKey(),
            'retry_of_id' => $retryOf?->getKey(),
            'source_revision_id' => $sourceRevisionId,
            'acknowledged_by_user_id' => $actor->getKey(),
            'operation' => $operation,
            'status' => PostAiRunStatus::Queued,
            'queue_token' => $token,
            'queue_name' => self::DEFAULT_QUEUE,
            'queue_priority' => 0,
            'source_hash' => $request['context']->sourceHash,
            'context_hash' => $request['context']->contextHash,
            'request_hash' => $request['request_hash'],
            'context_manifest' => $request['context']->manifest,
            'external_processing' => $profile->externalProcessing,
            'acknowledged_at' => $now,
            'provider' => $profile->provider,
            'model' => $profile->model,
            'normalized_endpoint' => $profile->endpoint,
            'provider_profile_hash' => $request['profile_hash'],
            'credential_hmac' => $profile->credentialFingerprint,
            'generation_options' => [
                ...$profile->generationOptions(),
                'max_output_tokens' => $contract->maxOutputTokens,
            ],
            'prompt_version' => $contract->promptVersion,
            'prompt_hash' => $contract->promptHash(),
            'schema_version' => $contract->schemaVersion,
            'schema_hash' => $contract->schemaHash(),
            'queued_at' => $now,
            'lease_expires_at' => $now->copy()->addMinutes(self::QUEUED_LEASE_MINUTES),
        ]);

        $this->dispatch($run, $token, self::DEFAULT_QUEUE);

        return $run;
    }

    /**
     * @param  array<string, mixed>  $selection
     * @return array{context: JournalAiContext, profile: ProviderExecutionProfile, profile_hash: string, contract: JournalAiContract, request_hash: string}
     */
    private function buildRequest(Post $post, PostAiOperation $operation, array $selection): array
    {
        if ($post->trashed()) {
            throw new DomainException('Journal AI cannot use a trashed post.');
        }

        $context = $this->contexts->build($post, $operation, $selection);
        $profile = $this->providers->createJournalExecutionProfile();
        $profileHash = $profile->canonicalHash();
        $contract = $this->contracts->for($operation);
        $profile->assertRequestCapacity(
            $contract->prompt,
            $contract->renderInput($context->outbound()),
            $contract->schema,
            $contract->maxOutputTokens,
        );
        $requestHash = CanonicalJson::hash([
            'operation' => $operation->value,
            'source_hash' => $context->sourceHash,
            'context_hash' => $context->contextHash,
            'provider_profile_hash' => $profileHash,
            'prompt_version' => $contract->promptVersion,
            'prompt_hash' => $contract->promptHash(),
            'schema_version' => $contract->schemaVersion,
            'schema_hash' => $contract->schemaHash(),
            'max_output_tokens' => $contract->maxOutputTokens,
        ]);

        return [
            'context' => $context,
            'profile' => $profile,
            'profile_hash' => $profileHash,
            'contract' => $contract,
            'request_hash' => $requestHash,
        ];
    }

    private function dispatch(PostAiRun $run, string $token, string $queue): void
    {
        GenerateJournalAiRun::dispatch($run->getKey(), $token)
            ->onQueue($queue)
            ->afterCommit();
    }

    private function authorizeRequest(User $actor, Post $post): void
    {
        Gate::forUser($actor)->authorize('request', [PostAiRun::class, $post]);
    }

    private function authorizeRun(User $actor, string $ability, PostAiRun $run): void
    {
        Gate::forUser($actor)->authorize($ability, $run);
    }

    /** @return array{Post, PostAiRun} */
    private function lockPostThenRun(PostAiRun $run): array
    {
        $postId = PostAiRun::query()->whereKey($run->getKey())->value('post_id');
        $post = Post::query()->lockForUpdate()->findOrFail($postId);
        $lockedRun = PostAiRun::query()
            ->lockForUpdate()
            ->findOrFail($run->getKey());

        if ((int) $lockedRun->post_id !== (int) $post->getKey()) {
            throw new DomainException('The Journal AI run source changed unexpectedly.');
        }

        $lockedRun->setRelation('post', $post);

        return [$post, $lockedRun];
    }

    private function hashMatches(string $expected, string $actual): bool
    {
        return preg_match('/\A[0-9a-f]{64}\z/', $expected) === 1
            && hash_equals($expected, $actual);
    }
}
