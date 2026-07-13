<?php

namespace App\Services;

use App\Data\JournalAiProviderResult;
use App\Exceptions\AiProviderException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class OllamaClient
{
    public function __construct(
        protected AiSettings $settings,
    ) {}

    /**
     * @return array{version:string, models:array<int, array<string, mixed>>}
     */
    public function inspect(?string $baseUrl = null): array
    {
        $client = $this->client($baseUrl, 20);
        $version = $client->get('api/version')->throw()->json('version');
        $models = $client->get('api/tags')->throw()->json('models', []);

        return [
            'version' => is_string($version) ? $version : 'Unknown',
            'models' => collect(is_array($models) ? $models : [])
                ->map(fn (array $model): array => $this->normalizeModel($client, $model))
                ->sortBy([
                    fn (array $model): int => ($model['image_suitable'] || $model['journal_suitable']) ? 0 : 1,
                    fn (array $model): string => strtolower($model['name']),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $analysisImage
     */
    public function analyze(string $prompt, array $schema, array $analysisImage): array
    {
        return $this->chat($prompt, $schema, [Str::after((string) $analysisImage['data_url'], ',')]);
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function generateStructured(string $prompt, array $schema): array
    {
        return $this->chat($prompt, $schema);
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    public function generateJournalStructured(
        ProviderExecutionProfile $profile,
        string $instructions,
        string $input,
        array $schema,
        int $maxTokens,
    ): JournalAiProviderResult {
        $profile->assertProvider('ollama');
        ProviderExecutionProfile::validateStructuredRequest($instructions, $input, $schema, $maxTokens);
        $profile->assertCurrent($this->settings);
        $profile->assertRequestCapacity($instructions, $input, $schema, $maxTokens);

        try {
            $providerResponse = $this->profileClient($profile)
                ->post('api/chat', [
                    'model' => $profile->model,
                    'messages' => [
                        ['role' => 'system', 'content' => $instructions],
                        ['role' => 'user', 'content' => $input],
                    ],
                    'format' => AiSchema::portable($schema),
                    'stream' => false,
                    'think' => false,
                    'keep_alive' => (string) ($profile->outputSettings['keep_alive'] ?? '5m'),
                    'options' => [
                        'num_ctx' => (int) ($profile->outputSettings['context_length'] ?? 4096),
                        'num_predict' => $maxTokens,
                        'temperature' => 0.2,
                    ],
                ]);

            $response = JournalAiHttpResponse::decode($providerResponse);
        } catch (AiProviderException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw AiProviderException::fromProviderFailure($exception);
        }

        $content = is_array($response) ? data_get($response, 'message.content') : null;
        $decoded = is_string($content) ? json_decode($content, true) : null;

        if (! is_array($decoded)) {
            throw AiProviderException::invalidStructuredOutput();
        }

        return new JournalAiProviderResult(
            payload: $decoded,
            inputTokens: data_get($response, 'prompt_eval_count'),
            outputTokens: data_get($response, 'eval_count'),
        );
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<int, string>  $images
     * @return array<string, mixed>
     */
    protected function chat(string $prompt, array $schema, array $images = []): array
    {
        $message = ['role' => 'user', 'content' => $prompt];

        if ($images !== []) {
            $message['images'] = $images;
        }

        $response = $this->client(timeout: $this->settings->ollamaRequestTimeout())
            ->retry(2, 750)
            ->post('api/chat', [
                'model' => $this->settings->model(),
                'messages' => [$message],
                'format' => AiSchema::portable($schema),
                'stream' => false,
                'think' => false,
                'keep_alive' => $this->settings->ollamaKeepAlive(),
                'options' => [
                    'num_ctx' => $this->settings->ollamaContextLength(),
                    'num_predict' => 1000,
                    'temperature' => 0.2,
                ],
            ])
            ->throw()
            ->json();

        $content = data_get($response, 'message.content');

        if (! is_string($content) || blank($content)) {
            $content = data_get($response, 'message.thinking');
        }

        if (! is_string($content) || blank($content)) {
            throw new RuntimeException('Ollama response did not include structured output text.');
        }

        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Ollama response was not valid JSON.');
        }

        return $decoded;
    }

    protected function client(?string $baseUrl = null, ?int $timeout = null): PendingRequest
    {
        return Http::baseUrl(rtrim($baseUrl ?: $this->settings->ollamaBaseUrl(), '/').'/')
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout($timeout ?: $this->settings->ollamaRequestTimeout());
    }

    protected function profileClient(ProviderExecutionProfile $profile): PendingRequest
    {
        return Http::baseUrl($profile->endpoint.'/')
            ->withoutRedirecting()
            ->withOptions(['stream' => true])
            ->acceptJson()
            ->asJson()
            ->connectTimeout(min(5, $profile->timeoutSeconds))
            ->timeout($profile->timeoutSeconds);
    }

    /**
     * @param  array<string, mixed>  $model
     * @return array<string, mixed>
     */
    protected function normalizeModel(PendingRequest $client, array $model): array
    {
        $name = (string) ($model['name'] ?? $model['model'] ?? 'Unknown');
        $details = is_array($model['details'] ?? null) ? $model['details'] : [];
        $capabilities = is_array($model['capabilities'] ?? null) ? $model['capabilities'] : [];
        $modelInfo = [];

        try {
            $shown = $client->post('api/show', ['model' => $name, 'verbose' => false])->throw()->json();
            $capabilities = is_array($shown['capabilities'] ?? null) ? $shown['capabilities'] : $capabilities;
            $details = array_replace($details, is_array($shown['details'] ?? null) ? $shown['details'] : []);
            $modelInfo = is_array($shown['model_info'] ?? null) ? $shown['model_info'] : [];
        } catch (Throwable) {
            // The tags response still provides enough information for a useful row.
        }

        $capabilities = collect($capabilities)
            ->filter(fn (mixed $capability): bool => is_string($capability))
            ->map(fn (string $capability): string => strtolower($capability))
            ->unique()
            ->values()
            ->all();

        if (in_array('completion', $capabilities, true) && ! in_array('structured', $capabilities, true)) {
            $capabilities[] = 'structured';
        }

        $contextLength = (int) ($details['context_length'] ?? $this->findContextLength($modelInfo));
        $size = (int) ($model['size'] ?? 0);

        $completion = in_array('completion', $capabilities, true);
        $structured = in_array('structured', $capabilities, true);
        $imageSuitable = in_array('vision', $capabilities, true) && $completion && $structured;
        $journalSuitable = $completion && $structured;

        return [
            'name' => $name,
            'size' => $size,
            'size_label' => $this->formatBytes($size),
            'parameter_size' => (string) ($details['parameter_size'] ?? 'Unknown'),
            'quantization' => (string) ($details['quantization_level'] ?? 'Unknown'),
            'context_length' => $contextLength,
            'context_label' => $contextLength > 0 ? number_format($contextLength) : 'Unknown',
            'capabilities' => $capabilities,
            'image_suitable' => $imageSuitable,
            'journal_suitable' => $journalSuitable,
            'suitable' => $imageSuitable,
            'recommended' => $name === 'qwen3.5:latest',
        ];
    }

    /**
     * @param  array<string, mixed>  $modelInfo
     */
    protected function findContextLength(array $modelInfo): int
    {
        foreach ($modelInfo as $key => $value) {
            if (str_ends_with((string) $key, '.context_length') && is_numeric($value)) {
                return (int) $value;
            }
        }

        return 0;
    }

    protected function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return 'Unknown';
        }

        return number_format($bytes / 1_000_000_000, 1).' GB';
    }
}
