<?php

namespace App\Services;

use App\Models\Artwork;
use App\Models\Tag;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ArtworkAiMetadataService
{
    public function __construct(
        protected ImageVariantService $imageVariantService,
        protected AiSettings $settings,
        protected AiProviderManager $providers,
    ) {}

    /**
     * @return array{
     *     title:string,
     *     description:string,
     *     alt_text:string,
     *     tags:array<int, string>,
     *     style_tags:array<int, string>,
     *     mood_tags:array<int, string>,
     *     color_tags:array<int, string>,
     *     medium_tags:array<int, string>,
     *     confidence:float,
     *     content_warning:string
     * }
     */
    public function analyze(Artwork $artwork): array
    {
        $sourcePath = $artwork->availableDisplayPath();
        $analysisImage = $this->imageVariantService->createAnalysisImageData($sourcePath);
        $prompt = $this->prompt($artwork, $analysisImage);
        $schema = $this->schema();

        $suggestion = $this->providers->analyzeImage($prompt, $schema, $analysisImage);

        return $this->normalizeSuggestion($suggestion);
    }

    public function applySuggestion(
        Artwork $artwork,
        bool $syncSmartCollections = true,
        bool $preserveQueueState = false,
    ): Artwork {
        if (blank($artwork->ai_suggestion)) {
            throw new RuntimeException('This artwork has no AI suggestion to apply.');
        }

        $suggestion = $this->normalizeSuggestion($artwork->ai_suggestion);

        if ($this->suggestionTagsAreEmpty($suggestion)) {
            throw new RuntimeException('This AI suggestion has no usable tags to apply.');
        }

        $artwork = DB::transaction(function () use ($artwork, $suggestion, $preserveQueueState): Artwork {
            $artwork->forceFill([
                'title' => $suggestion['title'] ?: $artwork->title,
                'description' => $suggestion['description'] ?: $artwork->description,
                'alt_text' => $suggestion['alt_text'] ?: $artwork->alt_text,
                'ai_suggestion' => $suggestion,
                'ai_status' => Artwork::AI_STATUS_APPLIED,
                'ai_queue_token' => $preserveQueueState ? $artwork->ai_queue_token : null,
                'ai_apply_after_analysis' => $preserveQueueState ? $artwork->ai_apply_after_analysis : false,
                'ai_error' => null,
                'ai_started_at' => $preserveQueueState ? $artwork->ai_started_at : null,
                'ai_analyzed_at' => $artwork->ai_analyzed_at ?: now(),
            ])->save();

            $this->syncTags($artwork, $suggestion);

            return $artwork->refresh();
        });

        if ($syncSmartCollections) {
            app(AutomaticCollectionService::class)->refreshExisting(sync: false);
            app(SmartCollectionService::class)->syncAutomatic();
        }

        return $artwork->refresh();
    }

    /** @param iterable<Artwork> $artworks */
    public function applySuggestions(iterable $artworks): int
    {
        $applied = 0;

        foreach ($artworks as $artwork) {
            if (blank($artwork->ai_suggestion) || $artwork->ai_status === Artwork::AI_STATUS_APPLIED) {
                continue;
            }

            $this->applySuggestion($artwork, syncSmartCollections: false);
            $applied++;
        }

        if ($applied > 0) {
            app(AutomaticCollectionService::class)->refreshExisting(sync: false);
            app(SmartCollectionService::class)->syncAutomatic();
        }

        return $applied;
    }

    public function applyReadySuggestions(int $limit = 0): int
    {
        $query = Artwork::query()
            ->where('ai_status', Artwork::AI_STATUS_READY)
            ->whereNotNull('ai_suggestion')
            ->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        return $this->applySuggestions($query->get());
    }

    public function model(): string
    {
        return $this->settings->modelDescriptor();
    }

    /**
     * @param  array<string, mixed>  $analysisImage
     */
    protected function prompt(Artwork $artwork, array $analysisImage): string
    {
        $promptVersion = config('creative_ai.ai.prompt_version', 'artwork-metadata-v1');

        return trim(<<<PROMPT
You are helping maintain a public portfolio for generative AI artwork.
Return concise English metadata for the supplied image.

Rules:
- Describe only visible content and style.
- Do not identify real people.
- Avoid claims about tools, source prompts, or artist intent unless visible or provided.
- Do not label the image as AI-generated based only on the portfolio context.
- Title should be title case and 2-7 words.
- Description should be 1-2 polished public-facing sentences.
- Alt text should be factual, accessible, and under 160 characters.
- Tags should be lowercase words or short phrases without hash symbols.
- Generic tags should describe visible subjects or themes, not style, mood, color, or medium.
- Put each label in exactly one tag array; do not repeat labels across category arrays.
- Color tags should be color names, not hex values.
- Use content_warning as an empty string unless the image contains sensitive or adult content.

Context:
- Prompt version: {$promptVersion}
- Existing title: {$artwork->title}
- Existing description: {$artwork->description}
- Existing prompt notes: {$artwork->prompt}
- Analysis image: {$analysisImage['width']}x{$analysisImage['height']} JPEG, {$analysisImage['bytes']} bytes
PROMPT);
    }

    /**
     * @return array<string, mixed>
     */
    protected function schema(): array
    {
        $tagArray = [
            'type' => 'array',
            'items' => [
                'type' => 'string',
                'maxLength' => 80,
            ],
            'minItems' => 0,
            'maxItems' => 12,
        ];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'title' => [
                    'type' => 'string',
                    'maxLength' => 120,
                ],
                'description' => [
                    'type' => 'string',
                    'maxLength' => 600,
                ],
                'alt_text' => [
                    'type' => 'string',
                    'maxLength' => 200,
                ],
                'tags' => $tagArray,
                'style_tags' => $tagArray,
                'mood_tags' => $tagArray,
                'color_tags' => $tagArray,
                'medium_tags' => $tagArray,
                'confidence' => [
                    'type' => 'number',
                    'minimum' => 0,
                    'maximum' => 1,
                ],
                'content_warning' => [
                    'type' => 'string',
                    'maxLength' => 200,
                ],
            ],
            'required' => [
                'title',
                'description',
                'alt_text',
                'tags',
                'style_tags',
                'mood_tags',
                'color_tags',
                'medium_tags',
                'confidence',
                'content_warning',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $suggestion
     * @return array{
     *     title:string,
     *     description:string,
     *     alt_text:string,
     *     tags:array<int, string>,
     *     style_tags:array<int, string>,
     *     mood_tags:array<int, string>,
     *     color_tags:array<int, string>,
     *     medium_tags:array<int, string>,
     *     confidence:float,
     *     content_warning:string
     * }
     */
    protected function normalizeSuggestion(array $suggestion): array
    {
        $tagLists = [
            'tags' => $this->cleanList($suggestion['tags'] ?? []),
            'style_tags' => $this->cleanList($suggestion['style_tags'] ?? []),
            'mood_tags' => $this->cleanList($suggestion['mood_tags'] ?? []),
            'color_tags' => $this->cleanList($suggestion['color_tags'] ?? []),
            'medium_tags' => $this->cleanList($suggestion['medium_tags'] ?? []),
        ];
        $seenSlugs = [];

        foreach (['color_tags', 'medium_tags', 'style_tags', 'mood_tags', 'tags'] as $key) {
            $tagLists[$key] = collect($tagLists[$key])
                ->filter(function (string $tag) use (&$seenSlugs): bool {
                    $slug = Str::slug($tag);

                    if (blank($slug) || isset($seenSlugs[$slug])) {
                        return false;
                    }

                    $seenSlugs[$slug] = true;

                    return true;
                })
                ->values()
                ->all();
        }

        return [
            'title' => $this->cleanText($suggestion['title'] ?? '', 120),
            'description' => $this->cleanText($suggestion['description'] ?? '', 600),
            'alt_text' => $this->cleanText($suggestion['alt_text'] ?? '', 200),
            'tags' => $tagLists['tags'],
            'style_tags' => $tagLists['style_tags'],
            'mood_tags' => $tagLists['mood_tags'],
            'color_tags' => $tagLists['color_tags'],
            'medium_tags' => $tagLists['medium_tags'],
            'confidence' => max(0, min(1, (float) ($suggestion['confidence'] ?? 0))),
            'content_warning' => $this->cleanText($suggestion['content_warning'] ?? '', 200),
        ];
    }

    /** @param array<string, mixed> $suggestion */
    protected function suggestionTagsAreEmpty(array $suggestion): bool
    {
        return collect(['tags', 'style_tags', 'mood_tags', 'color_tags', 'medium_tags'])
            ->every(fn (string $key): bool => empty($suggestion[$key]));
    }

    /**
     * @param  array<string, mixed>  $suggestion
     */
    protected function syncTags(Artwork $artwork, array $suggestion): void
    {
        $artwork->tags()->detach();
        $attachedSlugs = [];

        $categories = [
            'color' => $suggestion['color_tags'] ?? [],
            'medium' => $suggestion['medium_tags'] ?? [],
            'style' => $suggestion['style_tags'] ?? [],
            'mood' => $suggestion['mood_tags'] ?? [],
            'subject' => $suggestion['tags'] ?? [],
        ];

        foreach ($categories as $category => $tags) {
            foreach ($tags as $name) {
                $tag = $this->findOrCreateTag($name);

                if (! $tag) {
                    continue;
                }

                if (isset($attachedSlugs[$tag->slug])) {
                    continue;
                }

                $artwork->tags()->attach($tag->id, ['category' => $category]);
                $attachedSlugs[$tag->slug] = true;
            }
        }
    }

    protected function findOrCreateTag(string $name): ?Tag
    {
        $name = Str::of($name)->replace('#', '')->squish()->lower()->toString();
        $slug = Str::slug($name);

        if (blank($name) || blank($slug)) {
            return null;
        }

        return Tag::query()->firstOrCreate(
            ['slug' => $slug],
            ['name' => $name],
        );
    }

    protected function cleanText(mixed $value, int $limit): string
    {
        return Str::of((string) $value)
            ->squish()
            ->limit($limit, '')
            ->toString();
    }

    /**
     * @return array<int, string>
     */
    protected function cleanList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return collect($values)
            ->map(fn (mixed $value): string => Str::of((string) $value)->replace(['#', '_'], ['', ' '])->squish()->lower()->limit(80, '')->toString())
            ->filter()
            ->unique()
            ->take(12)
            ->values()
            ->all();
    }
}
