<?php

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Services\PostReadiness;
use App\Services\PostWorkflowService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PostLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_public_status_matrix_and_latest_order_fail_closed(): void
    {
        $now = CarbonImmutable::parse('2026-07-12 12:00:00');
        CarbonImmutable::setTestNow($now);

        $draft = $this->makePost('draft');
        $ready = $this->makePost('ready');
        $scheduled = $this->makePost('scheduled');
        $due = $this->makePost('due');
        $published = $this->makePost('published');
        $invalidPublished = $this->makePost('invalid-published');
        $invalidSchedule = $this->makePost('invalid-schedule');
        $invalidReady = $this->makePost('invalid-ready');

        $this->setLifecycle($ready, PostStatus::Ready, false);
        $this->setLifecycle($scheduled, PostStatus::Scheduled, true, $now->addHour(), $now->addHour());
        $this->setLifecycle($due, PostStatus::Scheduled, true, $now, $now);
        $this->setLifecycle($published, PostStatus::Published, true, null, $now->subHour());
        $this->setLifecycle($invalidPublished, PostStatus::Published, false, null, $now->subHour());
        $this->setLifecycle($invalidSchedule, PostStatus::Scheduled, true, $now->subHour(), $now->subMinutes(30));
        $this->setLifecycle($invalidReady, PostStatus::Ready, true, null, $now->subHour());

        $this->assertSame(PostStatus::Draft, $draft->refresh()->effectiveStatusAt());
        $this->assertSame(PostStatus::Ready, $ready->refresh()->effectiveStatusAt());
        $this->assertSame(PostStatus::Scheduled, $scheduled->refresh()->effectiveStatusAt());
        $this->assertSame(PostStatus::Published, $due->refresh()->effectiveStatusAt());
        $this->assertSame(PostStatus::Published, $published->refresh()->effectiveStatusAt());
        $this->assertSame(PostStatus::Draft, $invalidPublished->refresh()->effectiveStatusAt());
        $this->assertSame(PostStatus::Draft, $invalidSchedule->refresh()->effectiveStatusAt());
        $this->assertSame(PostStatus::Draft, $invalidReady->refresh()->effectiveStatusAt());

        $this->assertSame(
            [$due->id, $published->id],
            Post::query()->latestPublished()->pluck('id')->all(),
        );
        $this->assertFalse($scheduled->refresh()->isPubliclyPublishedAt());
        $this->assertTrue($due->refresh()->isPubliclyPublishedAt());
        $this->assertTrue($published->refresh()->isPubliclyPublishedAt());
    }

    public function test_scheduled_publication_becomes_public_at_the_exact_due_boundary(): void
    {
        $now = CarbonImmutable::parse('2026-07-12 12:00:00');
        CarbonImmutable::setTestNow($now);
        $post = $this->makePost('boundary');
        $workflow = app(PostWorkflowService::class);
        $ready = $workflow->markReady($post);
        $dueAt = $now->addHour();
        $scheduled = $workflow->schedule($ready, $dueAt);

        $this->assertSame(PostStatus::Scheduled, $scheduled->status);
        $this->assertFalse($scheduled->isPubliclyPublishedAt($dueAt->subMicrosecond()));
        $this->assertSame(PostStatus::Scheduled, $scheduled->effectiveStatusAt($dueAt->subMicrosecond()));
        $this->assertTrue($scheduled->isPubliclyPublishedAt($dueAt));
        $this->assertSame(PostStatus::Published, $scheduled->effectiveStatusAt($dueAt));
        $this->assertTrue(Post::query()->published($dueAt)->whereKey($post)->exists());
        $this->assertSame($dueAt->toDateTimeString(), $scheduled->effectivePublishedAt($dueAt)?->toDateTimeString());
    }

    public function test_explicit_workflow_transitions_keep_the_legacy_mirror_safe(): void
    {
        $now = CarbonImmutable::parse('2026-07-12 12:00:00');
        CarbonImmutable::setTestNow($now);
        $workflow = app(PostWorkflowService::class);
        $post = $this->makePost('workflow');
        $contentUpdatedAt = $post->public_content_updated_at;

        $ready = $workflow->markReady($post);
        $this->assertSame(PostStatus::Ready, $ready->status);
        $this->assertFalse($ready->published);
        $this->assertNull($ready->scheduled_at);
        $this->assertNull($ready->published_at);

        $scheduledAt = $now->addDay();
        $scheduled = $workflow->schedule($ready, $scheduledAt);
        $this->assertSame(PostStatus::Scheduled, $scheduled->status);
        $this->assertTrue($scheduled->published);
        $this->assertTrue($scheduled->scheduled_at->equalTo($scheduledAt));
        $this->assertTrue($scheduled->published_at->equalTo($scheduledAt));

        $cancelled = $workflow->cancelSchedule($scheduled);
        $this->assertSame(PostStatus::Ready, $cancelled->status);
        $this->assertFalse($cancelled->published);
        $this->assertNull($cancelled->scheduled_at);
        $this->assertNull($cancelled->published_at);

        CarbonImmutable::setTestNow($now->addHour());
        $published = $workflow->publishNow($cancelled);
        $this->assertSame(PostStatus::Published, $published->status);
        $this->assertTrue($published->published);
        $this->assertNull($published->scheduled_at);
        $this->assertTrue($published->published_at->equalTo(now()));
        $this->assertTrue($published->isPubliclyPublishedAt());

        $unpublished = $workflow->unpublish($published);
        $this->assertSame(PostStatus::Ready, $unpublished->status);
        $this->assertFalse($unpublished->published);
        $this->assertNull($unpublished->scheduled_at);
        $this->assertTrue($unpublished->published_at->equalTo($published->published_at));
        $this->assertFalse($unpublished->isPubliclyPublishedAt());

        $draft = $workflow->revertToDraft($unpublished);
        $this->assertSame(PostStatus::Draft, $draft->status);
        $this->assertFalse($draft->published);
        $this->assertTrue($draft->published_at->equalTo($published->published_at));
        $this->assertTrue($draft->public_content_updated_at->equalTo($contentUpdatedAt));
    }

    public function test_due_schedule_must_be_unpublished_and_cannot_be_rescheduled_or_cancelled(): void
    {
        $now = CarbonImmutable::parse('2026-07-12 12:00:00');
        CarbonImmutable::setTestNow($now);
        $workflow = app(PostWorkflowService::class);
        $post = $workflow->schedule(
            $workflow->markReady($this->makePost('due-workflow')),
            $now->addHour(),
        );

        CarbonImmutable::setTestNow($now->addHour());

        foreach (['cancel', 'reschedule'] as $operation) {
            try {
                $operation === 'cancel'
                    ? $workflow->cancelSchedule($post)
                    : $workflow->schedule($post, now()->addHour());
                $this->fail("The due schedule should not allow {$operation}.");
            } catch (DomainException) {
                $this->assertSame(PostStatus::Scheduled, $post->refresh()->status);
                $this->assertTrue($post->isPubliclyPublishedAt());
            }
        }

        $unpublished = $workflow->unpublish($post);
        $this->assertSame(PostStatus::Ready, $unpublished->status);
        $this->assertFalse($unpublished->published);
        $this->assertTrue($unpublished->published_at->equalTo($now->addHour()));
    }

    public function test_workflow_rejects_malformed_stored_state_without_partially_repairing_it(): void
    {
        $now = CarbonImmutable::parse('2026-07-12 12:00:00');
        CarbonImmutable::setTestNow($now);
        $workflow = app(PostWorkflowService::class);
        $post = $this->makePost('malformed-workflow');
        $this->setLifecycle($post, PostStatus::Scheduled, true, $now->addHour(), $now->addHours(2));

        foreach (['publish', 'cancel'] as $operation) {
            try {
                $operation === 'publish'
                    ? $workflow->publishNow($post)
                    : $workflow->cancelSchedule($post);
                $this->fail("Malformed state should not allow {$operation}.");
            } catch (DomainException) {
                $post->refresh();
                $this->assertSame(PostStatus::Scheduled, $post->status);
                $this->assertTrue($post->published);
                $this->assertTrue($post->scheduled_at->equalTo($now->addHour()));
                $this->assertTrue($post->published_at->equalTo($now->addHours(2)));
                $this->assertFalse($post->isPubliclyPublishedAt($now->addHours(3)));
            }
        }
    }

    public function test_ready_republication_uses_now_while_normalizing_a_due_schedule_preserves_its_instant(): void
    {
        $now = CarbonImmutable::parse('2026-07-12 12:00:00');
        CarbonImmutable::setTestNow($now);
        $workflow = app(PostWorkflowService::class);

        $published = $workflow->publishNow($workflow->markReady($this->makePost('republish')));
        $ready = $workflow->unpublish($published);
        CarbonImmutable::setTestNow($now->addDay());
        $republished = $workflow->publishNow($ready);
        $this->assertTrue($republished->published_at->equalTo(now()));

        $scheduled = $workflow->schedule(
            $workflow->markReady($this->makePost('normalize-due')),
            now()->addHour(),
        );
        $scheduledAt = $scheduled->scheduled_at;
        CarbonImmutable::setTestNow($scheduledAt);
        $normalized = $workflow->publishNow($scheduled);
        $this->assertSame(PostStatus::Published, $normalized->status);
        $this->assertTrue($normalized->published_at->equalTo($scheduledAt));
    }

    public function test_readiness_uses_safe_visible_markdown_and_rechecks_the_locked_record_atomically(): void
    {
        $readiness = app(PostReadiness::class);
        $invisible = new Post([
            'title' => 'Invisible',
            'slug' => 'invisible',
            'body' => "![Alt text](cover.jpg)\n\n<script>alert('no')</script><!-- note -->",
        ]);
        $report = $readiness->evaluate($invisible);

        $this->assertArrayHasKey('body', $report->blockers());
        $this->assertArrayHasKey('excerpt', $report->warnings());
        $this->assertArrayHasKey('seo_title', $report->warnings());
        $this->assertArrayHasKey('seo_description', $report->warnings());
        $this->assertFalse($report->isReady());

        $visible = new Post([
            'title' => 'Visible',
            'slug' => 'visible',
            'body' => '[A useful account](https://example.test)',
            'cover_image_path' => 'posts/cover.jpg',
        ]);
        $visibleReport = $readiness->evaluate($visible);
        $this->assertTrue($visibleReport->isReady());
        $this->assertArrayHasKey('cover_alt_text', $visibleReport->warnings());

        $post = $this->makePost('atomic');
        DB::table('posts')->where('id', $post->id)->update(['body' => '![Only image](cover.jpg)']);

        try {
            app(PostWorkflowService::class)->markReady($post);
            $this->fail('A stale, no-longer-ready post must not transition.');
        } catch (DomainException) {
            $post->refresh();
            $this->assertSame(PostStatus::Draft, $post->status);
            $this->assertFalse($post->published);
            $this->assertNull($post->scheduled_at);
        }
    }

    public function test_lifecycle_is_not_mass_assignable_and_private_editorial_context_is_hidden(): void
    {
        $post = Post::query()->create([
            'title' => 'Private notes',
            'slug' => 'private-notes',
            'body' => 'Public body.',
            'editorial_brief' => 'Private brief.',
            'editorial_notes' => 'Private notes.',
            'status' => PostStatus::Published,
            'published' => true,
            'published_at' => now(),
            'scheduled_at' => now(),
            'public_content_updated_at' => now()->subYear(),
        ]);

        $this->assertSame(PostStatus::Draft, $post->status);
        $this->assertFalse($post->published);
        $this->assertNull($post->published_at);
        $this->assertNull($post->scheduled_at);
        $this->assertSame('Private brief.', $post->editorial_brief);
        $this->assertSame('Private notes.', $post->editorial_notes);
        $this->assertArrayNotHasKey('editorial_brief', $post->toArray());
        $this->assertArrayNotHasKey('editorial_notes', $post->toArray());
    }

    public function test_only_public_content_changes_advance_the_public_content_timestamp_without_changing_status(): void
    {
        $createdAt = CarbonImmutable::parse('2026-07-12 10:00:00');
        CarbonImmutable::setTestNow($createdAt);
        $post = $this->makePost('content-timestamp');
        $this->assertTrue($post->public_content_updated_at->equalTo($createdAt));

        CarbonImmutable::setTestNow($createdAt->addHour());
        $post->update(['editorial_brief' => 'A private brief.', 'editorial_notes' => 'Private notes.']);
        $this->assertTrue($post->refresh()->public_content_updated_at->equalTo($createdAt));

        $ready = app(PostWorkflowService::class)->markReady($post);
        $this->assertSame(PostStatus::Ready, $ready->status);
        $this->assertTrue($ready->public_content_updated_at->equalTo($createdAt));

        CarbonImmutable::setTestNow($createdAt->addHours(2));
        $ready->update(['body' => 'A revised public body.']);
        $this->assertSame(PostStatus::Ready, $ready->refresh()->status);
        $this->assertTrue($ready->public_content_updated_at->equalTo(now()));

        CarbonImmutable::setTestNow($createdAt->addHours(3));
        $ready->public_content_updated_at = now();
        $ready->save();
        $this->assertTrue($ready->refresh()->public_content_updated_at->equalTo($createdAt->addHours(2)));

        CarbonImmutable::setTestNow($createdAt->addHours(4));
        $ready->update(['slug' => 'content-timestamp-updated']);
        $this->assertTrue($ready->refresh()->public_content_updated_at->equalTo(now()));

        CarbonImmutable::setTestNow($createdAt->addHours(5));
        $published = app(PostWorkflowService::class)->publishNow($ready);
        $this->assertTrue($published->effectivePublicContentUpdatedAt()->equalTo($published->published_at));

        CarbonImmutable::setTestNow($createdAt->addHours(6));
        $published->update(['seo_title' => 'Updated public SEO title']);
        $published->refresh();
        $this->assertSame(PostStatus::Published, $published->status);
        $this->assertTrue($published->effectivePublicContentUpdatedAt()->equalTo(now()));
    }

    private function makePost(string $slug): Post
    {
        return Post::query()->create([
            'title' => ucfirst(str_replace('-', ' ', $slug)),
            'slug' => $slug,
            'body' => 'A visible journal story.',
        ]);
    }

    private function setLifecycle(
        Post $post,
        PostStatus $status,
        bool $published,
        ?CarbonImmutable $scheduledAt = null,
        ?CarbonImmutable $publishedAt = null,
    ): void {
        DB::table('posts')->where('id', $post->id)->update([
            'status' => $status->value,
            'published' => $published,
            'scheduled_at' => $scheduledAt,
            'published_at' => $publishedAt,
        ]);
    }
}
