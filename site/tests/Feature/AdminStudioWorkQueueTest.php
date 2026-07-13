<?php

namespace Tests\Feature;

use App\Enums\PostAiRunStatus;
use App\Enums\PostStatus;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Albums\AlbumResource;
use App\Filament\Resources\Artworks\ArtworkResource;
use App\Filament\Resources\Playlists\PlaylistResource;
use App\Filament\Resources\Posts\PostResource;
use App\Filament\Resources\Tracks\TrackResource;
use App\Models\Album;
use App\Models\Artwork;
use App\Models\Playlist;
use App\Models\Post;
use App\Models\Track;
use App\Models\User;
use App\Services\StudioWorkQueueService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class AdminStudioWorkQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-07-13 12:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_dashboard_counts_only_open_query_backed_work_and_uses_the_oldest_relevant_timestamp(): void
    {
        $oldest = now()->subDays(9);
        $olderFailedArtwork = $this->artwork('Older variant failure', [
            'variant_status' => Artwork::VARIANT_STATUS_FAILED,
            'variant_error' => 'PRIVATE-VARIANT-ERROR-<script>alert(1)</script>',
            'created_at' => now()->subDays(20),
            'updated_at' => $oldest,
        ]);
        $this->artwork('Newer variant failure', [
            'variant_status' => Artwork::VARIANT_STATUS_FAILED,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);
        $this->artwork('Ready variants', ['variant_status' => Artwork::VARIANT_STATUS_READY]);

        $this->track('Old failed analysis', [
            'analysis_status' => 'failed',
            'analysis_error' => 'PRIVATE-FFMPEG-ERROR-<script>alert(2)</script>',
            'analyzed_at' => now()->subDays(7),
            'created_at' => now()->subDays(30),
        ]);
        $this->track('New failed analysis', [
            'analysis_status' => 'failed',
            'analyzed_at' => now()->subHour(),
        ]);
        $this->track('Healthy analysis', ['analysis_status' => 'ready']);

        $this->artwork('Public missing alt', [
            'alt_text' => '   ',
            'created_at' => now()->subDays(6),
            'updated_at' => now()->subDays(6),
        ]);
        $this->artwork('Public described art', ['alt_text' => 'A useful description.']);
        $this->artwork('Future missing alt', [
            'alt_text' => null,
            'published_at' => now()->addDay(),
        ]);

        $this->album('Missing album cover', [
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ]);
        $this->album('Intentional no cover', ['cover_preference' => 'none']);
        $this->album('Embedded album cover', ['embedded_cover_path' => 'albums/covers/embedded.jpg']);
        $this->playlist('Missing playlist cover');
        $publicCover = $this->artwork('Public music cover', ['alt_text' => 'Album artwork.']);
        $this->playlist('Covered playlist', ['cover_artwork_id' => $publicCover->getKey()]);
        $this->track('Missing standalone track cover', [
            'standalone_published' => true,
            'standalone_published_at' => now()->subHour(),
        ]);

        $this->artwork('Artwork suggestion', [
            'ai_status' => Artwork::AI_STATUS_READY,
            'ai_suggestion' => ['title' => 'Suggestion'],
            'ai_analyzed_at' => now()->subDays(4),
            'created_at' => now()->subDays(40),
        ]);
        $this->track('Track suggestion', [
            'ai_status' => Track::AI_STATUS_READY,
            'ai_suggestion' => ['tags' => ['ambient']],
            'ai_analyzed_at' => now()->subDay(),
        ]);
        $this->artwork('Ready without suggestion', ['ai_status' => Artwork::AI_STATUS_READY]);
        $this->track('Applied suggestion', [
            'ai_status' => Track::AI_STATUS_APPLIED,
            'ai_suggestion' => ['tags' => ['complete']],
        ]);

        $draft = $this->makeJournalPost('Old Journal draft', [
            'created_at' => now()->subDays(3),
            'updated_at' => now()->subDays(3),
        ]);
        $this->makeJournalPost('Malformed stored draft', ['published' => true]);
        $readyWithLeftoverDate = $this->makeJournalPost('Ready with a leftover future date', [
            'status' => PostStatus::Ready->value,
            'published_at' => now()->addWeek(),
        ]);
        $nextScheduledAt = now()->addHours(2);
        $this->makeJournalPost('Next scheduled story', [
            'status' => PostStatus::Scheduled->value,
            'published' => true,
            'scheduled_at' => $nextScheduledAt,
            'published_at' => $nextScheduledAt,
        ]);
        $later = now()->addDays(2);
        $this->makeJournalPost('Later scheduled story', [
            'status' => PostStatus::Scheduled->value,
            'published' => true,
            'scheduled_at' => $later,
            'published_at' => $later,
        ]);
        $this->makeJournalPost('Invalid scheduled story', [
            'status' => PostStatus::Scheduled->value,
            'published' => true,
            'scheduled_at' => now()->addDay(),
            'published_at' => now()->addDays(3),
        ]);

        $this->journalAiRun($draft, PostAiRunStatus::Failed, now()->subDays(2), [
            'error_message' => 'PRIVATE-PROVIDER-ERROR-<script>alert(3)</script>',
        ]);
        $this->journalAiRun($draft, PostAiRunStatus::Stale, now()->subDay(), [
            'stale_reason' => 'PRIVATE-CONTEXT-DETAILS',
        ]);
        $this->journalAiRun($draft, PostAiRunStatus::Ready, now());

        $component = Livewire::actingAs(User::factory()->admin()->create())
            ->test(Dashboard::class);
        $queues = collect($component->instance()->getWorkQueues())->keyBy('key');

        $this->assertSame(2, $queues['failed-artwork-variants']['count']);
        $this->assertTrue($queues['failed-artwork-variants']['oldest_at']->equalTo($oldest));
        $this->assertSame(2, $queues['failed-track-analysis']['count']);
        $this->assertTrue($queues['failed-track-analysis']['oldest_at']->equalTo(now()->subDays(7)));
        $this->assertSame(1, $queues['missing-artwork-alt']['count']);
        $this->assertSame(3, $queues['missing-public-music-covers']['count']);
        $this->assertSame(2, $queues['ai-metadata-review']['count']);
        $this->assertTrue($queues['ai-metadata-review']['oldest_at']->equalTo(now()->subDays(4)));
        $this->assertSame(3, $queues['journal-drafts']['count']);
        $this->assertTrue($queues['journal-drafts']['oldest_at']->equalTo($draft->created_at));
        $this->assertSame(PostStatus::Ready, $readyWithLeftoverDate->effectiveStatusAt());
        $this->assertSame(2, $queues['journal-scheduled']['count']);
        $this->assertSame('Next', $queues['journal-scheduled']['timestamp_label']);
        $this->assertTrue($queues['journal-scheduled']['oldest_at']->equalTo($nextScheduledAt));
        $this->assertSame(2, $queues['journal-ai-attention']['count']);
        $this->assertTrue($queues['journal-ai-attention']['oldest_at']->equalTo(now()->subDays(2)));

        $component
            ->assertSee('What needs attention')
            ->assertSee($nextScheduledAt->toIso8601String())
            ->assertDontSee('PRIVATE-VARIANT-ERROR')
            ->assertDontSee('PRIVATE-FFMPEG-ERROR')
            ->assertDontSee('PRIVATE-PROVIDER-ERROR')
            ->assertDontSee('PRIVATE-CONTEXT-DETAILS');

        $this->assertTrue($olderFailedArtwork->exists);
    }

    public function test_each_queue_links_to_the_relevant_admin_workspace(): void
    {
        $component = Livewire::actingAs(User::factory()->admin()->create())
            ->test(Dashboard::class);
        $queues = collect($component->instance()->getWorkQueues())->keyBy('key');

        $this->assertSame(ArtworkResource::getUrl(), $queues['failed-artwork-variants']['href']);
        $this->assertSame(TrackResource::getUrl(), $queues['failed-track-analysis']['href']);
        $this->assertSame(ArtworkResource::getUrl(), $queues['missing-artwork-alt']['href']);
        $this->assertSame(TrackResource::getUrl(), $queues['missing-public-music-covers']['href']);
        $this->assertSame(
            [TrackResource::getUrl(), AlbumResource::getUrl(), PlaylistResource::getUrl()],
            collect($queues['missing-public-music-covers']['links'])->pluck('href')->all(),
        );
        $this->assertStringContainsString('tableFilters', $queues['ai-metadata-review']['href']);
        $this->assertStringContainsString('tableFilters', $queues['journal-drafts']['href']);
        $this->assertStringContainsString('tableFilters', $queues['journal-scheduled']['href']);
        $this->assertNotSame('', $queues['journal-ai-attention']['href']);

        foreach ($queues as $queue) {
            $component->assertSee($queue['href'], escape: false);
        }

        $this->assertStringStartsWith(PostResource::getUrl(), $queues['journal-drafts']['href']);
    }

    public function test_queue_copy_is_escaped_even_if_a_future_service_value_is_untrusted(): void
    {
        $this->app->singleton(StudioWorkQueueService::class, fn (): StudioWorkQueueService => new class extends StudioWorkQueueService
        {
            public function summaries(): array
            {
                return [[
                    'key' => 'unsafe-copy',
                    'label' => '<script>window.queueLabel = true</script>',
                    'count' => 1,
                    'oldest_at' => null,
                    'timestamp_label' => 'Oldest',
                    'reason' => '<img src=x onerror="window.queueReason = true">',
                    'href' => PostResource::getUrl(),
                    'action_label' => 'Review',
                    'tone' => 'neutral',
                    'links' => [],
                ]];
            }
        });

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(Dashboard::class)
            ->assertSee('&lt;script&gt;window.queueLabel = true&lt;/script&gt;', escape: false)
            ->assertSee('&lt;img src=x onerror=&quot;window.queueReason = true&quot;&gt;', escape: false)
            ->assertDontSee('<script>window.queueLabel = true</script>', escape: false)
            ->assertDontSee('<img src=x onerror="window.queueReason = true">', escape: false);
    }

    public function test_queue_queries_are_a_fixed_set_of_database_aggregates(): void
    {
        Livewire::actingAs(User::factory()->admin()->create())
            ->test(Dashboard::class);

        DB::flushQueryLog();
        DB::enableQueryLog();

        app(StudioWorkQueueService::class)->summaries();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(11, count($queries));
        $this->assertNotEmpty($queries);

        foreach ($queries as $query) {
            $this->assertStringContainsString('count(*)', strtolower($query['query']));
            $this->assertStringContainsString('min(', strtolower($query['query']));
        }
    }

    /** @param array<string, mixed> $attributes */
    private function artwork(string $title, array $attributes = []): Artwork
    {
        return Artwork::withoutEvents(fn (): Artwork => Artwork::query()->forceCreate(array_replace([
            'title' => $title,
            'slug' => str($title)->slug()->toString(),
            'image_path' => 'artworks/originals/'.str($title)->slug().'.jpg',
            'alt_text' => 'A described artwork.',
            'published' => true,
            'published_at' => now()->subHour(),
            'variant_status' => Artwork::VARIANT_STATUS_READY,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes)));
    }

    /** @param array<string, mixed> $attributes */
    private function track(string $title, array $attributes = []): Track
    {
        return Track::withoutEvents(fn (): Track => Track::query()->forceCreate(array_replace([
            'title' => $title,
            'slug' => str($title)->slug()->toString(),
            'audio_path' => 'tracks/audio/'.str($title)->slug().'.mp3',
            'standalone_published' => false,
            'standalone_published_at' => null,
            'analysis_status' => 'ready',
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes)));
    }

    /** @param array<string, mixed> $attributes */
    private function album(string $title, array $attributes = []): Album
    {
        return Album::query()->forceCreate(array_replace([
            'title' => $title,
            'slug' => str($title)->slug()->toString(),
            'published' => true,
            'published_at' => now()->subHour(),
            'cover_preference' => 'auto',
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }

    /** @param array<string, mixed> $attributes */
    private function playlist(string $title, array $attributes = []): Playlist
    {
        return Playlist::query()->forceCreate(array_replace([
            'title' => $title,
            'slug' => str($title)->slug()->toString(),
            'published' => true,
            'published_at' => now()->subHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }

    /** @param array<string, mixed> $attributes */
    private function makeJournalPost(string $title, array $attributes = []): Post
    {
        return Post::withoutEvents(fn (): Post => Post::query()->forceCreate(array_replace([
            'title' => $title,
            'slug' => str($title)->slug()->toString(),
            'body' => 'Saved Journal body.',
            'status' => PostStatus::Draft->value,
            'published' => false,
            'scheduled_at' => null,
            'published_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes)));
    }

    /** @param array<string, mixed> $attributes */
    private function journalAiRun(
        Post $post,
        PostAiRunStatus $status,
        CarbonInterface $createdAt,
        array $attributes = [],
    ): void {
        DB::table('post_ai_runs')->insert(array_replace([
            'post_id' => $post->getKey(),
            'operation' => 'directions',
            'status' => $status->value,
            'queue_token' => (string) Str::uuid(),
            'queue_name' => 'ai',
            'queue_priority' => 0,
            'source_hash' => str_repeat('a', 64),
            'context_hash' => str_repeat('b', 64),
            'request_hash' => hash('sha256', (string) Str::uuid()),
            'context_manifest' => '{}',
            'external_processing' => false,
            'provider' => 'ollama',
            'model' => 'test-model',
            'normalized_endpoint' => 'http://ollama.test:11434',
            'provider_profile_hash' => str_repeat('c', 64),
            'generation_options' => '{}',
            'prompt_version' => 'test-v1',
            'prompt_hash' => str_repeat('d', 64),
            'schema_version' => 'test-v1',
            'schema_hash' => str_repeat('e', 64),
            'queued_at' => $createdAt,
            'completed_at' => in_array($status, [PostAiRunStatus::Failed, PostAiRunStatus::Stale], true)
                ? $createdAt
                : null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ], $attributes));
    }
}
