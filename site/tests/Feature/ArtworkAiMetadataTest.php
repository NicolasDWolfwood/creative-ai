<?php

namespace Tests\Feature;

use App\Filament\Pages\AiConfiguration;
use App\Filament\Resources\AiQueues\Pages\ManageAiQueue;
use App\Filament\Resources\Artworks\Pages\ManageArtworks;
use App\Jobs\AnalyzeArtworkWithAi;
use App\Models\Artwork;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\AiSettings;
use App\Services\ArtworkAiMetadataService;
use App\Services\ArtworkAiQueueService;
use App\Services\ImageVariantService;
use App\Services\OllamaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;
use Throwable;

class ArtworkAiMetadataTest extends TestCase
{
    use RefreshDatabase;

    public function test_analysis_image_is_downscaled_and_reencoded_as_jpeg(): void
    {
        Storage::fake('public');
        config([
            'creative_ai.ai.image_max_width' => 768,
            'creative_ai.ai.image_jpeg_quality' => 72,
        ]);

        $path = $this->storeImage(width: 1400, height: 900);
        $analysis = app(ImageVariantService::class)->createAnalysisImageData($path);

        $this->assertStringStartsWith('data:image/jpeg;base64,', $analysis['data_url']);

        $jpeg = base64_decode(substr($analysis['data_url'], strlen('data:image/jpeg;base64,')));
        $size = getimagesizefromstring($jpeg);

        $this->assertSame(768, $size[0]);
        $this->assertSame(494, $size[1]);
        $this->assertGreaterThan(0, $analysis['bytes']);
    }

    public function test_openai_request_uses_responses_api_with_structured_image_input(): void
    {
        Storage::fake('public');
        $artwork = $this->createArtwork();

        config([
            'services.openai.api_key' => 'test-key',
            'services.openai.base_url' => 'https://api.openai.com/v1',
            'creative_ai.ai.model' => 'gpt-5.4-mini',
        ]);

        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'output_text' => json_encode($this->suggestionPayload(title: 'Neon Bloom')),
            ]),
        ]);

        $suggestion = app(ArtworkAiMetadataService::class)->analyze($artwork);

        $this->assertSame('Neon Bloom', $suggestion['title']);

        Http::assertSent(function ($request): bool {
            $data = $request->data();
            $content = $data['input'][0]['content'];

            return $request->url() === 'https://api.openai.com/v1/responses'
                && $data['model'] === 'gpt-5.4-mini'
                && $data['text']['format']['type'] === 'json_schema'
                && $data['text']['format']['strict'] === true
                && $content[1]['type'] === 'input_image'
                && $content[1]['detail'] === 'low'
                && str_starts_with($content[1]['image_url'], 'data:image/jpeg;base64,');
        });
    }

    public function test_ollama_request_uses_selected_server_model_image_and_schema(): void
    {
        Storage::fake('public');
        $artwork = $this->createArtwork();
        $this->configureOllama();

        Http::fake([
            'ollama.test:11434/api/chat' => Http::response([
                'message' => [
                    'content' => json_encode($this->suggestionPayload(title: 'Local Vision')),
                    'thinking' => '',
                ],
                'done' => true,
            ]),
        ]);

        $suggestion = app(ArtworkAiMetadataService::class)->analyze($artwork);

        $this->assertSame('Local Vision', $suggestion['title']);
        $this->assertSame('ollama:qwen3.5:latest', app(ArtworkAiMetadataService::class)->model());

        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return $request->url() === 'http://ollama.test:11434/api/chat'
                && $data['model'] === 'qwen3.5:latest'
                && $data['stream'] === false
                && $data['think'] === false
                && $data['keep_alive'] === '5m'
                && $data['options']['num_ctx'] === 4096
                && $data['format']['type'] === 'object'
                && $data['messages'][0]['role'] === 'user'
                && ! str_starts_with($data['messages'][0]['images'][0], 'data:image/');
        });
    }

    public function test_ollama_can_decode_structured_output_from_thinking_field(): void
    {
        Storage::fake('public');
        $artwork = $this->createArtwork();
        $this->configureOllama(['ollama_model' => 'qwen3-vl:latest']);

        Http::fake([
            'ollama.test:11434/api/chat' => Http::response([
                'message' => [
                    'content' => '',
                    'thinking' => json_encode($this->suggestionPayload(title: 'Thinking Vision')),
                ],
                'done' => true,
            ]),
        ]);

        $suggestion = app(ArtworkAiMetadataService::class)->analyze($artwork);

        $this->assertSame('Thinking Vision', $suggestion['title']);
    }

    public function test_ollama_inspection_builds_a_capability_matrix(): void
    {
        $this->configureOllama();

        Http::fake([
            'ollama.test:11434/api/version' => Http::response(['version' => '0.31.2']),
            'ollama.test:11434/api/tags' => Http::response([
                'models' => [[
                    'name' => 'vision-model:latest',
                    'size' => 6_100_000_000,
                    'details' => [
                        'parameter_size' => '9B',
                        'quantization_level' => 'Q4_K_M',
                    ],
                ]],
            ]),
            'ollama.test:11434/api/show' => Http::response([
                'capabilities' => ['vision', 'completion', 'tools'],
                'details' => [
                    'parameter_size' => '9B',
                    'quantization_level' => 'Q4_K_M',
                ],
                'model_info' => [
                    'model.context_length' => 262144,
                ],
            ]),
        ]);

        $inspection = app(OllamaClient::class)->inspect();
        $model = $inspection['models'][0];

        $this->assertSame('0.31.2', $inspection['version']);
        $this->assertSame('vision-model:latest', $model['name']);
        $this->assertSame('6.1 GB', $model['size_label']);
        $this->assertSame('262,144', $model['context_label']);
        $this->assertTrue($model['suitable']);
        $this->assertContains('vision', $model['capabilities']);
    }

    public function test_ai_configuration_page_saves_database_overrides(): void
    {
        Http::fake([
            'ollama.test:11434/api/version' => Http::response(['version' => '0.31.2']),
            'ollama.test:11434/api/tags' => Http::response(['models' => []]),
        ]);

        config(['services.ollama.base_url' => 'http://ollama.test:11434']);
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(AiConfiguration::class)
            ->set('data.provider', 'ollama')
            ->set('data.ollama_base_url', 'http://ollama.test:11434/api/')
            ->set('data.ollama_model', 'qwen3.5:latest')
            ->set('data.ollama_context_length', 4096)
            ->set('data.ollama_keep_alive', '5m')
            ->set('data.ollama_request_timeout', 150)
            ->call('save')
            ->assertHasNoErrors();

        $stored = SiteSetting::query()->where('key', AiSettings::SETTING_KEY)->firstOrFail()->value;

        $this->assertSame('ollama', $stored['provider']);
        $this->assertSame('http://ollama.test:11434', $stored['ollama_base_url']);
        $this->assertSame('qwen3.5:latest', $stored['ollama_model']);
        $this->assertSame(4096, $stored['ollama_context_length']);
    }

    public function test_job_stores_ready_ai_suggestion_without_changing_public_fields(): void
    {
        Storage::fake('public');
        $artwork = $this->createArtwork([
            'title' => 'Original Title',
            'description' => 'Original description.',
            'ai_status' => Artwork::AI_STATUS_APPLIED,
            'ai_suggestion' => $this->suggestionPayload(title: 'Old Suggestion'),
        ]);

        config([
            'services.openai.api_key' => 'test-key',
            'services.openai.base_url' => 'https://api.openai.com/v1',
        ]);

        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'output_text' => json_encode($this->suggestionPayload(title: 'New Suggestion')),
            ]),
        ]);

        $token = 'analysis-token';
        $artwork->forceFill([
            'ai_status' => Artwork::AI_STATUS_QUEUED,
            'ai_queue_token' => $token,
        ])->saveQuietly();

        (new AnalyzeArtworkWithAi($artwork->id, $token, force: true))->handle(app(ArtworkAiMetadataService::class));

        $artwork->refresh();

        $this->assertSame(Artwork::AI_STATUS_READY, $artwork->ai_status);
        $this->assertNull($artwork->ai_queue_token);
        $this->assertSame('New Suggestion', $artwork->ai_suggestion['title']);
        $this->assertSame('Original Title', $artwork->title);
        $this->assertSame('Original description.', $artwork->description);
    }

    public function test_failed_job_marks_artwork_failed(): void
    {
        Storage::fake('public');
        $artwork = $this->createArtwork();

        config(['services.openai.api_key' => null]);

        $token = 'analysis-token';
        $artwork->forceFill([
            'ai_status' => Artwork::AI_STATUS_QUEUED,
            'ai_queue_token' => $token,
        ])->saveQuietly();

        $job = new AnalyzeArtworkWithAi($artwork->id, $token, force: true);

        try {
            $job->handle(app(ArtworkAiMetadataService::class));
            $this->fail('The job should throw when OpenAI is not configured.');
        } catch (Throwable $exception) {
            $job->failed($exception);
        }

        $artwork->refresh();

        $this->assertSame(Artwork::AI_STATUS_FAILED, $artwork->ai_status);
        $this->assertNull($artwork->ai_queue_token);
        $this->assertStringContainsString('OPENAI_API_KEY', $artwork->ai_error);
    }

    public function test_retry_continues_when_the_previous_attempt_left_the_artwork_processing(): void
    {
        Storage::fake('public');
        $artwork = $this->createArtwork([
            'ai_status' => Artwork::AI_STATUS_PROCESSING,
            'ai_queue_token' => 'analysis-token',
        ]);

        config([
            'services.openai.api_key' => 'test-key',
            'services.openai.base_url' => 'https://api.openai.com/v1',
        ]);

        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'output_text' => json_encode($this->suggestionPayload(title: 'Recovered Analysis')),
            ]),
        ]);

        (new AnalyzeArtworkWithAi($artwork->id, 'analysis-token', force: true))
            ->handle(app(ArtworkAiMetadataService::class));

        $artwork->refresh();

        $this->assertSame(Artwork::AI_STATUS_READY, $artwork->ai_status);
        $this->assertNull($artwork->ai_queue_token);
        $this->assertSame('Recovered Analysis', $artwork->ai_suggestion['title']);
        Http::assertSentCount(1);
    }

    public function test_stale_ai_job_token_does_not_analyze_artwork(): void
    {
        Storage::fake('public');
        $artwork = $this->createArtwork([
            'ai_status' => Artwork::AI_STATUS_QUEUED,
            'ai_queue_token' => 'current-token',
        ]);

        config([
            'services.openai.api_key' => 'test-key',
            'services.openai.base_url' => 'https://api.openai.com/v1',
        ]);

        Http::fake();

        (new AnalyzeArtworkWithAi($artwork->id, 'stale-token', force: true))->handle(app(ArtworkAiMetadataService::class));

        $artwork->refresh();

        $this->assertSame(Artwork::AI_STATUS_QUEUED, $artwork->ai_status);
        $this->assertSame('current-token', $artwork->ai_queue_token);
        $this->assertNull($artwork->ai_suggestion);
        Http::assertNothingSent();
    }

    public function test_applying_suggestions_updates_public_metadata_and_tags_without_changing_slug(): void
    {
        Storage::fake('public');
        $artwork = $this->createArtwork([
            'title' => 'Original Title',
            'slug' => 'original-title',
            'ai_status' => Artwork::AI_STATUS_READY,
            'ai_suggestion' => $this->suggestionPayload(title: 'Neon Bloom'),
        ]);

        app(ArtworkAiMetadataService::class)->applySuggestion($artwork);

        $artwork->refresh();

        $this->assertSame('Neon Bloom', $artwork->title);
        $this->assertSame('original-title', $artwork->slug);
        $this->assertSame('A luminous abstract flower opens against a dark background.', $artwork->description);
        $this->assertSame('A luminous abstract flower shape against a dark background.', $artwork->alt_text);
        $this->assertSame(Artwork::AI_STATUS_APPLIED, $artwork->ai_status);
        $this->assertEqualsCanonicalizing(
            ['abstract', 'blue', 'digital art', 'glowing', 'neon', 'surreal'],
            $artwork->tags()->pluck('name')->all(),
        );
    }

    public function test_filament_row_action_queues_ai_analysis(): void
    {
        Storage::fake('public');
        Queue::fake();

        $user = User::factory()->create();
        $artwork = $this->createArtwork();

        $this->actingAs($user);

        Livewire::test(ManageArtworks::class)
            ->callTableAction('analyzeWithAi', $artwork);

        Queue::assertPushed(AnalyzeArtworkWithAi::class);
        $this->assertSame(Artwork::AI_STATUS_QUEUED, $artwork->refresh()->ai_status);
        $this->assertNotNull($artwork->ai_queue_token);
    }

    public function test_filament_bulk_action_queues_selected_artworks(): void
    {
        Storage::fake('public');
        Queue::fake();

        $user = User::factory()->create();
        $first = $this->createArtwork(['title' => 'First']);
        $second = $this->createArtwork(['title' => 'Second']);

        $this->actingAs($user);

        Livewire::test(ManageArtworks::class)
            ->callTableBulkAction('analyzeSelectedWithAi', [$first, $second]);

        Queue::assertPushed(AnalyzeArtworkWithAi::class, 2);
        $this->assertSame(Artwork::AI_STATUS_QUEUED, $first->refresh()->ai_status);
        $this->assertSame(Artwork::AI_STATUS_QUEUED, $second->refresh()->ai_status);
    }

    public function test_prioritizing_queued_artwork_dispatches_high_priority_job_and_invalidates_old_token(): void
    {
        Storage::fake('public');
        Queue::fake();

        $artwork = $this->createArtwork();
        $oldToken = AnalyzeArtworkWithAi::dispatchFor($artwork, force: true);

        app(ArtworkAiQueueService::class)->prioritize($artwork->refresh());

        $artwork->refresh();

        $this->assertSame(Artwork::AI_STATUS_QUEUED, $artwork->ai_status);
        $this->assertNotSame($oldToken, $artwork->ai_queue_token);

        Queue::assertPushed(
            AnalyzeArtworkWithAi::class,
            fn (AnalyzeArtworkWithAi $job): bool => $job->queue === ArtworkAiQueueService::HIGH_PRIORITY_QUEUE
                && $job->queueToken === $artwork->ai_queue_token,
        );
    }

    public function test_ai_queue_page_clear_queued_action_cancels_pending_jobs(): void
    {
        Storage::fake('public');
        Queue::fake();

        $user = User::factory()->create();
        $queued = $this->createArtwork(['title' => 'Queued']);
        $processing = $this->createArtwork([
            'title' => 'Processing',
            'ai_status' => Artwork::AI_STATUS_PROCESSING,
            'ai_queue_token' => 'processing-token',
        ]);

        AnalyzeArtworkWithAi::dispatchFor($queued, force: true);

        $this->actingAs($user);

        Livewire::test(ManageAiQueue::class)
            ->callAction('clearQueued');

        $this->assertSame(Artwork::AI_STATUS_IDLE, $queued->refresh()->ai_status);
        $this->assertNull($queued->ai_queue_token);
        $this->assertSame(Artwork::AI_STATUS_PROCESSING, $processing->refresh()->ai_status);
        $this->assertSame('processing-token', $processing->ai_queue_token);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function createArtwork(array $attributes = []): Artwork
    {
        $title = $attributes['title'] ?? 'Artwork Test';

        return Artwork::query()->create([
            'title' => $title,
            'slug' => $attributes['slug'] ?? str($title)->slug()->toString(),
            'description' => $attributes['description'] ?? 'Original artwork description.',
            'image_path' => $attributes['image_path'] ?? $this->storeImage(),
            'published' => $attributes['published'] ?? true,
            'ai_status' => $attributes['ai_status'] ?? Artwork::AI_STATUS_IDLE,
            'ai_queue_token' => $attributes['ai_queue_token'] ?? null,
            'ai_suggestion' => $attributes['ai_suggestion'] ?? null,
        ]);
    }

    protected function storeImage(int $width = 900, int $height = 600): string
    {
        $filename = uniqid('artwork-', true).'.jpg';

        Storage::disk('public')->putFileAs(
            'artworks/originals',
            UploadedFile::fake()->image($filename, $width, $height),
            $filename,
        );

        return 'artworks/originals/'.$filename;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function configureOllama(array $overrides = []): void
    {
        app(AiSettings::class)->save(array_replace([
            'provider' => 'ollama',
            'ollama_base_url' => 'http://ollama.test:11434',
            'ollama_model' => 'qwen3.5:latest',
            'ollama_request_timeout' => 150,
            'ollama_context_length' => 4096,
            'ollama_keep_alive' => '5m',
        ], $overrides));
    }

    /**
     * @return array<string, mixed>
     */
    protected function suggestionPayload(string $title = 'Neon Bloom'): array
    {
        return [
            'title' => $title,
            'description' => 'A luminous abstract flower opens against a dark background.',
            'alt_text' => 'A luminous abstract flower shape against a dark background.',
            'tags' => ['abstract', 'neon'],
            'style_tags' => ['surreal', 'glowing'],
            'mood_tags' => ['glowing'],
            'color_tags' => ['blue'],
            'medium_tags' => ['digital art'],
            'confidence' => 0.83,
            'content_warning' => '',
        ];
    }
}
