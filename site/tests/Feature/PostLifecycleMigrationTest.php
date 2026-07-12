<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PostLifecycleMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_legacy_publication_state_is_backfilled_without_breaking_the_old_image_mirror(): void
    {
        $now = CarbonImmutable::parse('2026-07-12 12:00:00');
        CarbonImmutable::setTestNow($now);

        $migration = require database_path('migrations/2026_07_12_040000_add_editorial_lifecycle_to_posts.php');
        $migration->down();

        $past = $now->subDay();
        $future = $now->addDay();
        $updated = $now->subHours(2);

        DB::table('posts')->insert([
            $this->legacyPost('future', true, $future, $updated),
            $this->legacyPost('past', true, $past, $updated),
            $this->legacyPost('no-date', true, null, $updated),
            $this->legacyPost('unpublished', false, $past, $updated),
        ]);

        $migration->up();

        $futurePost = DB::table('posts')->where('slug', 'future')->first();
        $this->assertSame('scheduled', $futurePost->status);
        $this->assertTrue((bool) $futurePost->published);
        $this->assertSame($future->toDateTimeString(), $futurePost->scheduled_at);
        $this->assertSame($future->toDateTimeString(), $futurePost->published_at);

        $pastPost = DB::table('posts')->where('slug', 'past')->first();
        $this->assertSame('published', $pastPost->status);
        $this->assertTrue((bool) $pastPost->published);
        $this->assertNull($pastPost->scheduled_at);
        $this->assertSame($past->toDateTimeString(), $pastPost->published_at);

        $withoutDate = DB::table('posts')->where('slug', 'no-date')->first();
        $this->assertSame('published', $withoutDate->status);
        $this->assertTrue((bool) $withoutDate->published);
        $this->assertNull($withoutDate->scheduled_at);
        $this->assertSame($updated->subDay()->toDateTimeString(), $withoutDate->published_at);

        $unpublished = DB::table('posts')->where('slug', 'unpublished')->first();
        $this->assertSame('draft', $unpublished->status);
        $this->assertFalse((bool) $unpublished->published);
        $this->assertNull($unpublished->scheduled_at);
        $this->assertSame($past->toDateTimeString(), $unpublished->published_at);

        foreach (['future', 'past', 'no-date', 'unpublished'] as $slug) {
            $this->assertSame(
                $updated->toDateTimeString(),
                DB::table('posts')->where('slug', $slug)->value('public_content_updated_at'),
            );
        }
    }

    /** @return array<string, mixed> */
    private function legacyPost(
        string $slug,
        bool $published,
        ?CarbonImmutable $publishedAt,
        CarbonImmutable $updatedAt,
    ): array {
        return [
            'title' => ucfirst($slug),
            'slug' => $slug,
            'excerpt' => null,
            'body' => 'Visible body.',
            'cover_image_path' => null,
            'seo_title' => null,
            'seo_description' => null,
            'featured' => false,
            'published' => $published,
            'published_at' => $publishedAt,
            'created_at' => $updatedAt->subDay(),
            'updated_at' => $updatedAt,
        ];
    }
}
