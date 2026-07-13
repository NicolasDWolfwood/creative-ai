<?php

namespace Tests\Feature;

use App\Enums\PostAiOperation;
use App\Enums\PostAiRunStatus;
use App\Filament\Resources\Posts\Pages\EditPost;
use App\Filament\Resources\Posts\Pages\ManagePostAssistant;
use App\Filament\Resources\Posts\PostResource;
use App\Jobs\GenerateJournalAiRun;
use App\Models\Post;
use App\Models\PostAiRun;
use App\Models\User;
use App\Services\AiSettings;
use App\Services\JournalAiRunPreview;
use App\Services\JournalAiRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class AdminJournalAiAssistantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->configureOllama();
    }

    public function test_assistant_page_and_post_navigation_are_administrator_only(): void
    {
        $post = $this->makePost();
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $url = PostResource::getUrl('assistant', ['record' => $post]);

        $this->get($url)->assertRedirect();
        $this->actingAs($user)->get($url)->assertForbidden();

        Livewire::actingAs($user)
            ->test(ManagePostAssistant::class, ['record' => $post->getKey()])
            ->assertForbidden();

        Livewire::actingAs($admin)
            ->test(ManagePostAssistant::class, ['record' => $post->getKey()])
            ->assertOk()
            ->assertSee('AI assistant for Saved assistant story')
            ->assertSee('Saved-post assistant runs');

        Livewire::actingAs($admin)
            ->test(EditPost::class, ['record' => $post->getKey()])
            ->assertSee('AI assistant')
            ->assertSee($url, escape: false);
    }

    public function test_request_preview_shows_and_acknowledges_only_the_exact_selected_saved_context(): void
    {
        $post = $this->makePost();
        $admin = User::factory()->admin()->create();
        $component = Livewire::actingAs($admin)
            ->test(ManagePostAssistant::class, ['record' => $post->getKey()])
            ->callAction('request_directions', [
                'fields' => ['title', 'body'],
                'include_editorial_brief' => false,
                'include_editorial_notes' => false,
                'include_tags' => false,
                'include_connected_media' => false,
                'include_connected_media_prompts' => false,
                'include_connected_media_process_notes' => false,
            ])
            ->assertHasNoActionErrors()
            ->assertActionMounted('confirmAiRequest')
            ->assertMountedActionModalSee('http://ollama.test:11434')
            ->assertMountedActionModalSee('Saved body uniquely visible in the outbound preview.')
            ->assertMountedActionModalDontSee('PRIVATE-BRIEF-MARKER')
            ->assertMountedActionModalDontSee('PRIVATE-NOTES-MARKER');

        $pending = $component->get('pendingAiRequest');

        $this->assertSame(PostAiOperation::Directions->value, $pending['operation']);
        $this->assertSame([
            'title' => 'Saved assistant story',
            'body' => 'Saved body uniquely visible in the outbound preview.',
        ], $pending['context_manifest']['outbound']['journal']);
        $this->assertSame('explicit_opt_in_required', $pending['context_manifest']['omitted_fields']['journal.editorial_brief']);
        $this->assertSame('explicit_opt_in_required', $pending['context_manifest']['omitted_fields']['journal.editorial_notes']);
        $this->assertSame('ollama', $pending['provider']);
        $this->assertSame('qwen3.5:latest', $pending['model']);
        $this->assertFalse($pending['external_processing']);

        $component
            ->callMountedAction()
            ->assertHasActionErrors(['acknowledged']);

        $this->assertDatabaseCount('post_ai_runs', 0);
        Queue::assertNothingPushed();

        $component
            ->setActionData(['acknowledged' => true])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $run = PostAiRun::query()->sole();

        $this->assertSame($pending['context_hash'], $run->context_hash);
        $this->assertSame($pending['provider_profile_hash'], $run->provider_profile_hash);
        $this->assertSame($pending['request_hash'], $run->request_hash);
        $this->assertSame($pending['context_manifest'], $run->context_manifest);
        $this->assertSame($admin->getKey(), $run->acknowledged_by_user_id);
        Queue::assertPushed(
            GenerateJournalAiRun::class,
            fn (GenerateJournalAiRun $job): bool => $job->runId === $run->getKey()
                && $job->queueToken === $run->queue_token,
        );
    }

    public function test_ready_results_are_escaped_and_unsupported_contracts_fail_closed(): void
    {
        $post = $this->makePost();
        $admin = User::factory()->admin()->create();
        $safeRun = $this->readyDirectionsRun($post, $admin, [
            'summary' => '<script>window.safeResultCompromised = true</script>',
            'directions' => [[
                'title' => '<img onerror="window.imageCompromised = true">',
                'rationale' => 'Keep literal HTML-looking text visible for editorial review.',
                'suggested_angle' => '<svg onload="window.svgCompromised = true"></svg>',
                'questions' => ['Could this remain plain text?'],
            ]],
            'claims_requiring_verification' => [[
                'claim' => '<strong>Unverified claim</strong>',
                'reason' => 'The author must verify it.',
            ]],
        ]);

        Livewire::actingAs($admin)
            ->test(ManagePostAssistant::class, ['record' => $post->getKey()])
            ->mountTableAction('viewResult', $safeRun)
            ->assertMountedActionModalSeeHtml('&lt;script&gt;window.safeResultCompromised = true&lt;/script&gt;')
            ->assertMountedActionModalSeeHtml('&lt;img onerror=&quot;window.imageCompromised = true&quot;&gt;')
            ->assertMountedActionModalSeeHtml('&lt;svg onload=&quot;window.svgCompromised = true&quot;&gt;&lt;/svg&gt;')
            ->assertMountedActionModalDontSeeHtml('<script>window.safeResultCompromised = true</script>')
            ->assertMountedActionModalDontSeeHtml('<img onerror="window.imageCompromised = true">')
            ->assertMountedActionModalDontSeeHtml('<svg onload="window.svgCompromised = true"></svg>');

        $unsupportedRun = $this->readyDirectionsRun($post, $admin, [
            'summary' => 'RAW-UNSUPPORTED-RESULT-MARKER',
            'directions' => [[
                'title' => 'Unsupported direction',
                'rationale' => 'This stored payload belongs to an unsupported contract.',
                'suggested_angle' => 'It must not be rendered.',
                'questions' => [],
            ]],
            'claims_requiring_verification' => [],
        ]);
        DB::table('post_ai_runs')
            ->where('id', $unsupportedRun->getKey())
            ->update(['prompt_hash' => str_repeat('0', 64)]);

        Livewire::actingAs($admin)
            ->test(ManagePostAssistant::class, ['record' => $post->getKey()])
            ->mountTableAction('viewResult', $unsupportedRun->fresh())
            ->assertMountedActionModalSee('Unsupported Journal AI result')
            ->assertMountedActionModalDontSee('RAW-UNSUPPORTED-RESULT-MARKER');
    }

    public function test_table_actions_cannot_substitute_a_run_from_another_post(): void
    {
        $post = $this->makePost();
        $other = Post::query()->create([
            'title' => 'Other assistant story',
            'slug' => 'other-assistant-story',
            'body' => 'Other saved body.',
        ]);
        $admin = User::factory()->admin()->create();
        $otherRun = $this->readyDirectionsRun($other, $admin, [
            'summary' => 'CROSS-POST-RESULT-MARKER',
            'directions' => [[
                'title' => 'Other post direction',
                'rationale' => 'This belongs only to the other post.',
                'suggested_angle' => 'Keep the relationship scope intact.',
                'questions' => [],
            ]],
            'claims_requiring_verification' => [],
        ]);

        Livewire::actingAs($admin)
            ->test(ManagePostAssistant::class, ['record' => $post->getKey()])
            ->mountTableAction('viewResult', $otherRun)
            ->assertTableActionNotMounted('viewResult')
            ->assertDontSee('CROSS-POST-RESULT-MARKER');
    }

    public function test_passage_preview_derives_unicode_code_point_offsets_from_one_exact_saved_match(): void
    {
        $post = $this->makePost();
        $post->update(['body' => 'Before 🌙 café after.']);
        $admin = User::factory()->admin()->create();

        $component = Livewire::actingAs($admin)
            ->test(ManagePostAssistant::class, ['record' => $post->getKey()])
            ->callAction('request_improve_passage', [
                'fields' => ['title'],
                'passage_field' => 'body',
                'passage_text' => '🌙 café',
                'include_editorial_brief' => false,
                'include_editorial_notes' => false,
                'include_tags' => false,
                'include_connected_media' => false,
                'include_connected_media_prompts' => false,
                'include_connected_media_process_notes' => false,
            ])
            ->assertHasNoActionErrors()
            ->assertActionMounted('confirmAiRequest')
            ->assertMountedActionModalSee('🌙 café');

        $pending = $component->get('pendingAiRequest');
        $start = mb_strpos($post->body, '🌙 café', 0, 'UTF-8');

        $this->assertIsInt($start);
        $this->assertSame([
            'field' => 'body',
            'start' => $start,
            'end' => $start + mb_strlen('🌙 café', 'UTF-8'),
        ], $pending['selection']['passage']);
        $this->assertSame('🌙 café', $pending['context_manifest']['outbound']['selected_passage']['content']);
    }

    public function test_regeneration_requires_a_new_preview_acknowledgement_and_creates_a_child_run(): void
    {
        $post = $this->makePost();
        $admin = User::factory()->admin()->create();
        $parent = $this->readyDirectionsRun($post, $admin, $this->ordinaryDirectionsResult());
        Queue::fake();

        $component = Livewire::actingAs($admin)
            ->test(ManagePostAssistant::class, ['record' => $post->getKey()])
            ->callTableAction('repeatRun', $parent)
            ->assertHasNoActionErrors()
            ->assertActionMounted('confirmAiRequest');

        $pending = $component->get('pendingAiRequest');

        $this->assertSame($parent->getKey(), $pending['repeat_of_id']);
        $this->assertSame($parent->operation->value, $pending['operation']);
        $this->assertDatabaseCount('post_ai_runs', 1);

        $component
            ->callMountedAction()
            ->assertHasActionErrors(['acknowledged']);

        $this->assertDatabaseCount('post_ai_runs', 1);
        Queue::assertNothingPushed();

        $component
            ->setActionData(['acknowledged' => true])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $child = PostAiRun::query()->whereKeyNot($parent->getKey())->sole();

        $this->assertSame($parent->getKey(), $child->retry_of_id);
        $this->assertSame(PostAiRunStatus::Queued, $child->status);
        $this->assertSame($pending['context_hash'], $child->context_hash);
        $this->assertSame($pending['provider_profile_hash'], $child->provider_profile_hash);
        $this->assertSame($pending['request_hash'], $child->request_hash);
        $this->assertSame($admin->getKey(), $child->acknowledged_by_user_id);
        Queue::assertPushed(
            GenerateJournalAiRun::class,
            fn (GenerateJournalAiRun $job): bool => $job->runId === $child->getKey()
                && $job->queueToken === $child->queue_token,
        );
    }

    private function configureOllama(): void
    {
        app(AiSettings::class)->save([
            'provider' => 'ollama',
            'ollama_base_url' => 'http://ollama.test:11434',
            'ollama_model' => 'qwen3.5:latest',
            'ollama_journal_model' => 'qwen3.5:latest',
            'ollama_request_timeout' => 60,
            'ollama_external_processing' => false,
            'ollama_context_length' => 8192,
            'ollama_keep_alive' => '5m',
        ]);
    }

    private function makePost(): Post
    {
        return Post::query()->create([
            'title' => 'Saved assistant story',
            'slug' => 'saved-assistant-story',
            'excerpt' => 'A saved excerpt that is not selected in the focused preview.',
            'body' => 'Saved body uniquely visible in the outbound preview.',
            'editorial_brief' => 'PRIVATE-BRIEF-MARKER',
            'editorial_notes' => 'PRIVATE-NOTES-MARKER',
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
            'include_connected_media_prompts' => false,
            'include_connected_media_process_notes' => false,
        ];
    }

    /** @param array<string, mixed> $result */
    private function readyDirectionsRun(Post $post, User $admin, array $result): PostAiRun
    {
        $run = $this->requestRun($post, $admin);
        $run->forceFill([
            'status' => PostAiRunStatus::Ready,
            'structured_result' => $result,
            'completed_at' => now(),
            'lease_expires_at' => null,
        ])->saveOrFail();

        return $run->fresh();
    }

    private function requestRun(Post $post, User $admin): PostAiRun
    {
        $service = app(JournalAiRunService::class);
        $selection = $this->selection();
        $preview = $service->preview($post, PostAiOperation::Directions, $selection, $admin);

        return $this->acknowledge($service, $post, $admin, $selection, $preview);
    }

    /** @param array<string, mixed> $selection */
    private function acknowledge(
        JournalAiRunService $service,
        Post $post,
        User $admin,
        array $selection,
        JournalAiRunPreview $preview,
    ): PostAiRun {
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

    /** @return array<string, mixed> */
    private function ordinaryDirectionsResult(): array
    {
        return [
            'summary' => 'A useful next pass.',
            'directions' => [[
                'title' => 'Focus the central experiment',
                'rationale' => 'The saved draft already contains a clear starting point.',
                'suggested_angle' => 'Explain what changed during the experiment.',
                'questions' => ['Which observation surprised the author?'],
            ]],
            'claims_requiring_verification' => [],
        ];
    }
}
