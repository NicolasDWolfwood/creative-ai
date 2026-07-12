<?php

namespace App\Services;

use App\Models\SiteSetting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class AiSettings
{
    public const SETTING_KEY = 'ai_configuration';

    public const PROVIDERS = [
        'ollama' => 'Ollama (local)',
        'openai' => 'OpenAI',
        'anthropic' => 'Claude by Anthropic',
        'zai' => 'Z.AI',
    ];

    private const SECRET_FIELDS = [
        'openai' => 'openai_api_key',
        'anthropic' => 'anthropic_api_key',
        'zai' => 'zai_api_key',
    ];

    /** @var array<string, mixed> | null */
    protected ?array $resolved = null;

    /** @return array<string, mixed> */
    public function all(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $stored = $this->stored();
        $settings = array_replace($this->defaults(), $stored);

        foreach (self::SECRET_FIELDS as $field) {
            $settings[$field] = $this->decryptSecret($stored[$field] ?? null)
                ?: (string) ($this->defaults()[$field] ?? '');
        }

        return $this->resolved = $settings;
    }

    /** @return array<string, mixed> */
    public function formValues(): array
    {
        $settings = $this->all();

        foreach (self::SECRET_FIELDS as $field) {
            $settings[$field] = '';
        }

        return $settings;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function save(array $values): array
    {
        $stored = $this->stored();
        $existing = $this->all();
        $settings = $this->sanitize(array_replace($existing, $values));

        foreach (self::SECRET_FIELDS as $provider => $field) {
            $submitted = trim((string) ($values[$field] ?? ''));
            $baseUrlField = $provider.'_base_url';
            $endpointChanged = array_key_exists($baseUrlField, $values)
                && rtrim((string) $values[$baseUrlField], '/') !== rtrim((string) ($existing[$baseUrlField] ?? ''), '/');

            if (filled($submitted)) {
                $settings[$field] = 'encrypted:'.Crypt::encryptString($submitted);
            } elseif (! $endpointChanged && is_string($stored[$field] ?? null) && str_starts_with($stored[$field], 'encrypted:')) {
                $settings[$field] = $stored[$field];
            } else {
                unset($settings[$field]);
            }
        }

        SiteSetting::query()->updateOrCreate(
            ['key' => self::SETTING_KEY],
            ['value' => $settings],
        );

        $this->resolved = null;

        return $this->formValues();
    }

    public function provider(): string
    {
        return (string) $this->all()['provider'];
    }

    public function model(?string $provider = null): string
    {
        $provider ??= $this->provider();

        return (string) ($this->all()[$provider.'_model'] ?? '');
    }

    public function modelDescriptor(): string
    {
        return $this->provider().':'.$this->model();
    }

    public function baseUrl(?string $provider = null): string
    {
        $provider ??= $this->provider();

        return (string) ($this->all()[$provider.'_base_url'] ?? '');
    }

    public function requestTimeout(?string $provider = null): int
    {
        $provider ??= $this->provider();

        return (int) ($this->all()[$provider.'_request_timeout'] ?? 90);
    }

    public function apiKey(string $provider, ?string $override = null): string
    {
        if (filled($override)) {
            return trim((string) $override);
        }

        $field = self::SECRET_FIELDS[$provider] ?? null;

        return $field ? (string) ($this->all()[$field] ?? '') : '';
    }

    public function hasApiKey(string $provider): bool
    {
        return filled($this->apiKey($provider));
    }

    public function ollamaBaseUrl(): string
    {
        return $this->baseUrl('ollama');
    }

    public function ollamaRequestTimeout(): int
    {
        return $this->requestTimeout('ollama');
    }

    public function ollamaContextLength(): int
    {
        return (int) $this->all()['ollama_context_length'];
    }

    public function ollamaKeepAlive(): string
    {
        return (string) $this->all()['ollama_keep_alive'];
    }

    public function openAiModel(): string
    {
        return $this->model('openai');
    }

    public function openAiRequestTimeout(): int
    {
        return $this->requestTimeout('openai');
    }

    public function autoAnalyzeUploads(): bool
    {
        return (bool) $this->all()['auto_analyze_uploads'];
    }

    public function imageMaxWidth(): int
    {
        return (int) $this->all()['image_max_width'];
    }

    public function imageJpegQuality(): int
    {
        return (int) $this->all()['image_jpeg_quality'];
    }

    /** @return array<string, mixed> */
    protected function defaults(): array
    {
        return [
            'provider' => 'ollama',
            'ollama_base_url' => 'http://ollama:11434',
            'ollama_model' => 'qwen3.5:latest',
            'ollama_request_timeout' => 150,
            'ollama_context_length' => 4096,
            'ollama_keep_alive' => '5m',
            'openai_api_key' => '',
            'openai_base_url' => 'https://api.openai.com/v1',
            'openai_model' => 'gpt-5.4-mini',
            'openai_request_timeout' => 90,
            'anthropic_api_key' => '',
            'anthropic_base_url' => 'https://api.anthropic.com/v1',
            'anthropic_model' => 'claude-sonnet-4-6',
            'anthropic_request_timeout' => 120,
            'zai_api_key' => '',
            'zai_base_url' => 'https://api.z.ai/api/paas/v4',
            'zai_model' => 'glm-4.6v-flash',
            'zai_request_timeout' => 120,
            'auto_analyze_uploads' => false,
            'image_max_width' => 768,
            'image_jpeg_quality' => 72,
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    protected function sanitize(array $settings): array
    {
        $provider = array_key_exists((string) ($settings['provider'] ?? ''), self::PROVIDERS)
            ? (string) $settings['provider']
            : 'ollama';

        return [
            'provider' => $provider,
            'ollama_base_url' => $this->normalizeUrl((string) ($settings['ollama_base_url'] ?? ''), 'Ollama server'),
            'ollama_model' => $this->cleanName($settings['ollama_model'] ?? 'qwen3.5:latest', 'qwen3.5:latest'),
            'ollama_request_timeout' => $this->clampInteger($settings['ollama_request_timeout'] ?? 150, 30, 600),
            'ollama_context_length' => $this->clampInteger($settings['ollama_context_length'] ?? 4096, 2048, 131072),
            'ollama_keep_alive' => $this->normalizeKeepAlive((string) ($settings['ollama_keep_alive'] ?? '5m')),
            'openai_base_url' => $this->normalizeUrl((string) ($settings['openai_base_url'] ?? ''), 'OpenAI API'),
            'openai_model' => $this->cleanName($settings['openai_model'] ?? 'gpt-5.4-mini', 'gpt-5.4-mini'),
            'openai_request_timeout' => $this->clampInteger($settings['openai_request_timeout'] ?? 90, 30, 600),
            'anthropic_base_url' => $this->normalizeUrl((string) ($settings['anthropic_base_url'] ?? ''), 'Anthropic API'),
            'anthropic_model' => $this->cleanName($settings['anthropic_model'] ?? 'claude-sonnet-4-6', 'claude-sonnet-4-6'),
            'anthropic_request_timeout' => $this->clampInteger($settings['anthropic_request_timeout'] ?? 120, 30, 600),
            'zai_base_url' => $this->normalizeUrl((string) ($settings['zai_base_url'] ?? ''), 'Z.AI API'),
            'zai_model' => $this->cleanName($settings['zai_model'] ?? 'glm-4.6v-flash', 'glm-4.6v-flash'),
            'zai_request_timeout' => $this->clampInteger($settings['zai_request_timeout'] ?? 120, 30, 600),
            'auto_analyze_uploads' => filter_var($settings['auto_analyze_uploads'] ?? false, FILTER_VALIDATE_BOOL),
            'image_max_width' => $this->clampInteger($settings['image_max_width'] ?? 768, 256, 2048),
            'image_jpeg_quality' => $this->clampInteger($settings['image_jpeg_quality'] ?? 72, 40, 95),
        ];
    }

    /** @return array<string, mixed> */
    protected function stored(): array
    {
        $stored = SiteSetting::query()
            ->where('key', self::SETTING_KEY)
            ->first()?->value;

        return is_array($stored) ? $stored : [];
    }

    protected function decryptSecret(mixed $value): string
    {
        if (! is_string($value) || blank($value)) {
            return '';
        }

        if (! str_starts_with($value, 'encrypted:')) {
            return '';
        }

        try {
            return Crypt::decryptString(Str::after($value, 'encrypted:'));
        } catch (Throwable) {
            return '';
        }
    }

    protected function normalizeUrl(string $url, string $label): string
    {
        $url = rtrim(trim($url), '/');
        $scheme = parse_url($url, PHP_URL_SCHEME);

        $parts = parse_url($url);

        if (! filter_var($url, FILTER_VALIDATE_URL)
            || ! in_array($scheme, ['http', 'https'], true)
            || ! empty($parts['user'])
            || ! empty($parts['pass'])) {
            throw new RuntimeException($label.' must be a valid HTTP or HTTPS URL.');
        }

        if (str_ends_with($url, '/api') && str_contains(strtolower($label), 'ollama')) {
            return substr($url, 0, -4);
        }

        return $url;
    }

    protected function normalizeKeepAlive(string $value): string
    {
        $value = trim($value);

        return preg_match('/^-?\d+(?:ms|s|m|h)?$/', $value) ? $value : '5m';
    }

    protected function cleanName(mixed $value, string $fallback): string
    {
        $value = Str::of((string) $value)->trim()->limit(255, '')->toString();

        return filled($value) ? $value : $fallback;
    }

    protected function clampInteger(mixed $value, int $minimum, int $maximum): int
    {
        return max($minimum, min($maximum, (int) $value));
    }
}
