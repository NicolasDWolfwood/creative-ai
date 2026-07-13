<?php

namespace Tests\Feature;

use App\Enums\PostAiOperation;
use App\Enums\PostAiRunStatus;
use App\Enums\PostStatus;
use App\Jobs\GenerateJournalAiRun;
use App\Models\Post;
use App\Models\PostAiRun;
use App\Models\User;
use App\Services\AiSettings;
use App\Services\JournalAiApplicationService;
use App\Services\JournalAiContextBuilder;
use App\Services\JournalAiContractRegistry;
use App\Services\JournalAiRunService;
use App\Services\PostRevisionService;
use App\Services\PostSlugRedirectService;
use App\Services\PostWorkflowService;
use App\Support\CanonicalJson;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class JournalAiApplicationTest extends TestCase
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

    public function test_only_applicable_ready_results_on_draft_or_ready_posts_can_be_applied_by_an_administrator(): void
    {
        $post = $this->makePost();
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $directions = $this->readyRun(
            $post,
            $admin,
            PostAiOperation::Directions,
            $this->directionsResult(),
            $this->selection(),
        );
        $service = app(JournalAiApplicationService::class);

        $this->assertFalse($service->canApply($directions));

        try {
            $service->apply($directions, $admin, []);
            $this->fail('Editorial directions must remain read-only feedback.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('writing result', $exception->getMessage());
        }

        $outline = $this->readyRun(
            $post,
            $admin,
            PostAiOperation::Outline,
            $this->outlineResult(),
            $this->selection(),
        );
        $this->assertTrue($service->canApply($outline));

        try {
            $service->apply($outline, $user, ['mode' => 'append']);
            $this->fail('A non-administrator must not apply Journal AI writing.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(PostAiRunStatus::Ready, $outline->fresh()->status);
        $this->assertSame('Original Journal body.', $post->fresh()->body);
    }

    public function test_outline_append_is_server_rendered_and_creates_exactly_one_ai_revision_while_staling_siblings(): void
    {
        $post = $this->makePost([
            'editorial_brief' => 'Private brief.',
            'editorial_notes' => 'Private notes.',
            'featured' => true,
        ]);
        $admin = User::factory()->admin()->create();
        $run = $this->readyRun(
            $post,
            $admin,
            PostAiOperation::Outline,
            $this->outlineResult(),
            $this->selection(),
        );
        $sibling = $this->readyRun(
            $post,
            $admin,
            PostAiOperation::Metadata,
            $this->metadataResult(),
            $this->selection(['title']),
        );
        $revisionCount = $post->revisions()->count();

        $applied = app(JournalAiApplicationService::class)->apply($run, $admin, ['mode' => 'append']);
        $post->refresh();

        $expectedOutline = <<<'MARKDOWN'
# A stronger working title

Explain how the experiment changed the author's process.

## Start with the constraint

Show what made the initial approach difficult.

- Name the practical limitation.
- Connect it to the first experiment.
MARKDOWN;
        $this->assertSame("Original Journal body.\n\n{$expectedOutline}", $post->body);
        $this->assertSame($revisionCount + 1, $post->revisions()->count());
        $this->assertSame('ai_apply', $post->revisions()->firstOrFail()->provenance);
        $this->assertSame(['content.body'], $post->revisions()->firstOrFail()->changed_fields);
        $this->assertSame(PostAiRunStatus::Applied, $applied->status);
        $this->assertSame($admin->id, $applied->applied_by_user_id);
        $this->assertSame($post->revisions()->firstOrFail()->id, $applied->applied_revision_id);
        $this->assertSame(['mode' => 'append'], $applied->application_manifest['selection']);
        $this->assertSame(['body'], $applied->application_manifest['changed_fields']);
        $this->assertSame('original-journal-title', $post->slug);
        $this->assertSame(PostStatus::Draft, $post->status);
        $this->assertFalse($post->published);
        $this->assertTrue($post->featured);
        $this->assertSame('Private brief.', $post->editorial_brief);
        $this->assertSame('Private notes.', $post->editorial_notes);
        $this->assertSame(PostAiRunStatus::Stale, $sibling->fresh()->status);
        $this->assertSame('sibling_result_applied', $sibling->fresh()->stale_reason);
    }

    public function test_outline_prepend_and_selection_validation_never_accept_client_text(): void
    {
        $post = $this->makePost();
        $admin = User::factory()->admin()->create();
        $run = $this->readyRun(
            $post,
            $admin,
            PostAiOperation::Outline,
            $this->outlineResult(),
            $this->selection(),
        );

        try {
            app(JournalAiApplicationService::class)->apply($run, $admin, [
                'mode' => 'prepend',
                'body' => 'Client-controlled replacement.',
            ]);
            $this->fail('Client-provided writing must not enter an AI application patch.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('requires only', $exception->getMessage());
        }

        $this->assertSame('Original Journal body.', $post->fresh()->body);
        $this->assertSame(PostAiRunStatus::Ready, $run->fresh()->status);

        app(JournalAiApplicationService::class)->apply($run, $admin, ['mode' => 'prepend']);
        $this->assertStringStartsWith('# A stronger working title', $post->fresh()->body);
        $this->assertStringEndsWith('Original Journal body.', $post->body);
    }

    public function test_passage_improvement_uses_only_the_acknowledged_unicode_code_point_offsets(): void
    {
        $post = $this->makePost(['body' => 'Before 🌙 café after.']);
        $admin = User::factory()->admin()->create();
        $start = mb_strpos($post->body, '🌙', 0, 'UTF-8');
        $this->assertIsInt($start);
        $selection = $this->selection(['title']);
        $selection['passage'] = [
            'field' => 'body',
            'start' => $start,
            'end' => $start + mb_strlen('🌙 café', 'UTF-8'),
        ];
        $run = $this->readyRun(
            $post,
            $admin,
            PostAiOperation::ImprovePassage,
            $this->passageResult('moonlit café'),
            $selection,
        );

        try {
            app(JournalAiApplicationService::class)->apply($run, $admin, ['start' => 0]);
            $this->fail('The client must not replace the acknowledged passage offsets.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('fixed', $exception->getMessage());
        }

        app(JournalAiApplicationService::class)->apply($run, $admin, []);
        $post->refresh();
        $run->refresh();

        $this->assertSame('Before moonlit café after.', $post->body);
        $this->assertSame('body', $run->application_manifest['effect']['field']);
        $this->assertSame($start, $run->application_manifest['effect']['start']);
        $this->assertSame($start + mb_strlen('🌙 café', 'UTF-8'), $run->application_manifest['effect']['end']);
    }

    public function test_metadata_applies_only_explicit_bounded_fields_in_canonical_order(): void
    {
        $post = $this->makePost([
            'cover_alt_text' => 'Keep this cover description.',
            'seo_description' => 'Keep this SEO description.',
        ]);
        $admin = User::factory()->admin()->create();
        $run = $this->readyRun(
            $post,
            $admin,
            PostAiOperation::Metadata,
            $this->metadataResult(),
            $this->selection(['title', 'body']),
        );

        app(JournalAiApplicationService::class)->apply($run, $admin, [
            'fields' => ['seo_title', 'excerpt'],
        ]);
        $post->refresh();

        $this->assertSame('A concise suggested excerpt.', $post->excerpt);
        $this->assertSame('Suggested SEO title', $post->seo_title);
        $this->assertSame('Keep this cover description.', $post->cover_alt_text);
        $this->assertSame('Keep this SEO description.', $post->seo_description);
        $this->assertSame(
            ['excerpt', 'seo_title'],
            $run->fresh()->application_manifest['selection']['fields'],
        );
        $this->assertSame(
            ['content.excerpt', 'content.seo_title'],
            $post->revisions()->firstOrFail()->changed_fields,
        );
    }

    public function test_cover_alternative_text_supports_the_documented_five_hundred_character_limit(): void
    {
        $post = $this->makePost();
        $admin = User::factory()->admin()->create();
        $suggestion = str_repeat('a', 500);
        $run = $this->readyRun(
            $post,
            $admin,
            PostAiOperation::Metadata,
            $this->metadataResult(['cover_alt_text' => $suggestion]),
            $this->selection(['title']),
        );

        app(JournalAiApplicationService::class)->apply($run, $admin, ['fields' => ['cover_alt_text']]);

        $this->assertSame($suggestion, $post->fresh()->cover_alt_text);
    }

    public function test_metadata_rejects_unknown_duplicate_null_unchanged_and_oversized_values_atomically(): void
    {
        $admin = User::factory()->admin()->create();
        $cases = [
            [['fields' => ['slug']], $this->metadataResult(), 'not supported'],
            [['fields' => ['excerpt', 'excerpt']], $this->metadataResult(), 'only once'],
            [['fields' => ['cover_alt_text']], $this->metadataResult(['cover_alt_text' => null]), 'no cover_alt_text'],
            [['fields' => ['cover_alt_text']], $this->metadataResult(['cover_alt_text' => '   ']), 'no cover_alt_text'],
            [['fields' => ['excerpt']], $this->metadataResult(['excerpt' => 'Original Journal excerpt.']), 'already matches'],
            [['fields' => ['seo_title']], $this->metadataResult(['seo_title' => str_repeat('s', 71)]), 'too long'],
            [['fields' => ['cover_alt_text']], $this->metadataResult(['cover_alt_text' => str_repeat('a', 501)]), 'invalid length'],
        ];

        foreach ($cases as $index => [$selection, $result, $message]) {
            $post = $this->makePost(['slug' => 'metadata-rejection-'.$index]);
            $run = $this->readyRun(
                $post,
                $admin,
                PostAiOperation::Metadata,
                $result,
                $this->selection(['title']),
            );
            $before = app(PostRevisionService::class)->contentFingerprint($post);
            $revisionCount = $post->revisions()->count();

            try {
                app(JournalAiApplicationService::class)->apply($run, $admin, $selection);
                $this->fail('Invalid metadata application input must be rejected.');
            } catch (DomainException $exception) {
                $this->assertStringContainsString($message, $exception->getMessage());
            }

            $this->assertSame($before, app(PostRevisionService::class)->contentFingerprint($post->fresh()));
            $this->assertSame($revisionCount, $post->revisions()->count());
            $this->assertSame(PostAiRunStatus::Ready, $run->fresh()->status);
            $this->assertNull($run->application_manifest);
        }

        $post = $this->makePost(['slug' => 'metadata-without-applicable-suggestions']);
        $run = $this->readyRun(
            $post,
            $admin,
            PostAiOperation::Metadata,
            $this->metadataResult([
                'excerpt' => null,
                'cover_alt_text' => '   ',
                'seo_title' => $post->seo_title,
                'seo_description' => null,
            ]),
            $this->selection(['title']),
        );

        $this->assertFalse(app(JournalAiApplicationService::class)->canApply($run));
    }

    public function test_any_safe_content_change_or_contract_change_blocks_application_without_partial_state(): void
    {
        $post = $this->makePost();
        $admin = User::factory()->admin()->create();
        $run = $this->readyRun(
            $post,
            $admin,
            PostAiOperation::Metadata,
            $this->metadataResult(),
            $this->selection(['title']),
        );
        $post->update(['cover_image_path' => 'posts/covers/new-source.jpg']);
        $revisionCount = $post->revisions()->count();

        try {
            app(JournalAiApplicationService::class)->apply($run, $admin, ['fields' => ['excerpt']]);
            $this->fail('A safe-content change outside the AI context must still block application.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('writing changed', $exception->getMessage());
        }

        $this->assertSame('Original Journal excerpt.', $post->fresh()->excerpt);
        $this->assertSame($revisionCount, $post->revisions()->count());
        $this->assertSame(PostAiRunStatus::Ready, $run->fresh()->status);

        $other = $this->makePost(['slug' => 'old-contract']);
        $oldContract = $this->readyRun(
            $other,
            $admin,
            PostAiOperation::Metadata,
            $this->metadataResult(),
            $this->selection(['title']),
        );
        PostAiRun::query()->whereKey($oldContract)->update(['schema_hash' => str_repeat('0', 64)]);

        try {
            app(JournalAiApplicationService::class)->apply($oldContract->fresh(), $admin, ['fields' => ['excerpt']]);
            $this->fail('An obsolete Journal AI contract must not be applied.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('older contract', $exception->getMessage());
        }

        $this->assertSame('Original Journal excerpt.', $other->fresh()->excerpt);
    }

    public function test_private_slug_featured_and_workflow_changes_are_preserved_by_apply(): void
    {
        $post = $this->makePost();
        $admin = User::factory()->admin()->create();
        $run = $this->readyRun(
            $post,
            $admin,
            PostAiOperation::Outline,
            $this->outlineResult(),
            $this->selection(),
        );
        $post->update([
            'editorial_brief' => 'New private brief.',
            'editorial_notes' => 'New private notes.',
            'featured' => true,
        ]);
        $post = app(PostSlugRedirectService::class)->changeSlug($post, 'new-protected-slug', $admin);
        $post = app(PostWorkflowService::class)->markReady($post);

        app(JournalAiApplicationService::class)->apply($run, $admin, ['mode' => 'append']);
        $post->refresh();

        $this->assertSame('new-protected-slug', $post->slug);
        $this->assertSame(PostStatus::Ready, $post->status);
        $this->assertFalse($post->published);
        $this->assertNull($post->scheduled_at);
        $this->assertTrue($post->featured);
        $this->assertSame('New private brief.', $post->editorial_brief);
        $this->assertSame('New private notes.', $post->editorial_notes);
    }

    public function test_scheduled_and_published_posts_reject_apply_and_undo(): void
    {
        $admin = User::factory()->admin()->create();
        $workflow = app(PostWorkflowService::class);
        $post = $workflow->markReady($this->makePost());
        $run = $this->readyRun(
            $post,
            $admin,
            PostAiOperation::Outline,
            $this->outlineResult(),
            $this->selection(),
        );
        $post = $workflow->schedule($post, now()->addDay());

        $this->assertFalse(app(JournalAiApplicationService::class)->canApply($run->fresh()));

        try {
            app(JournalAiApplicationService::class)->apply($run->fresh(), $admin, ['mode' => 'append']);
            $this->fail('A scheduled post must reject AI application.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('Draft or Ready', $exception->getMessage());
        }

        $this->assertSame(PostStatus::Scheduled, $post->fresh()->status);
        $this->assertSame('Original Journal body.', $post->body);

        $publishedPost = $workflow->markReady($this->makePost(['slug' => 'published-ai-guard']));
        $publishedRun = $this->readyRun(
            $publishedPost,
            $admin,
            PostAiOperation::Outline,
            $this->outlineResult(),
            $this->selection(),
        );
        $publishedPost = $workflow->publishNow($publishedPost);

        $this->assertFalse(app(JournalAiApplicationService::class)->canApply($publishedRun->fresh()));

        try {
            app(JournalAiApplicationService::class)->apply($publishedRun->fresh(), $admin, ['mode' => 'append']);
            $this->fail('A published post must reject AI application.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('Draft or Ready', $exception->getMessage());
        }

        $this->assertSame(PostStatus::Published, $publishedPost->fresh()->status);
        $this->assertSame('Original Journal body.', $publishedPost->body);

        $undoPost = $this->makePost(['slug' => 'scheduled-ai-undo-guard']);
        $undoRun = $this->readyRun(
            $undoPost,
            $admin,
            PostAiOperation::Metadata,
            $this->metadataResult(),
            $this->selection(['title']),
        );
        $applications = app(JournalAiApplicationService::class);
        $applications->apply($undoRun, $admin, ['fields' => ['excerpt']]);
        $undoPost = $workflow->schedule($workflow->markReady($undoPost->fresh()), now()->addDays(2));

        $this->assertFalse($applications->canUndo($undoRun->fresh()));

        try {
            $applications->undo($undoRun->fresh(), $admin);
            $this->fail('A scheduled post must reject AI undo.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('Draft or Ready', $exception->getMessage());
        }

        $this->assertSame(PostStatus::Scheduled, $undoPost->fresh()->status);
        $this->assertSame('A concise suggested excerpt.', $undoPost->excerpt);

        $publishedUndoPost = $this->makePost(['slug' => 'published-ai-undo-guard']);
        $publishedUndoRun = $this->readyRun(
            $publishedUndoPost,
            $admin,
            PostAiOperation::Metadata,
            $this->metadataResult(),
            $this->selection(['title']),
        );
        $applications->apply($publishedUndoRun, $admin, ['fields' => ['excerpt']]);
        $publishedUndoPost = $workflow->publishNow($workflow->markReady($publishedUndoPost->fresh()));

        $this->assertFalse($applications->canUndo($publishedUndoRun->fresh()));

        try {
            $applications->undo($publishedUndoRun->fresh(), $admin);
            $this->fail('A published post must reject AI undo.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('Draft or Ready', $exception->getMessage());
        }

        $this->assertSame(PostStatus::Published, $publishedUndoPost->fresh()->status);
        $this->assertSame('A concise suggested excerpt.', $publishedUndoPost->excerpt);
    }

    public function test_undo_restores_the_source_revision_and_preserves_later_protected_changes(): void
    {
        $post = $this->makePost();
        $admin = User::factory()->admin()->create();
        $run = $this->readyRun(
            $post,
            $admin,
            PostAiOperation::Outline,
            $this->outlineResult(),
            $this->selection(),
        );
        $service = app(JournalAiApplicationService::class);
        $service->apply($run, $admin, ['mode' => 'append']);
        $run->refresh();
        $post->refresh();
        $post->update([
            'editorial_notes' => 'Protected notes after apply.',
            'featured' => true,
        ]);
        $post = app(PostSlugRedirectService::class)->changeSlug($post, 'undo-protected-slug', $admin);
        $post = app(PostWorkflowService::class)->markReady($post);
        $revisionCount = $post->revisions()->count();

        $this->assertTrue($service->canUndo($run->fresh()));
        $restored = $service->undo($run->fresh(), $admin);

        $this->assertSame('Original Journal body.', $restored->body);
        $this->assertSame('undo-protected-slug', $restored->slug);
        $this->assertSame(PostStatus::Ready, $restored->status);
        $this->assertTrue($restored->featured);
        $this->assertSame('Protected notes after apply.', $restored->editorial_notes);
        $this->assertSame($revisionCount + 1, $restored->revisions()->count());
        $this->assertSame('revision_restore', $restored->revisions()->firstOrFail()->provenance);
        $this->assertSame('Undid reviewed Journal AI suggestions.', $restored->revisions()->firstOrFail()->reason);
        $this->assertSame(PostAiRunStatus::Applied, $run->fresh()->status);
        $this->assertFalse($service->canUndo($run->fresh()));
    }

    public function test_undo_rejects_any_later_safe_content_change(): void
    {
        $post = $this->makePost();
        $admin = User::factory()->admin()->create();
        $run = $this->readyRun(
            $post,
            $admin,
            PostAiOperation::Metadata,
            $this->metadataResult(),
            $this->selection(['title']),
        );
        $service = app(JournalAiApplicationService::class);
        $service->apply($run, $admin, ['fields' => ['excerpt']]);
        $post->update(['seo_description' => 'A later human SEO edit.']);
        $revisionCount = $post->revisions()->count();

        $this->assertFalse($service->canUndo($run->fresh()));

        try {
            $service->undo($run->fresh(), $admin);
            $this->fail('Undo must not overwrite newer safe writing.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('newer Journal content revision', $exception->getMessage());
        }

        $this->assertSame('A concise suggested excerpt.', $post->fresh()->excerpt);
        $this->assertSame('A later human SEO edit.', $post->seo_description);
        $this->assertSame($revisionCount, $post->revisions()->count());
    }

    public function test_undo_stays_blocked_when_newer_human_edits_recreate_the_applied_content(): void
    {
        $post = $this->makePost();
        $admin = User::factory()->admin()->create();
        $run = $this->readyRun(
            $post,
            $admin,
            PostAiOperation::Metadata,
            $this->metadataResult(),
            $this->selection(['title']),
        );
        $service = app(JournalAiApplicationService::class);
        $service->apply($run, $admin, ['fields' => ['excerpt']]);
        $run->refresh();
        $post->update(['excerpt' => 'A later human rewrite.']);
        $post->update(['excerpt' => 'A concise suggested excerpt.']);

        $this->assertSame(
            app(PostRevisionService::class)->revisionContentFingerprint($run->appliedRevision),
            app(PostRevisionService::class)->contentFingerprint($post->fresh()),
        );
        $this->assertFalse($service->canUndo($run->fresh()));

        try {
            $service->undo($run->fresh(), $admin);
            $this->fail('Recreating the AI-applied values must not revive an older undo action.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('newer Journal content revision', $exception->getMessage());
        }

        $this->assertSame('A concise suggested excerpt.', $post->fresh()->excerpt);
    }

    public function test_stored_results_and_application_provenance_become_immutable(): void
    {
        $post = $this->makePost();
        $admin = User::factory()->admin()->create();
        $run = $this->readyRun(
            $post,
            $admin,
            PostAiOperation::Metadata,
            $this->metadataResult(),
            $this->selection(['title']),
        );

        try {
            $run->forceFill(['structured_result' => $this->metadataResult(['excerpt' => 'Mutated result.'])])->saveOrFail();
            $this->fail('A stored structured result must be immutable.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('result is immutable', $exception->getMessage());
        }

        $run->refresh();

        try {
            $run->forceFill(['applied_by_user_id' => $admin->getKey()])->saveOrFail();
            $this->fail('Application provenance must not be written in separate steps.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('atomic applied transition', $exception->getMessage());
        }

        $run->refresh();

        try {
            $run->forceFill(['status' => PostAiRunStatus::Applied])->saveOrFail();
            $this->fail('Applied status must not be written without complete application provenance.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('atomic applied transition', $exception->getMessage());
        }

        $forged = $run->fresh()->replicate()->forceFill([
            'status' => PostAiRunStatus::Applied,
            'queue_token' => (string) Str::uuid(),
            'request_hash' => hash('sha256', (string) Str::uuid()),
        ]);

        try {
            $forged->saveOrFail();
            $this->fail('A Journal AI run must not be created as already applied.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('created without application provenance', $exception->getMessage());
        }

        app(JournalAiApplicationService::class)->apply($run->fresh(), $admin, ['fields' => ['excerpt']]);
        $run->refresh();

        try {
            $run->forceFill(['application_manifest' => ['version' => 'mutated']])->saveOrFail();
            $this->fail('Application provenance must be immutable.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('application provenance is immutable', $exception->getMessage());
        }
    }

    public function test_assistant_migration_refuses_to_discard_application_audit_data_or_shrink_long_alt_text(): void
    {
        $post = $this->makePost();
        $admin = User::factory()->admin()->create();
        $run = $this->readyRun(
            $post,
            $admin,
            PostAiOperation::Metadata,
            $this->metadataResult(),
            $this->selection(['title']),
        );
        app(JournalAiApplicationService::class)->apply($run, $admin, ['fields' => ['excerpt']]);
        $migration = require database_path('migrations/2026_07_13_040000_add_journal_ai_assistant.php');

        try {
            $migration->down();
            $this->fail('An applied Journal AI audit manifest must block a destructive migration rollback.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('audit column', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasColumn('post_ai_runs', 'application_manifest'));

        $other = $this->makePost(['slug' => 'long-alt-rollback-guard']);
        $other->forceFill(['cover_alt_text' => str_repeat('b', 500)])->saveQuietly();
        DB::table('post_ai_runs')->whereNotNull('application_manifest')->update([
            'application_manifest' => null,
        ]);

        try {
            $migration->down();
            $this->fail('Long Journal alternative text must block a destructive column shrink.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('longer than 255', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasColumn('post_ai_runs', 'application_manifest'));
        $this->assertSame(500, mb_strlen($other->fresh()->cover_alt_text, 'UTF-8'));
    }

    public function test_regenerate_requires_fresh_acknowledgement_and_only_dismisses_a_ready_parent_after_child_creation(): void
    {
        $post = $this->makePost();
        $admin = User::factory()->admin()->create();
        $parent = $this->readyRun(
            $post,
            $admin,
            PostAiOperation::Outline,
            $this->outlineResult(),
            $this->selection(),
        );
        $service = app(JournalAiRunService::class);
        $selection = $parent->context_manifest['selection'];
        $preview = $service->preview($post, $parent->operation, $selection, $admin);

        try {
            $service->regenerate(
                $parent,
                $admin,
                str_repeat('0', 64),
                $preview->providerProfileHash,
                $preview->requestHash,
            );
            $this->fail('A stale regeneration acknowledgement must not dismiss the ready parent.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('changed', $exception->getMessage());
        }

        $this->assertSame(PostAiRunStatus::Ready, $parent->fresh()->status);
        $this->assertDatabaseCount('post_ai_runs', 1);

        $child = $service->regenerate(
            $parent->fresh(),
            $admin,
            $preview->contextHash,
            $preview->providerProfileHash,
            $preview->requestHash,
        );

        $this->assertSame($parent->id, $child->retry_of_id);
        $this->assertSame(PostAiRunStatus::Queued, $child->status);
        $parent->refresh();
        $this->assertSame(PostAiRunStatus::Dismissed, $parent->status);
        $this->assertNotNull($parent->dismissed_at);
        Queue::assertPushed(
            GenerateJournalAiRun::class,
            fn (GenerateJournalAiRun $job): bool => $job->runId === $child->id,
        );
    }

    public function test_regenerate_from_an_applied_result_keeps_the_parent_applied(): void
    {
        $post = $this->makePost();
        $admin = User::factory()->admin()->create();
        $parent = $this->readyRun(
            $post,
            $admin,
            PostAiOperation::Metadata,
            $this->metadataResult(),
            $this->selection(['title']),
        );
        app(JournalAiApplicationService::class)->apply($parent, $admin, ['fields' => ['excerpt']]);
        $parent->refresh();
        $service = app(JournalAiRunService::class);
        $selection = $parent->context_manifest['selection'];
        $preview = $service->preview($post->fresh(), $parent->operation, $selection, $admin);
        $child = $service->regenerate(
            $parent,
            $admin,
            $preview->contextHash,
            $preview->providerProfileHash,
            $preview->requestHash,
        );

        $this->assertSame(PostAiRunStatus::Applied, $parent->fresh()->status);
        $this->assertSame($parent->id, $child->retry_of_id);
        $this->assertSame(PostAiRunStatus::Queued, $child->status);
    }

    /** @param array<string, mixed> $overrides */
    private function makePost(array $overrides = []): Post
    {
        return Post::query()->create(array_replace([
            'title' => 'Original Journal title',
            'slug' => 'original-journal-title',
            'excerpt' => 'Original Journal excerpt.',
            'body' => 'Original Journal body.',
            'cover_alt_text' => 'Original cover description.',
            'seo_title' => 'Original SEO title',
            'seo_description' => 'Original SEO description.',
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $selection
     */
    private function readyRun(
        Post $post,
        User $admin,
        PostAiOperation $operation,
        array $result,
        array $selection,
    ): PostAiRun {
        $context = app(JournalAiContextBuilder::class)->build($post, $operation, $selection);
        $contract = app(JournalAiContractRegistry::class)->for($operation);

        return PostAiRun::query()->create([
            'post_id' => $post->getKey(),
            'requester_id' => $admin->getKey(),
            'source_revision_id' => $post->revisions()->latest('id')->value('id'),
            'acknowledged_by_user_id' => $admin->getKey(),
            'operation' => $operation,
            'status' => PostAiRunStatus::Ready,
            'queue_token' => (string) Str::uuid(),
            'queue_name' => JournalAiRunService::DEFAULT_QUEUE,
            'queue_priority' => 0,
            'source_hash' => $context->sourceHash,
            'context_hash' => $context->contextHash,
            'request_hash' => CanonicalJson::hash([
                'post_id' => $post->getKey(),
                'operation' => $operation->value,
                'nonce' => (string) Str::uuid(),
            ]),
            'context_manifest' => $context->manifest,
            'external_processing' => false,
            'acknowledged_at' => now(),
            'provider' => 'ollama',
            'model' => 'qwen3.5:latest',
            'normalized_endpoint' => 'http://ollama.test:11434',
            'provider_profile_hash' => str_repeat('a', 64),
            'credential_hmac' => null,
            'generation_options' => [],
            'prompt_version' => $contract->promptVersion,
            'prompt_hash' => $contract->promptHash(),
            'schema_version' => $contract->schemaVersion,
            'schema_hash' => $contract->schemaHash(),
            'structured_result' => $result,
            'queued_at' => now()->subSecond(),
            'started_at' => now()->subSecond(),
            'completed_at' => now(),
        ]);
    }

    /** @param list<string> $fields */
    private function selection(array $fields = ['title', 'excerpt', 'body']): array
    {
        return [
            'fields' => $fields,
            'include_editorial_brief' => false,
            'include_editorial_notes' => false,
            'include_tags' => false,
            'include_connected_media' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function directionsResult(): array
    {
        return [
            'summary' => 'A useful next pass.',
            'directions' => [[
                'title' => 'Focus the experiment',
                'rationale' => 'The draft has a clear starting point.',
                'suggested_angle' => 'Explain what changed.',
                'questions' => ['What surprised the author?'],
            ]],
            'claims_requiring_verification' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function outlineResult(): array
    {
        return [
            'working_title' => 'A stronger working title',
            'thesis' => "Explain how the experiment changed the author's process.",
            'sections' => [[
                'heading' => 'Start with the constraint',
                'purpose' => 'Show what made the initial approach difficult.',
                'key_points' => [
                    'Name the practical limitation.',
                    'Connect it to the first experiment.',
                ],
            ]],
            'claims_requiring_verification' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function passageResult(string $replacement): array
    {
        return [
            'replacement_markdown' => $replacement,
            'rationale' => 'The replacement is more specific.',
            'preserved_meaning' => true,
            'claims_requiring_verification' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function metadataResult(array $overrides = []): array
    {
        return array_replace([
            'excerpt' => 'A concise suggested excerpt.',
            'cover_alt_text' => 'A suggested cover description.',
            'seo_title' => 'Suggested SEO title',
            'seo_description' => 'A suggested search and sharing description.',
            'rationale' => ['Each value is more specific.'],
            'claims_requiring_verification' => [],
        ], $overrides);
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
}
