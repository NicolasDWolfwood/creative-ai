<?php

namespace App\Services;

use App\Data\JournalAiProviderResult;
use App\Exceptions\AiProviderException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ZAiClient
{
    private const MODELS = [
        ['name' => 'glm-5v-turbo', 'label' => 'GLM-5V-Turbo', 'context' => 200000, 'profile' => 'Highest capability', 'vision' => true],
        ['name' => 'glm-4.6v', 'label' => 'GLM-4.6V', 'context' => 128000, 'profile' => 'Balanced quality', 'vision' => true],
        ['name' => 'glm-4.6v-flashx', 'label' => 'GLM-4.6V-FlashX', 'context' => 128000, 'profile' => 'Fast and economical', 'vision' => true],
        ['name' => 'glm-4.6v-flash', 'label' => 'GLM-4.6V-Flash', 'context' => 128000, 'profile' => 'Free vision tier', 'vision' => true],
        ['name' => 'glm-4.5v', 'label' => 'GLM-4.5V', 'context' => 64000, 'profile' => 'Visual reasoning', 'vision' => true],
    ];

    public function __construct(protected AiSettings $settings) {}

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $analysisImage
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
     * @param  array<string, mixed>  $schema
     */
    public function generateJournalStructured(
        ProviderExecutionProfile $profile,
        string $instructions,
        string $input,
        array $schema,
        int $maxTokens,
    ): JournalAiProviderResult {
        $profile->assertProvider('zai');
        ProviderExecutionProfile::validateStructuredRequest($instructions, $input, $schema, $maxTokens);
        $profile->assertCurrent($this->settings);
        $profile->assertRequestCapacity($instructions, $input, $schema, $maxTokens);

        try {
            $providerResponse = $this->profileClient($profile)
                ->post('chat/completions', [
                    'model' => $profile->model,
                    'messages' => [
                        ['role' => 'system', 'content' => $instructions."\n\n".$this->jsonInstruction($schema)],
                        ['role' => 'user', 'content' => $input],
                    ],
                    'response_format' => ['type' => 'json_object'],
                    'thinking' => ['type' => 'disabled'],
                    'temperature' => 0.2,
                    'max_tokens' => $maxTokens,
                    'stream' => false,
                ]);

            $response = JournalAiHttpResponse::decode($providerResponse);
        } catch (AiProviderException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw AiProviderException::fromProviderFailure($exception);
        }

        $text = is_array($response) ? data_get($response, 'choices.0.message.content') : null;

        if (is_string($text)) {
            $text = preg_replace('/<think>.*?<\/think>/s', '', $text) ?: $text;
            $text = Str::of($text)->trim()->replaceMatches('/^```(?:json)?\s*|\s*```$/', '')->toString();
        }

        $decoded = is_string($text) ? json_decode($text, true) : null;

        if (! is_array($decoded)) {
            throw AiProviderException::invalidStructuredOutput();
        }

        return new JournalAiProviderResult(
            payload: $decoded,
            providerRequestId: data_get($response, 'id'),
            inputTokens: data_get($response, 'usage.prompt_tokens'),
            outputTokens: data_get($response, 'usage.completion_tokens'),
        );
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

        $imageSelected = (string) ($overrides['image_model'] ?? $overrides['model'] ?? $this->settings->model('zai'));
        $journalSelected = (string) ($overrides['journal_model'] ?? $this->settings->journalModel('zai'));
        $models = collect(self::MODELS)
            ->map(function (array $model) use ($imageSelected, $journalSelected): array {
                $imageSuitable = (bool) $model['vision'];

                return [
                    'name' => $model['name'],
                    'label' => $model['label'],
                    'parameter_size' => 'Managed',
                    'quantization' => $model['profile'],
                    'size_label' => 'API',
                    'context_label' => number_format($model['context']),
                    'capabilities' => array_values(array_filter([
                        $imageSuitable ? 'vision' : null,
                        'completion',
                        'structured',
                        'tools',
                        'thinking',
                    ])),
                    'image_suitable' => $imageSuitable,
                    'journal_suitable' => true,
                    'suitable' => $imageSuitable,
                    'recommended' => in_array($model['name'], [$imageSelected, $journalSelected, 'glm-4.6v-flash'], true),
                ];
            })
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

    protected function profileClient(ProviderExecutionProfile $profile): PendingRequest
    {
        $apiKey = $this->settings->apiKey('zai');

        if (blank($apiKey)) {
            throw AiProviderException::invalidConfiguration();
        }

        return Http::baseUrl($profile->endpoint.'/')
            ->withToken($apiKey)
            ->withoutRedirecting()
            ->withOptions(['stream' => true])
            ->acceptJson()
            ->asJson()
            ->connectTimeout(min(8, $profile->timeoutSeconds))
            ->timeout($profile->timeoutSeconds);
    }

    /** @param array<string, mixed> $schema */
    protected function jsonInstruction(array $schema): string
    {
        return 'Return only a valid JSON object matching this schema: '.json_encode(AiSchema::portable($schema), JSON_UNESCAPED_SLASHES);
    }
}
