<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAiClient
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
            'model' => $this->settings->model('openai'),
            'input' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'input_text', 'text' => $prompt],
                    ['type' => 'input_image', 'image_url' => $analysisImage['data_url'], 'detail' => 'low'],
                ],
            ]],
            'text' => $this->outputFormat($schema),
            'max_output_tokens' => 1000,
        ]);
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function generateStructured(string $prompt, array $schema): array
    {
        return $this->request([
            'model' => $this->settings->model('openai'),
            'input' => $prompt,
            'text' => $this->outputFormat($schema),
            'max_output_tokens' => 900,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{version:string, models:array<int, array<string, mixed>>}
     */
    public function inspect(array $overrides = []): array
    {
        $models = $this->client($overrides, 30)
            ->get('models')
            ->throw()
            ->json('data', []);

        $selected = (string) ($overrides['model'] ?? $this->settings->model('openai'));

        return [
            'version' => 'Responses API',
            'models' => collect(is_array($models) ? $models : [])
                ->map(fn (array $model): array => $this->normalizeModel((string) ($model['id'] ?? ''), $selected))
                ->filter(fn (array $model): bool => filled($model['name']) && $model['relevant'])
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
        $response = $this->client(timeout: $this->settings->requestTimeout('openai'))
            ->retry(2, 750)
            ->post('responses', $payload)
            ->throw()
            ->json();

        $text = $response['output_text'] ?? null;

        if (! is_string($text)) {
            foreach ($response['output'] ?? [] as $output) {
                foreach ($output['content'] ?? [] as $content) {
                    if (is_string($content['text'] ?? null)) {
                        $text = $content['text'];
                        break 2;
                    }
                }
            }
        }

        return $this->decode($text, 'OpenAI');
    }

    /** @param array<string, mixed> $schema */
    protected function outputFormat(array $schema): array
    {
        return [
            'format' => [
                'type' => 'json_schema',
                'name' => 'creative_ai_result',
                'strict' => true,
                'schema' => AiSchema::portable($schema),
            ],
        ];
    }

    /** @param array<string, mixed> $overrides */
    protected function client(array $overrides = [], ?int $timeout = null): PendingRequest
    {
        $apiKey = $this->settings->apiKey('openai', $overrides['api_key'] ?? null);

        if (blank($apiKey)) {
            throw new RuntimeException('An OpenAI API key is required. Configure it in AI providers or OPENAI_API_KEY.');
        }

        return Http::baseUrl(rtrim((string) ($overrides['base_url'] ?? $this->settings->baseUrl('openai')), '/').'/')
            ->withToken($apiKey)
            ->acceptJson()
            ->asJson()
            ->connectTimeout(8)
            ->timeout($timeout ?: $this->settings->requestTimeout('openai'));
    }

    /** @return array<string, mixed> */
    protected function normalizeModel(string $name, string $selected): array
    {
        $lower = strtolower($name);
        $excluded = preg_match('/(audio|realtime|transcribe|tts|embedding|moderation|image|search|computer|codex)/', $lower);
        $vision = ! $excluded && (bool) preg_match('/^(gpt-4o|gpt-4\.1|gpt-5)/', $lower);
        $reasoning = str_starts_with($lower, 'gpt-5') || preg_match('/^o\d/', $lower);

        return [
            'name' => $name,
            'label' => $name,
            'parameter_size' => 'Managed',
            'quantization' => 'Cloud',
            'size_label' => 'API',
            'context_label' => 'See model docs',
            'capabilities' => array_values(array_filter([
                'completion',
                $vision ? 'vision' : null,
                $vision ? 'structured' : null,
                $vision ? 'tools' : null,
                $reasoning ? 'thinking' : null,
            ])),
            'suitable' => $vision,
            'recommended' => $name === $selected || $name === 'gpt-5.4-mini',
            'relevant' => $vision || $name === $selected,
        ];
    }

    /** @return array<string, mixed> */
    protected function decode(mixed $text, string $provider): array
    {
        if (! is_string($text) || blank($text)) {
            throw new RuntimeException($provider.' response did not include structured output text.');
        }

        $decoded = json_decode($text, true);

        if (! is_array($decoded)) {
            throw new RuntimeException($provider.' response was not valid JSON.');
        }

        return $decoded;
    }
}
