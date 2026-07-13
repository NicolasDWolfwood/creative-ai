<?php

namespace App\Services;

use App\Data\JournalAiProviderResult;
use App\Exceptions\AiProviderException;
use RuntimeException;

class AiProviderManager
{
    public function __construct(
        protected AiSettings $settings,
        protected OllamaClient $ollama,
        protected OpenAiClient $openAi,
        protected AnthropicClient $anthropic,
        protected ZAiClient $zai,
    ) {}

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $image
     */
    public function analyzeImage(string $prompt, array $schema, array $image): array
    {
        return match ($this->settings->provider()) {
            'ollama' => $this->ollama->analyze($prompt, $schema, $image),
            'openai' => $this->openAi->analyze($prompt, $schema, $image),
            'anthropic' => $this->anthropic->analyze($prompt, $schema, $image),
            'zai' => $this->zai->analyze($prompt, $schema, $image),
            default => throw new RuntimeException('Unsupported AI provider configured.'),
        };
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function generateStructured(string $prompt, array $schema): array
    {
        return match ($this->settings->provider()) {
            'ollama' => $this->ollama->generateStructured($prompt, $schema),
            'openai' => $this->openAi->generateStructured($prompt, $schema),
            'anthropic' => $this->anthropic->generateStructured($prompt, $schema),
            'zai' => $this->zai->generateStructured($prompt, $schema),
            default => throw new RuntimeException('Unsupported AI provider configured.'),
        };
    }

    public function createJournalExecutionProfile(): ProviderExecutionProfile
    {
        $this->settings->refresh();

        return ProviderExecutionProfile::fromSettings($this->settings);
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
        ProviderExecutionProfile::validateStructuredRequest($instructions, $input, $schema, $maxTokens);
        $profile->assertRequestCapacity($instructions, $input, $schema, $maxTokens);

        return match ($profile->provider) {
            'ollama' => $this->ollama->generateJournalStructured($profile, $instructions, $input, $schema, $maxTokens),
            'openai' => $this->openAi->generateJournalStructured($profile, $instructions, $input, $schema, $maxTokens),
            'anthropic' => $this->anthropic->generateJournalStructured($profile, $instructions, $input, $schema, $maxTokens),
            'zai' => $this->zai->generateJournalStructured($profile, $instructions, $input, $schema, $maxTokens),
            default => throw AiProviderException::invalidConfiguration(),
        };
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{version:string, models:array<int, array<string, mixed>>}
     */
    public function inspect(string $provider, array $overrides = []): array
    {
        return match ($provider) {
            'ollama' => $this->ollama->inspect($overrides['base_url'] ?? null),
            'openai' => $this->openAi->inspect($overrides),
            'anthropic' => $this->anthropic->inspect($overrides),
            'zai' => $this->zai->inspect($overrides),
            default => throw new RuntimeException('Unsupported AI provider selected.'),
        };
    }
}
