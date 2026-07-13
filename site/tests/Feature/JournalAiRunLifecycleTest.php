<?php

namespace Tests\Feature;

use App\Data\JournalAiProviderResult;
use App\Enums\PostAiOperation;
use App\Enums\PostAiRunStatus;
use App\Enums\PostStatus;
use App\Exceptions\AiProviderException;
use App\Jobs\GenerateJournalAiRun;
use App\Models\Post;
use App\Models\PostAiRun;
use App\Models\User;
use App\Services\AiProviderManager;
use App\Services\AiSettings;
use App\Services\JournalAiContextBuilder;
use App\Services\JournalAiContractRegistry;
use App\Services\JournalAiResultNormalizer;
use App\Services\JournalAiRunPreview;
use App\Services\JournalAiRunService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class JournalAiRunLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
        Queue::fake();
        $this->configureOllama();
    }

    public function test_only_an_administrator_can_acknowledge_the_exact_current_request(): void
    {
        $post = $this->makePost();
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $selection = $this->selection();
        $service = app(JournalAiRunService::class);
        $preview = $service->preview($post, PostAiOperation::Directions, $selection, $admin);

        $this->assertInstanceOf(JournalAiRunPreview::class, $preview);
        $this->assertSame('ollama', $preview->provider);
        $this->assertSame('qwen3.5:latest', $preview->model);
        $this->assertSame('http://ollama.test:11434', $preview->endpoint);
        $this->assertFalse($preview->externalProcessing);

        try {
            $service->request(
                $post,
                PostAiOperation::Directions,
                $selection,
                $user,
                $preview->contextHash,
                $preview->providerProfileHash,
                $preview->requestHash,
            );
            $this->fail('A non-administrator must not request Journal AI processing.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('post_ai_runs', 0);
        }

        try {
            $service->request(
                $post,
                PostAiOperation::Directions,
                $selection,
                $admin,
                str_repeat('0', 64),
                $preview->providerProfileHash,
                $preview->requestHash,
            );
            $this->fail('A stale context acknowledgement must be rejected.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('changed', $exception->getMessage());
            $this->assertDatabaseCount('post_ai_runs', 0);
        }

        $run = $this->requestRun($post, $admin, $selection, $preview);

        $this->assertSame(PostAiRunStatus::Queued, $run->status);
        $this->assertSame($admin->id, $run->requester_id);
        $this->assertSame($admin->id, $run->acknowledged_by_user_id);
        $this->assertNotNull($run->acknowledged_at);
        $this->assertNotNull($run->source_revision_id);
        $this->assertSame($preview->contextHash, $run->context_hash);
        $this->assertSame($preview->providerProfileHash, $run->provider_profile_hash);
        $this->assertSame($preview->requestHash, $run->request_hash);
        $this->assertSame(1, $post->revisions()->count());

        Queue::assertPushed(
            GenerateJournalAiRun::class,
            fn (GenerateJournalAiRun $job): bool => $job->runId === $run->id
                && $job->queueToken === $run->queue_token
                && $job->queue === JournalAiRunService::DEFAULT_QUEUE,
        );
    }

    public function test_preview_rejects_an_ollama_request_that_cannot_fit_the_pinned_context(): void
    {
        $post = $this->makePost();
        $admin = User::factory()->admin()->create();
        $this->configureOllama(['ollama_context_length' => 2048]);

        try {
            app(JournalAiRunService::class)->preview(
                $post,
                PostAiOperation::EditorialReview,
                $this->selection(),
                $admin,
            );
            $this->fail('An undersized pinned context must fail before acknowledgement or queueing.');
        } catch (AiProviderException $exception) {
            $this->assertSame(AiProviderException::CATEGORY_INVALID_CONFIGURATION, $exception->category);
        }

        $this->assertDatabaseCount('post_ai_runs', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_cancel_and_prioritize_rotate_tokens_and_superseded_jobs_do_not_call_the_provider(): void
    {
        $post = $this->makePost();
        $admin = User::factory()->admin()->create();
        $service = app(JournalAiRunService::class);
        $cancelled = $this->requestRun($post, $admin);
        $cancelledToken = $cancelled->queue_token;

        $cancelled = $service->cancel($cancelled, $admin);

        $this->assertSame(PostAiRunStatus::Cancelled, $cancelled->status);
        $this->assertNotSame($cancelledToken, $cancelled->queue_token);
        $this->runJob($cancelled->id, $cancelledToken);
        Http::assertNothingSent();

        $prioritized = $this->requestRun($post, $admin);
        $oldToken = $prioritized->queue_token;
        $prioritized = $service->prioritize($prioritized, $admin);

        $this->assertSame(PostAiRunStatus::Queued, $prioritized->status);
        $this->assertNotSame($oldToken, $prioritized->queue_token);
        $this->assertSame(JournalAiRunService::HIGH_PRIORITY_QUEUE, $prioritized->queue_name);
        $this->assertSame(100, $prioritized->queue_priority);

        $this->runJob($prioritized->id, $oldToken);
        Http::assertNothingSent();
        Queue::assertPushed(
            GenerateJournalAiRun::class,
            fn (GenerateJournalAiRun $job): bool => $job->queue === JournalAiRunService::HIGH_PRIORITY_QUEUE
                && $job->queueToken === $prioritized->queue_token,
        );
    }

    public function test_retry_creates_a_new_acknowledged_child_and_recovery_never_reuses_consent(): void
    {
        $post = $this->makePost();
        $admin = User::factory()->admin()->create();
        $service = app(JournalAiRunService::class);
        $parent = $service->cancel($this->requestRun($post, $admin), $admin);
        $selection = $parent->context_manifest['selection'];
        $preview = $service->preview($post, $parent->operation, $selection, $admin);
        $child = $service->retry(
            $parent,
            $admin,
            $preview->contextHash,
            $preview->providerProfileHash,
            $preview->requestHash,
        );

        $this->assertSame(PostAiRunStatus::Cancelled, $parent->fresh()->status);
        $this->assertSame(PostAiRunStatus::Queued, $child->status);
        $this->assertSame($parent->id, $child->retry_of_id);
        $this->assertNotSame($parent->queue_token, $child->queue_token);

        $child->forceFill(['lease_expires_at' => now()->subMinute()])->saveOrFail();
        $countBeforeRecovery = PostAiRun::query()->count();

        $this->assertSame(1, $service->recoverStale($admin));
        $this->assertSame($countBeforeRecovery, PostAiRun::query()->count());
        $child->refresh();
        $this->assertSame(PostAiRunStatus::Stale, $child->status);
        $this->assertSame('queue_expired', $child->stale_reason);
        $this->assertSame('internal', $child->error_category);
    }

    public function test_successful_job_stores_only_a_normalized_result_without_touching_the_post(): void
    {
        $post = $this->makePost();
        $admin = User::factory()->admin()->create();
        $run = $this->requestRun($post, $admin);
        $postBefore = $post->fresh()->getAttributes();
        $revisionCount = $post->revisions()->count();

        Http::fake([
            'ollama.test:11434/api/chat' => Http::response([
                'message' => ['content' => json_encode($this->directionsResult(), JSON_THROW_ON_ERROR)],
                'prompt_eval_count' => 123,
                'eval_count' => 45,
            ]),
        ]);

        $this->runJob($run->id, $run->queue_token);

        $run->refresh();
        $this->assertSame(PostAiRunStatus::Ready, $run->status);
        $this->assertSame('A useful next pass.', $run->structured_result['summary']);
        $this->assertNull($run->error_category);
        $this->assertNotNull($run->completed_at);
        $this->assertNotNull($run->duration_ms);
        $this->assertNull($run->lease_expires_at);
        $this->assertNull($run->provider_request_id);
        $this->assertSame(123, $run->input_tokens);
        $this->assertSame(45, $run->output_tokens);
        $this->assertSame($postBefore, $post->fresh()->getAttributes());
        $this->assertSame($revisionCount, $post->revisions()->count());

        Http::assertSent(function ($request): bool {
            $messages = $request->data()['messages'] ?? [];

            return $messages[0]['role'] === 'system'
                && str_contains($messages[0]['content'], 'untrusted data')
                && $messages[1]['role'] === 'user'
                && str_contains($messages[1]['content'], 'Original Journal body')
                && ! str_contains($messages[0]['content'], 'Original Journal body');
        });
        Http::assertSentCount(1);
    }

    public function test_ready_runs_persist_only_bounded_provider_provenance(): void
    {
        $post = $this->makePost();
        $admin = User::factory()->admin()->create();
        $provider = \Mockery::mock(AiProviderManager::class);
        $provider->shouldReceive('generateJournalStructured')
            ->twice()
            ->andReturnValues([
                new JournalAiProviderResult($this->directionsResult(), 'request_safe-123', 81, 34),
                new JournalAiProviderResult(
                    $this->directionsResult(),
                    str_repeat('x', JournalAiProviderResult::MAX_REQUEST_ID_LENGTH + 1),
                    JournalAiProviderResult::MAX_TOKEN_COUNT + 1,
                    -1,
                ),
            ]);

        $valid = $this->requestRun($post, $admin);
        $this->runJob($valid->id, $valid->queue_token, $provider);
        $valid->refresh();

        $this->assertSame(PostAiRunStatus::Ready, $valid->status);
        $this->assertSame('request_safe-123', $valid->provider_request_id);
        $this->assertSame(81, $valid->input_tokens);
        $this->assertSame(34, $valid->output_tokens);
        $this->assertNotNull($valid->duration_ms);

        $outOfBounds = $this->requestRun($post, $admin);
        $this->runJob($outOfBounds->id, $outOfBounds->queue_token, $provider);
        $outOfBounds->refresh();

        $this->assertSame(PostAiRunStatus::Ready, $outOfBounds->status);
        $this->assertNull($outOfBounds->provider_request_id);
        $this->assertNull($outOfBounds->input_tokens);
        $this->assertNull($outOfBounds->output_tokens);
        $this->assertNotNull($outOfBounds->duration_ms);
    }

    public function test_source_change_before_claim_marks_the_run_stale_without_http(): void
    {
        $post = $this->makePost();
        $admin = User::factory()->admin()->create();
        $run = $this->requestRun($post, $admin);
        $post->update(['body' => 'A newer human edit before the queued job was claimed.']);

        $this->runJob($run->id, $run->queue_token);

        $run->refresh();
        $this->assertSame(PostAiRunStatus::Stale, $run->status);
        $this->assertSame('source_changed_before_execution', $run->stale_reason);
        $this->assertNull($run->structured_result);
        Http::assertNothingSent();
    }

    public function test_source_changes_during_the_provider_call_make_the_result_stale(): void
    {
        $post = $this->makePost();
        $admin = User::factory()->admin()->create();
        $run = $this->requestRun($post, $admin);

        $provider = \Mockery::mock(AiProviderManager::class);
        $provider->shouldReceive('generateJournalStructured')
            ->once()
            ->andReturnUsing(function () use ($post): JournalAiProviderResult {
                $post->update(['body' => 'A newer human edit made during the provider request.']);

                return new JournalAiProviderResult(
                    $this->directionsResult(),
                    'stale-request-123',
                    144,
                    55,
                );
            });

        $this->runJob($run->id, $run->queue_token, $provider);

        $run->refresh();
        $this->assertSame(PostAiRunStatus::Stale, $run->status);
        $this->assertSame('source_changed', $run->error_category);
        $this->assertSame('source_changed_during_execution', $run->stale_reason);
        $this->assertNull($run->structured_result);
        $this->assertNotNull($run->duration_ms);
        $this->assertSame('stale-request-123', $run->provider_request_id);
        $this->assertSame(144, $run->input_tokens);
        $this->assertSame(55, $run->output_tokens);
        $this->assertSame('A newer human edit made during the provider request.', $post->fresh()->body);
    }

    public function test_cancellation_during_the_provider_call_discards_the_result(): void
    {
        $post = $this->makePost();
        $admin = User::factory()->admin()->create();
        $service = app(JournalAiRunService::class);
        $run = $this->requestRun($post, $admin);

        $provider = \Mockery::mock(AiProviderManager::class);
        $provider->shouldReceive('generateJournalStructured')
            ->once()
            ->andReturnUsing(function () use ($service, $run, $admin): JournalAiProviderResult {
                $service->cancel($run->fresh(), $admin);

                return new JournalAiProviderResult(
                    $this->directionsResult(),
                    'cancelled-request-123',
                    155,
                    66,
                );
            });

        $this->runJob($run->id, $run->queue_token, $provider);

        $run->refresh();
        $this->assertSame(PostAiRunStatus::Cancelled, $run->status);
        $this->assertNull($run->structured_result);
        $this->assertNull($run->provider_request_id);
        $this->assertNull($run->input_tokens);
        $this->assertNull($run->output_tokens);
        $this->assertNull($run->duration_ms);
    }

    public function test_requester_revocation_during_the_provider_call_discards_the_result(): void
    {
        $post = $this->makePost();
        $admin = User::factory()->admin()->create();
        $run = $this->requestRun($post, $admin);

        Http::fake(function () use ($admin) {
            $admin->forceFill(['is_admin' => false])->save();

            return Http::response([
                'message' => ['content' => json_encode($this->directionsResult(), JSON_THROW_ON_ERROR)],
            ]);
        });

        $this->runJob($run->id, $run->queue_token);

        $run->refresh();
        $this->assertSame(PostAiRunStatus::Cancelled, $run->status);
        $this->assertSame('requester_unauthorized', $run->error_category);
        $this->assertSame('requester_unauthorized_during_execution', $run->stale_reason);
        $this->assertNull($run->structured_result);
    }

    public function test_revoked_requester_and_changed_provider_configuration_fail_before_http(): void
    {
        $post = $this->makePost();
        $admin = User::factory()->admin()->create();
        $revoked = $this->requestRun($post, $admin);
        $admin->forceFill(['is_admin' => false])->save();

        $this->runJob($revoked->id, $revoked->queue_token);

        $revoked->refresh();
        $this->assertSame(PostAiRunStatus::Cancelled, $revoked->status);
        $this->assertSame('requester_unauthorized', $revoked->error_category);
        $this->assertNull($revoked->provider_request_id);
        $this->assertNull($revoked->input_tokens);
        $this->assertNull($revoked->output_tokens);
        $this->assertNull($revoked->duration_ms);
        Http::assertNothingSent();

        $otherAdmin = User::factory()->admin()->create();
        $changed = $this->requestRun($post, $otherAdmin);
        $this->configureOllama(['ollama_base_url' => 'http://changed-ollama.test:11434']);

        $this->runJob($changed->id, $changed->queue_token);

        $changed->refresh();
        $this->assertSame(PostAiRunStatus::Failed, $changed->status);
        $this->assertSame('configuration', $changed->error_category);
        $this->assertNull($changed->provider_request_id);
        $this->assertNull($changed->input_tokens);
        $this->assertNull($changed->output_tokens);
        $this->assertNull($changed->duration_ms);
        Http::assertNothingSent();
    }

    public function test_unexpected_claim_failure_terminalizes_the_matching_queued_token(): void
    {
        $post = $this->makePost();
        $admin = User::factory()->admin()->create();
        $run = $this->requestRun($post, $admin);
        $settings = \Mockery::mock(AiSettings::class);
        $settings->shouldReceive('refresh')
            ->once()
            ->andThrow(new RuntimeException('raw settings failure with private context'));
        $providers = \Mockery::mock(AiProviderManager::class);
        $providers->shouldNotReceive('generateJournalStructured');

        (new GenerateJournalAiRun($run->id, $run->queue_token))->handle(
            app(JournalAiContextBuilder::class),
            app(JournalAiContractRegistry::class),
            app(JournalAiResultNormalizer::class),
            $providers,
            $settings,
        );

        $run->refresh();
        $this->assertSame(PostAiRunStatus::Failed, $run->status);
        $this->assertSame('internal', $run->error_category);
        $this->assertSame(
            'The Journal AI request could not be completed because of an internal error.',
            $run->error_message,
        );
        $this->assertStringNotContainsString('private context', (string) $run->error_message);
        Http::assertNothingSent();
    }

    public function test_provider_errors_are_stored_as_fixed_sanitized_messages(): void
    {
        $post = $this->makePost();
        $admin = User::factory()->admin()->create();
        $run = $this->requestRun($post, $admin);
        $secret = 'secret-key-and-private-body-/storage/posts/covers/private.jpg';

        Http::fake([
            'ollama.test:11434/api/chat' => Http::response($secret, 500),
        ]);

        $this->runJob($run->id, $run->queue_token);

        $run->refresh();
        $this->assertSame(PostAiRunStatus::Failed, $run->status);
        $this->assertSame('provider_rejected', $run->error_category);
        $this->assertSame('The Journal AI provider rejected the structured request.', $run->error_message);
        $this->assertNotNull($run->duration_ms);
        $this->assertStringNotContainsString($secret, (string) $run->error_message);
        $this->assertStringNotContainsString('Original Journal body', (string) $run->error_message);
    }

    public function test_unknown_and_oversized_provider_outputs_fail_without_a_partial_result(): void
    {
        $post = $this->makePost();
        $admin = User::factory()->admin()->create();
        $invalidResults = [
            $this->directionsResult() + ['unexpected' => 'must be rejected'],
            array_replace($this->directionsResult(), ['summary' => str_repeat('x', 140_000)]),
        ];

        foreach ($invalidResults as $invalidResult) {
            $run = $this->requestRun($post, $admin);
            Http::fake([
                'ollama.test:11434/api/chat' => Http::response([
                    'message' => ['content' => json_encode($invalidResult, JSON_THROW_ON_ERROR)],
                ]),
            ]);

            $this->runJob($run->id, $run->queue_token);

            $run->refresh();
            $this->assertSame(PostAiRunStatus::Failed, $run->status);
            $this->assertSame('invalid_output', $run->error_category);
            $this->assertSame(
                'The AI provider returned a result that did not match the Journal contract.',
                $run->error_message,
            );
            $this->assertNull($run->structured_result);
            $this->assertNotNull($run->duration_ms);
            $this->assertStringNotContainsString('unexpected', (string) $run->error_message);
        }
    }

    public function test_invalid_output_retains_only_sanitized_provider_call_telemetry(): void
    {
        $post = $this->makePost();
        $admin = User::factory()->admin()->create();
        $run = $this->requestRun($post, $admin);
        $provider = \Mockery::mock(AiProviderManager::class);
        $provider->shouldReceive('generateJournalStructured')
            ->once()
            ->andReturn(new JournalAiProviderResult(
                $this->directionsResult() + ['unexpected' => 'discard this raw output'],
                'invalid-output-request-123',
                166,
                77,
            ));

        $this->runJob($run->id, $run->queue_token, $provider);

        $run->refresh();
        $this->assertSame(PostAiRunStatus::Failed, $run->status);
        $this->assertSame('invalid_output', $run->error_category);
        $this->assertNull($run->structured_result);
        $this->assertSame('invalid-output-request-123', $run->provider_request_id);
        $this->assertSame(166, $run->input_tokens);
        $this->assertSame(77, $run->output_tokens);
        $this->assertNotNull($run->duration_ms);
        $this->assertStringNotContainsString('discard this raw output', (string) $run->error_message);
    }

    public function test_failed_callback_from_a_cancelled_token_cannot_overwrite_terminal_state(): void
    {
        $post = $this->makePost();
        $admin = User::factory()->admin()->create();
        $service = app(JournalAiRunService::class);
        $run = $this->requestRun($post, $admin);
        $oldToken = $run->queue_token;
        $run = $service->cancel($run, $admin);
        $cancelledAt = $run->cancelled_at;

        (new GenerateJournalAiRun($run->id, $oldToken))->failed(new RuntimeException('raw secret timeout'));

        $run->refresh();
        $this->assertSame(PostAiRunStatus::Cancelled, $run->status);
        $this->assertTrue($run->cancelled_at->equalTo($cancelledAt));
        $this->assertNull($run->error_category);
        $this->assertStringNotContainsString('raw secret', (string) $run->error_message);

        $queued = $this->requestRun($post, $admin);
        (new GenerateJournalAiRun($queued->id, $queued->queue_token))
            ->failed(new MaxAttemptsExceededException('raw queued failure details'));
        $queued->refresh();

        $this->assertSame(PostAiRunStatus::Failed, $queued->status);
        $this->assertSame('internal', $queued->error_category);
        $this->assertSame(
            'The Journal AI request could not be completed because of an internal error.',
            $queued->error_message,
        );
        $this->assertStringNotContainsString('raw queued failure', (string) $queued->error_message);

        $processing = $this->requestRun($post, $admin);
        $processing->forceFill([
            'status' => PostAiRunStatus::Processing,
            'started_at' => now(),
            'lease_expires_at' => now()->addMinute(),
        ])->saveOrFail();
        (new GenerateJournalAiRun($processing->id, $processing->queue_token))
            ->failed(new TimeoutExceededException('raw timeout details'));
        $processing->refresh();

        $this->assertSame(PostAiRunStatus::Failed, $processing->status);
        $this->assertSame('timeout', $processing->error_category);
        $this->assertSame(
            'The Journal AI worker did not finish the request within its time limit.',
            $processing->error_message,
        );
        $this->assertNotNull($processing->duration_ms);
        $this->assertStringNotContainsString('raw timeout', (string) $processing->error_message);
    }

    public function test_every_lifecycle_action_is_administrator_only_and_ready_runs_can_be_dismissed(): void
    {
        $post = $this->makePost();
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $service = app(JournalAiRunService::class);
        $queued = $this->requestRun($post, $admin);

        $this->assertAuthorizationDenied(fn () => $service->cancel($queued, $user));
        $this->assertAuthorizationDenied(fn () => $service->prioritize($queued, $user));

        $cancelled = $service->cancel($queued, $admin);
        $selection = $cancelled->context_manifest['selection'];
        $preview = $service->preview($post, $cancelled->operation, $selection, $admin);
        $this->assertAuthorizationDenied(fn () => $service->retry(
            $cancelled,
            $user,
            $preview->contextHash,
            $preview->providerProfileHash,
            $preview->requestHash,
        ));
        $this->assertAuthorizationDenied(fn () => $service->recoverStale($user));

        $ready = $this->requestRun($post, $admin);
        $ready->forceFill([
            'status' => PostAiRunStatus::Ready,
            'structured_result' => $this->directionsResult(),
            'completed_at' => now(),
            'lease_expires_at' => null,
        ])->saveOrFail();

        $this->assertAuthorizationDenied(fn () => $service->dismiss($ready, $user));
        $ready = $service->dismiss($ready, $admin);
        $this->assertSame(PostAiRunStatus::Dismissed, $ready->status);
        $this->assertNotNull($ready->dismissed_at);
    }

    public function test_processing_lease_recovery_is_bounded_and_rotates_only_expired_tokens(): void
    {
        $post = $this->makePost();
        $admin = User::factory()->admin()->create();
        $service = app(JournalAiRunService::class);
        $runs = collect(range(1, 3))->map(function () use ($post, $admin): PostAiRun {
            $run = $this->requestRun($post, $admin);
            $run->forceFill([
                'status' => PostAiRunStatus::Processing,
                'started_at' => now()->subMinutes(5),
                'lease_expires_at' => now()->subMinute(),
            ])->saveOrFail();

            return $run;
        });
        $tokens = $runs->pluck('queue_token', 'id');

        $this->assertSame(2, $service->recoverStale($admin, 2));

        $recovered = PostAiRun::query()->where('status', PostAiRunStatus::Stale->value)->get();
        $remaining = PostAiRun::query()->where('status', PostAiRunStatus::Processing->value)->firstOrFail();
        $this->assertCount(2, $recovered);
        $this->assertSame('worker_lease_expired', $recovered->first()->stale_reason);

        foreach ($recovered as $run) {
            $this->assertNotSame($tokens[$run->id], $run->queue_token);
        }

        $this->assertSame($tokens[$remaining->id], $remaining->queue_token);
        $this->assertSame(3, PostAiRun::query()->count());
    }

    public function test_unapplied_ready_result_cannot_change_public_journal_feed_or_sitemap(): void
    {
        $this->travelTo(now()->startOfSecond());
        $post = $this->makePost();
        $post->forceFill([
            'status' => PostStatus::Published,
            'scheduled_at' => null,
            'published' => true,
            'published_at' => now()->subHour(),
        ])->saveOrFail();
        $admin = User::factory()->admin()->create();
        $this->publicJournalResponses($post); // Warm one-time Livewire asset injection state.
        $before = $this->publicJournalResponses($post);
        $run = $this->requestRun($post, $admin);

        Http::fake([
            'ollama.test:11434/api/chat' => Http::response([
                'message' => ['content' => json_encode($this->directionsResult(), JSON_THROW_ON_ERROR)],
            ]),
        ]);
        $this->runJob($run->id, $run->queue_token);

        $this->assertSame(PostAiRunStatus::Ready, $run->fresh()->status);
        $this->assertSame($before, $this->publicJournalResponses($post));
    }

    public function test_trashing_a_post_cancels_work_and_stales_ready_results(): void
    {
        $post = $this->makePost();
        $admin = User::factory()->admin()->create();
        $queued = $this->requestRun($post, $admin);
        $ready = $this->requestRun($post, $admin);
        $ready->forceFill([
            'status' => PostAiRunStatus::Ready,
            'structured_result' => $this->directionsResult(),
            'completed_at' => now(),
            'lease_expires_at' => null,
        ])->saveOrFail();

        $queuedToken = $queued->queue_token;
        $readyToken = $ready->queue_token;
        $post->delete();

        $queued->refresh();
        $ready->refresh();
        $this->assertSame(PostAiRunStatus::Cancelled, $queued->status);
        $this->assertNotSame($queuedToken, $queued->queue_token);
        $this->assertSame(PostAiRunStatus::Stale, $ready->status);
        $this->assertSame('post_trashed', $ready->stale_reason);
        $this->assertNotSame($readyToken, $ready->queue_token);
    }

    /** @param array<string, mixed> $overrides */
    private function configureOllama(array $overrides = []): void
    {
        app(AiSettings::class)->save(array_replace([
            'provider' => 'ollama',
            'ollama_base_url' => 'http://ollama.test:11434',
            'ollama_model' => 'qwen3.5:latest',
            'ollama_journal_model' => 'qwen3.5:latest',
            'ollama_request_timeout' => 60,
            'ollama_external_processing' => false,
            'ollama_context_length' => 8192,
            'ollama_keep_alive' => '5m',
        ], $overrides));
    }

    private function makePost(): Post
    {
        return Post::query()->create([
            'title' => 'Original Journal title',
            'slug' => 'original-journal-title',
            'excerpt' => 'Original Journal excerpt.',
            'body' => 'Original Journal body with enough context for an editorial direction.',
            'editorial_brief' => 'Private brief that is not selected.',
            'editorial_notes' => 'Private notes that are not selected.',
        ]);
    }

    /** @return array<string, mixed> */
    private function selection(): array
    {
        return [
            'fields' => ['title', 'excerpt', 'body'],
            'include_editorial_brief' => false,
            'include_editorial_notes' => false,
            'include_tags' => false,
            'include_connected_media' => false,
        ];
    }

    /** @param array<string, mixed>|null $selection */
    private function requestRun(
        Post $post,
        User $admin,
        ?array $selection = null,
        ?JournalAiRunPreview $preview = null,
    ): PostAiRun {
        $selection ??= $this->selection();
        $service = app(JournalAiRunService::class);
        $preview ??= $service->preview($post, PostAiOperation::Directions, $selection, $admin);

        return $service->request(
            $post,
            PostAiOperation::Directions,
            $selection,
            $admin,
            $preview->contextHash,
            $preview->providerProfileHash,
            $preview->requestHash,
        );
    }

    private function runJob(int $runId, string $token, ?AiProviderManager $providers = null): void
    {
        (new GenerateJournalAiRun($runId, $token))->handle(
            app(JournalAiContextBuilder::class),
            app(JournalAiContractRegistry::class),
            app(JournalAiResultNormalizer::class),
            $providers ?? app(AiProviderManager::class),
            app(AiSettings::class),
        );
    }

    /** @return array<string, mixed> */
    private function directionsResult(): array
    {
        return [
            'summary' => 'A useful next pass.',
            'directions' => [[
                'title' => 'Focus the central experiment',
                'rationale' => 'The draft already contains a clear starting point.',
                'suggested_angle' => 'Explain what changed during the experiment.',
                'questions' => ['Which observation surprised the author?'],
            ]],
            'claims_requiring_verification' => [],
        ];
    }

    /** @param callable(): mixed $callback */
    private function assertAuthorizationDenied(callable $callback): void
    {
        try {
            $callback();
            $this->fail('The lifecycle action must be administrator-only.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
    }

    /** @return array{page:string, feed:string, sitemap:string} */
    private function publicJournalResponses(Post $post): array
    {
        return [
            'page' => $this->get(route('posts.show', $post))->assertOk()->getContent(),
            'feed' => $this->get(route('feed'))->assertOk()->getContent(),
            'sitemap' => $this->get(route('sitemap'))->assertOk()->getContent(),
        ];
    }
}
