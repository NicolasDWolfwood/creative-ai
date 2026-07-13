<?php

namespace Tests\Feature;

use App\Enums\PostMediaType;
use App\Enums\PostStatus;
use App\Filament\Resources\Posts\Pages\CreatePost;
use App\Filament\Resources\Posts\Pages\EditPost;
use App\Filament\Resources\Posts\Pages\ListPosts;
use App\Filament\Resources\Posts\Pages\ManagePostConnections;
use App\Filament\Resources\Posts\Pages\ManagePostHistory;
use App\Filament\Resources\Posts\PostResource;
use App\Models\Artwork;
use App\Models\Post;
use App\Models\PostSlugRedirect;
use App\Models\Tag;
use App\Models\User;
use App\Services\PostConnectionService;
use App\Services\PostSlugRedirectService;
use App\Services\PostWorkflowService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class AdminPostHistorySafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_revision_history_is_administrator_only_and_previews_content_and_connection_context(): void
    {
        $post = $this->createPost();
        $tag = Tag::query()->create(['name' => 'history context']);
        $artwork = Artwork::withoutEvents(fn (): Artwork => Artwork::query()->create([
            'title' => 'Revision artwork',
            'slug' => 'revision-artwork',
            'image_path' => 'artworks/originals/revision.jpg',
        ]));
        $connections = app(PostConnectionService::class);
        $connections->syncTags($post, [$tag->getKey()], []);
        $connections->syncMedia($post, [[
            'type' => PostMediaType::Artwork->value,
            'id' => $artwork->getKey(),
        ]], []);
        $revision = $post->revisions()->latest('id')->firstOrFail();
        $admin = User::factory()->admin()->create();

        Livewire::actingAs(User::factory()->create())
            ->test(ManagePostHistory::class, ['record' => $post->getKey()])
            ->assertForbidden();

        Livewire::actingAs($admin)
            ->test(EditPost::class, ['record' => $post->getKey()])
            ->assertSee('History')
            ->assertSee(PostResource::getUrl('history', ['record' => $post]), escape: false);

        Livewire::actingAs($admin)
            ->test(ManagePostHistory::class, ['record' => $post->getKey()])
            ->assertOk()
            ->assertSee('Immutable revisions')
            ->assertSee('Shared tags')
            ->assertSee('Media connections')
            ->assertTableActionVisible('previewRevision', $revision)
            ->assertTableActionVisible('restoreRevision', $revision);

        $this->view('filament.posts.revision-preview', [
            'revision' => $revision->load('user'),
            'fields' => [
                'title' => 'Title',
                'excerpt' => 'Excerpt',
                'body' => 'Body',
                'cover_image_path' => 'Cover image source',
                'cover_alt_text' => 'Cover alternative text',
                'seo_title' => 'SEO title',
                'seo_description' => 'SEO description',
            ],
        ])
            ->assertSee('Connection context')
            ->assertSee('Shared tag IDs')
            ->assertSee((string) $tag->getKey())
            ->assertSee('Artwork')
            ->assertSee('ID '.$artwork->getKey())
            ->assertSee('Original revision body.');
    }

    public function test_restoring_a_revision_changes_only_the_allowlisted_content_fields(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('posts/covers/original.jpg', 'original-cover');
        Storage::disk('local')->put('posts/covers/current.jpg', 'current-cover');
        $post = $this->createPost([
            'slug' => 'original-history-slug',
            'cover_image_path' => 'posts/covers/original.jpg',
            'cover_alt_text' => 'Original cover description.',
            'editorial_brief' => 'Original private brief.',
            'editorial_notes' => 'Original private notes.',
        ]);
        $revision = $post->revisions()->oldest('id')->firstOrFail();
        $tag = Tag::query()->create(['name' => 'current connection']);

        app(PostConnectionService::class)->syncTags($post, [$tag->getKey()], []);
        $post->update([
            'title' => 'Current title',
            'excerpt' => 'Current excerpt.',
            'body' => 'Current body.',
            'cover_image_path' => 'posts/covers/current.jpg',
            'cover_alt_text' => 'Current cover description.',
            'seo_title' => 'Current SEO title',
            'seo_description' => 'Current SEO description.',
            'editorial_brief' => 'Current private brief.',
            'editorial_notes' => 'Current private notes.',
            'featured' => true,
        ]);
        $post = app(PostSlugRedirectService::class)->changeSlug($post, 'current-history-slug');
        $post = app(PostWorkflowService::class)->publishNow(
            app(PostWorkflowService::class)->markReady($post),
        );

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(ManagePostHistory::class, ['record' => $post->getKey()])
            ->callTableAction('restoreRevision', $revision, [
                'reason' => 'Restore the original public writing.',
            ])
            ->assertHasNoActionErrors();

        $post->refresh();

        $this->assertSame('Journal history story', $post->title);
        $this->assertSame('Original excerpt.', $post->excerpt);
        $this->assertSame('Original revision body.', $post->body);
        $this->assertSame('posts/covers/original.jpg', $post->cover_image_path);
        $this->assertSame('Original cover description.', $post->cover_alt_text);
        $this->assertSame('Original SEO title', $post->seo_title);
        $this->assertSame('Original SEO description.', $post->seo_description);

        $this->assertSame('current-history-slug', $post->slug);
        $this->assertSame(PostStatus::Published, $post->status);
        $this->assertTrue($post->published);
        $this->assertTrue($post->featured);
        $this->assertSame('Current private brief.', $post->editorial_brief);
        $this->assertSame('Current private notes.', $post->editorial_notes);
        $this->assertSame([$tag->getKey()], $post->tags()->pluck('tags.id')->all());
        $this->assertDatabaseHas('post_revisions', [
            'post_id' => $post->getKey(),
            'provenance' => 'revision_restore',
            'reason' => 'Restore the original public writing.',
        ]);
    }

    public function test_trash_restore_and_permanent_delete_actions_are_service_backed_and_direct_mutation_routes_fail_closed(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('posts/covers/retained.jpg', 'retained-cover');
        $post = $this->createPost([
            'slug' => 'trashed-history-story',
            'cover_image_path' => 'posts/covers/retained.jpg',
        ]);
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)
            ->test(ListPosts::class)
            ->callTableAction('delete', $post)
            ->assertHasNoActionErrors();

        $trashed = Post::onlyTrashed()->findOrFail($post->getKey());
        $this->assertSame(PostStatus::Draft, $trashed->status);
        $this->assertFalse($trashed->published);
        Storage::disk('local')->assertExists('posts/covers/retained.jpg');

        Livewire::actingAs($admin)
            ->test(EditPost::class, ['record' => $trashed->getKey()])
            ->assertForbidden();
        Livewire::actingAs($admin)
            ->test(ManagePostConnections::class, ['record' => $trashed->getKey()])
            ->assertForbidden();
        Livewire::actingAs($admin)
            ->test(ManagePostHistory::class, ['record' => $trashed->getKey()])
            ->assertOk()
            ->assertSee('Immutable revisions');

        Livewire::actingAs($admin)
            ->test(ListPosts::class)
            ->filterTable('trashed', false)
            ->assertCanSeeTableRecords([$trashed])
            ->assertTableColumnStateSet('cover_image_path', null, $trashed)
            ->assertTableActionVisible('history', $trashed)
            ->assertTableActionVisible('restore', $trashed)
            ->assertTableActionVisible('forceDelete', $trashed)
            ->assertTableActionHidden('edit', $trashed)
            ->assertTableActionHidden('preview', $trashed)
            ->callTableAction('restore', $trashed)
            ->assertHasNoActionErrors();

        $post = Post::query()->findOrFail($post->getKey());
        $this->assertSame(PostStatus::Draft, $post->status);
        $this->assertFalse($post->published);

        Livewire::actingAs($admin)
            ->test(ListPosts::class)
            ->callTableAction('delete', $post)
            ->assertHasNoActionErrors();

        $trashed = Post::onlyTrashed()->findOrFail($post->getKey());
        Livewire::actingAs($admin)
            ->test(ListPosts::class)
            ->filterTable('trashed', false)
            ->callTableAction('forceDelete', $trashed)
            ->assertHasNoActionErrors();

        $this->assertNull(Post::withTrashed()->find($post->getKey()));
        $this->assertDatabaseMissing('post_revisions', ['post_id' => $post->getKey()]);
        $this->assertDatabaseHas('post_slug_redirects', [
            'slug' => 'trashed-history-story',
            'post_id' => null,
        ]);
        Storage::disk('local')->assertExists('posts/covers/retained.jpg');
    }

    public function test_edit_page_changes_slug_through_the_redirect_service_and_reports_conflicts_inline(): void
    {
        $post = $this->createPost(['slug' => 'original-edit-slug']);
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)
            ->test(EditPost::class, ['record' => $post->getKey()])
            ->fillForm(['slug' => 'new-edit-slug'])
            ->call('save')
            ->assertHasNoFormErrors();

        $post->refresh();
        $this->assertSame('new-edit-slug', $post->slug);
        $this->assertDatabaseHas('post_slug_redirects', [
            'slug' => 'original-edit-slug',
            'post_id' => $post->getKey(),
        ]);

        PostSlugRedirect::query()->create([
            'slug' => 'reserved-edit-slug',
            'post_id' => null,
        ]);
        $titleBeforeConflict = $post->title;
        $revisionCountBeforeConflict = $post->revisions()->count();

        Livewire::actingAs($admin)
            ->test(EditPost::class, ['record' => $post->getKey()])
            ->fillForm([
                'title' => 'This title must roll back',
                'slug' => 'reserved-edit-slug',
            ])
            ->call('save')
            ->assertHasFormErrors(['slug']);

        $post->refresh();
        $this->assertSame('new-edit-slug', $post->slug);
        $this->assertSame($titleBeforeConflict, $post->title);
        $this->assertSame($revisionCountBeforeConflict, $post->revisions()->count());
    }

    public function test_a_stale_editor_tab_cannot_overwrite_any_newer_editable_post_state(): void
    {
        $post = $this->createPost([
            'editorial_notes' => 'Original private notes.',
            'featured' => false,
        ]);
        $originalSlug = (string) $post->slug;
        $admin = User::factory()->admin()->create();
        $firstTab = Livewire::actingAs($admin)
            ->test(EditPost::class, ['record' => $post->getKey()]);
        $staleTab = Livewire::actingAs($admin)
            ->test(EditPost::class, ['record' => $post->getKey()]);

        $firstTab
            ->fillForm([
                'title' => 'First tab title',
                'editorial_notes' => 'First tab private notes.',
                'featured' => true,
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->fillForm(['excerpt' => 'First tab follow-up excerpt.'])
            ->call('save')
            ->assertHasNoFormErrors();
        $revisionCountBeforeStaleSave = $post->revisions()->count();

        $staleTab
            ->fillForm([
                'body' => 'Stale tab body that must not persist.',
                'editorial_brief' => 'Stale tab private brief.',
                'slug' => 'stale-tab-slug',
            ])
            ->call('save')
            ->assertHasFormErrors(['title']);

        $post->refresh();
        $this->assertSame('First tab title', $post->title);
        $this->assertSame('First tab follow-up excerpt.', $post->excerpt);
        $this->assertSame('Original revision body.', $post->body);
        $this->assertSame('First tab private notes.', $post->editorial_notes);
        $this->assertNull($post->editorial_brief);
        $this->assertTrue($post->featured);
        $this->assertSame($originalSlug, $post->slug);
        $this->assertDatabaseMissing('post_slug_redirects', ['slug' => $originalSlug]);
        $this->assertSame($revisionCountBeforeStaleSave, $post->revisions()->count());
    }

    public function test_workflow_only_changes_do_not_create_a_false_editor_conflict(): void
    {
        $post = $this->createPost();
        $admin = User::factory()->admin()->create();
        $editor = Livewire::actingAs($admin)
            ->test(EditPost::class, ['record' => $post->getKey()]);

        app(PostWorkflowService::class)->markReady($post);

        $editor
            ->fillForm(['editorial_notes' => 'Saved after the workflow-only transition.'])
            ->call('save')
            ->assertHasNoFormErrors();

        $post->refresh();
        $this->assertSame(PostStatus::Ready, $post->status);
        $this->assertSame('Saved after the workflow-only transition.', $post->editorial_notes);
    }

    public function test_a_stale_history_tab_cannot_restore_over_a_newer_content_edit(): void
    {
        $post = $this->createPost();
        $targetRevision = $post->revisions()->oldest('id')->firstOrFail();
        $post->update([
            'title' => 'Content visible when History opened',
            'body' => 'Content visible when History opened.',
        ]);
        $admin = User::factory()->admin()->create();
        $historyTab = Livewire::actingAs($admin)
            ->test(ManagePostHistory::class, ['record' => $post->getKey()]);
        $editorTab = Livewire::actingAs($admin)
            ->test(EditPost::class, ['record' => $post->getKey()]);

        $editorTab
            ->fillForm([
                'title' => 'Newer content from the editor tab',
                'body' => 'The newer body must survive a stale restore attempt.',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $revisionCountBeforeRestore = $post->revisions()->count();
        $historyTab
            ->callTableAction('restoreRevision', $targetRevision, [
                'reason' => 'Stale restore attempt.',
            ])
            ->assertHasActionErrors(['reason']);

        $post->refresh();
        $this->assertSame('Newer content from the editor tab', $post->title);
        $this->assertSame('The newer body must survive a stale restore attempt.', $post->body);
        $this->assertSame($revisionCountBeforeRestore, $post->revisions()->count());
        $this->assertDatabaseMissing('post_revisions', [
            'post_id' => $post->getKey(),
            'reason' => 'Stale restore attempt.',
        ]);
    }

    public function test_bulk_trash_restore_and_permanent_delete_also_use_the_guarded_service(): void
    {
        $posts = new EloquentCollection([
            $this->createPost(['slug' => 'bulk-history-one']),
            $this->createPost(['slug' => 'bulk-history-two']),
        ]);
        $postIds = $posts->pluck('id')->all();
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)
            ->test(ListPosts::class)
            ->callTableBulkAction('delete', $posts)
            ->assertHasNoActionErrors();

        $trashed = Post::onlyTrashed()->whereKey($postIds)->get();
        $this->assertCount(2, $trashed);

        Livewire::actingAs($admin)
            ->test(ListPosts::class)
            ->filterTable('trashed', false)
            ->callTableBulkAction('restore', $trashed)
            ->assertHasNoActionErrors();

        $restored = Post::query()->whereKey($postIds)->get();
        $this->assertCount(2, $restored);
        $this->assertTrue($restored->every(fn (Post $post): bool => $post->status === PostStatus::Draft));

        Livewire::actingAs($admin)
            ->test(ListPosts::class)
            ->callTableBulkAction('delete', $restored)
            ->assertHasNoActionErrors();

        $trashed = Post::onlyTrashed()->whereKey($postIds)->get();
        Livewire::actingAs($admin)
            ->test(ListPosts::class)
            ->filterTable('trashed', false)
            ->callTableBulkAction('forceDelete', $trashed)
            ->assertHasNoActionErrors();

        $this->assertSame(0, Post::withTrashed()->whereKey($postIds)->count());
        $this->assertSame(2, PostSlugRedirect::query()
            ->whereIn('slug', ['bulk-history-one', 'bulk-history-two'])
            ->whereNull('post_id')
            ->count());
    }

    public function test_create_page_reports_current_and_reserved_slug_collisions_inline(): void
    {
        $existing = $this->createPost(['slug' => 'existing-create-slug']);
        PostSlugRedirect::query()->create([
            'slug' => 'reserved-create-slug',
            'post_id' => null,
        ]);
        $admin = User::factory()->admin()->create();

        foreach (['existing-create-slug', 'reserved-create-slug'] as $slug) {
            Livewire::actingAs($admin)
                ->test(CreatePost::class)
                ->fillForm([
                    'title' => 'Conflicting create '.$slug,
                    'slug' => $slug,
                    'body' => 'Draft body.',
                ])
                ->call('create')
                ->assertHasFormErrors(['slug']);

            $this->assertDatabaseMissing('posts', ['title' => 'Conflicting create '.$slug]);
        }

        $this->assertDatabaseHas('posts', ['id' => $existing->getKey()]);
    }

    /** @param array<string, mixed> $overrides */
    private function createPost(array $overrides = []): Post
    {
        return Post::query()->create(array_replace([
            'title' => 'Journal history story',
            'slug' => 'journal-history-story-'.str()->uuid(),
            'excerpt' => 'Original excerpt.',
            'body' => 'Original revision body.',
            'seo_title' => 'Original SEO title',
            'seo_description' => 'Original SEO description.',
        ], $overrides));
    }
}
