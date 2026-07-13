<?php

namespace App\Services;

use App\Data\JournalAiContext;
use App\Enums\PostAiOperation;
use App\Models\Post;
use App\Models\PostMedia;
use App\Support\CanonicalJson;
use DomainException;

final class JournalAiContextBuilder
{
    public const CONTEXT_VERSION = 'journal-ai-context-v1';

    public const TOTAL_BYTES = 65536;

    /** @var list<string> */
    public const PUBLIC_FIELDS = [
        'title',
        'excerpt',
        'body',
        'cover_alt_text',
        'seo_title',
        'seo_description',
    ];

    /** @var array<string, int> */
    private const FIELD_CHARACTER_BUDGETS = [
        'title' => 500,
        'excerpt' => 4000,
        'body' => 40000,
        'cover_alt_text' => 1000,
        'seo_title' => 500,
        'seo_description' => 2000,
        'editorial_brief' => 20000,
        'editorial_notes' => 20000,
        'tag' => 200,
        'media_title' => 500,
        'media_description' => 4000,
        'media_prompt' => 12000,
        'media_process_notes' => 12000,
        'passage' => 20000,
    ];

    /** @var list<string> */
    private const SELECTION_KEYS = [
        'fields',
        'include_editorial_brief',
        'include_editorial_notes',
        'include_tags',
        'include_connected_media',
        'include_connected_media_prompts',
        'include_connected_media_process_notes',
        'passage',
    ];

    public function __construct(private readonly JournalAiContractRegistry $contracts) {}

    /**
     * @param  array<string, mixed>  $selection
     */
    public function build(Post $post, PostAiOperation $operation, array $selection): JournalAiContext
    {
        if (! $post->exists || $post->trashed()) {
            throw new DomainException('Journal AI context requires an active saved post.');
        }

        $selection = $this->normalizeSelection($operation, $selection);
        $contract = $this->contracts->for($operation);
        $outbound = [];
        $journal = [];
        $included = [];
        $omitted = [];
        $usage = [];

        foreach (self::PUBLIC_FIELDS as $field) {
            if (! in_array($field, $selection['fields'], true)) {
                $omitted['journal.'.$field] = 'not_selected';

                continue;
            }

            $value = $this->normalizedNullableString($post->getAttribute($field), $field);
            $journal[$field] = $value;
            $included[] = 'journal.'.$field;
            $usage['journal.'.$field] = $this->characterLength($value);
        }

        foreach (['editorial_brief', 'editorial_notes'] as $privateField) {
            $selectionKey = 'include_'.$privateField;

            if (! $selection[$selectionKey]) {
                $omitted['journal.'.$privateField] = 'explicit_opt_in_required';

                continue;
            }

            $value = $this->normalizedNullableString($post->getAttribute($privateField), $privateField);
            $journal[$privateField] = $value;
            $included[] = 'journal.'.$privateField;
            $usage['journal.'.$privateField] = $this->characterLength($value);
        }

        if ($journal !== []) {
            $outbound['journal'] = $journal;
        }

        if ($selection['passage'] !== null) {
            $passage = $this->selectedPassage($post, $selection['passage']);
            $outbound['selected_passage'] = $passage;
            $included[] = 'selected_passage';
            $usage['selected_passage'] = mb_strlen($passage['content'], 'UTF-8');
        }

        if ($selection['include_tags']) {
            $tags = $post->tags()
                ->reorder('name')
                ->pluck('name')
                ->map(fn (mixed $name): string => $this->normalizedString($name, 'tag'))
                ->values()
                ->all();

            if (count($tags) > 50) {
                throw new DomainException('The selected Journal tags exceed the AI context budget.');
            }

            $outbound['shared_tags'] = $tags;
            $included[] = 'shared_tags';
            $usage['shared_tags'] = count($tags);
        } else {
            $omitted['shared_tags'] = 'not_selected';
        }

        if ($selection['include_connected_media']) {
            [$media, $excludedMedia] = $this->publicConnectedMedia($post, $selection);
            $outbound['connected_media'] = $media;
            $included[] = 'connected_media';
            $usage['connected_media'] = count($media);

            foreach (['prompts' => 'prompt', 'process_notes' => 'process_notes'] as $option => $field) {
                if ($selection['include_connected_media_'.$option]) {
                    $included[] = 'connected_media.artwork.'.$field;
                } else {
                    $omitted['connected_media.artwork.'.$field] = 'explicit_opt_in_required';
                }
            }

            if ($excludedMedia) {
                $omitted['connected_media.non_public_records'] = 'not_effectively_public';
            }
        } else {
            $omitted['connected_media'] = 'not_selected';
        }

        if ($outbound === []) {
            throw new DomainException('Select at least one Journal field or context source for AI assistance.');
        }

        $outboundBytes = strlen(CanonicalJson::encode($outbound));

        if ($outboundBytes > self::TOTAL_BYTES) {
            throw new DomainException('The selected Journal AI context exceeds the total byte budget.');
        }

        $targetValues = $this->protectedTargetValues($post, $operation, $selection);
        $protectedTargets = $this->protectedTargetManifest($targetValues, $selection);
        $manifest = [
            'context_version' => self::CONTEXT_VERSION,
            'operation' => $operation->value,
            'selection' => $selection,
            'outbound' => $outbound,
            'included_fields' => $included,
            'omitted_fields' => $omitted,
            'protected_targets' => $protectedTargets,
            'budgets' => [
                'total_bytes_limit' => self::TOTAL_BYTES,
                'total_bytes_used' => $outboundBytes,
                'field_character_limits' => self::FIELD_CHARACTER_BUDGETS,
                'usage' => $usage,
            ],
        ];
        $sourceGuard = [
            'post_id' => (int) $post->getKey(),
            'context_version' => self::CONTEXT_VERSION,
            'operation' => $operation->value,
            'selection' => $selection,
            'outbound' => $outbound,
            'protected_target_values' => $targetValues,
            'prompt_version' => $contract->promptVersion,
            'prompt_hash' => $contract->promptHash(),
            'schema_version' => $contract->schemaVersion,
            'schema_hash' => $contract->schemaHash(),
        ];

        return new JournalAiContext(
            manifest: $manifest,
            contextHash: CanonicalJson::hash($outbound),
            sourceHash: CanonicalJson::hash($sourceGuard),
        );
    }

    /** @param array<string, mixed> $selection
     * @return array<string, mixed>
     */
    private function normalizeSelection(PostAiOperation $operation, array $selection): array
    {
        $unknown = array_diff(array_keys($selection), self::SELECTION_KEYS);

        if ($unknown !== []) {
            throw new DomainException('The Journal AI context selection contains unsupported options.');
        }

        $submittedFields = $selection['fields'] ?? [];

        if (! is_array($submittedFields) || ! array_is_list($submittedFields)) {
            throw new DomainException('Journal AI fields must be a list.');
        }

        foreach ($submittedFields as $field) {
            if (! is_string($field) || ! in_array($field, self::PUBLIC_FIELDS, true)) {
                throw new DomainException('The Journal AI field selection is not allowed.');
            }
        }

        $fields = array_values(array_filter(
            self::PUBLIC_FIELDS,
            fn (string $field): bool => in_array($field, $submittedFields, true),
        ));

        foreach ([
            'include_editorial_brief',
            'include_editorial_notes',
            'include_tags',
            'include_connected_media',
            'include_connected_media_prompts',
            'include_connected_media_process_notes',
        ] as $key) {
            if (array_key_exists($key, $selection) && ! is_bool($selection[$key])) {
                throw new DomainException('Journal AI context choices must be boolean.');
            }
        }

        $passage = $selection['passage'] ?? null;

        if (! ($selection['include_connected_media'] ?? false)
            && (($selection['include_connected_media_prompts'] ?? false)
                || ($selection['include_connected_media_process_notes'] ?? false))) {
            throw new DomainException('Connected media prompts and process notes require connected media context.');
        }

        if ($operation === PostAiOperation::ImprovePassage) {
            if (! is_array($passage)
                || array_diff(array_keys($passage), ['field', 'start', 'end']) !== []
                || array_diff(['field', 'start', 'end'], array_keys($passage)) !== []) {
                throw new DomainException('Improving a passage requires a field and exact character offsets.');
            }

            if (
                ! is_string($passage['field'])
                || ! in_array($passage['field'], ['title', 'excerpt', 'body'], true)
                || ! is_int($passage['start'])
                || ! is_int($passage['end'])
            ) {
                throw new DomainException('The selected Journal passage is invalid.');
            }
        } elseif ($passage !== null) {
            throw new DomainException('Passage offsets are only supported for passage improvement.');
        }

        return [
            'fields' => $fields,
            'include_editorial_brief' => $selection['include_editorial_brief'] ?? false,
            'include_editorial_notes' => $selection['include_editorial_notes'] ?? false,
            'include_tags' => $selection['include_tags'] ?? false,
            'include_connected_media' => $selection['include_connected_media'] ?? false,
            'include_connected_media_prompts' => $selection['include_connected_media_prompts'] ?? false,
            'include_connected_media_process_notes' => $selection['include_connected_media_process_notes'] ?? false,
            'passage' => $passage,
        ];
    }

    /** @param array{field:string,start:int,end:int} $selection
     * @return array{field:string,start:int,end:int,content:string}
     */
    private function selectedPassage(Post $post, array $selection): array
    {
        $source = $this->normalizedString($post->getAttribute($selection['field']), $selection['field']);
        $length = mb_strlen($source, 'UTF-8');

        if ($selection['start'] < 0 || $selection['end'] <= $selection['start'] || $selection['end'] > $length) {
            throw new DomainException('The selected Journal passage offsets are outside the current field.');
        }

        $content = mb_substr($source, $selection['start'], $selection['end'] - $selection['start'], 'UTF-8');
        $this->assertBudget('passage', $content);

        return [
            'field' => $selection['field'],
            'start' => $selection['start'],
            'end' => $selection['end'],
            'content' => $content,
        ];
    }

    /** @param array<string, mixed> $selection
     * @return array{list<array<string, mixed>>, bool}
     */
    private function publicConnectedMedia(Post $post, array $selection): array
    {
        $items = $post->mediaItems()
            ->with(['artwork', 'collection', 'album', 'playlist', 'track.album'])
            ->orderBy('position')
            ->get();
        $result = [];
        $excluded = false;

        foreach ($items as $item) {
            if (! $item instanceof PostMedia || $item->type() === null || ! $item->mediaIsPublic()) {
                $excluded = true;

                continue;
            }

            $media = $item->media();

            if ($media === null) {
                $excluded = true;

                continue;
            }

            $context = [
                'type' => $item->type()->value,
                'title' => $this->normalizedString($media->getAttribute('title'), 'media_title'),
                'description' => $this->normalizedNullableString($media->getAttribute('description'), 'media_description'),
            ];

            if ($item->type()->value === 'artwork' && $selection['include_connected_media_prompts']) {
                $context['prompt'] = $this->normalizedNullableString($media->getAttribute('prompt'), 'media_prompt');
            }

            if ($item->type()->value === 'artwork' && $selection['include_connected_media_process_notes']) {
                $context['process_notes'] = $this->normalizedNullableString(
                    $media->getAttribute('process_notes'),
                    'media_process_notes',
                );
            }

            $result[] = $context;
        }

        if (count($result) > 50) {
            throw new DomainException('The selected connected media exceed the Journal AI context budget.');
        }

        return [$result, $excluded];
    }

    /** @param array<string, mixed> $selection
     * @return array<string, ?string>
     */
    private function protectedTargetValues(Post $post, PostAiOperation $operation, array $selection): array
    {
        $fields = match ($operation) {
            PostAiOperation::ImprovePassage => [$selection['passage']['field']],
            PostAiOperation::Metadata => ['excerpt', 'cover_alt_text', 'seo_title', 'seo_description'],
            default => [],
        };

        return collect($fields)
            ->mapWithKeys(fn (string $field): array => [
                $field => $this->normalizedNullableString($post->getAttribute($field), $field),
            ])
            ->all();
    }

    /** @param array<string, ?string> $targetValues
     * @param  array<string, mixed>  $selection
     * @return array<string, array<string, mixed>>
     */
    private function protectedTargetManifest(array $targetValues, array $selection): array
    {
        $targets = [];

        foreach ($targetValues as $field => $value) {
            $targets[$field] = [
                'value_hash' => CanonicalJson::hash($value),
            ];

            if (($selection['passage']['field'] ?? null) === $field) {
                $targets[$field]['start'] = $selection['passage']['start'];
                $targets[$field]['end'] = $selection['passage']['end'];
            }
        }

        return $targets;
    }

    private function normalizedNullableString(mixed $value, string $budget): ?string
    {
        if ($value === null) {
            return null;
        }

        return $this->normalizedString($value, $budget);
    }

    private function normalizedString(mixed $value, string $budget): string
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
            throw new DomainException('Selected Journal AI context contains invalid text.');
        }

        $value = str_replace(["\r\n", "\r"], "\n", $value);

        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value)) {
            throw new DomainException('Selected Journal AI context contains unsupported control characters.');
        }

        $this->assertBudget($budget, $value);

        return $value;
    }

    private function assertBudget(string $budget, string $value): void
    {
        $limit = self::FIELD_CHARACTER_BUDGETS[$budget] ?? null;

        if ($limit === null || mb_strlen($value, 'UTF-8') > $limit) {
            throw new DomainException('A selected Journal AI context field exceeds its character budget.');
        }
    }

    private function characterLength(?string $value): int
    {
        return $value === null ? 0 : mb_strlen($value, 'UTF-8');
    }
}
