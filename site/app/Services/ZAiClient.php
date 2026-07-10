<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class ZAiClient
{
    private const MODELS = [
        ['name' => 'glm-5v-turbo', 'label' => 'GLM-5V-Turbo', 'context' => 200000, 'profile' => 'Highest capability'],
        ['name' => 'glm-4.6v', 'label' => 'GLM-4.6V', 'context' => 128000, 'profile' => 'Balanced quality'],
        ['name' => 'glm-4.6v-flashx', 'label' => 'GLM-4.6V-FlashX', 'context' => 128000, 'profile' => 'Fast and economical'],
        ['name' => 'glm-4.6v-flash', 'label' => 'GLM-4.6V-Flash', 'context' => 128000, 'profile' => 'Free vision tier'],
        ['name' => 'glm-4.5v', 'label' => 'GLM-4.5V', 'context' => 64000, 'profile' => 'Visual reasoning'],
    ];

    public function __construct(protected AiSettings $settings) {}

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $analysisImage
     * @return array<string, mixed>
     */
    public function analyze(string $prompt, array $schema, array $analysisImage): array
    {
        return $this->request([
            'model' => $this->settings->model('zai'),
            'messages' => [
                ['role' => 'system', 'content' => $this->jsonInstruction($schema)],
                ['role' => 'user', 'content' => [
                    ['type' => 'image_url', 'image_url' => ['url' => $analysisImage['data_url']]],
                    ['type' => 'text', 'text' => $prompt],
                ]],
            ],
            'response_format' => ['type' => 'json_object'],
            'thinking' => ['type' => 'disabled'],
            'temperature' => 0.2,
            'max_tokens' => 1200,
            'stream' => false,
        ]);
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function generateStructured(string $prompt, array $schema): array
    {
        return $this->request([
            'model' => $this->settings->model('zai'),
            'messages' => [
                ['role' => 'system', 'content' => $this->jsonInstruction($schema)],
                ['role' => 'user', 'content' => $prompt],
            ],
            'response_format' => ['type' => 'json_object'],
            'thinking' => ['type' => 'disabled'],
            'temperature' => 0.2,
            'max_tokens' => 1000,
            'stream' => false,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{version:string, models:array<int, array<string, mixed>>}
     */
    public function inspect(array $overrides = []): array
    {
        if (blank($this->settings->apiKey('zai', $overrides['api_key'] ?? null))) {
            throw new RuntimeException('A Z.AI API key is required to verify the connection.');
        }

        $selected = (string) ($overrides['model'] ?? $this->settings->model('zai'));
        $models = collect(self::MODELS)
            ->map(fn (array $model): array => [
                'name' => $model['name'],
                'label' => $model['label'],
                'parameter_size' => 'Managed',
                'quantization' => $model['profile'],
                'size_label' => 'API',
                'context_label' => number_format($model['context']),
                'capabilities' => ['vision', 'completion', 'structured', 'tools', 'thinking'],
                'suitable' => true,
                'recommended' => $model['name'] === $selected || $model['name'] === 'glm-4.6v-flash',
            ])
            ->sortBy(fn (array $model): int => $model['recommended'] ? 0 : 1)
            ->values()
            ->all();

        return ['version' => 'Chat Completions API', 'models' => $models];
    }

    /** @param array<string, mixed> $payload */
    protected function request(array $payload): array
    {
        $response = $this->client()
            ->retry(2, 750)
            ->post('chat/completions', $payload)
            ->throw()
            ->json();

        $text = data_get($response, 'choices.0.message.content');

        if (! is_string($text) || blank($text)) {
            throw new RuntimeException('Z.AI response did not include structured output text.');
        }

        $text = preg_replace('/<think>.*?<\/think>/s', '', $text) ?: $text;
        $text = Str::of($text)->trim()->replaceMatches('/^```(?:json)?\s*|\s*```$/', '')->toString();
        $decoded = json_decode($text, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Z.AI response was not valid JSON.');
        }

        return $decoded;
    }

    protected function client(): PendingRequest
    {
        $apiKey = $this->settings->apiKey('zai');

        if (blank($apiKey)) {
            throw new RuntimeException('A Z.AI API key is required.');
        }

        return Http::baseUrl(rtrim($this->settings->baseUrl('zai'), '/').'/')
            ->withToken($apiKey)
            ->acceptJson()
            ->asJson()
            ->connectTimeout(8)
            ->timeout($this->settings->requestTimeout('zai'));
    }

    /** @param array<string, mixed> $schema */
    protected function jsonInstruction(array $schema): string
    {
        return 'Return only a valid JSON object matching this schema: '.json_encode(AiSchema::portable($schema), JSON_UNESCAPED_SLASHES);
    }
}
