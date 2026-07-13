<?php

namespace App\Services;

use App\Exceptions\AiProviderConfigurationChangedException;
use App\Exceptions\AiProviderException;
use App\Support\CanonicalJson;
use JsonException;
use JsonSerializable;

final class ProviderExecutionProfile implements JsonSerializable
{
    public const VERSION = 1;

    public const MIN_TIMEOUT_SECONDS = 10;

    public const MAX_TIMEOUT_SECONDS = 120;

    public const MIN_OUTPUT_TOKENS = 64;

    public const MAX_OUTPUT_TOKENS = 4096;

    public const OLLAMA_FRAMING_TOKEN_RESERVE = 256;

    private const PROVIDERS = ['ollama', 'openai', 'anthropic', 'zai'];

    /**
     * @param  array<string, int|string|float|bool>  $outputSettings
     */
    private function __construct(
        public readonly string $provider,
        public readonly string $model,
        public readonly string $endpoint,
        public readonly int $timeoutSeconds,
        public readonly array $outputSettings,
        public readonly bool $externalProcessing,
        public readonly ?string $credentialFingerprint,
    ) {
        if (! in_array($provider, self::PROVIDERS, true)
            || blank($model)
            || mb_strlen($model) > 255
            || $endpoint !== self::normalizeEndpoint($endpoint)
            || ($externalProcessing && parse_url($endpoint, PHP_URL_SCHEME) !== 'https')
            || $timeoutSeconds < self::MIN_TIMEOUT_SECONDS
            || $timeoutSeconds > self::MAX_TIMEOUT_SECONDS
            || ($provider !== 'ollama' && ! preg_match('/^[a-f0-9]{64}$/', (string) $credentialFingerprint))
            || collect($outputSettings)->contains(fn (mixed $value): bool => ! is_scalar($value))) {
            throw AiProviderException::invalidConfiguration();
        }
    }

    public static function fromSettings(AiSettings $settings): self
    {
        $provider = $settings->provider();
        $credentialFingerprint = $provider === 'ollama'
            ? null
            : self::credentialFingerprint($settings->apiKey($provider));

        return new self(
            provider: $provider,
            model: $settings->journalModel($provider),
            endpoint: self::normalizeEndpoint($settings->baseUrl($provider)),
            timeoutSeconds: min(self::MAX_TIMEOUT_SECONDS, max(
                self::MIN_TIMEOUT_SECONDS,
                $settings->requestTimeout($provider),
            )),
            outputSettings: $provider === 'ollama' ? [
                'context_length' => $settings->ollamaContextLength(),
                'keep_alive' => $settings->ollamaKeepAlive(),
            ] : [],
            externalProcessing: $settings->externalProcessing($provider),
            credentialFingerprint: $credentialFingerprint,
        );
    }

    /** @param array<string, mixed> $profile */
    public static function fromArray(array $profile): self
    {
        if (($profile['version'] ?? null) !== self::VERSION
            || ! is_array($profile['output_settings'] ?? null)
            || ! is_bool($profile['external_processing'] ?? null)) {
            throw AiProviderException::invalidConfiguration();
        }

        return new self(
            provider: is_string($profile['provider'] ?? null) ? $profile['provider'] : '',
            model: is_string($profile['model'] ?? null) ? $profile['model'] : '',
            endpoint: is_string($profile['endpoint'] ?? null) ? $profile['endpoint'] : '',
            timeoutSeconds: is_int($profile['timeout_seconds'] ?? null) ? $profile['timeout_seconds'] : 0,
            outputSettings: $profile['output_settings'],
            externalProcessing: $profile['external_processing'],
            credentialFingerprint: is_string($profile['credential_fingerprint'] ?? null)
                ? $profile['credential_fingerprint']
                : null,
        );
    }

    /**
     * Rebuild a profile from the flat immutable fields stored on a Journal AI run.
     *
     * @param  array<string, mixed>  $generationOptions
     */
    public static function fromStoredFields(
        string $provider,
        string $model,
        string $endpoint,
        bool $externalProcessing,
        ?string $credentialFingerprint,
        array $generationOptions,
    ): self {
        return self::fromArray([
            'version' => $generationOptions['profile_version'] ?? null,
            'provider' => $provider,
            'model' => $model,
            'endpoint' => $endpoint,
            'timeout_seconds' => $generationOptions['timeout_seconds'] ?? null,
            'output_settings' => $generationOptions['output_settings'] ?? null,
            'external_processing' => $externalProcessing,
            'credential_fingerprint' => $credentialFingerprint,
        ]);
    }

    public function assertCurrent(AiSettings $settings): void
    {
        $settings->refresh();

        $currentEndpoint = self::normalizeEndpoint($settings->baseUrl($this->provider));
        $credential = $this->provider === 'ollama' ? '' : $settings->apiKey($this->provider);
        $currentFingerprint = $this->provider === 'ollama' || blank($credential)
            ? null
            : self::credentialFingerprint($credential);
        $fingerprintMatches = $this->credentialFingerprint === null && $currentFingerprint === null;

        if (is_string($this->credentialFingerprint) && is_string($currentFingerprint)) {
            $fingerprintMatches = hash_equals($this->credentialFingerprint, $currentFingerprint);
        }

        if ($this->externalProcessing !== $settings->externalProcessing($this->provider)
            || ! hash_equals($this->endpoint, $currentEndpoint)
            || ! $fingerprintMatches) {
            throw new AiProviderConfigurationChangedException;
        }
    }

    public function assertProvider(string $provider): void
    {
        if ($this->provider !== $provider) {
            throw AiProviderException::invalidConfiguration();
        }
    }

    public static function validateMaxTokens(int $maxTokens): void
    {
        if ($maxTokens < self::MIN_OUTPUT_TOKENS || $maxTokens > self::MAX_OUTPUT_TOKENS) {
            throw AiProviderException::invalidConfiguration();
        }
    }

    /** @param array<string, mixed> $schema */
    public static function validateStructuredRequest(
        string $instructions,
        string $input,
        array $schema,
        int $maxTokens,
    ): void {
        self::validateMaxTokens($maxTokens);

        if (blank($instructions) || blank($input) || $schema === []) {
            throw AiProviderException::invalidConfiguration();
        }
    }

    /** @param array<string, mixed> $schema */
    public function assertRequestCapacity(
        string $instructions,
        string $input,
        array $schema,
        int $maxOutputTokens,
    ): void {
        if ($this->provider !== 'ollama') {
            return;
        }

        $contextLength = $this->outputSettings['context_length'] ?? null;

        if (! is_int($contextLength)) {
            throw AiProviderException::invalidConfiguration();
        }

        try {
            $portableSchema = CanonicalJson::encode(AiSchema::portable($schema));
        } catch (JsonException) {
            throw AiProviderException::invalidConfiguration();
        }

        $usedTokenUpperBound = strlen($instructions)
            + strlen($input)
            + strlen($portableSchema)
            + $maxOutputTokens
            + self::OLLAMA_FRAMING_TOKEN_RESERVE;

        if ($usedTokenUpperBound > $contextLength) {
            throw AiProviderException::invalidConfiguration();
        }
    }

    /** @return array{version:int,provider:string,model:string,endpoint:string,timeout_seconds:int,output_settings:array<string, int|string|float|bool>,external_processing:bool,credential_fingerprint:?string} */
    public function toArray(): array
    {
        return [
            'version' => self::VERSION,
            'provider' => $this->provider,
            'model' => $this->model,
            'endpoint' => $this->endpoint,
            'timeout_seconds' => $this->timeoutSeconds,
            'output_settings' => $this->outputSettings,
            'external_processing' => $this->externalProcessing,
            'credential_fingerprint' => $this->credentialFingerprint,
        ];
    }

    /** @return array{profile_version:int,timeout_seconds:int,output_settings:array<string, int|string|float|bool>} */
    public function generationOptions(): array
    {
        return [
            'profile_version' => self::VERSION,
            'timeout_seconds' => $this->timeoutSeconds,
            'output_settings' => $this->outputSettings,
        ];
    }

    public function canonicalHash(): string
    {
        $canonical = self::canonicalize($this->toArray());

        return hash('sha256', json_encode(
            $canonical,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ));
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private static function credentialFingerprint(string $credential): string
    {
        $appKey = (string) config('app.key');

        if (blank($credential) || blank($appKey)) {
            throw AiProviderException::invalidConfiguration();
        }

        return hash_hmac('sha256', $credential, $appKey);
    }

    private static function normalizeEndpoint(string $endpoint): string
    {
        $endpoint = trim($endpoint);
        $parts = parse_url($endpoint);

        if (! is_array($parts)
            || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || blank($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw AiProviderException::invalidConfiguration();
        }

        $authority = strtolower((string) $parts['scheme']).'://'.strtolower((string) $parts['host']);

        if (isset($parts['port'])) {
            $authority .= ':'.(int) $parts['port'];
        }

        $path = isset($parts['path']) ? '/'.ltrim((string) $parts['path'], '/') : '';

        return rtrim($authority.$path, '/');
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
