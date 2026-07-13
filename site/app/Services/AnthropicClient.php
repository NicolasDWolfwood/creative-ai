<?php

namespace App\Services;

use App\Data\JournalAiProviderResult;
use App\Exceptions\AiProviderException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class AnthropicClient
{
    public function __construct(protected AiSettings $settings) {}

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $analysisImage
     */
    public function analyze(string $prompt, array $schema, array $analysisImage): array
    {
        return $this->request([
            'model' => $this->settings->model('anthropic'),
            'max_tokens' => 1200,
            'messages' => [[
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'image',
                        'source' => [
                            'type' => 'base64',
                            'media_type' => 'image/jpeg',
                            'data' => Str::after((string) $analysisImage['data_url'], ','),
                        ],
                    ],
                    ['type' => 'text', 'text' => $prompt],
                ],
            ]],
            'output_config' => $this->outputFormat($schema),
        ]);
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function generateStructured(string $prompt, array $schema): array
    {
        return $this->request([
            'model' => $this->settings->model('anthropic'),
            'max_tokens' => 1000,
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'output_config' => $this->outputFormat($schema),
        ]);
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
        $profile->assertProvider('anthropic');
        ProviderExecutionProfile::validateStructuredRequest($instructions, $input, $schema, $maxTokens);
        $profile->assertCurrent($this->settings);
        $profile->assertRequestCapacity($instructions, $input, $schema, $maxTokens);

        try {
            $providerResponse = $this->profileClient($profile)
                ->post('messages', [
                    'model' => $profile->model,
                    'max_tokens' => $maxTokens,
                    'system' => $instructions,
                    'messages' => [['role' => 'user', 'content' => $input]],
                    'output_config' => $this->outputFormat($schema),
                ]);

            $response = JournalAiHttpResponse::decode($providerResponse);
        } catch (AiProviderException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw AiProviderException::fromProviderFailure($exception);
        }

        $text = is_array($response)
            ? collect($response['content'] ?? [])->firstWhere('type', 'text')['text'] ?? null
            : null;
        $decoded = is_string($text) ? json_decode($text, true) : null;

        if (! is_array($decoded)) {
            throw AiProviderException::invalidStructuredOutput();
        }

        return new JournalAiProviderResult(
            payload: $decoded,
            providerRequestId: data_get($response, 'id'),
            inputTokens: data_get($response, 'usage.input_tokens'),
            outputTokens: data_get($response, 'usage.output_tokens'),
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{version:string, models:array<int, array<string, mixed>>}
     */
    public function inspect(array $overrides = []): array
    {
        $models = $this->client($overrides, 30)
            ->get('models', ['limit' => 100])
            ->throw()
            ->json('data', []);
        $imageSelected = (string) ($overrides['image_model'] ?? $overrides['model'] ?? $this->settings->model('anthropic'));
        $journalSelected = (string) ($overrides['journal_model'] ?? $this->settings->journalModel('anthropic'));

        return [
            'version' => 'Messages API',
            'models' => collect(is_array($models) ? $models : [])
                ->map(fn (array $model): array => $this->normalizeModel($model, $imageSelected, $journalSelected))
                ->filter(fn (array $model): bool => $model['image_suitable']
                    || $model['journal_suitable']
                    || in_array($model['name'], [$imageSelected, $journalSelected], true))
                ->sortBy([
                    fn (array $model): int => $model['recommended'] ? 0 : 1,
                    fn (array $model): string => $model['name'],
                ])
                ->values()
                ->all(),
        ];
    }

    /** @param array<string, mixed> $payload */
    protected function request(array $payload): array
    {
        $response = $this->client(timeout: $this->settings->requestTimeout('anthropic'))
            ->retry(2, 750)
            ->post('messages', $payload)
            ->throw()
            ->json();

        $text = collect($response['content'] ?? [])->firstWhere('type', 'text')['text'] ?? null;

        if (! is_string($text) || blank($text)) {
            throw new RuntimeException('Anthropic response did not include structured output text.');
        }

        $decoded = json_decode($text, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Anthropic response was not valid JSON.');
        }

        return $decoded;
    }

    /** @param array<string, mixed> $schema */
    protected function outputFormat(array $schema): array
    {
        return [
            'format' => [
                'type' => 'json_schema',
                'schema' => AiSchema::portable($schema),
            ],
        ];
    }

    /** @param array<string, mixed> $overrides */
    protected function client(array $overrides = [], ?int $timeout = null): PendingRequest
    {
        $apiKey = $this->settings->apiKey('anthropic', $overrides['api_key'] ?? null);

        if (blank($apiKey)) {
            throw new RuntimeException('An Anthropic API key is required.');
        }

        return Http::baseUrl(rtrim((string) ($overrides['base_url'] ?? $this->settings->baseUrl('anthropic')), '/').'/')
            ->withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
            ])
            ->acceptJson()
            ->asJson()
            ->connectTimeout(8)
            ->timeout($timeout ?: $this->settings->requestTimeout('anthropic'));
    }

    protected function profileClient(ProviderExecutionProfile $profile): PendingRequest
    {
        $apiKey = $this->settings->apiKey('anthropic');

        if (blank($apiKey)) {
            throw AiProviderException::invalidConfiguration();
        }

        return Http::baseUrl($profile->endpoint.'/')
            ->withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
            ])
            ->withoutRedirecting()
            ->withOptions(['stream' => true])
            ->acceptJson()
            ->asJson()
            ->connectTimeout(min(8, $profile->timeoutSeconds))
            ->timeout($profile->timeoutSeconds);
    }

    /** @param array<string, mixed> $model */
    protected function normalizeModel(array $model, string $imageSelected, string $journalSelected): array
    {
        $name = (string) ($model['id'] ?? '');
        $capabilities = is_array($model['capabilities'] ?? null) ? $model['capabilities'] : [];
        $vision = (bool) data_get($capabilities, 'image_input.supported', str_starts_with($name, 'claude-'));
        $structured = (bool) data_get($capabilities, 'structured_outputs.supported', true);
        $thinking = (bool) data_get($capabilities, 'thinking.supported', str_contains($name, '-4-'));
        $tools = (bool) data_get($capabilities, 'code_execution.supported', true);
        $context = (int) ($model['max_input_tokens'] ?? 0);

        $imageSuitable = $vision && $structured;
        $journalSuitable = $structured;

        return [
            'name' => $name,
            'label' => (string) ($model['display_name'] ?? $name),
            'parameter_size' => 'Managed',
            'quantization' => 'Cloud',
            'size_label' => 'API',
            'context_label' => $context > 0 ? number_format($context) : 'See model docs',
            'capabilities' => array_values(array_filter([
                'completion',
                $vision ? 'vision' : null,
                $structured ? 'structured' : null,
                $tools ? 'tools' : null,
                $thinking ? 'thinking' : null,
            ])),
            'image_suitable' => $imageSuitable,
            'journal_suitable' => $journalSuitable,
            'suitable' => $imageSuitable,
            'recommended' => in_array($name, [$imageSelected, $journalSelected], true) || str_contains($name, 'sonnet'),
        ];
    }
}
