<?php

namespace App\Jobs;

use App\Enums\PostAiOperation;
use App\Enums\PostAiRunStatus;
use App\Exceptions\AiProviderException;
use App\Models\Post;
use App\Models\PostAiRun;
use App\Models\User;
use App\Services\AiProviderManager;
use App\Services\AiSettings;
use App\Services\JournalAiContextBuilder;
use App\Services\JournalAiContract;
use App\Services\JournalAiContractRegistry;
use App\Services\JournalAiResultNormalizer;
use App\Services\ProviderExecutionProfile;
use App\Support\CanonicalJson;
use DomainException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

class GenerateJournalAiRun implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 150;

    public bool $failOnTimeout = true;

    public function __construct(
        public int $runId,
        public string $queueToken,
    ) {}

    /** @return array<int, WithoutOverlapping> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("journal-ai:{$this->runId}:{$this->queueToken}"))
                ->dontRelease()
                ->expireAfter($this->timeout + 10),
        ];
    }

    public function handle(
        JournalAiContextBuilder $contexts,
        JournalAiContractRegistry $contracts,
        JournalAiResultNormalizer $normalizer,
        AiProviderManager $providers,
        AiSettings $settings,
    ): void {
        try {
            $claimed = $this->claim($contexts, $contracts, $settings);
        } catch (Throwable) {
            $this->storeFailure(
                'internal',
                'The Journal AI request could not be completed because of an internal error.',
            );

            return;
        }

        if ($claimed === null) {
            return;
        }

        $started = hrtime(true);

        try {
            /** @var JournalAiContract $contract */
            $contract = $claimed['contract'];
            $providerResult = $providers->generateJournalStructured(
                $claimed['profile'],
                $contract->prompt,
                $contract->renderInput($claimed['outbound']),
                $contract->portableSchema(),
                $claimed['max_output_tokens'],
            );
        } catch (AiProviderException $exception) {
            $this->storeFailure(
                $this->normalizedProviderCategory($exception->category),
                $this->providerFailureMessage($exception->category),
                durationMs: $this->durationSince($started),
            );

            return;
        } catch (Throwable) {
            $this->storeFailure(
                'internal',
                'The Journal AI request could not be completed because of an internal error.',
                durationMs: $this->durationSince($started),
            );

            return;
        }

        $durationMs = $this->durationSince($started);

        try {
            $result = $normalizer->normalize($claimed['operation'], $providerResult->payload);
        } catch (DomainException) {
            $this->storeFailure(
                'invalid_output',
                'The AI provider returned a result that did not match the Journal contract.',
                $providerResult->providerRequestId,
                $providerResult->inputTokens,
                $providerResult->outputTokens,
                $durationMs,
            );

            return;
        } catch (Throwable) {
            $this->storeFailure(
                'internal',
                'The Journal AI request could not be completed because of an internal error.',
                $providerResult->providerRequestId,
                $providerResult->inputTokens,
                $providerResult->outputTokens,
                $durationMs,
            );

            return;
        }

        try {
            $this->storeResult(
                $contexts,
                $result,
                $durationMs,
                $providerResult->providerRequestId,
                $providerResult->inputTokens,
                $providerResult->outputTokens,
            );
        } catch (Throwable) {
            $this->storeFailure(
                'internal',
                'The Journal AI request could not be completed because of an internal error.',
                $providerResult->providerRequestId,
                $providerResult->inputTokens,
                $providerResult->outputTokens,
                $durationMs,
            );
        }
    }

    public function failed(?Throwable $exception): void
    {
        $timedOut = $exception instanceof TimeoutExceededException;

        $this->storeFailure(
            $timedOut ? 'timeout' : 'internal',
            $timedOut
                ? 'The Journal AI worker did not finish the request within its time limit.'
                : 'The Journal AI request could not be completed because of an internal error.',
        );
    }

    /**
     * @return array{
     *   operation: PostAiOperation,
     *   outbound: array<string, mixed>,
     *   profile: ProviderExecutionProfile,
     *   contract: JournalAiContract,
     *   max_output_tokens: int
     * }|null
     */
    private function claim(
        JournalAiContextBuilder $contexts,
        JournalAiContractRegistry $contracts,
        AiSettings $settings,
    ): ?array {
        $postId = PostAiRun::query()->whereKey($this->runId)->value('post_id');

        if ($postId === null) {
            return null;
        }

        return DB::transaction(function () use ($postId, $contexts, $contracts, $settings): ?array {
            $post = Post::query()->withTrashed()->lockForUpdate()->find($postId);
            $run = PostAiRun::query()->lockForUpdate()->find($this->runId);

            if ($run === null
                || $run->status !== PostAiRunStatus::Queued
                || ! hash_equals((string) $run->queue_token, $this->queueToken)) {
                return null;
            }

            if ($post === null || $post->trashed()) {
                $this->cancelLockedRun($run, 'source_changed', 'post_unavailable', 'The source Journal post is no longer available.');

                return null;
            }

            if ((int) $run->post_id !== (int) $post->getKey()) {
                $this->failLockedRun($run, 'internal', 'The Journal AI request could not be validated.');

                return null;
            }

            $requester = User::query()->find($run->requester_id);

            if (! $requester instanceof User
                || ! Gate::forUser($requester)->allows('request', [PostAiRun::class, $post])
                || (int) $run->acknowledged_by_user_id !== (int) $requester->getKey()
                || $run->acknowledged_at === null) {
                $this->cancelLockedRun(
                    $run,
                    'requester_unauthorized',
                    'requester_unauthorized',
                    'The administrator who requested this Journal AI run is no longer authorized.',
                );

                return null;
            }

            $contract = $contracts->for($run->operation);

            if (! $this->contractMatches($run, $contract)) {
                $this->failLockedRun($run, 'configuration', 'The Journal AI request contract is no longer available.');

                return null;
            }

            $selection = $run->context_manifest['selection'] ?? null;

            if (! is_array($selection)) {
                $this->failLockedRun($run, 'configuration', 'The Journal AI request context is invalid.');

                return null;
            }

            try {
                $context = $contexts->build($post, $run->operation, $selection);
            } catch (DomainException) {
                $this->staleLockedRun($run, 'source_context_invalid', 'The Journal AI source context is no longer valid.');

                return null;
            }

            if (! hash_equals((string) $run->context_hash, $context->contextHash)
                || ! hash_equals((string) $run->source_hash, $context->sourceHash)) {
                $this->staleLockedRun($run, 'source_changed_before_execution', 'The Journal post changed before AI processing started.');

                return null;
            }

            try {
                $profile = $this->storedProfile($run);
                $profile->assertCurrent($settings);
            } catch (AiProviderException $exception) {
                $this->failLockedRun(
                    $run,
                    $this->normalizedProviderCategory($exception->category),
                    $this->providerFailureMessage($exception->category),
                );

                return null;
            }

            $maxOutputTokens = $run->generation_options['max_output_tokens'] ?? null;

            if (! is_int($maxOutputTokens)
                || $maxOutputTokens !== $contract->maxOutputTokens
                || ! $this->requestHashMatches($run, $context->contextHash, $context->sourceHash, $contract, $profile)) {
                $this->failLockedRun($run, 'configuration', 'The Journal AI request integrity check failed.');

                return null;
            }

            $run->forceFill([
                'status' => PostAiRunStatus::Processing,
                'started_at' => now(),
                'lease_expires_at' => now()->addSeconds($this->timeout + 20),
                'error_category' => null,
                'error_message' => null,
                'stale_reason' => null,
            ])->saveOrFail();

            return [
                'operation' => $run->operation,
                'outbound' => $context->outbound(),
                'profile' => $profile,
                'contract' => $contract,
                'max_output_tokens' => $maxOutputTokens,
            ];
        }, 3);
    }

    /** @param array<string, mixed> $result */
    private function storeResult(
        JournalAiContextBuilder $contexts,
        array $result,
        int $durationMs,
        ?string $providerRequestId,
        ?int $inputTokens,
        ?int $outputTokens,
    ): void {
        $postId = PostAiRun::query()->whereKey($this->runId)->value('post_id');

        if ($postId === null) {
            return;
        }

        DB::transaction(function () use (
            $postId,
            $contexts,
            $result,
            $durationMs,
            $providerRequestId,
            $inputTokens,
            $outputTokens,
        ): void {
            $post = Post::query()->withTrashed()->lockForUpdate()->find($postId);
            $run = PostAiRun::query()->lockForUpdate()->find($this->runId);

            if ($run === null
                || $run->status !== PostAiRunStatus::Processing
                || ! hash_equals((string) $run->queue_token, $this->queueToken)) {
                return;
            }

            if ($post === null || $post->trashed()) {
                $this->cancelLockedRun($run, 'source_changed', 'post_unavailable', 'The source Journal post is no longer available.');

                return;
            }

            $requester = User::query()->find($run->requester_id);

            if (! $requester instanceof User
                || ! Gate::forUser($requester)->allows('request', [PostAiRun::class, $post])
                || (int) $run->acknowledged_by_user_id !== (int) $requester->getKey()
                || $run->acknowledged_at === null) {
                $this->cancelLockedRun(
                    $run,
                    'requester_unauthorized',
                    'requester_unauthorized_during_execution',
                    'The administrator who requested this Journal AI run is no longer authorized.',
                );

                return;
            }

            $selection = $run->context_manifest['selection'] ?? null;

            if (! is_array($selection)) {
                $this->staleLockedRun(
                    $run,
                    'source_context_invalid',
                    'The Journal AI source context is no longer valid.',
                    $providerRequestId,
                    $inputTokens,
                    $outputTokens,
                    $durationMs,
                );

                return;
            }

            try {
                $context = $contexts->build($post, $run->operation, $selection);
            } catch (DomainException) {
                $this->staleLockedRun(
                    $run,
                    'source_context_invalid',
                    'The Journal AI source context is no longer valid.',
                    $providerRequestId,
                    $inputTokens,
                    $outputTokens,
                    $durationMs,
                );

                return;
            }

            $now = now();

            if (! hash_equals((string) $run->context_hash, $context->contextHash)
                || ! hash_equals((string) $run->source_hash, $context->sourceHash)) {
                $run->forceFill([
                    'status' => PostAiRunStatus::Stale,
                    'structured_result' => null,
                    'lease_expires_at' => null,
                    'completed_at' => $now,
                    'duration_ms' => $durationMs,
                    'provider_request_id' => $providerRequestId,
                    'input_tokens' => $inputTokens,
                    'output_tokens' => $outputTokens,
                    'error_category' => 'source_changed',
                    'error_message' => 'The Journal post changed while AI processing was in progress.',
                    'stale_reason' => 'source_changed_during_execution',
                ])->saveOrFail();

                return;
            }

            $run->forceFill([
                'status' => PostAiRunStatus::Ready,
                'structured_result' => $result,
                'lease_expires_at' => null,
                'completed_at' => $now,
                'duration_ms' => $durationMs,
                'provider_request_id' => $providerRequestId,
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'error_category' => null,
                'error_message' => null,
                'stale_reason' => null,
            ])->saveOrFail();
        }, 3);
    }

    private function storeFailure(
        string $category,
        string $message,
        ?string $providerRequestId = null,
        ?int $inputTokens = null,
        ?int $outputTokens = null,
        ?int $durationMs = null,
    ): void {
        $postId = PostAiRun::query()->whereKey($this->runId)->value('post_id');

        if ($postId === null) {
            return;
        }

        DB::transaction(function () use (
            $postId,
            $category,
            $message,
            $providerRequestId,
            $inputTokens,
            $outputTokens,
            $durationMs,
        ): void {
            Post::query()->withTrashed()->lockForUpdate()->find($postId);
            $run = PostAiRun::query()->lockForUpdate()->find($this->runId);

            if ($run === null
                || ! in_array($run->status, [PostAiRunStatus::Queued, PostAiRunStatus::Processing], true)
                || ! hash_equals((string) $run->queue_token, $this->queueToken)) {
                return;
            }

            if ($durationMs === null
                && $run->status === PostAiRunStatus::Processing
                && $run->started_at !== null) {
                $durationMs = max(0, (int) round($run->started_at->diffInMilliseconds(now())));
            }

            $this->failLockedRun(
                $run,
                $category,
                $message,
                $providerRequestId,
                $inputTokens,
                $outputTokens,
                $durationMs,
            );
        }, 3);
    }

    private function storedProfile(PostAiRun $run): ProviderExecutionProfile
    {
        $profile = ProviderExecutionProfile::fromStoredFields(
            provider: (string) $run->provider,
            model: (string) $run->model,
            endpoint: (string) $run->normalized_endpoint,
            externalProcessing: (bool) $run->external_processing,
            credentialFingerprint: $run->credential_hmac === null ? null : (string) $run->credential_hmac,
            generationOptions: $run->generation_options,
        );

        if (! hash_equals((string) $run->provider_profile_hash, $profile->canonicalHash())) {
            throw AiProviderException::invalidConfiguration();
        }

        return $profile;
    }

    private function contractMatches(PostAiRun $run, JournalAiContract $contract): bool
    {
        return $run->prompt_version === $contract->promptVersion
            && hash_equals((string) $run->prompt_hash, $contract->promptHash())
            && $run->schema_version === $contract->schemaVersion
            && hash_equals((string) $run->schema_hash, $contract->schemaHash());
    }

    private function requestHashMatches(
        PostAiRun $run,
        string $contextHash,
        string $sourceHash,
        JournalAiContract $contract,
        ProviderExecutionProfile $profile,
    ): bool {
        $expected = CanonicalJson::hash([
            'operation' => $run->operation->value,
            'source_hash' => $sourceHash,
            'context_hash' => $contextHash,
            'provider_profile_hash' => $profile->canonicalHash(),
            'prompt_version' => $contract->promptVersion,
            'prompt_hash' => $contract->promptHash(),
            'schema_version' => $contract->schemaVersion,
            'schema_hash' => $contract->schemaHash(),
            'max_output_tokens' => $contract->maxOutputTokens,
        ]);

        return hash_equals((string) $run->request_hash, $expected);
    }

    private function staleLockedRun(
        PostAiRun $run,
        string $reason,
        string $message,
        ?string $providerRequestId = null,
        ?int $inputTokens = null,
        ?int $outputTokens = null,
        ?int $durationMs = null,
    ): void {
        $run->forceFill([
            'status' => PostAiRunStatus::Stale,
            'lease_expires_at' => null,
            'completed_at' => now(),
            'provider_request_id' => $providerRequestId,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'duration_ms' => $durationMs,
            'error_category' => 'source_changed',
            'error_message' => $message,
            'stale_reason' => $reason,
        ])->saveOrFail();
    }

    private function failLockedRun(
        PostAiRun $run,
        string $category,
        string $message,
        ?string $providerRequestId = null,
        ?int $inputTokens = null,
        ?int $outputTokens = null,
        ?int $durationMs = null,
    ): void {
        $run->forceFill([
            'status' => PostAiRunStatus::Failed,
            'lease_expires_at' => null,
            'completed_at' => now(),
            'provider_request_id' => $providerRequestId,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'duration_ms' => $durationMs,
            'error_category' => $category,
            'error_message' => $message,
            'stale_reason' => null,
        ])->saveOrFail();
    }

    private function cancelLockedRun(PostAiRun $run, string $category, string $reason, string $message): void
    {
        $now = now();
        $run->forceFill([
            'status' => PostAiRunStatus::Cancelled,
            'lease_expires_at' => null,
            'completed_at' => $now,
            'cancelled_at' => $now,
            'error_category' => $category,
            'error_message' => $message,
            'stale_reason' => $reason,
        ])->saveOrFail();
    }

    private function normalizedProviderCategory(string $category): string
    {
        return match ($category) {
            AiProviderException::CATEGORY_AUTHORIZATION => 'authentication',
            AiProviderException::CATEGORY_RATE_LIMITED => 'rate_limited',
            AiProviderException::CATEGORY_TIMEOUT => 'timeout',
            AiProviderException::CATEGORY_CONNECTION => 'connection',
            AiProviderException::CATEGORY_PROVIDER_REJECTED => 'provider_rejected',
            AiProviderException::CATEGORY_INVALID_OUTPUT => 'invalid_output',
            default => 'configuration',
        };
    }

    private function providerFailureMessage(string $category): string
    {
        return match ($this->normalizedProviderCategory($category)) {
            'authentication' => 'The Journal AI provider rejected its configured credential.',
            'rate_limited' => 'The Journal AI provider is temporarily rate limited.',
            'timeout' => 'The Journal AI provider did not respond within the request time limit.',
            'connection' => 'The Journal AI provider could not be reached.',
            'provider_rejected' => 'The Journal AI provider rejected the structured request.',
            'invalid_output' => 'The AI provider returned invalid structured output.',
            default => 'The Journal AI provider configuration changed or is invalid.',
        };
    }

    private function durationSince(int $started): int
    {
        return max(0, (int) round((hrtime(true) - $started) / 1_000_000));
    }
}
