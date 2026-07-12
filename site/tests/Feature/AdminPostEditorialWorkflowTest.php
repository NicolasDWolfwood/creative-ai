<?php

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Filament\Resources\Posts\Pages\CreatePost;
use App\Filament\Resources\Posts\Pages\EditPost;
use App\Filament\Resources\Posts\Pages\ListPosts;
use App\Models\Post;
use App\Models\User;
use Filament\Forms\Components\MarkdownEditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class AdminPostEditorialWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_administrators_receive_post_editor_and_workflow_abilities(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $post = $this->createPost();
        $abilities = [
            'view',
            'update',
            'delete',
            'preview',
            'markReady',
            'revertToDraft',
            'schedule',
            'publishNow',
            'cancelSchedule',
            'unpublish',
        ];

        $this->assertTrue(Gate::forUser($admin)->allows('viewAny', Post::class));
        $this->assertTrue(Gate::forUser($admin)->allows('create', Post::class));
        $this->assertFalse(Gate::forUser($user)->allows('viewAny', Post::class));
        $this->assertFalse(Gate::forUser($user)->allows('create', Post::class));

        foreach ($abilities as $ability) {
            $this->assertTrue(Gate::forUser($admin)->allows($ability, $post), $ability.' should be allowed for an administrator.');
            $this->assertFalse(Gate::forUser($user)->allows($ability, $post), $ability.' should be denied for a non-administrator.');
        }
    }

    public function test_post_form_saves_an_incomplete_draft_without_exposing_lifecycle_fields(): void
    {
        $component = Livewire::actingAs(User::factory()->admin()->create())
            ->test(CreatePost::class)
            ->fillForm([
                'title' => 'A title-only story idea',
                'body' => null,
                'editorial_brief' => 'Private story direction.',
                'editorial_notes' => 'Private research note.',
            ]);

        $fields = collect($component->instance()->form->getFlatComponents(withHidden: true))
            ->filter(fn ($field): bool => method_exists($field, 'getName'))
            ->keyBy(fn ($field): string => $field->getName());

        $this->assertInstanceOf(MarkdownEditor::class, $fields->get('body'));
        $this->assertFalse($fields->get('body')->isRequired());
        $this->assertTrue($fields->has('editorial_brief'));
        $this->assertTrue($fields->has('editorial_notes'));
        $this->assertTrue($fields->has('cover_alt_text'));
        $this->assertFalse($fields->has('status'));
        $this->assertFalse($fields->has('scheduled_at'));
        $this->assertFalse($fields->has('published'));
        $this->assertFalse($fields->has('published_at'));

        $component->call('create')->assertHasNoFormErrors();

        $post = Post::query()->where('title', 'A title-only story idea')->firstOrFail();

        $this->assertSame('', $post->body);
        $this->assertSame(PostStatus::Draft, $post->status);
        $this->assertFalse($post->published);
        $this->assertSame('Private story direction.', $post->editorial_brief);
        $this->assertSame('Private research note.', $post->editorial_notes);
        $this->get(route('admin.posts.preview', $post))
            ->assertOk()
            ->assertSee('1 min read');
    }

    public function test_admin_table_transitions_a_saved_post_without_direct_publication_edits(): void
    {
        $admin = User::factory()->admin()->create();
        $post = $this->createPost();
        $component = Livewire::actingAs($admin)->test(ListPosts::class);

        $component
            ->assertTableActionVisible('preview', $post)
            ->assertTableActionVisible('readiness', $post)
            ->assertTableActionVisible('markReady', $post)
            ->assertTableActionHidden('publishNow', $post)
            ->callTableAction('markReady', $post)
            ->assertHasNoActionErrors();

        $post->refresh();
        $this->assertSame(PostStatus::Ready, $post->status);
        $this->assertFalse($post->published);

        $scheduledAt = now()->addDay()->startOfMinute();
        $component
            ->assertTableActionVisible('schedule', $post)
            ->assertTableActionVisible('publishNow', $post)
            ->callTableAction('schedule', $post, ['scheduled_at' => $scheduledAt->toDateTimeString()])
            ->assertHasNoActionErrors();

        $post->refresh();
        $this->assertSame(PostStatus::Scheduled, $post->status);
        $this->assertTrue($post->published);
        $this->assertTrue($post->scheduled_at->equalTo($scheduledAt));

        $component
            ->assertTableActionVisible('reschedule', $post)
            ->assertTableActionVisible('cancelSchedule', $post)
            ->callTableAction('cancelSchedule', $post)
            ->assertHasNoActionErrors();

        $post->refresh();
        $this->assertSame(PostStatus::Ready, $post->status);
        $this->assertFalse($post->published);
        $this->assertNull($post->scheduled_at);

        $component
            ->callTableAction('publishNow', $post)
            ->assertHasNoActionErrors();

        $post->refresh();
        $this->assertSame(PostStatus::Published, $post->status);
        $this->assertTrue($post->isPubliclyPublishedAt());

        $component
            ->assertTableActionVisible('unpublish', $post)
            ->callTableAction('unpublish', $post)
            ->assertHasNoActionErrors();

        $post->refresh();
        $this->assertSame(PostStatus::Ready, $post->status);
        $this->assertFalse($post->published);
    }

    public function test_edit_page_exposes_saved_preview_readiness_and_workflow_controls(): void
    {
        $post = $this->createPost();

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(EditPost::class, ['record' => $post->getKey()])
            ->assertOk()
            ->assertActionVisible('preview')
            ->assertActionVisible('readiness')
            ->assertActionVisible('markReady');
    }

    public function test_preview_is_private_saved_state_and_never_renders_editorial_notes(): void
    {
        $post = $this->createPost([
            'title' => 'Private preview story',
            'body' => "Visible preview copy.\n\n<script>window.previewCompromised = true</script>\n\n[Unsafe](javascript:alert('no'))",
            'editorial_brief' => 'SECRET-BRIEF-CONTENT',
            'editorial_notes' => 'SECRET-NOTES-CONTENT',
        ]);

        $this->get(route('admin.posts.preview', $post))->assertNotFound();
        $this->actingAs(User::factory()->create())
            ->get(route('admin.posts.preview', $post))
            ->assertNotFound();

        $response = $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.posts.preview', $post));

        $response
            ->assertOk()
            ->assertSee('Private preview — not publicly available')
            ->assertSee('Stored status: Draft.')
            ->assertSee('Effective status: Draft.')
            ->assertSee('Private preview story')
            ->assertSee('<meta name="robots" content="noindex,nofollow,noarchive">', false)
            ->assertDontSee('SECRET-BRIEF-CONTENT')
            ->assertDontSee('SECRET-NOTES-CONTENT')
            ->assertDontSee('<link rel="canonical"', false)
            ->assertDontSee('rel="alternate" type="application/rss+xml"', false)
            ->assertDontSee('property="og:url"', false)
            ->assertDontSee('application/ld+json', false)
            ->assertDontSee('<script>window.previewCompromised = true</script>', false)
            ->assertDontSee('href="javascript:', false)
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->assertHeader('Referrer-Policy', 'no-referrer');

        $cacheControl = (string) $response->headers->get('Cache-Control');

        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-cache', $cacheControl);
    }

    public function test_status_badge_and_filter_treat_a_due_schedule_as_effectively_published(): void
    {
        $dueSchedule = $this->createPost(['title' => 'Due scheduled story']);
        $futureSchedule = $this->createPost(['title' => 'Future scheduled story']);
        $published = $this->createPost(['title' => 'Normally published story']);
        $dueAt = now()->subMinute();

        $dueSchedule->forceFill([
            'status' => PostStatus::Scheduled,
            'scheduled_at' => $dueAt,
            'published' => true,
            'published_at' => $dueAt,
        ])->saveQuietly();
        $futureAt = now()->addDay();
        $futureSchedule->forceFill([
            'status' => PostStatus::Scheduled,
            'scheduled_at' => $futureAt,
            'published' => true,
            'published_at' => $futureAt,
        ])->saveQuietly();
        $published->forceFill([
            'status' => PostStatus::Published,
            'scheduled_at' => null,
            'published' => true,
            'published_at' => now()->subHour(),
        ])->saveQuietly();

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(ListPosts::class)
            ->assertTableColumnStateSet('workflow_status', PostStatus::Published, $dueSchedule)
            ->assertSee('Published (scheduled)')
            ->filterTable('workflow_status', PostStatus::Published->value)
            ->assertCanSeeTableRecords([$dueSchedule, $published])
            ->assertCanNotSeeTableRecords([$futureSchedule]);
    }

    /** @param array<string, mixed> $overrides */
    private function createPost(array $overrides = []): Post
    {
        return Post::query()->create(array_replace([
            'title' => 'Journal workflow story',
            'excerpt' => 'A concise public summary.',
            'body' => 'Visible journal content.',
            'seo_title' => 'Journal workflow story',
            'seo_description' => 'A page-specific description.',
        ], $overrides));
    }
}
