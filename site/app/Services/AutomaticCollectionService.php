<?php

namespace App\Services;

use App\Models\Artwork;
use App\Models\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class AutomaticCollectionService
{
    public const DEFAULT_TARGET = 4;

    public const MAX_AUTOMATIC_COLLECTIONS = 5;

    public const DEFAULT_MINIMUM_ARTWORK = 3;

    public const DEFAULT_AI_ASSISTED_MINIMUM_ARTWORK = 2;

    public function __construct(
        protected SmartCollectionService $smartCollections,
        protected AiProviderManager $providers,
        protected AiSettings $settings,
    ) {}

    /**
     * @return array{
     *     created:int,
     *     updated:int,
     *     removed:int,
     *     collection_count:int,
     *     artwork_matches:int,
     *     collections:array<int, array{title:string,count:int}>
     * }
     */
    public function maintain(
        int $target = self::DEFAULT_TARGET,
        int $minimumArtwork = self::DEFAULT_MINIMUM_ARTWORK,
        bool $published = true,
        bool $sync = true,
    ): array {
        $target = max(1, min(self::MAX_AUTOMATIC_COLLECTIONS, $target));
        $minimumArtwork = max(1, min(500, $minimumArtwork));
        $proposals = $this->tagBasedProposals($minimumArtwork)->take($target)->values();
        $created = 0;
        $updated = 0;
        $removed = 0;
        $matches = [];

        DB::transaction(function () use (
            $proposals,
            $target,
            $minimumArtwork,
            $published,
            $sync,
            &$created,
            &$updated,
            &$removed,
            &$matches,
        ): void {
            $existing = Collection::query()
                ->where('is_auto_generated', true)
                ->lockForUpdate()
                ->get()
                ->keyBy('auto_generation_key');

            foreach ($proposals as $position => $proposal) {
                $collection = $existing->pull($proposal['key']) ?: new Collection;
                $collection->exists ? $updated++ : $created++;
                $rules = [
                    'tag_ids' => $proposal['tag_ids'],
                    'match' => 'any',
                    'only_published' => true,
                    'only_analyzed' => true,
                    'only_ai_applied' => true,
                    'source' => 'tag_frequency',
                    'target_count' => $target,
                    'minimum_artwork' => $minimumArtwork,
                    'publish_automatically' => $published,
                    'generated_theme' => $proposal['key'],
                    'generation_explanation' => 'Built from recurring AI-approved tags: '.implode(', ', $proposal['tag_names']).'.',
                ];

                $collection->forceFill([
                    'title' => $proposal['title'],
                    'slug' => $collection->exists ? $collection->slug : $this->uniqueCollectionSlug($proposal['title']),
                    'description' => $proposal['description'],
                    'sort_order' => 100 + $position,
                    'featured' => false,
                    'published' => $published,
                    'published_at' => $published ? ($collection->published_at ?: now()) : null,
                    'is_smart' => true,
                    'is_auto_generated' => true,
                    'auto_generation_key' => $proposal['key'],
                    'hero_image_path' => null,
                    'smart_rules' => $rules,
                    'auto_sync' => true,
                ])->saveQuietly();

                $matched = $sync ? $this->smartCollections->sync($collection) : $proposal['artwork_count'];

                $matches[] = ['title' => $collection->title, 'count' => $matched];
            }

            $removed = $existing->count();
            $existing->each->delete();
        });

        return [
            'created' => $created,
            'updated' => $updated,
            'removed' => $removed,
            'collection_count' => count($matches),
            'artwork_matches' => collect($matches)->sum('count'),
            'collections' => $matches,
        ];
    }

    /**
     * Refresh the current managed set while preserving its configured target.
     *
     * @return array<string, mixed>|null
     */
    public function refreshExisting(bool $sync = true): ?array
    {
        $collection = Collection::query()
            ->where('is_auto_generated', true)
            ->orderBy('id')
            ->first();

        if (! $collection) {
            return null;
        }

        $rules = is_array($collection->smart_rules) ? $collection->smart_rules : [];

        return $this->maintain(
            target: (int) ($rules['target_count'] ?? self::DEFAULT_TARGET),
            minimumArtwork: (int) ($rules['minimum_artwork'] ?? self::DEFAULT_MINIMUM_ARTWORK),
            published: (bool) ($rules['publish_automatically'] ?? true),
            sync: $sync,
        );
    }

    /**
     * @return array{collection:Collection,count:int,explanation:string}
     */
    public function createWithAi(?string $guidance = null, int $minimumArtwork = self::DEFAULT_AI_ASSISTED_MINIMUM_ARTWORK, bool $published = true): array
    {
        $minimumArtwork = max(1, min(500, $minimumArtwork));
        $tags = $this->prioritizedAiCatalog($guidance, $minimumArtwork);

        if ($tags->isEmpty()) {
            $artworkLabel = Str::plural('artwork', $minimumArtwork);

            throw new RuntimeException("No suitable AI-approved artwork tag is shared by at least {$minimumArtwork} eligible published {$artworkLabel}.");
        }

        $catalog = $tags
            ->map(fn (array $tag): string => $tag['slug'].' | '.$tag['name'].' | '.$tag['artwork_count'].' '.Str::plural('artwork', $tag['artwork_count']))
            ->implode("\n");
        $existingTitles = Collection::query()->orderBy('title')->pluck('title')->implode(', ');
        $request = filled($guidance) ? trim((string) $guidance) : 'Choose the strongest broad theme that is not already represented.';
        $prompt = trim(<<<PROMPT
Create one useful, broad artwork collection from existing AI-approved tags.

Creative direction: {$request}
Existing collection titles to avoid duplicating: {$existingTitles}
Minimum matching artwork required: {$minimumArtwork}

Available tags are listed as slug | label | approved artwork count:
{$catalog}

Choose up to twelve tag slugs from the catalog and use match-any semantics. Prefer coherent subjects or creative themes over colors, generic media labels, or lighting terms. Do not invent tags. Return a concise title, a public-facing one-sentence description, and a short explanation of the rule.
PROMPT);

        $result = $this->providers->generateStructured($prompt, [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'title' => ['type' => 'string', 'maxLength' => 100],
                'description' => ['type' => 'string', 'maxLength' => 500],
                'tag_slugs' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'minItems' => 1,
                    'maxItems' => 12,
                ],
                'explanation' => ['type' => 'string', 'maxLength' => 300],
            ],
            'required' => ['title', 'description', 'tag_slugs', 'explanation'],
        ]);

        $selectedSlugs = collect($result['tag_slugs'] ?? [])
            ->map(fn (mixed $slug): string => Str::slug((string) $slug))
            ->filter()
            ->unique()
            ->take(12);
        $selectedTags = $tags->whereIn('slug', $selectedSlugs)->values();

        if ($selectedTags->isEmpty()) {
            throw new RuntimeException('The AI did not select any tags from the approved catalog.');
        }

        $tagIds = $selectedTags->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $matched = $this->countMatchingArtwork($tagIds);

        if ($matched < $minimumArtwork) {
            throw new RuntimeException("The suggested collection only matched {$matched} approved artwork; {$minimumArtwork} are required.");
        }

        $title = Str::of((string) ($result['title'] ?? 'AI Collection'))->squish()->limit(100, '')->toString();
        $description = Str::of((string) ($result['description'] ?? ''))->squish()->limit(500, '')->toString();
        $explanation = Str::of((string) ($result['explanation'] ?? ''))->squish()->limit(300, '')->toString();

        $collection = Collection::query()->create([
            'title' => $title ?: 'AI Collection',
            'description' => $description,
            'sort_order' => (int) Collection::query()->max('sort_order') + 1,
            'published' => $published,
            'published_at' => $published ? now() : null,
            'is_smart' => true,
            'is_auto_generated' => false,
            'smart_rules' => [
                'tag_ids' => $tagIds,
                'match' => 'any',
                'only_published' => true,
                'only_analyzed' => true,
                'only_ai_applied' => true,
                'source' => 'ai_assisted',
                'ai_explanation' => $explanation,
                'ai_model' => $this->settings->modelDescriptor(),
            ],
            'auto_sync' => true,
        ]);

        return [
            'collection' => $collection->refresh(),
            'count' => $this->smartCollections->sync($collection),
            'explanation' => $explanation,
        ];
    }

    /** @return SupportCollection<int, array<string, mixed>> */
    protected function tagBasedProposals(int $minimumArtwork): SupportCollection
    {
        $tags = $this->availableTagStats();
        $proposals = collect();

        foreach ($this->themes() as $key => $theme) {
            $matchedTags = $tags
                ->filter(fn (array $tag): bool => collect($theme['keywords'])->contains(
                    fn (string $keyword): bool => $this->tagMatchesKeyword($tag['name'], $keyword),
                ))
                ->sortByDesc('artwork_count')
                ->take(12)
                ->values();

            if ($matchedTags->isEmpty()) {
                continue;
            }

            $tagIds = $matchedTags->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
            $artworkCount = $this->countMatchingArtwork($tagIds);

            if ($artworkCount < $minimumArtwork) {
                continue;
            }

            $proposals->push([
                'key' => $key,
                'title' => $theme['title'],
                'description' => $theme['description'],
                'tag_ids' => $tagIds,
                'tag_names' => $matchedTags->pluck('name')->take(8)->all(),
                'artwork_count' => $artworkCount,
            ]);
        }

        return $proposals->sortByDesc('artwork_count')->values();
    }

    /** @return SupportCollection<int, array{id:int,name:string,slug:string,artwork_count:int}> */
    protected function availableTagStats(): SupportCollection
    {
        return Artwork::query()
            ->published()
            ->where('artworks.ai_status', Artwork::AI_STATUS_APPLIED)
            ->whereNotNull('artworks.ai_analyzed_at')
            ->join('artwork_tag', 'artworks.id', '=', 'artwork_tag.artwork_id')
            ->join('tags', 'tags.id', '=', 'artwork_tag.tag_id')
            ->whereIn('artwork_tag.category', ['subject', 'style', 'mood'])
            ->select(['tags.id', 'tags.name', 'tags.slug'])
            ->selectRaw('COUNT(DISTINCT artworks.id) AS artwork_count')
            ->groupBy('tags.id', 'tags.name', 'tags.slug')
            ->orderByDesc('artwork_count')
            ->toBase()
            ->get()
            ->map(fn (object $tag): array => [
                'id' => (int) $tag->id,
                'name' => (string) $tag->name,
                'slug' => (string) $tag->slug,
                'artwork_count' => (int) $tag->artwork_count,
            ]);
    }

    /** @return SupportCollection<int, array{id:int,name:string,slug:string,artwork_count:int}> */
    protected function prioritizedAiCatalog(?string $guidance, int $minimumArtwork): SupportCollection
    {
        $words = Str::of((string) $guidance)
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->explode(' ')
            ->filter(fn (string $word): bool => strlen($word) >= 3)
            ->values();
        $genericSlugs = collect([
            'digital art',
            'digital artwork',
            'digital painting',
            'generative art',
            'cinematic lighting',
            'high detail',
            'realistic',
            'photorealistic',
        ])->map(fn (string $label): string => Str::slug($label));

        return $this->availableTagStats()
            ->filter(fn (array $tag): bool => $tag['artwork_count'] >= $minimumArtwork)
            ->reject(fn (array $tag): bool => $genericSlugs->contains(Str::slug($tag['slug'])))
            ->sortByDesc(function (array $tag) use ($words): int {
                $guidanceMatch = $words->contains(fn (string $word): bool => $this->tagMatchesKeyword($tag['name'], $word));

                return ($guidanceMatch ? 100000 : 0) + $tag['artwork_count'];
            })
            ->take(120)
            ->values();
    }

    /** @param array<int, int> $tagIds */
    protected function countMatchingArtwork(array $tagIds): int
    {
        return Artwork::query()
            ->published()
            ->where('ai_status', Artwork::AI_STATUS_APPLIED)
            ->whereNotNull('ai_analyzed_at')
            ->whereHas('tags', fn ($query) => $query->whereKey($tagIds))
            ->count();
    }

    protected function tagMatchesKeyword(string $tag, string $keyword): bool
    {
        $normalize = fn (string $value): string => Str::of($value)
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->toString();
        $tag = ' '.$normalize($tag).' ';
        $keyword = $normalize($keyword);

        return $keyword !== '' && str_contains($tag, ' '.$keyword.' ');
    }

    protected function uniqueCollectionSlug(string $title): string
    {
        $base = Str::slug($title) ?: Str::random(8);
        $slug = $base;
        $suffix = 2;

        while (Collection::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    /** @return array<string, array{title:string,description:string,keywords:array<int, string>}> */
    protected function themes(): array
    {
        return [
            'people' => [
                'title' => 'People',
                'description' => 'Portraits, characters, fashion, and studies of the human presence.',
                'keywords' => ['portrait', 'person', 'people', 'woman', 'women', 'man', 'men', 'face', 'human', 'girl', 'boy', 'female', 'male', 'fashion'],
            ],
            'fantastical' => [
                'title' => 'Fantastical',
                'description' => 'Mythic characters, magical worlds, surreal visions, and dreamlike encounters.',
                'keywords' => ['fantasy', 'magical', 'mythical', 'mythic', 'surreal', 'dreamlike', 'dreamy', 'angel', 'wings', 'fairy', 'otherworldly', 'dark fantasy'],
            ],
            'future-worlds' => [
                'title' => 'Future Worlds',
                'description' => 'Science fiction, cyberpunk, cosmic imagery, machines, and imagined futures.',
                'keywords' => ['sci fi', 'science fiction', 'futuristic', 'cyberpunk', 'robot', 'cyborg', 'space', 'cosmos', 'cosmic', 'galaxy', 'nebula', 'neon'],
            ],
            'nature' => [
                'title' => 'Nature',
                'description' => 'Forests, landscapes, water, mountains, flowers, and atmospheric natural worlds.',
                'keywords' => ['nature', 'forest', 'landscape', 'mountain', 'mountains', 'river', 'ocean', 'beach', 'flower', 'flowers', 'tree', 'trees', 'cloud', 'clouds', 'sunset', 'sunrise'],
            ],
            'design-form' => [
                'title' => 'Design & Form',
                'description' => 'Character design, architecture, abstraction, crafted objects, and visual studies.',
                'keywords' => ['design', 'architecture', 'architectural', 'abstract', 'conceptual', 'geometric', 'typography', 'fashion', 'jewelry', 'product', 'interior', 'mechanical'],
            ],
            'animals' => [
                'title' => 'Animals',
                'description' => 'Wildlife, companions, and imagined creatures rendered with character and detail.',
                'keywords' => ['animal', 'wildlife', 'cat', 'dog', 'owl', 'bird', 'deer', 'horse', 'fox', 'wolf', 'creature'],
            ],
            'cars-machines' => [
                'title' => 'Cars & Machines',
                'description' => 'Automotive concepts, performance machines, vehicles, and mechanical studies.',
                'keywords' => ['car', 'vehicle', 'automotive', 'hypercar', 'motorcycle', 'machine', 'mechanical'],
            ],
        ];
    }
}
