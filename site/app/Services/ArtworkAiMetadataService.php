<?php

namespace App\Services;

use App\Models\Artwork;
use App\Models\Tag;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class ArtworkAiMetadataService
{
    public function __construct(
        protected ImageVariantService $imageVariantService,
        protected AiSettings $settings,
        protected OllamaClient $ollamaClient,
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
        $sourcePath = $artwork->display_path ?: $artwork->image_path;
        $analysisImage = $this->imageVariantService->createAnalysisImageData($sourcePath);
        $prompt = $this->prompt($artwork, $analysisImage);
        $schema = $this->schema();

        $suggestion = match ($this->settings->provider()) {
            'ollama' => $this->ollamaClient->analyze($prompt, $schema, $analysisImage),
            'openai' => $this->analyzeWithOpenAi($prompt, $schema, $analysisImage),
            default => throw new RuntimeException('Unsupported AI provider configured.'),
        };

        return $this->normalizeSuggestion($suggestion);
    }

    public function applySuggestion(Artwork $artwork): Artwork
    {
        if (blank($artwork->ai_suggestion)) {
            throw new RuntimeException('This artwork has no AI suggestion to apply.');
        }

        $suggestion = $this->normalizeSuggestion($artwork->ai_suggestion);

        $artwork->forceFill([
            'title' => $suggestion['title'] ?: $artwork->title,
            'description' => $suggestion['description'] ?: $artwork->description,
            'alt_text' => $suggestion['alt_text'] ?: $artwork->alt_text,
            'ai_suggestion' => $suggestion,
            'ai_status' => Artwork::AI_STATUS_APPLIED,
            'ai_error' => null,
            'ai_analyzed_at' => $artwork->ai_analyzed_at ?: now(),
        ])->save();

        $this->syncTags($artwork, $suggestion);

        return $artwork->refresh();
    }

    public function model(): string
    {
        return $this->settings->modelDescriptor();
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $analysisImage
     * @return array<string, mixed>
     */
    protected function analyzeWithOpenAi(string $prompt, array $schema, array $analysisImage): array
    {
        $apiKey = config('services.openai.api_key');

        if (blank($apiKey)) {
            throw new RuntimeException('OPENAI_API_KEY is not configured.');
        }

        $response = Http::baseUrl(rtrim(config('services.openai.base_url'), '/'))
            ->withToken($apiKey)
            ->acceptJson()
            ->asJson()
            ->timeout($this->settings->openAiRequestTimeout())
            ->retry(2, 750)
            ->post('responses', [
                'model' => $this->settings->openAiModel(),
                'input' => [
                    [
                        'role' => 'user',
                        'content' => [
                            [
                                'type' => 'input_text',
                                'text' => $prompt,
                            ],
                            [
                                'type' => 'input_image',
                                'image_url' => $analysisImage['data_url'],
                                'detail' => 'low',
                            ],
                        ],
                    ],
                ],
                'text' => [
                    'format' => [
                        'type' => 'json_schema',
                        'name' => 'artwork_metadata',
                        'strict' => true,
                        'schema' => $schema,
                    ],
                ],
                'max_output_tokens' => 900,
            ])
            ->throw()
            ->json();

        return $this->decodeSuggestion($response);
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
            'items' => ['type' => 'string'],
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
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    protected function decodeSuggestion(array $response): array
    {
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

        if (! is_string($text) || blank($text)) {
            throw new RuntimeException('OpenAI response did not include structured output text.');
        }

        $decoded = json_decode($text, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('OpenAI response was not valid JSON.');
        }

        return $decoded;
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
        return [
            'title' => $this->cleanText($suggestion['title'] ?? '', 120),
            'description' => $this->cleanText($suggestion['description'] ?? '', 600),
            'alt_text' => $this->cleanText($suggestion['alt_text'] ?? '', 200),
            'tags' => $this->cleanList($suggestion['tags'] ?? []),
            'style_tags' => $this->cleanList($suggestion['style_tags'] ?? []),
            'mood_tags' => $this->cleanList($suggestion['mood_tags'] ?? []),
            'color_tags' => $this->cleanList($suggestion['color_tags'] ?? []),
            'medium_tags' => $this->cleanList($suggestion['medium_tags'] ?? []),
            'confidence' => max(0, min(1, (float) ($suggestion['confidence'] ?? 0))),
            'content_warning' => $this->cleanText($suggestion['content_warning'] ?? '', 200),
        ];
    }

    /**
     * @param  array<string, mixed>  $suggestion
     */
    protected function syncTags(Artwork $artwork, array $suggestion): void
    {
        $artwork->tags()->detach();
        $attachedSlugs = [];

        $categories = [
            'subject' => $suggestion['tags'] ?? [],
            'style' => $suggestion['style_tags'] ?? [],
            'mood' => $suggestion['mood_tags'] ?? [],
            'color' => $suggestion['color_tags'] ?? [],
            'medium' => $suggestion['medium_tags'] ?? [],
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
            ->map(fn (mixed $value): string => Str::of((string) $value)->replace(['#', '_'], ['', ' '])->squish()->lower()->toString())
            ->filter()
            ->unique()
            ->take(12)
            ->values()
            ->all();
    }
}
