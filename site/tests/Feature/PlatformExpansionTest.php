<?php

namespace Tests\Feature;

use App\Filament\Pages\AiConfiguration;
use App\Jobs\AnalyzeArtworkWithAi;
use App\Models\Artwork;
use App\Models\Collection;
use App\Models\Playlist;
use App\Models\Post;
use App\Models\SiteSetting;
use App\Models\Tag;
use App\Models\Track;
use App\Models\User;
use App\Services\AiSettings;
use App\Services\ArtworkAiMetadataService;
use App\Services\ArtworkAiQueueService;
use App\Services\ArtworkBulkUploadService;
use App\Services\SmartCollectionService;
use App\Services\SmartPlaylistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class PlatformExpansionTest extends TestCase
{
    use RefreshDatabase;

    public function test_cloud_api_keys_are_encrypted_hidden_and_preserved(): void
    {
        $settings = app(AiSettings::class);
        $settings->save([
            'provider' => 'openai',
            'openai_api_key' => 'secret-cloud-key',
        ]);

        $stored = SiteSetting::query()->where('key', AiSettings::SETTING_KEY)->firstOrFail()->value;

        $this->assertStringStartsWith('encrypted:', $stored['openai_api_key']);
        $this->assertStringNotContainsString('secret-cloud-key', $stored['openai_api_key']);
        $this->assertSame('secret-cloud-key', app(AiSettings::class)->apiKey('openai'));
        $this->assertSame('', app(AiSettings::class)->formValues()['openai_api_key']);

        app(AiSettings::class)->save(['provider' => 'openai', 'openai_api_key' => '']);

        $this->assertSame('secret-cloud-key', app(AiSettings::class)->apiKey('openai'));
    }

    public function test_switching_provider_refreshes_the_matching_model_catalog(): void
    {
        config([
            'services.ollama.base_url' => 'http://ollama.test:11434',
            'services.openai.api_key' => 'openai-test-key',
            'services.openai.base_url' => 'https://api.openai.com/v1',
        ]);

        Http::fake([
            'ollama.test:11434/api/version' => Http::response(['version' => '0.31.2']),
            'ollama.test:11434/api/tags' => Http::response(['models' => []]),
            'api.openai.com/v1/models' => Http::response([
                'data' => [['id' => 'gpt-5.4-mini']],
            ]),
        ]);

        $this->actingAs(User::factory()->create());

        Livewire::test(AiConfiguration::class)
            ->set('data.provider', 'openai')
            ->assertSet('providerVersion', 'Responses API')
            ->assertSet('providerModels.0.name', 'gpt-5.4-mini')
            ->assertSet('providerError', null);
    }

    public function test_anthropic_uses_messages_vision_and_structured_output(): void
    {
        Storage::fake('public');
        $artwork = $this->artwork();
        app(AiSettings::class)->save([
            'provider' => 'anthropic',
            'anthropic_api_key' => 'anthropic-test-key',
            'anthropic_model' => 'claude-sonnet-test',
        ]);

        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'content' => [['type' => 'text', 'text' => json_encode($this->suggestion())]],
            ]),
        ]);

        $result = app(ArtworkAiMetadataService::class)->analyze($artwork);

        $this->assertSame('Chromatic Echo', $result['title']);
        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return $request->url() === 'https://api.anthropic.com/v1/messages'
                && $request->hasHeader('x-api-key', 'anthropic-test-key')
                && data_get($payload, 'model') === 'claude-sonnet-test'
                && data_get($payload, 'messages.0.content.0.type') === 'image'
                && data_get($payload, 'output_config.format.type') === 'json_schema';
        });
    }

    public function test_zai_uses_vision_chat_and_json_mode(): void
    {
        Storage::fake('public');
        $artwork = $this->artwork();
        app(AiSettings::class)->save([
            'provider' => 'zai',
            'zai_api_key' => 'zai-test-key',
            'zai_model' => 'glm-4.6v-flash',
        ]);

        Http::fake([
            'https://api.z.ai/api/paas/v4/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => json_encode($this->suggestion())]]],
            ]),
        ]);

        app(ArtworkAiMetadataService::class)->analyze($artwork);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.z.ai/api/paas/v4/chat/completions'
            && data_get($request->data(), 'messages.1.content.0.type') === 'image_url'
            && data_get($request->data(), 'response_format.type') === 'json_object');
    }

    public function test_smart_collection_syncs_artwork_by_tag_rules(): void
    {
        Storage::fake('public');
        $match = $this->artwork(['title' => 'Match']);
        $other = $this->artwork(['title' => 'Other']);
        $tag = Tag::query()->create(['name' => 'neon', 'slug' => 'neon']);
        $match->tags()->attach($tag, ['category' => 'style']);
        $collection = Collection::query()->create([
            'title' => 'Neon Worlds',
            'is_smart' => true,
            'auto_sync' => false,
            'smart_rules' => ['tag_ids' => [$tag->id], 'match' => 'all', 'only_published' => true],
        ]);

        $count = app(SmartCollectionService::class)->sync($collection);

        $this->assertSame(1, $count);
        $this->assertTrue($collection->artworks()->whereKey($match->id)->exists());
        $this->assertFalse($collection->artworks()->whereKey($other->id)->exists());
    }

    public function test_smart_playlist_syncs_tracks_and_positions_by_tag_rules(): void
    {
        $tag = Tag::query()->create(['name' => 'ambient', 'slug' => 'ambient']);
        $first = $this->track(['title' => 'First', 'sort_order' => 1]);
        $second = $this->track(['title' => 'Second', 'sort_order' => 2]);
        $first->tags()->attach($tag, ['category' => 'genre']);
        $playlist = Playlist::query()->create([
            'title' => 'Quiet Systems',
            'is_smart' => true,
            'auto_sync' => false,
            'smart_rules' => ['tag_ids' => [$tag->id], 'match' => 'any', 'only_published' => true],
        ]);

        $count = app(SmartPlaylistService::class)->sync($playlist);

        $this->assertSame(1, $count);
        $this->assertSame([$first->id], $playlist->tracks()->pluck('tracks.id')->all());
        $this->assertSame(1, $playlist->tracks()->first()->pivot->position);
        $this->assertFalse($playlist->tracks()->whereKey($second->id)->exists());
    }

    public function test_pending_analysis_queues_only_unanalyzed_eligible_artwork(): void
    {
        Storage::fake('public');
        Queue::fake();
        $this->artwork(['title' => 'Idle']);
        $this->artwork(['title' => 'Failed', 'ai_status' => Artwork::AI_STATUS_FAILED]);
        $this->artwork(['title' => 'Already analyzed', 'ai_status' => Artwork::AI_STATUS_APPLIED, 'ai_analyzed_at' => now()]);

        $count = app(ArtworkAiQueueService::class)->queuePending();

        $this->assertSame(2, $count);
        Queue::assertPushed(AnalyzeArtworkWithAi::class, 2);
    }

    public function test_bulk_upload_creates_multiple_artworks_collections_and_jobs(): void
    {
        Storage::fake('public');
        Queue::fake();
        $collection = Collection::query()->create(['title' => 'New Batch']);
        $paths = ['artworks/originals/one.jpg', 'artworks/originals/two.jpg'];
        Storage::disk('public')->put($paths[0], UploadedFile::fake()->image('one.jpg', 600, 800)->getContent());
        Storage::disk('public')->put($paths[1], UploadedFile::fake()->image('two.jpg', 800, 600)->getContent());

        $created = app(ArtworkBulkUploadService::class)->create(
            $paths,
            [$paths[0] => 'First Vision.jpg', $paths[1] => 'Second Vision.jpg'],
            [$collection->id],
            published: true,
            analyze: true,
        );

        $this->assertCount(2, $created);
        $this->assertSame(['First Vision', 'Second Vision'], Artwork::query()->orderBy('id')->pluck('title')->all());
        $this->assertSame(2, $collection->artworks()->count());
        Queue::assertPushed(AnalyzeArtworkWithAi::class, 2);
    }

    public function test_published_journal_post_has_public_page_feed_and_sitemap(): void
    {
        $post = Post::query()->create([
            'title' => 'A New Creative Baseline',
            'body' => 'A short update from the studio.',
            'published' => true,
            'published_at' => now()->subMinute(),
        ]);

        $this->get(route('posts.show', $post))->assertOk()->assertSee($post->title);
        $this->get(route('feed'))->assertOk()->assertSee($post->title);
        $this->get(route('sitemap'))->assertOk()->assertSee(route('posts.show', $post), false);
    }

    /** @param array<string, mixed> $overrides */
    protected function artwork(array $overrides = []): Artwork
    {
        $path = 'artworks/originals/'.uniqid().'.jpg';
        Storage::disk('public')->put($path, UploadedFile::fake()->image('art.jpg', 900, 600)->getContent());

        return Artwork::query()->create(array_replace([
            'title' => 'Artwork',
            'image_path' => $path,
            'published' => true,
            'ai_status' => Artwork::AI_STATUS_IDLE,
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    protected function track(array $overrides = []): Track
    {
        return Track::query()->create(array_replace([
            'title' => 'Track',
            'audio_path' => 'tracks/audio/test.mp3',
            'published' => true,
        ], $overrides));
    }

    /** @return array<string, mixed> */
    protected function suggestion(): array
    {
        return [
            'title' => 'Chromatic Echo',
            'description' => 'A polished description.',
            'alt_text' => 'Abstract colored shapes.',
            'tags' => ['abstract'],
            'style_tags' => ['surreal'],
            'mood_tags' => ['calm'],
            'color_tags' => ['blue'],
            'medium_tags' => ['digital art'],
            'confidence' => 0.9,
            'content_warning' => '',
        ];
    }
}
