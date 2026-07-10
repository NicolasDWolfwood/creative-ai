<?php

namespace App\Services;

use App\Models\SiteSetting;
use Illuminate\Support\Str;
use RuntimeException;

class AiSettings
{
    public const SETTING_KEY = 'ai_configuration';

    /** @var array<string, mixed> | null */
    protected ?array $resolved = null;

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $stored = SiteSetting::query()
            ->where('key', self::SETTING_KEY)
            ->first()?->value;

        return $this->resolved = array_replace($this->defaults(), is_array($stored) ? $stored : []);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function save(array $values): array
    {
        $settings = $this->sanitize(array_replace($this->defaults(), $values));

        SiteSetting::query()->updateOrCreate(
            ['key' => self::SETTING_KEY],
            ['value' => $settings],
        );

        return $this->resolved = $settings;
    }

    public function provider(): string
    {
        return (string) $this->all()['provider'];
    }

    public function model(): string
    {
        $settings = $this->all();

        return $settings['provider'] === 'ollama'
            ? (string) $settings['ollama_model']
            : (string) $settings['openai_model'];
    }

    public function modelDescriptor(): string
    {
        return $this->provider().':'.$this->model();
    }

    public function ollamaBaseUrl(): string
    {
        return (string) $this->all()['ollama_base_url'];
    }

    public function ollamaRequestTimeout(): int
    {
        return (int) $this->all()['ollama_request_timeout'];
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
        return (string) $this->all()['openai_model'];
    }

    public function openAiRequestTimeout(): int
    {
        return (int) $this->all()['openai_request_timeout'];
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

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'provider' => config('creative_ai.ai.provider', 'openai'),
            'ollama_base_url' => config('services.ollama.base_url', 'http://127.0.0.1:11434'),
            'ollama_model' => config('services.ollama.model', 'qwen3.5:latest'),
            'ollama_request_timeout' => (int) config('services.ollama.timeout', 150),
            'ollama_context_length' => (int) config('services.ollama.context_length', 4096),
            'ollama_keep_alive' => (string) config('services.ollama.keep_alive', '5m'),
            'openai_model' => config('creative_ai.ai.model', 'gpt-5.4-mini'),
            'openai_request_timeout' => (int) config('creative_ai.ai.timeout', 90),
            'auto_analyze_uploads' => (bool) config('creative_ai.ai.auto_analyze_uploads', false),
            'image_max_width' => (int) config('creative_ai.ai.image_max_width', 768),
            'image_jpeg_quality' => (int) config('creative_ai.ai.image_jpeg_quality', 72),
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    protected function sanitize(array $settings): array
    {
        $provider = in_array($settings['provider'] ?? null, ['ollama', 'openai'], true)
            ? $settings['provider']
            : 'ollama';

        return [
            'provider' => $provider,
            'ollama_base_url' => $this->normalizeOllamaBaseUrl((string) ($settings['ollama_base_url'] ?? '')),
            'ollama_model' => $this->cleanName($settings['ollama_model'] ?? 'qwen3.5:latest', 'qwen3.5:latest'),
            'ollama_request_timeout' => $this->clampInteger($settings['ollama_request_timeout'] ?? 150, 30, 300),
            'ollama_context_length' => $this->clampInteger($settings['ollama_context_length'] ?? 4096, 2048, 32768),
            'ollama_keep_alive' => $this->normalizeKeepAlive((string) ($settings['ollama_keep_alive'] ?? '5m')),
            'openai_model' => $this->cleanName($settings['openai_model'] ?? 'gpt-5.4-mini', 'gpt-5.4-mini'),
            'openai_request_timeout' => $this->clampInteger($settings['openai_request_timeout'] ?? 90, 30, 300),
            'auto_analyze_uploads' => filter_var($settings['auto_analyze_uploads'] ?? false, FILTER_VALIDATE_BOOL),
            'image_max_width' => $this->clampInteger($settings['image_max_width'] ?? 768, 256, 2048),
            'image_jpeg_quality' => $this->clampInteger($settings['image_jpeg_quality'] ?? 72, 40, 95),
        ];
    }

    protected function normalizeOllamaBaseUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');

        if (str_ends_with($url, '/api')) {
            $url = substr($url, 0, -4);
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (! filter_var($url, FILTER_VALIDATE_URL) || ! in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException('Ollama server must be a valid HTTP or HTTPS URL.');
        }

        return $url;
    }

    protected function normalizeKeepAlive(string $value): string
    {
        $value = trim($value);

        if (! preg_match('/^-?\d+(?:ms|s|m|h)?$/', $value)) {
            return '5m';
        }

        return $value;
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
