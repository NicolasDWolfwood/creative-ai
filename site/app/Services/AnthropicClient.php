<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class AnthropicClient
{
    public function __construct(protected AiSettings $settings) {}

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $analysisImage
     * @return array<string, mixed>
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
     * @param  array<string, mixed>  $overrides
     * @return array{version:string, models:array<int, array<string, mixed>>}
     */
    public function inspect(array $overrides = []): array
    {
        $models = $this->client($overrides, 30)
            ->get('models', ['limit' => 100])
            ->throw()
            ->json('data', []);
        $selected = (string) ($overrides['model'] ?? $this->settings->model('anthropic'));

        return [
            'version' => 'Messages API',
            'models' => collect(is_array($models) ? $models : [])
                ->map(fn (array $model): array => $this->normalizeModel($model, $selected))
                ->filter(fn (array $model): bool => $model['suitable'] || $model['name'] === $selected)
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

    /** @param array<string, mixed> $model */
    protected function normalizeModel(array $model, string $selected): array
    {
        $name = (string) ($model['id'] ?? '');
        $capabilities = is_array($model['capabilities'] ?? null) ? $model['capabilities'] : [];
        $vision = (bool) data_get($capabilities, 'image_input.supported', str_starts_with($name, 'claude-'));
        $structured = (bool) data_get($capabilities, 'structured_outputs.supported', true);
        $thinking = (bool) data_get($capabilities, 'thinking.supported', str_contains($name, '-4-'));
        $tools = (bool) data_get($capabilities, 'code_execution.supported', true);
        $context = (int) ($model['max_input_tokens'] ?? 0);

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
            'suitable' => $vision && $structured,
            'recommended' => $name === $selected || str_contains($name, 'sonnet'),
        ];
    }
}
