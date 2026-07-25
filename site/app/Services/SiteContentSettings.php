<?php

namespace App\Services;

use App\Models\SiteSetting;
use Illuminate\Validation\ValidationException;

class SiteContentSettings
{
    public const SETTING_KEY = 'home_intro';

    public const DEFAULT_HOME_INTRO = 'A living archive of generative artwork, visual experiments, and original sound.';

    public const MAX_HOME_INTRO_LENGTH = 500;

    private ?string $resolvedHomeIntro = null;

    public function homeIntro(): string
    {
        if ($this->resolvedHomeIntro !== null) {
            return $this->resolvedHomeIntro;
        }

        $body = $this->stored()['body'] ?? null;

        if (! is_string($body) || blank($body)) {
            return $this->resolvedHomeIntro = self::DEFAULT_HOME_INTRO;
        }

        return $this->resolvedHomeIntro = trim($body);
    }

    /** @return array{body: string} */
    public function formValues(): array
    {
        return ['body' => $this->homeIntro()];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array{body: string}
     *
     * @throws ValidationException
     */
    public function save(array $values): array
    {
        $validated = validator($values, [
            'body' => ['required', 'string', 'max:'.self::MAX_HOME_INTRO_LENGTH],
        ], attributes: [
            'body' => 'introduction text',
        ])->validate();

        $body = trim($validated['body']);
        $stored = $this->stored();
        $stored['body'] = $body;

        SiteSetting::query()->updateOrCreate(
            ['key' => self::SETTING_KEY],
            ['value' => $stored],
        );

        $this->resolvedHomeIntro = $body;

        return $this->formValues();
    }

    public function refresh(): self
    {
        $this->resolvedHomeIntro = null;

        return $this;
    }

    /** @return array<string, mixed> */
    private function stored(): array
    {
        $value = SiteSetting::query()
            ->where('key', self::SETTING_KEY)
            ->first()
            ?->value;

        return is_array($value) ? $value : [];
    }
}
