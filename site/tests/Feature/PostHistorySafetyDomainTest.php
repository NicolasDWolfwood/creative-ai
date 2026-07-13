<?php

namespace Tests\Feature;

use App\Enums\PostMediaType;
use App\Enums\PostStatus;
use App\Models\Artwork;
use App\Models\Collection;
use App\Models\Post;
use App\Models\PostSlugRedirect;
use App\Models\Tag;
use App\Services\JournalDraftPlanningService;
use App\Services\PostConnectionService;
use App\Services\PostRevisionService;
use App\Services\PostSlugRedirectService;
use App\Services\PostWorkflowService;
use App\Services\StoryOpportunityService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Tests\TestCase;

class PostHistorySafetyDomainTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
        Queue::fake();
    }

    public function test_safe_content_and_connection_changes_create_deduplicated_private_revisions(): void
    {
        $post = $this->makePost([
            'editorial_brief' => 'Private brief',
            'editorial_notes' => 'Private notes',
        ]);

        $this->assertCount(1, $post->revisions);
        $initial = $post->revisions()->firstOrFail();
        $encoded = json_encode($initial->snapshot, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('slug', $encoded);
        $this->assertStringNotContainsString('status', $encoded);
        $this->assertStringNotContainsString('published', $encoded);
        $this->assertStringNotContainsString('scheduled', $encoded);
        $this->assertStringNotContainsString('featured', $encoded);
        $this->assertStringNotContainsString('Private brief', $encoded);
        $this->assertStringNotContainsString('Private notes', $encoded);

        try {
            $initial->update(['reason' => 'Mutated history']);
            $this->fail('Existing Journal revisions must not be editable.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('immutable', $exception->getMessage());
        }

        $initial->refresh();

        try {
            $initial->delete();
            $this->fail('Existing Journal revisions must not be individually deletable.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('immutable', $exception->getMessage());
        }

        $post->update([
            'editorial_brief' => 'Changed private brief',
            'editorial_notes' => 'Changed private notes',
            'featured' => true,
        ]);
        $this->assertSame(1, $post->revisions()->count());

        $post->update(['title' => 'A revised public title']);
        $this->assertSame(2, $post->revisions()->count());
        $this->assertSame(
            ['content.title'],
            $post->revisions()->firstOrFail()->changed_fields,
        );

        $tag = Tag::query()->create(['name' => 'History', 'slug' => 'history']);
        $artwork = $this->artwork('history-artwork');
        $connections = app(PostConnectionService::class);
        $connections->syncTags($post, [$tag->id]);
        $connections->syncMedia($post, [['type' => 'artwork', 'id' => $artwork->id]]);

        $this->assertSame(4, $post->revisions()->count());
        $connectionRevision = $post->revisions()->firstOrFail();
        $this->assertSame('connections_update', $connectionRevision->provenance);
        $this->assertSame([$tag->id], $connectionRevision->snapshot['tag_ids']);
        $this->assertSame([
            ['position' => 1, 'type' => 'artwork', 'id' => $artwork->id],
        ], $connectionRevision->snapshot['media']);

        $connections->syncTags($post, [$tag->id]);
        $connections->syncMedia($post, [['type' => 'artwork', 'id' => $artwork->id]]);
        $this->assertSame(4, $post->revisions()->count());

        $collection = Collection::query()->create([
            'title' => 'Malformed audit collection',
            'slug' => 'malformed-audit-collection',
        ]);
        $malformedArtwork = $this->artwork('malformed-audit-artwork');
        DB::table('post_media')->insert([
            'post_id' => $post->id,
            'position' => 2,
            'artwork_id' => $malformedArtwork->id,
            'collection_id' => $collection->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $post->update(['excerpt' => 'Changed after malformed legacy media appeared.']);
        $invalid = $post->revisions()->firstOrFail()->snapshot['media'][1];
        $this->assertTrue($invalid['invalid']);
        $this->assertSame('invalid', $invalid['type']);
        $this->assertSame([
            'artwork_id' => $malformedArtwork->id,
            'collection_id' => $collection->id,
        ], $invalid['references']);
    }

    public function test_planning_created_drafts_finish_with_a_revision_of_the_source_connection(): void
    {
        $artwork = $this->artwork('planning-history-source');

        $post = app(JournalDraftPlanningService::class)->createFromPublicSource($artwork);

        $this->assertSame(PostStatus::Draft, $post->status);
        $this->assertSame(2, $post->revisions()->count());
        $revision = $post->revisions()->firstOrFail();
        $this->assertSame('draft_planning', $revision->provenance);
        $this->assertSame([
            ['position' => 1, 'type' => 'artwork', 'id' => $artwork->id],
        ], $revision->snapshot['media']);
    }

    public function test_revision_restore_changes_only_the_safe_allowlist_and_keeps_current_connections(): void
    {
        Storage::disk('public')->put('posts/covers/old-public.jpg', 'legacy public cover');
        Storage::disk('local')->put('posts/covers/current-private.jpg', 'current private cover');
        $oldTag = Tag::query()->create(['name' => 'Old', 'slug' => 'old']);
        $currentTag = Tag::query()->create(['name' => 'Current', 'slug' => 'current']);
        $oldArtwork = $this->artwork('old-history-artwork');
        $currentArtwork = $this->artwork('current-history-artwork');
        $post = $this->makePost([
            'title' => 'Old safe title',
            'cover_image_path' => 'posts/covers/old-public.jpg',
            'cover_alt_text' => 'Old cover description',
            'seo_title' => 'Old SEO title',
            'seo_description' => 'Old SEO description',
        ]);
        $connections = app(PostConnectionService::class);
        $connections->syncTags($post, [$oldTag->id]);
        $connections->syncMedia($post, [['type' => 'artwork', 'id' => $oldArtwork->id]]);
        $oldRevision = $post->revisions()->firstOrFail();
        $this->assertSame([
            'path' => 'posts/covers/old-public.jpg',
            'source_disk' => 'public',
            'size' => strlen('legacy public cover'),
            'sha256' => hash('sha256', 'legacy public cover'),
        ], $oldRevision->snapshot['cover']);

        $post->update([
            'title' => 'Current safe title',
            'excerpt' => 'Current excerpt',
            'body' => 'Current body with enough detail for the editorial workflow.',
            'cover_image_path' => 'posts/covers/current-private.jpg',
            'cover_alt_text' => 'Current cover description',
            'seo_title' => 'Current SEO title',
            'seo_description' => 'Current SEO description',
            'editorial_brief' => 'Current private brief',
            'editorial_notes' => 'Current private notes',
            'featured' => true,
        ]);
        $post = app(PostWorkflowService::class)->markReady($post);
        $post = app(PostSlugRedirectService::class)->changeSlug($post, 'current-history-slug');
        $connections->syncTags($post, [$currentTag->id]);
        $connections->syncMedia($post, [['type' => 'artwork', 'id' => $currentArtwork->id]]);

        $oldTag->delete();
        $oldArtwork->delete();
        Storage::disk('local')->put('posts/covers/old-public.jpg', 'legacy public cover');
        Storage::disk('public')->delete('posts/covers/old-public.jpg');
        $restored = app(PostRevisionService::class)->restore(
            $post,
            $oldRevision,
            reason: 'Bring back the earlier prose.',
        );

        $this->assertSame('Old safe title', $restored->title);
        $this->assertSame('A public excerpt for revision testing.', $restored->excerpt);
        $this->assertSame('A complete Journal body for revision and restore testing.', $restored->body);
        $this->assertSame('posts/covers/old-public.jpg', $restored->cover_image_path);
        $this->assertSame('Old cover description', $restored->cover_alt_text);
        $this->assertSame('Old SEO title', $restored->seo_title);
        $this->assertSame('Old SEO description', $restored->seo_description);
        $this->assertSame('current-history-slug', $restored->slug);
        $this->assertSame(PostStatus::Ready, $restored->status);
        $this->assertFalse($restored->published);
        $this->assertTrue($restored->featured);
        $this->assertSame('Current private brief', $restored->editorial_brief);
        $this->assertSame('Current private notes', $restored->editorial_notes);
        $this->assertSame([$currentTag->id], $restored->tags()->pluck('tags.id')->all());
        $this->assertSame([$currentArtwork->id], $restored->mediaItems()->pluck('artwork_id')->all());

        $restoreRevision = $restored->revisions()->firstOrFail();
        $this->assertSame('revision_restore', $restoreRevision->provenance);
        $this->assertSame('Bring back the earlier prose.', $restoreRevision->reason);
        $this->assertSame([$currentTag->id], $restoreRevision->snapshot['tag_ids']);
        $this->assertSame($currentArtwork->id, $restoreRevision->snapshot['media'][0]['id']);
    }

    public function test_missing_revision_cover_fails_before_any_restore_change(): void
    {
        Storage::disk('local')->put('posts/covers/missing-later.jpg', 'cover');
        $post = $this->makePost([
            'title' => 'Historical title',
            'cover_image_path' => 'posts/covers/missing-later.jpg',
        ]);
        $revision = $post->revisions()->firstOrFail();
        Storage::disk('local')->delete('posts/covers/missing-later.jpg');
        $post->update([
            'title' => 'Current title',
            'cover_image_path' => null,
        ]);
        $revisionCount = $post->revisions()->count();

        try {
            app(PostRevisionService::class)->restore($post, $revision);
            $this->fail('A revision whose cover source is missing must not be restored.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('cover image', $exception->getMessage());
        }

        $this->assertSame('Current title', $post->refresh()->title);
        $this->assertNull($post->cover_image_path);
        $this->assertSame($revisionCount, $post->revisions()->count());
    }

    public function test_overwritten_revision_cover_fails_integrity_before_any_restore_change(): void
    {
        $originalBytes = 'historical-cover-a';
        $overwrittenBytes = 'historical-cover-b';
        $path = 'posts/covers/overwritten.jpg';
        Storage::disk('local')->put($path, $originalBytes);
        $post = $this->makePost([
            'title' => 'Historical cover title',
            'cover_image_path' => $path,
        ]);
        $revision = $post->revisions()->firstOrFail();
        $post->update([
            'title' => 'Current cover title',
            'cover_image_path' => null,
        ]);
        Storage::disk('local')->put($path, $overwrittenBytes);
        $revisionCount = $post->revisions()->count();

        try {
            app(PostRevisionService::class)->restore($post, $revision);
            $this->fail('A revision whose cover bytes changed must not be restored.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('recorded bytes', $exception->getMessage());
        }

        $this->assertSame('Current cover title', $post->refresh()->title);
        $this->assertNull($post->cover_image_path);
        $this->assertSame($revisionCount, $post->revisions()->count());
    }

    public function test_published_and_scheduled_posts_reject_incomplete_revision_restores_atomically(): void
    {
        $workflow = app(PostWorkflowService::class);
        $published = $this->makePost([
            'slug' => 'published-readiness-restore',
            'body' => '',
        ]);
        $publishedRevision = $published->revisions()->firstOrFail();
        $published->update(['body' => 'Complete public writing that is safe to publish.']);
        $published = $workflow->publishNow($workflow->markReady($published));

        $scheduled = $this->makePost([
            'slug' => 'scheduled-readiness-restore',
            'body' => '',
        ]);
        $scheduledRevision = $scheduled->revisions()->firstOrFail();
        $scheduled->update(['body' => 'Complete scheduled writing that is safe to publish.']);
        $scheduled = $workflow->schedule($workflow->markReady($scheduled), now()->addDay());

        $futurePublished = $this->makePost([
            'slug' => 'future-published-readiness-restore',
            'body' => '',
        ]);
        $futureRevision = $futurePublished->revisions()->firstOrFail();
        $futurePublished->update(['body' => 'Complete future-published writing.']);
        DB::table('posts')->where('id', $futurePublished->id)->update([
            'status' => PostStatus::Published->value,
            'scheduled_at' => null,
            'published' => true,
            'published_at' => now()->addDay(),
        ]);
        $futurePublished->refresh();

        $malformedPublished = $this->makePost([
            'slug' => 'malformed-published-readiness-restore',
            'body' => '',
        ]);
        $malformedRevision = $malformedPublished->revisions()->firstOrFail();
        $malformedPublished->update(['body' => 'Complete malformed-state writing.']);
        DB::table('posts')->where('id', $malformedPublished->id)->update([
            'status' => PostStatus::Published->value,
            'scheduled_at' => null,
            'published' => false,
            'published_at' => null,
        ]);
        $malformedPublished->refresh();

        foreach ([
            [$published, $publishedRevision, PostStatus::Published, 'Complete public writing that is safe to publish.'],
            [$scheduled, $scheduledRevision, PostStatus::Scheduled, 'Complete scheduled writing that is safe to publish.'],
            [$futurePublished, $futureRevision, PostStatus::Draft, 'Complete future-published writing.'],
            [$malformedPublished, $malformedRevision, PostStatus::Draft, 'Complete malformed-state writing.'],
        ] as [$post, $revision, $status, $body]) {
            $revisionCount = $post->revisions()->count();

            try {
                app(PostRevisionService::class)->restore($post, $revision);
                $this->fail('A non-Draft post must reject an incomplete restored candidate.');
            } catch (DomainException $exception) {
                $this->assertStringContainsString('not publication-ready', $exception->getMessage());
            }

            $post->refresh();
            $this->assertSame($status, $post->effectiveStatusAt());
            $this->assertSame($body, $post->body);
            $this->assertSame($revisionCount, $post->revisions()->count());
        }
    }

    public function test_stale_safe_content_fingerprint_cannot_restore_over_a_newer_edit(): void
    {
        $post = $this->makePost(['title' => 'Historical fingerprint title']);
        $revision = $post->revisions()->firstOrFail();
        $post->update(['title' => 'Current fingerprint title']);
        $service = app(PostRevisionService::class);
        $expected = $service->contentFingerprint($post);
        $post->update(['body' => 'A newer safe edit from another browser session.']);
        $revisionCount = $post->revisions()->count();

        try {
            $service->restore($post, $revision, expectedContentFingerprint: $expected);
            $this->fail('A stale History tab must not overwrite newer safe content.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('changed in another session', $exception->getMessage());
        }

        $post->refresh();
        $this->assertSame('Current fingerprint title', $post->title);
        $this->assertSame('A newer safe edit from another browser session.', $post->body);
        $this->assertSame($revisionCount, $post->revisions()->count());
    }

    public function test_workflow_transitions_create_non_restorable_audit_revisions(): void
    {
        $workflow = app(PostWorkflowService::class);
        $post = $this->makePost(['slug' => 'workflow-revision-history']);
        $post = $workflow->markReady($post);
        $scheduledAt = now()->addDay();
        $post = $workflow->schedule($post, $scheduledAt);
        $post = $workflow->cancelSchedule($post);
        $post = $workflow->publishNow($post);
        $post = $workflow->unpublish($post);
        $post = $workflow->revertToDraft($post);

        $workflowRevisions = $post->revisions()
            ->where('provenance', 'workflow')
            ->reorder('id')
            ->get();

        $this->assertCount(6, $workflowRevisions);
        $this->assertSame([
            'Marked Journal post Ready.',
            'Scheduled Journal post for '.$scheduledAt->toIso8601String().'.',
            'Cancelled Journal post schedule.',
            'Published Journal post immediately.',
            'Unpublished Journal post and returned it to Ready.',
            'Returned Journal post to Draft.',
        ], $workflowRevisions->pluck('reason')->all());

        foreach ($workflowRevisions as $revision) {
            $this->assertSame([], $revision->changed_fields);
            $encoded = json_encode($revision->snapshot, JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('status', $encoded);
            $this->assertStringNotContainsString('published', $encoded);
            $this->assertStringNotContainsString('scheduled_at', $encoded);
        }
    }

    public function test_slug_changes_and_permanent_deletion_reserve_every_historical_slug(): void
    {
        $post = $this->makePost(['slug' => 'first-history-slug']);
        $post->slug = 'unsafe-direct-change';

        try {
            $post->saveOrFail();
            $this->fail('Direct slug changes must not bypass redirect creation.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('slug service', $exception->getMessage());
        }

        $post->refresh();
        $post = app(PostSlugRedirectService::class)->changeSlug($post, 'current-history-slug');
        $this->assertDatabaseHas('post_slug_redirects', [
            'slug' => 'first-history-slug',
            'post_id' => $post->id,
        ]);
        $this->assertSame('slug_change', $post->revisions()->firstOrFail()->provenance);
        $this->assertStringContainsString('first-history-slug', (string) $post->revisions()->first()->reason);
        $redirect = PostSlugRedirect::query()->where('slug', 'first-history-slug')->firstOrFail();

        foreach ([
            fn () => $redirect->update(['slug' => 'mutated-history-slug']),
            fn () => $redirect->update(['post_id' => null]),
            fn () => $redirect->delete(),
        ] as $mutation) {
            try {
                $mutation();
                $this->fail('Journal redirects must reject arbitrary mutation and deletion.');
            } catch (LogicException $exception) {
                $this->assertStringContainsString('redirect', $exception->getMessage());
            }

            $redirect->refresh();
        }

        $trashed = app(PostRevisionService::class)->trash($post, reason: 'No longer needed.');
        $restored = app(PostRevisionService::class)->restoreTrashed($trashed, reason: 'Recover as draft.');
        $this->assertSame(PostStatus::Draft, $restored->status);
        $this->assertFalse($restored->published);
        $this->assertNull($restored->published_at);
        $this->assertSame('trash_restore', $restored->revisions()->firstOrFail()->provenance);

        $trashed = app(PostRevisionService::class)->trash($restored);
        app(PostRevisionService::class)->forceDelete($trashed);

        $this->assertDatabaseMissing('posts', ['id' => $post->id]);
        $this->assertDatabaseMissing('post_revisions', ['post_id' => $post->id]);
        $this->assertDatabaseHas('post_slug_redirects', ['slug' => 'first-history-slug', 'post_id' => null]);
        $this->assertDatabaseHas('post_slug_redirects', ['slug' => 'current-history-slug', 'post_id' => null]);
        $this->assertNull(app(PostSlugRedirectService::class)->resolvePublic('first-history-slug'));

        foreach (['first-history-slug', 'current-history-slug'] as $reservedSlug) {
            try {
                $this->makePost(['slug' => $reservedSlug]);
                $this->fail('Historical and tombstoned slugs must remain reserved.');
            } catch (DomainException $exception) {
                $this->assertStringContainsString('reserved', $exception->getMessage());
            }
        }

        $autoSlug = $this->makePost([
            'title' => 'Current history slug',
            'slug' => null,
        ]);
        $this->assertSame('current-history-slug-2', $autoSlug->slug);

        $direct = $this->makePost(['slug' => 'direct-force-delete']);
        $direct->forceDelete();
        $this->assertDatabaseHas('post_slug_redirects', [
            'slug' => 'direct-force-delete',
            'post_id' => null,
        ]);
    }

    public function test_maximum_length_slug_change_keeps_a_bounded_complete_audit_reason(): void
    {
        $oldSlug = 'a'.str_repeat('x', 254);
        $newSlug = 'b'.str_repeat('y', 254);
        $post = $this->makePost(['slug' => $oldSlug]);

        $post = app(PostSlugRedirectService::class)->changeSlug($post, $newSlug);
        $revision = $post->revisions()->firstOrFail();

        $this->assertSame('slug_change', $revision->provenance);
        $this->assertStringContainsString($oldSlug, $revision->reason);
        $this->assertStringContainsString($newSlug, $revision->reason);
        $this->assertLessThanOrEqual(600, mb_strlen($revision->reason));
    }

    public function test_direct_soft_delete_and_restore_cannot_republish_a_post(): void
    {
        $workflow = app(PostWorkflowService::class);
        $published = $workflow->publishNow($workflow->markReady($this->makePost()));

        $published->delete();

        $this->assertDatabaseHas('posts', [
            'id' => $published->id,
            'status' => PostStatus::Draft->value,
            'published' => false,
            'published_at' => null,
        ]);

        $published->restore();
        $published->refresh();
        $this->assertSame(PostStatus::Draft, $published->status);
        $this->assertFalse($published->published);
        $this->assertFalse($published->isPubliclyPublishedAt());

        DB::table('posts')->where('id', $published->id)->update([
            'status' => PostStatus::Published->value,
            'published' => true,
            'published_at' => now(),
            'deleted_at' => now(),
        ]);
        $trashedPublished = Post::query()->withTrashed()->findOrFail($published->id);
        $this->assertSame(PostStatus::Draft, $trashedPublished->effectiveStatusAt());
        $this->assertNull($trashedPublished->effectivePublishedAt());
        $this->assertFalse($trashedPublished->isPubliclyPublishedAt());
    }

    public function test_only_connections_to_live_posts_suppress_story_opportunities(): void
    {
        $artwork = $this->artwork('restored-opportunity');
        $post = $this->makePost();
        app(PostConnectionService::class)->syncMedia($post, [[
            'type' => 'artwork',
            'id' => $artwork->id,
        ]]);

        $this->assertNull(app(StoryOpportunityService::class)->find(PostMediaType::Artwork, $artwork->id));

        app(PostRevisionService::class)->trash($post);

        $this->assertTrue(
            app(StoryOpportunityService::class)
                ->find(PostMediaType::Artwork, $artwork->id)
                ?->is($artwork) ?? false,
        );
    }

    /** @param array<string, mixed> $attributes */
    private function makePost(array $attributes = []): Post
    {
        return Post::query()->create(array_replace([
            'title' => 'Journal revision test',
            'slug' => 'journal-revision-'.str()->uuid(),
            'excerpt' => 'A public excerpt for revision testing.',
            'body' => 'A complete Journal body for revision and restore testing.',
        ], $attributes));
    }

    private function artwork(string $slug): Artwork
    {
        return Artwork::query()->create([
            'title' => str($slug)->headline()->toString(),
            'slug' => $slug,
            'image_path' => 'artworks/'.$slug.'.jpg',
            'published' => true,
        ]);
    }
}
