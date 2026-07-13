<?php

namespace App\Services;

use App\Data\JournalAiProviderResult;
use App\Exceptions\AiProviderException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class OpenAiClient
{
    public function __construct(protected AiSettings $settings) {}

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $analysisImage
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
            'store' => false,
            'truncation' => 'disabled',
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
            'store' => false,
            'truncation' => 'disabled',
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
        $profile->assertProvider('openai');
        ProviderExecutionProfile::validateStructuredRequest($instructions, $input, $schema, $maxTokens);
        $profile->assertCurrent($this->settings);
        $profile->assertRequestCapacity($instructions, $input, $schema, $maxTokens);

        try {
            $providerResponse = $this->profileClient($profile)
                ->post('responses', [
                    'model' => $profile->model,
                    'instructions' => $instructions,
                    'input' => $input,
                    'text' => $this->outputFormat($schema),
                    'max_output_tokens' => $maxTokens,
                    'store' => false,
                    'truncation' => 'disabled',
                ]);

            $response = JournalAiHttpResponse::decode($providerResponse);
        } catch (AiProviderException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw AiProviderException::fromProviderFailure($exception);
        }

        $payload = $this->decodeJournalResponse($response);

        return new JournalAiProviderResult(
            payload: $payload,
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
            ->get('models')
            ->throw()
            ->json('data', []);

        $imageSelected = (string) ($overrides['image_model'] ?? $overrides['model'] ?? $this->settings->model('openai'));
        $journalSelected = (string) ($overrides['journal_model'] ?? $this->settings->journalModel('openai'));

        return [
            'version' => 'Responses API',
            'models' => collect(is_array($models) ? $models : [])
                ->map(fn (array $model): array => $this->normalizeModel(
                    (string) ($model['id'] ?? ''),
                    $imageSelected,
                    $journalSelected,
                ))
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

        if (($response['status'] ?? null) === 'incomplete' || ! empty($response['incomplete_details'])) {
            throw new RuntimeException('OpenAI response was incomplete.');
        }

        foreach ($response['output'] ?? [] as $output) {
            foreach ($output['content'] ?? [] as $content) {
                if (($content['type'] ?? null) === 'refusal' || filled($content['refusal'] ?? null)) {
                    throw new RuntimeException('OpenAI refused the structured output request.');
                }
            }
        }

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
            throw new RuntimeException('An OpenAI API key is required. Configure it on the AI providers page.');
        }

        return Http::baseUrl(rtrim((string) ($overrides['base_url'] ?? $this->settings->baseUrl('openai')), '/').'/')
            ->withToken($apiKey)
            ->acceptJson()
            ->asJson()
            ->connectTimeout(8)
            ->timeout($timeout ?: $this->settings->requestTimeout('openai'));
    }

    protected function profileClient(ProviderExecutionProfile $profile): PendingRequest
    {
        $apiKey = $this->settings->apiKey('openai');

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

    /** @return array<string, mixed> */
    protected function normalizeModel(string $name, string $imageSelected, string $journalSelected): array
    {
        $lower = strtolower($name);
        $excluded = preg_match('/(audio|realtime|transcribe|tts|embedding|moderation|image|search|computer|codex)/', $lower);
        $completion = ! $excluded && (bool) preg_match('/^(gpt-|o\d)/', $lower);
        $structured = $completion;
        $vision = $completion && (bool) preg_match('/^(gpt-4o|gpt-4\.1|gpt-5)/', $lower);
        $reasoning = str_starts_with($lower, 'gpt-5') || preg_match('/^o\d/', $lower);
        $imageSuitable = $vision && $structured;
        $journalSuitable = $completion && $structured;

        return [
            'name' => $name,
            'label' => $name,
            'parameter_size' => 'Managed',
            'quantization' => 'Cloud',
            'size_label' => 'API',
            'context_label' => 'See model docs',
            'capabilities' => array_values(array_filter([
                $completion ? 'completion' : null,
                $vision ? 'vision' : null,
                $structured ? 'structured' : null,
                $vision ? 'tools' : null,
                $reasoning ? 'thinking' : null,
            ])),
            'image_suitable' => $imageSuitable,
            'journal_suitable' => $journalSuitable,
            'suitable' => $imageSuitable,
            'recommended' => in_array($name, [$imageSelected, $journalSelected, 'gpt-5.4-mini'], true),
            'relevant' => $imageSuitable || $journalSuitable || in_array($name, [$imageSelected, $journalSelected], true),
        ];
    }

    /** @return array<string, mixed> */
    protected function decodeJournalResponse(mixed $response): array
    {
        if (! is_array($response)
            || ($response['status'] ?? null) === 'incomplete'
            || ! empty($response['incomplete_details'])) {
            throw AiProviderException::invalidStructuredOutput();
        }

        $text = $response['output_text'] ?? null;

        foreach ($response['output'] ?? [] as $output) {
            foreach ($output['content'] ?? [] as $content) {
                if (($content['type'] ?? null) === 'refusal' || filled($content['refusal'] ?? null)) {
                    throw AiProviderException::invalidStructuredOutput();
                }

                if (! is_string($text) && is_string($content['text'] ?? null)) {
                    $text = $content['text'];
                }
            }
        }

        if (! is_string($text) || blank($text)) {
            throw AiProviderException::invalidStructuredOutput();
        }

        $decoded = json_decode($text, true);

        if (! is_array($decoded)) {
            throw AiProviderException::invalidStructuredOutput();
        }

        return $decoded;
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
