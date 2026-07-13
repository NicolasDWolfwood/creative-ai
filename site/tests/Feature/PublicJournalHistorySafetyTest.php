<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\PostSlugRedirect;
use App\Services\PostWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PublicJournalHistorySafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_canonical_journal_routes_keep_using_slugs_and_revalidate_public_access(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('posts/covers/history-safety.jpg', 'cover');
        $post = $this->publishPost([
            'title' => 'History safety',
            'slug' => 'history-safety',
            'cover_image_path' => 'posts/covers/history-safety.jpg',
            'cover_alt_text' => 'A layered archival composition.',
        ]);

        $this->assertSame(url('/journal/history-safety'), route('posts.show', $post));

        $postResponse = $this->get(route('posts.show', $post))->assertOk();
        $coverResponse = $this->get(route('media.posts.cover', $post))->assertOk();

        $this->assertRequiresRevalidation($postResponse);
        $this->assertNotStored($postResponse);
        $this->assertRequiresRevalidation($coverResponse);

        $workflow = app(PostWorkflowService::class);
        $unpublished = $workflow->unpublish($post);

        $this->get(route('posts.show', $post))->assertNotFound();
        $this->get(route('media.posts.cover', $post))->assertNotFound();

        $republished = $workflow->publishNow($unpublished);
        $republished->delete();

        $this->get(route('posts.show', $republished))->assertNotFound();
        $this->get(route('media.posts.cover', $republished))->assertNotFound();
    }

    public function test_old_slugs_redirect_once_to_the_current_public_canonical_url(): void
    {
        $post = $this->publishPost([
            'title' => 'Current Journal title',
            'slug' => 'current-journal-slug',
        ]);
        PostSlugRedirect::query()->create([
            'slug' => 'previous-journal-slug',
            'post_id' => $post->getKey(),
        ]);
        PostSlugRedirect::query()->create([
            'slug' => 'oldest-journal-slug',
            'post_id' => $post->getKey(),
        ]);

        foreach (['previous-journal-slug', 'oldest-journal-slug'] as $oldSlug) {
            $response = $this->get(url('/journal/'.$oldSlug))
                ->assertMovedPermanently()
                ->assertRedirect(route('posts.show', $post));

            $this->assertRequiresRevalidation($response);
            $this->assertNotStored($response);
        }

        $this->get(route('posts.show', $post))
            ->assertOk()
            ->assertSee('<link rel="canonical" href="'.route('posts.show', $post).'">', false);

        app(PostWorkflowService::class)->unpublish($post);

        $this->get(url('/journal/previous-journal-slug'))->assertNotFound();
        $this->get(url('/journal/oldest-journal-slug'))->assertNotFound();
    }

    public function test_redirects_fail_closed_for_non_public_deleted_missing_tombstoned_or_colliding_slugs(): void
    {
        $publicTarget = $this->publishPost([
            'title' => 'Public redirect target',
            'slug' => 'public-redirect-target',
        ]);
        $privateTarget = Post::query()->create([
            'title' => 'Private redirect target',
            'slug' => 'private-redirect-target',
            'body' => 'Private editorial work.',
        ]);
        $workflow = app(PostWorkflowService::class);
        $futureTarget = $workflow->schedule(
            $workflow->markReady(Post::query()->create([
                'title' => 'Future redirect target',
                'slug' => 'future-redirect-target',
                'excerpt' => 'A concise scheduled summary.',
                'body' => 'A complete scheduled Journal story.',
            ])),
            now()->addDay(),
        );
        $deletedTarget = $this->publishPost([
            'title' => 'Deleted redirect target',
            'slug' => 'deleted-redirect-target',
        ]);

        foreach ([
            'private-old-slug' => $privateTarget,
            'future-old-slug' => $futureTarget,
            'deleted-old-slug' => $deletedTarget,
        ] as $oldSlug => $target) {
            PostSlugRedirect::query()->create([
                'slug' => $oldSlug,
                'post_id' => $target->getKey(),
            ]);
        }

        $deletedTarget->delete();
        PostSlugRedirect::query()->create([
            'slug' => 'permanent-tombstone',
            'post_id' => null,
        ]);

        // Even corrupt cross-table state must not let a redirect shadow a
        // current private canonical slug.
        Post::query()->create([
            'title' => 'Private collision',
            'slug' => 'colliding-current-slug',
            'body' => 'Private editorial work.',
        ]);
        PostSlugRedirect::query()->create([
            'slug' => 'colliding-current-slug',
            'post_id' => $publicTarget->getKey(),
        ]);

        foreach ([
            'private-old-slug',
            'future-old-slug',
            'deleted-old-slug',
            'permanent-tombstone',
            'colliding-current-slug',
            'missing-old-slug',
        ] as $slug) {
            $this->get(url('/journal/'.$slug))->assertNotFound();
        }
    }

    /** @param array<string, mixed> $attributes */
    private function publishPost(array $attributes): Post
    {
        $post = Post::query()->create(array_replace([
            'title' => 'Journal history test',
            'slug' => 'journal-history-test-'.str()->uuid(),
            'excerpt' => 'A concise summary for the public Journal.',
            'body' => 'A complete public Journal story used to exercise history safety.',
        ], $attributes));
        $workflow = app(PostWorkflowService::class);

        return $workflow->publishNow($workflow->markReady($post));
    }

    private function assertRequiresRevalidation(TestResponse $response): void
    {
        $cacheControl = (string) $response->headers->get('Cache-Control');

        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-cache', $cacheControl);
        $this->assertStringContainsString('max-age=0', $cacheControl);
        $this->assertStringContainsString('must-revalidate', $cacheControl);
    }

    private function assertNotStored(TestResponse $response): void
    {
        $this->assertStringContainsString(
            'no-store',
            (string) $response->headers->get('Cache-Control'),
        );
    }
}
