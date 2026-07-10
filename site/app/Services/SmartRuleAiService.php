<?php

namespace App\Services;

use App\Models\Tag;
use Illuminate\Support\Collection;

class SmartRuleAiService
{
    public function __construct(
        protected AiProviderManager $providers,
        protected AiSettings $settings,
    ) {}

    /**
     * @return array{tag_ids:array<int, int>, explanation:string, model:string}
     */
    public function suggest(string $type, string $title, ?string $description, Collection $tags): array
    {
        $catalog = $tags
            ->map(fn (Tag $tag): string => $tag->slug.' ('.$tag->name.')')
            ->implode(', ');

        $prompt = trim(<<<PROMPT
Choose existing tags for a smart {$type} named "{$title}".
Description: {$description}

Only choose tag slugs from this catalog:
{$catalog}

Select a focused set of up to eight tags. Do not invent tags. Explain the choice in one short sentence.
PROMPT);

        $result = $this->providers->generateStructured($prompt, [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'tag_slugs' => ['type' => 'array', 'items' => ['type' => 'string']],
                'explanation' => ['type' => 'string'],
            ],
            'required' => ['tag_slugs', 'explanation'],
        ]);

        $selected = $tags
            ->whereIn('slug', collect($result['tag_slugs'] ?? [])->map(fn (mixed $slug): string => str((string) $slug)->slug()->toString()))
            ->pluck('id')
            ->values()
            ->all();

        return [
            'tag_ids' => $selected,
            'explanation' => str((string) ($result['explanation'] ?? ''))->squish()->limit(240, '')->toString(),
            'model' => $this->settings->modelDescriptor(),
        ];
    }
}
