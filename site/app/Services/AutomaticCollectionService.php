<?php

namespace App\Services;

use App\Models\Artwork;
use App\Models\Collection;
use Illuminate\Database\Eloquent\Builder;
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
     *     memberships_added:int,
     *     memberships_removed:int,
     *     publicly_visible:int,
     *     collection_only:int,
     *     collections:array<int, array{title:string,count:int,added:int,removed:int,visible:int,collection_only:int}>
     * }
     */
    public function maintain(
        int $target = self::DEFAULT_TARGET,
        int $minimumArtwork = self::DEFAULT_MINIMUM_ARTWORK,
        bool $published = true,
        bool $sync = true,
        bool $publishesMembers = true,
    ): array {
        $target = max(1, min(self::MAX_AUTOMATIC_COLLECTIONS, $target));
        $minimumArtwork = max(1, min(500, $minimumArtwork));
        $proposals = $this->tagBasedProposals($minimumArtwork)->take($target)->values();
        $created = 0;
        $updated = 0;
        $removed = 0;
        $membershipsAdded = 0;
        $membershipsRemoved = 0;
        $publiclyVisibleIds = [];
        $collectionOnlyIds = [];
        $matches = [];

        DB::transaction(function () use (
            $proposals,
            $target,
            $minimumArtwork,
            $published,
            $sync,
            $publishesMembers,
            &$created,
            &$updated,
            &$removed,
            &$membershipsAdded,
            &$membershipsRemoved,
            &$publiclyVisibleIds,
            &$collectionOnlyIds,
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
                $beforeIds = $collection->exists
                    ? $collection->artworks()->pluck('artworks.id')->map(fn (mixed $id): int => (int) $id)
                    : collect();
                $rules = [
                    'tag_ids' => $proposal['tag_ids'],
                    'match' => 'any',
                    'only_published' => false,
                    'exclude_future_scheduled' => true,
                    'only_with_available_media' => true,
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
                    'publishes_members' => $publishesMembers,
                    'is_smart' => true,
                    'is_auto_generated' => true,
                    'auto_generation_key' => $proposal['key'],
                    'hero_image_path' => null,
                    'smart_rules' => $rules,
                    'auto_sync' => ! $publishesMembers,
                ])->saveQuietly();

                $matched = $sync
                    ? $this->smartCollections->sync($collection, explicit: true)
                    : $proposal['artwork_count'];
                $afterIds = $sync
                    ? $collection->artworks()->pluck('artworks.id')->map(fn (mixed $id): int => (int) $id)
                    : $beforeIds;
                $added = $afterIds->diff($beforeIds)->count();
                $removedFromCollection = $beforeIds->diff($afterIds)->count();
                $membershipsAdded += $added;
                $membershipsRemoved += $removedFromCollection;

                $matches[] = [
                    'collection_id' => (int) $collection->getKey(),
                    'title' => $collection->title,
                    'count' => $matched,
                    'added' => $added,
                    'removed' => $removedFromCollection,
                ];
            }

            $removed = $existing->count();
            $membershipsRemoved += $existing->sum(fn (Collection $collection): int => $collection->artworks()->count());
            $existing->each->delete();

            // Visibility must be measured only after every generated
            // collection has its final publication state. Themes can overlap,
            // so measuring inside the update loop can count a grant that a
            // later iteration is about to revoke.
            $matches = collect($matches)
                ->map(function (array $match) use (&$publiclyVisibleIds, &$collectionOnlyIds): array {
                    $collection = Collection::query()->findOrFail($match['collection_id']);
                    $memberIds = $collection->artworks()
                        ->pluck('artworks.id')
                        ->map(fn (mixed $id): int => (int) $id);
                    $visibleIds = $this->visibleArtworkIds(
                        $memberIds,
                        $collection->isPubliclyPublished(),
                    );
                    $onlyViaCollectionIds = $visibleIds->diff($this->standalonePublishedIds($visibleIds));

                    $publiclyVisibleIds = array_replace(
                        $publiclyVisibleIds,
                        $visibleIds->mapWithKeys(fn (int $id): array => [$id => true])->all(),
                    );
                    $collectionOnlyIds = array_replace(
                        $collectionOnlyIds,
                        $onlyViaCollectionIds->mapWithKeys(fn (int $id): array => [$id => true])->all(),
                    );

                    unset($match['collection_id']);

                    return [
                        ...$match,
                        'visible' => $visibleIds->count(),
                        'collection_only' => $onlyViaCollectionIds->count(),
                    ];
                })
                ->all();
        });

        return [
            'created' => $created,
            'updated' => $updated,
            'removed' => $removed,
            'collection_count' => count($matches),
            'artwork_matches' => collect($matches)->sum('count'),
            'memberships_added' => $membershipsAdded,
            'memberships_removed' => $membershipsRemoved,
            'publicly_visible' => count($publiclyVisibleIds),
            'collection_only' => count($collectionOnlyIds),
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
        if (! $sync && Collection::query()
            ->where('is_auto_generated', true)
            ->get()
            ->contains(fn (Collection $collection): bool => $this->requiresSnapshot($collection))) {
            return null;
        }

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
            publishesMembers: (bool) $collection->publishes_members,
        );
    }

    /**
     * @return array{
     *     collection:Collection,
     *     count:int,
     *     added:int,
     *     removed:int,
     *     visible:int,
     *     collection_only:int,
     *     explanation:string
     * }
     */
    public function createWithAi(
        ?string $guidance = null,
        int $minimumArtwork = self::DEFAULT_AI_ASSISTED_MINIMUM_ARTWORK,
        bool $published = true,
        bool $publishesMembers = true,
    ): array {
        $minimumArtwork = max(1, min(500, $minimumArtwork));
        $tags = $this->prioritizedAiCatalog($guidance, $minimumArtwork);

        if ($tags->isEmpty()) {
            $artworkLabel = Str::plural('artwork', $minimumArtwork);

            throw new RuntimeException("No suitable AI-approved artwork tag is shared by at least {$minimumArtwork} eligible {$artworkLabel}.");
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
            'publishes_members' => $publishesMembers,
            'is_smart' => true,
            'is_auto_generated' => false,
            'smart_rules' => [
                'tag_ids' => $tagIds,
                'match' => 'any',
                'only_published' => false,
                'exclude_future_scheduled' => true,
                'only_with_available_media' => true,
                'only_analyzed' => true,
                'only_ai_applied' => true,
                'source' => 'ai_assisted',
                'ai_explanation' => $explanation,
                'ai_model' => $this->settings->modelDescriptor(),
            ],
            'auto_sync' => ! $publishesMembers,
        ]);

        $count = $this->smartCollections->sync($collection, explicit: true);
        $memberIds = $collection->artworks()->pluck('artworks.id')->map(fn (mixed $id): int => (int) $id);
        $visibleIds = $this->visibleArtworkIds(
            $memberIds,
            $collection->isPubliclyPublished(),
        );
        $collectionOnly = $visibleIds->diff($this->standalonePublishedIds($visibleIds))->count();

        return [
            'collection' => $collection->refresh(),
            'count' => $count,
            'added' => $memberIds->count(),
            'removed' => 0,
            'visible' => $visibleIds->count(),
            'collection_only' => $collectionOnly,
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
        $eligibleIds = $this->eligibleGeneratedArtwork()
            ->pluck('id')
            ->all();

        if ($eligibleIds === []) {
            return collect();
        }

        return Artwork::query()
            ->whereKey($eligibleIds)
            ->join('artwork_tag', 'artworks.id', '=', 'artwork_tag.artwork_id')
            ->join('tags', 'tags.id', '=', 'artwork_tag.tag_id')
            ->whereIn('artwork_tag.category', ['subject', 'style', 'mood', 'other'])
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
        return $this->eligibleGeneratedArtworkQuery()
            ->whereHas('tags', fn ($query) => $query->whereKey($tagIds))
            ->get()
            ->filter(fn (Artwork $artwork): bool => $artwork->hasAvailableImage())
            ->count();
    }

    /** @return SupportCollection<int, Artwork> */
    protected function eligibleGeneratedArtwork(): SupportCollection
    {
        return $this->eligibleGeneratedArtworkQuery()
            ->get()
            ->filter(fn (Artwork $artwork): bool => $artwork->hasAvailableImage())
            ->values();
    }

    protected function eligibleGeneratedArtworkQuery(): Builder
    {
        return Artwork::query()
            ->where('ai_status', Artwork::AI_STATUS_APPLIED)
            ->whereNotNull('ai_analyzed_at')
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            });
    }

    protected function requiresSnapshot(Collection $collection): bool
    {
        return (bool) $collection->publishes_members
            && ! (bool) data_get($collection->smart_rules, 'only_published', true);
    }

    /**
     * @param  SupportCollection<int, int>  $ids
     * @return SupportCollection<int, int>
     */
    protected function visibleArtworkIds(SupportCollection $ids, bool $collectionIsPublic): SupportCollection
    {
        if (! $collectionIsPublic || $ids->isEmpty()) {
            return collect();
        }

        return Artwork::query()
            ->publiclyAvailable()
            ->whereKey($ids->all())
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values();
    }

    /**
     * @param  SupportCollection<int, int>  $ids
     * @return SupportCollection<int, int>
     */
    protected function standalonePublishedIds(SupportCollection $ids): SupportCollection
    {
        if ($ids->isEmpty()) {
            return collect();
        }

        return Artwork::query()
            ->published()
            ->whereKey($ids->all())
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values();
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
