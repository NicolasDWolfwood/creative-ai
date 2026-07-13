<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PostHistorySafetyMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_posts_receive_a_safe_complete_history_baseline(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $migration = require database_path('migrations/2026_07_13_020000_add_journal_history_safety.php');
        $migration->down();

        try {
            $now = now();
            $coverPath = 'posts/covers/pre-history.jpg';
            $coverBytes = 'pre-feature-cover-bytes';
            Storage::disk('public')->put($coverPath, $coverBytes);
            $postId = DB::table('posts')->insertGetId([
                'title' => 'Pre-feature Journal story',
                'slug' => 'pre-feature-journal-story',
                'excerpt' => 'Public historical excerpt.',
                'body' => 'Public historical body.',
                'editorial_brief' => 'Private historical brief.',
                'editorial_notes' => 'Private historical notes.',
                'cover_image_path' => $coverPath,
                'cover_alt_text' => 'Historical cover description.',
                'seo_title' => 'Historical SEO title',
                'seo_description' => 'Historical SEO description.',
                'featured' => true,
                'status' => 'draft',
                'scheduled_at' => null,
                'published' => false,
                'published_at' => null,
                'public_content_updated_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $firstTagId = DB::table('tags')->insertGetId([
                'name' => 'First baseline tag',
                'slug' => 'first-baseline-tag',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $secondTagId = DB::table('tags')->insertGetId([
                'name' => 'Second baseline tag',
                'slug' => 'second-baseline-tag',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('post_tag')->insert([
                ['post_id' => $postId, 'tag_id' => $secondTagId, 'created_at' => $now, 'updated_at' => $now],
                ['post_id' => $postId, 'tag_id' => $firstTagId, 'created_at' => $now, 'updated_at' => $now],
            ]);
            $artworkId = DB::table('artworks')->insertGetId([
                'title' => 'Baseline artwork',
                'slug' => 'baseline-artwork',
                'image_path' => 'artworks/baseline.jpg',
                'published' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $malformedArtworkId = DB::table('artworks')->insertGetId([
                'title' => 'Malformed baseline artwork',
                'slug' => 'malformed-baseline-artwork',
                'image_path' => 'artworks/malformed-baseline.jpg',
                'published' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $collectionId = DB::table('collections')->insertGetId([
                'title' => 'Malformed baseline collection',
                'slug' => 'malformed-baseline-collection',
                'published' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('post_media')->insert([
                [
                    'post_id' => $postId,
                    'position' => 1,
                    'artwork_id' => $artworkId,
                    'collection_id' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'post_id' => $postId,
                    'position' => 2,
                    'artwork_id' => $malformedArtworkId,
                    'collection_id' => $collectionId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);

            $migration->up();

            $revision = DB::table('post_revisions')->where('post_id', $postId)->sole();
            $snapshot = json_decode($revision->snapshot, true, flags: JSON_THROW_ON_ERROR);
            $encoded = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

            $this->assertSame('history_baseline', $revision->provenance);
            $this->assertNull($revision->user_id);
            $this->assertSame('Pre-feature Journal story', $snapshot['content']['title']);
            $this->assertSame('Public historical body.', $snapshot['content']['body']);
            $this->assertSame([$firstTagId, $secondTagId], $snapshot['tag_ids']);
            $this->assertSame(['position' => 1, 'type' => 'artwork', 'id' => $artworkId], $snapshot['media'][0]);
            $this->assertSame([
                'position' => 2,
                'type' => 'invalid',
                'id' => null,
                'invalid' => true,
                'references' => [
                    'artwork_id' => $malformedArtworkId,
                    'collection_id' => $collectionId,
                ],
            ], $snapshot['media'][1]);
            $this->assertSame([
                'path' => $coverPath,
                'source_disk' => 'public',
                'size' => strlen($coverBytes),
                'sha256' => hash('sha256', $coverBytes),
            ], $snapshot['cover']);
            $this->assertStringNotContainsString('Private historical brief.', $encoded);
            $this->assertStringNotContainsString('Private historical notes.', $encoded);
            $this->assertStringNotContainsString('published', $encoded);
            $this->assertSame(hash('sha256', $encoded), $revision->snapshot_hash);
        } finally {
            $migration->up();
        }
    }
}
