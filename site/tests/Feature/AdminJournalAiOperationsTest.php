<?php

namespace Tests\Feature;

use App\Enums\PostAiOperation;
use App\Enums\PostAiRunStatus;
use App\Filament\Resources\JournalAiRuns\JournalAiRunResource;
use App\Filament\Resources\JournalAiRuns\Pages\ManageJournalAiRuns;
use App\Filament\Resources\Posts\PostResource;
use App\Jobs\GenerateJournalAiRun;
use App\Models\Post;
use App\Models\PostAiRun;
use App\Models\User;
use App\Services\JournalAiRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class AdminJournalAiOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_queue_is_administrator_only_and_lists_only_actionable_jobs_oldest_first(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $post = $this->makePost();
        $oldest = $this->makeRun($post, $admin, PostAiRunStatus::Queued, [
            'queued_at' => now()->subHours(5),
        ]);
        $processing = $this->makeRun($post, $admin, PostAiRunStatus::Processing, [
            'queued_at' => now()->subHours(4),
            'started_at' => now()->subHours(3),
        ]);
        $ready = $this->makeRun($post, $admin, PostAiRunStatus::Ready, [
            'queued_at' => now()->subHours(3),
            'completed_at' => now()->subHours(2),
            'duration_ms' => 1345,
            'input_tokens' => 321,
            'output_tokens' => 87,
            'structured_result' => ['summary' => 'RAW-RESULT-MUST-NOT-RENDER'],
        ]);
        $failed = $this->makeRun($post, $admin, PostAiRunStatus::Failed, [
            'queued_at' => now()->subHours(2),
            'completed_at' => now()->subHour(),
            'error_category' => 'invalid_response',
            'error_message' => 'RAW-ERROR-MUST-NOT-RENDER',
            'provider_request_id' => 'PRIVATE-PROVIDER-REQUEST-ID',
        ]);
        $stale = $this->makeRun($post, $admin, PostAiRunStatus::Stale, [
            'queued_at' => now()->subHour(),
            'completed_at' => now()->subMinutes(30),
            'error_category' => 'source_changed',
            'retry_of_id' => $failed->id,
        ]);
        $closed = collect([
            PostAiRunStatus::Cancelled,
            PostAiRunStatus::Dismissed,
            PostAiRunStatus::Applied,
        ])->map(fn (PostAiRunStatus $status): PostAiRun => $this->makeRun($post, $admin, $status));
        $url = JournalAiRunResource::getUrl('index');

        $this->actingAs($user)->get($url)->assertForbidden();
        Livewire::actingAs($user)
            ->test(ManageJournalAiRuns::class)
            ->assertForbidden();

        $component = Livewire::actingAs($admin)
            ->test(ManageJournalAiRuns::class)
            ->assertSuccessful()
            ->assertSee('Journal AI queue')
            ->assertSee('Review results and acknowledge fresh context')
            ->assertCanSeeTableRecords([$oldest, $processing, $ready, $failed, $stale], inOrder: true)
            ->assertCanNotSeeTableRecords($closed)
            ->assertSee('Invalid Response')
            ->assertSee('Source Changed')
            ->assertSee('Ollama')
            ->assertSee('test-model')
            ->assertSee($admin->name)
            ->assertSee('Original request')
            ->assertSee('Retry of #'.$failed->id)
            ->assertSee('Revision #')
            ->assertSee('1.35 s')
            ->assertSee('321 / 87')
            ->assertSee('hours ago')
            ->assertDontSee('RAW-RESULT-MUST-NOT-RENDER')
            ->assertDontSee('RAW-ERROR-MUST-NOT-RENDER')
            ->assertDontSee('PRIVATE-PROVIDER-REQUEST-ID');

        $component
            ->assertTableActionHasUrl(
                'openAssistant',
                PostResource::getUrl('assistant', ['record' => $post]),
                $failed,
            )
            ->assertTableActionDoesNotExist('retry', record: $failed);

        $this->assertSame('5', JournalAiRunResource::getNavigationBadge());
        $this->assertSame('danger', JournalAiRunResource::getNavigationBadgeColor());
    }

    public function test_queue_actions_prioritize_and_cancel_without_bypassing_retry_acknowledgement(): void
    {
        $admin = User::factory()->admin()->create();
        $post = $this->makePost();
        $queued = $this->makeRun($post, $admin, PostAiRunStatus::Queued);
        $processing = $this->makeRun($post, $admin, PostAiRunStatus::Processing, [
            'started_at' => now()->subMinute(),
            'lease_expires_at' => now()->addMinutes(4),
        ]);
        $queuedToken = $queued->queue_token;
        $processingToken = $processing->queue_token;

        $component = Livewire::actingAs($admin)
            ->test(ManageJournalAiRuns::class)
            ->assertTableActionVisible('prioritize', $queued)
            ->assertTableActionVisible('cancel', $queued)
            ->assertTableActionHidden('prioritize', $processing)
            ->assertTableActionVisible('cancel', $processing)
            ->assertTableActionDoesNotExist('retry', record: $queued)
            ->callTableAction('prioritize', $queued)
            ->assertHasNoTableActionErrors();

        $queued->refresh();
        $this->assertSame(PostAiRunStatus::Queued, $queued->status);
        $this->assertSame(JournalAiRunService::HIGH_PRIORITY_QUEUE, $queued->queue_name);
        $this->assertSame(100, $queued->queue_priority);
        $this->assertNotSame($queuedToken, $queued->queue_token);
        Queue::assertPushed(
            GenerateJournalAiRun::class,
            fn (GenerateJournalAiRun $job): bool => $job->runId === $queued->id
                && $job->queueToken === $queued->queue_token
                && $job->queue === JournalAiRunService::HIGH_PRIORITY_QUEUE,
        );

        $component
            ->callTableAction('cancel', $processing)
            ->assertHasNoTableActionErrors();

        $processing->refresh();
        $this->assertSame(PostAiRunStatus::Cancelled, $processing->status);
        $this->assertNotSame($processingToken, $processing->queue_token);
        $this->assertNotNull($processing->cancelled_at);
    }

    public function test_jobs_for_trashed_posts_are_visible_for_diagnosis_but_every_manage_action_fails_closed(): void
    {
        $admin = User::factory()->admin()->create();
        $post = $this->makePost();
        $run = $this->makeRun($post, $admin, PostAiRunStatus::Queued);

        $this->assertSame(1, DB::table('posts')->where('id', $post->id)->update([
            'deleted_at' => now(),
            'updated_at' => now(),
        ]));
        $this->assertTrue(Post::query()->withTrashed()->findOrFail($post->id)->trashed());
        $run->unsetRelation('post');

        Livewire::actingAs($admin)
            ->test(ManageJournalAiRuns::class)
            ->assertCanSeeTableRecords([$run])
            ->assertSee('(Trashed)')
            ->assertSee('actions are unavailable')
            ->assertTableActionHidden('openAssistant', $run)
            ->assertTableActionHidden('prioritize', $run)
            ->assertTableActionHidden('cancel', $run);

        $this->assertSame(PostAiRunStatus::Queued, $run->fresh()->status);
    }

    /** @param array<string, mixed> $attributes */
    private function makePost(array $attributes = []): Post
    {
        return Post::query()->create(array_replace([
            'title' => 'Journal AI operations post',
            'slug' => 'journal-ai-operations-'.Str::uuid(),
            'excerpt' => 'An operations test excerpt.',
            'body' => 'A complete saved body for operations testing.',
        ], $attributes));
    }

    /** @param array<string, mixed> $overrides */
    private function makeRun(
        Post $post,
        User $requester,
        PostAiRunStatus $status,
        array $overrides = [],
    ): PostAiRun {
        $run = PostAiRun::query()->create(array_replace([
            'post_id' => $post->id,
            'requester_id' => $requester->id,
            'acknowledged_by_user_id' => $requester->id,
            'acknowledged_at' => now()->subMinutes(10),
            'source_revision_id' => $post->revisions()->latest('id')->value('id'),
            'operation' => PostAiOperation::Directions,
            'status' => $status === PostAiRunStatus::Applied ? PostAiRunStatus::Ready : $status,
            'queue_token' => (string) Str::uuid(),
            'queue_name' => JournalAiRunService::DEFAULT_QUEUE,
            'queue_priority' => 0,
            'source_hash' => str_repeat('a', 64),
            'context_hash' => str_repeat('b', 64),
            'request_hash' => str_repeat('c', 64),
            'context_manifest' => [
                'selection' => ['fields' => ['title', 'body']],
                'outbound' => ['journal' => ['body' => 'Test body']],
            ],
            'external_processing' => false,
            'provider' => 'ollama',
            'model' => 'test-model',
            'normalized_endpoint' => 'http://ollama:11434',
            'provider_profile_hash' => str_repeat('d', 64),
            'credential_hmac' => null,
            'generation_options' => ['profile_version' => 1, 'timeout_seconds' => 90],
            'prompt_version' => 'prompt-v1',
            'prompt_hash' => str_repeat('e', 64),
            'schema_version' => 'schema-v1',
            'schema_hash' => str_repeat('f', 64),
            'queued_at' => now()->subMinutes(10),
        ], $overrides));

        if ($status === PostAiRunStatus::Applied) {
            DB::table('post_ai_runs')->where('id', $run->getKey())->update([
                'status' => PostAiRunStatus::Applied->value,
            ]);

            return $run->fresh();
        }

        return $run;
    }
}
