<?php

namespace App\Services;

use App\Data\JournalPlanningDefaults;
use App\Enums\JournalPlanningMode;
use App\Models\PostTemplate;
use App\Models\SiteSetting;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class JournalPlanningSettings
{
    public const SETTING_KEY = 'journal_planning';

    /** @var list<string> */
    private const MODE_FIELDS = [
        'artwork_mode',
        'collection_mode',
        'album_mode',
        'playlist_mode',
        'track_mode',
        'artwork_batch_mode',
        'album_import_mode',
    ];

    private ?JournalPlanningDefaults $resolved = null;

    public function current(): JournalPlanningDefaults
    {
        return $this->resolved ??= $this->resolve($this->stored());
    }

    /** @return array<string, int|string|bool|null> */
    public function formValues(): array
    {
        return $this->current()->toArray();
    }

    /**
     * @param  array<string, mixed>  $values
     *
     * @throws ValidationException
     */
    public function save(array $values): JournalPlanningDefaults
    {
        $candidate = array_replace($this->formValues(), $values);

        if (blank($candidate['post_template_id'] ?? null)) {
            $candidate['post_template_id'] = null;
        }

        $validated = validator($candidate, [
            ...collect(self::MODE_FIELDS)
                ->mapWithKeys(fn (string $field): array => [
                    $field => ['required', Rule::enum(JournalPlanningMode::class)],
                ])
                ->all(),
            'post_template_id' => [
                'nullable',
                'integer',
                'min:1',
                Rule::exists('post_templates', 'id')->where('is_active', true),
            ],
            'copy_shared_tags' => ['required', 'boolean'],
            'use_source_artwork_as_cover' => ['required', 'boolean'],
        ])->validate();

        $defaults = $this->fromValidated($validated);

        SiteSetting::query()->updateOrCreate(
            ['key' => self::SETTING_KEY],
            ['value' => $defaults->toArray()],
        );

        return $this->resolved = $defaults;
    }

    public function template(): ?PostTemplate
    {
        $templateId = $this->current()->postTemplateId;

        return $templateId === null
            ? null
            : PostTemplate::query()->active()->find($templateId);
    }

    public function refresh(): self
    {
        $this->resolved = null;

        return $this;
    }

    /** @param array<string, mixed> $values */
    private function resolve(array $values): JournalPlanningDefaults
    {
        $defaults = JournalPlanningDefaults::disabled();

        return new JournalPlanningDefaults(
            artworkMode: $this->storedMode($values, 'artwork_mode', $defaults->artworkMode),
            collectionMode: $this->storedMode($values, 'collection_mode', $defaults->collectionMode),
            albumMode: $this->storedMode($values, 'album_mode', $defaults->albumMode),
            playlistMode: $this->storedMode($values, 'playlist_mode', $defaults->playlistMode),
            trackMode: $this->storedMode($values, 'track_mode', $defaults->trackMode),
            artworkBatchMode: $this->storedMode($values, 'artwork_batch_mode', $defaults->artworkBatchMode),
            albumImportMode: $this->storedMode($values, 'album_import_mode', $defaults->albumImportMode),
            postTemplateId: $this->activeTemplateId($values['post_template_id'] ?? null),
            copySharedTags: $this->storedBoolean($values, 'copy_shared_tags', $defaults->copySharedTags),
            useSourceArtworkAsCover: $this->storedBoolean(
                $values,
                'use_source_artwork_as_cover',
                $defaults->useSourceArtworkAsCover,
            ),
        );
    }

    /** @param array<string, mixed> $values */
    private function storedMode(
        array $values,
        string $field,
        JournalPlanningMode $default,
    ): JournalPlanningMode {
        $value = $values[$field] ?? null;

        return is_string($value)
            ? JournalPlanningMode::tryFrom($value) ?? $default
            : $default;
    }

    private function validatedMode(mixed $value): JournalPlanningMode
    {
        if ($value instanceof JournalPlanningMode) {
            return $value;
        }

        return JournalPlanningMode::from((string) $value);
    }

    /** @param array<string, mixed> $values */
    private function storedBoolean(array $values, string $field, bool $default): bool
    {
        if (! array_key_exists($field, $values)) {
            return $default;
        }

        $value = filter_var($values[$field], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        return is_bool($value) ? $value : $default;
    }

    private function activeTemplateId(mixed $value): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($id === false) {
            return null;
        }

        return PostTemplate::query()->active()->whereKey($id)->exists() ? $id : null;
    }

    /** @return array<string, mixed> */
    private function stored(): array
    {
        $stored = SiteSetting::query()
            ->where('key', self::SETTING_KEY)
            ->first()?->value;

        return is_array($stored) ? $stored : [];
    }

    /** @param array<string, mixed> $values */
    private function fromValidated(array $values): JournalPlanningDefaults
    {
        return new JournalPlanningDefaults(
            artworkMode: $this->validatedMode($values['artwork_mode']),
            collectionMode: $this->validatedMode($values['collection_mode']),
            albumMode: $this->validatedMode($values['album_mode']),
            playlistMode: $this->validatedMode($values['playlist_mode']),
            trackMode: $this->validatedMode($values['track_mode']),
            artworkBatchMode: $this->validatedMode($values['artwork_batch_mode']),
            albumImportMode: $this->validatedMode($values['album_import_mode']),
            postTemplateId: isset($values['post_template_id']) ? (int) $values['post_template_id'] : null,
            copySharedTags: (bool) $values['copy_shared_tags'],
            useSourceArtworkAsCover: (bool) $values['use_source_artwork_as_cover'],
        );
    }
}
