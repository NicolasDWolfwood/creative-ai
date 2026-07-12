<?php

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Services\PostStructuredData;
use App\Services\PostWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

class PublicJournalEditorialTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_scheduled_post_becomes_public_everywhere_when_due_without_mutating_its_stored_state(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('posts/covers/scheduled.jpg', 'scheduled-cover');
        $now = Carbon::parse('2026-07-12 10:00:00 UTC');
        Carbon::setTestNow($now);
        $post = $this->readyPost([
            'title' => 'Scheduled studio dispatch',
            'slug' => 'scheduled-studio-dispatch',
            'cover_image_path' => 'posts/covers/scheduled.jpg',
            'cover_alt_text' => 'Layered blue shapes in a dark studio.',
        ]);
        $scheduledAt = $now->copy()->addHour();
        $post = app(PostWorkflowService::class)->schedule($post, $scheduledAt);

        $this->assertSame(PostStatus::Scheduled, $post->status);
        $this->assertSame(PostStatus::Scheduled, $post->effectiveStatusAt());
        $this->assertFalse($post->isPubliclyPublishedAt());
        $this->get(route('posts.show', $post))->assertNotFound();
        $this->get(route('posts.index'))->assertOk()->assertDontSee($post->title);
        $this->get(route('home'))->assertOk()->assertDontSee($post->title);
        $this->get(route('feed'))->assertOk()->assertDontSee($post->title);
        $this->get(route('sitemap'))->assertOk()->assertDontSee(route('posts.show', $post), false);
        $this->get(route('media.posts.cover', $post))->assertNotFound();

        Carbon::setTestNow($scheduledAt->copy()->addSecond());

        $this->get(route('posts.show', $post))->assertOk()->assertSee($post->title);
        $this->get(route('posts.index'))->assertOk()->assertSee($post->title);
        $this->get(route('home'))->assertOk()->assertSee($post->title);
        $this->get(route('feed'))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/rss+xml; charset=UTF-8')
            ->assertSee($post->title);
        $this->get(route('sitemap'))->assertOk()->assertSee(route('posts.show', $post), false);
        $this->get(route('media.posts.cover', $post))->assertOk();

        $post->refresh();
        $this->assertSame(PostStatus::Scheduled, $post->status);
        $this->assertSame(PostStatus::Published, $post->effectiveStatusAt());
        $this->assertTrue($post->published);
        $this->assertTrue($post->scheduled_at->equalTo($scheduledAt));
        $this->assertTrue($post->published_at->equalTo($scheduledAt));
    }

    public function test_public_post_metadata_is_complete_and_excludes_private_editorial_context(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('posts/covers/schema.jpg', 'schema-cover');
        Carbon::setTestNow(Carbon::parse('2026-07-12 12:00:00 UTC'));
        $post = $this->readyPost([
            'title' => 'A connected studio note',
            'slug' => 'connected-studio-note',
            'excerpt' => 'A safe summary with ]]> and an ampersand & marker.',
            'body' => "A visible paragraph.\n\n<script>window.privateLeak = true</script>\n\n[unsafe](javascript:alert('no'))",
            'cover_image_path' => 'posts/covers/schema.jpg',
            'cover_alt_text' => 'A green line connecting artwork and sound.',
            'editorial_brief' => 'PRIVATE-BRIEF-MARKER',
            'editorial_notes' => 'PRIVATE-NOTES-MARKER',
            'seo_title' => 'Connected studio note',
            'seo_description' => 'A public description of a connected studio note.',
        ]);
        $post = app(PostWorkflowService::class)->publishNow($post);
        $publishedAt = $post->effectivePublishedAt();

        Carbon::setTestNow(now()->addHour());
        $post->update(['body' => $post->body."\n\nA public follow-up."]);
        $publicModifiedAt = $post->refresh()->public_content_updated_at;
        Carbon::setTestNow(now()->addHour());
        $post->update(['editorial_notes' => 'PRIVATE-NOTES-CHANGED']);

        $post->refresh();
        $this->assertTrue($post->public_content_updated_at->equalTo($publicModifiedAt));

        $schema = app(PostStructuredData::class)->forPost($post);
        $canonical = route('posts.show', $post);
        $graph = collect($schema['@graph'])->keyBy('@id');
        $article = $graph[$canonical.'#article'];

        $this->assertSame('BlogPosting', $article['@type']);
        $this->assertSame($post->title, $article['headline']);
        $this->assertSame($canonical, $article['mainEntityOfPage']['@id']);
        $this->assertSame($publishedAt?->toIso8601String(), $article['datePublished']);
        $this->assertSame($publicModifiedAt?->toIso8601String(), $article['dateModified']);
        $this->assertSame($canonical.'#cover', $article['image']['@id']);
        $this->assertSame('A green line connecting artwork and sound.', $graph[$canonical.'#cover']['caption']);
        $encodedSchema = json_encode($schema, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('PRIVATE-BRIEF-MARKER', $encodedSchema);
        $this->assertStringNotContainsString('PRIVATE-NOTES', $encodedSchema);
        $this->assertStringNotContainsString('posts/covers/schema.jpg', $encodedSchema);

        $this->get($canonical)
            ->assertOk()
            ->assertSee('<link rel="canonical" href="'.$canonical.'">', false)
            ->assertSee('BlogPosting')
            ->assertSee('article:modified_time', false)
            ->assertSee('alt="A green line connecting artwork and sound."', false)
            ->assertDontSee('PRIVATE-BRIEF-MARKER')
            ->assertDontSee('PRIVATE-NOTES-CHANGED')
            ->assertDontSee('<script>window.privateLeak = true</script>', false)
            ->assertDontSee('href="javascript:', false);

        $this->get(route('posts.index'))
            ->assertOk()
            ->assertSee('aria-label="Read A connected studio note"', false)
            ->assertSee('alt="A green line connecting artwork and sound."', false);

        $this->get(route('feed'))
            ->assertOk()
            ->assertSee('<?xml version="1.0" encoding="UTF-8"?>', false)
            ->assertSee('xmlns:atom="http://www.w3.org/2005/Atom"', false)
            ->assertSee('rel="self"', false)
            ->assertSee('<lastBuildDate>', false)
            ->assertSee('A safe summary with ]]&gt; and an ampersand &amp; marker.', false)
            ->assertDontSee('<![CDATA[', false)
            ->assertDontSee('PRIVATE-BRIEF-MARKER')
            ->assertDontSee('PRIVATE-NOTES-CHANGED');
    }

    public function test_feed_orders_posts_by_effective_publication_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 09:00:00 UTC'));
        $older = app(PostWorkflowService::class)->publishNow($this->readyPost([
            'title' => 'Older public studio note',
            'slug' => 'older-public-studio-note',
        ]));

        Carbon::setTestNow(Carbon::parse('2026-07-11 09:00:00 UTC'));
        $newer = app(PostWorkflowService::class)->publishNow($this->readyPost([
            'title' => 'Newer public studio note',
            'slug' => 'newer-public-studio-note',
        ]));

        $this->get(route('feed'))
            ->assertOk()
            ->assertSeeInOrder([$newer->title, $older->title]);
    }

    public function test_structured_data_rejects_a_private_post(): void
    {
        $post = Post::query()->create([
            'title' => 'Private schema source',
            'slug' => 'private-schema-source',
            'body' => 'This is not public.',
        ]);

        $this->expectException(InvalidArgumentException::class);

        app(PostStructuredData::class)->forPost($post);
    }

    /** @param array<string, mixed> $attributes */
    private function readyPost(array $attributes): Post
    {
        $post = Post::query()->create(array_replace([
            'title' => 'Journal draft',
            'slug' => 'journal-draft-'.str()->uuid(),
            'excerpt' => 'A concise public summary.',
            'body' => 'A visible journal entry from the studio.',
        ], $attributes));

        return app(PostWorkflowService::class)->markReady($post);
    }
}
