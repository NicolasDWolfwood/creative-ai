<?php

namespace App\Services;

use App\Models\Playlist;
use App\Models\Track;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class AutomaticPlaylistService
{
    public const DEFAULT_TARGET = 4;

    public const MAX_AUTOMATIC_PLAYLISTS = 8;

    public const DEFAULT_MINIMUM_TRACKS = 2;

    public function __construct(
        protected SmartPlaylistService $smartPlaylists,
        protected AiProviderManager $providers,
        protected AiSettings $settings,
    ) {}

    /**
     * @return array{created:int,updated:int,removed:int,playlist_count:int,track_matches:int,playlists:array<int,array{title:string,count:int}>}
     */
    public function maintain(
        int $target = self::DEFAULT_TARGET,
        int $minimumTracks = self::DEFAULT_MINIMUM_TRACKS,
        bool $published = true,
        bool $sync = true,
    ): array {
        $target = max(1, min(self::MAX_AUTOMATIC_PLAYLISTS, $target));
        $minimumTracks = max(1, min(500, $minimumTracks));
        $proposals = $this->proposals($minimumTracks)->take($target)->values();
        $created = 0;
        $updated = 0;
        $removed = 0;
        $matches = [];

        DB::transaction(function () use ($proposals, $target, $minimumTracks, $published, $sync, &$created, &$updated, &$removed, &$matches): void {
            $existing = Playlist::query()->where('is_auto_generated', true)->lockForUpdate()->get()->keyBy('auto_generation_key');

            foreach ($proposals as $position => $proposal) {
                $playlist = $existing->pull($proposal['key']) ?: new Playlist;
                $playlist->exists ? $updated++ : $created++;
                $rules = [
                    'tag_ids' => $proposal['tag_ids'],
                    'match' => 'any',
                    'only_published' => true,
                    'order' => 'library',
                    'source' => 'tag_frequency',
                    'target_count' => $target,
                    'minimum_tracks' => $minimumTracks,
                    'publish_automatically' => $published,
                    'generated_theme' => $proposal['key'],
                    'generation_explanation' => $proposal['explanation'],
                ];

                $playlist->forceFill([
                    'title' => $proposal['title'],
                    'slug' => $playlist->exists ? $playlist->slug : $this->uniqueSlug($proposal['title']),
                    'description' => $proposal['description'],
                    'sort_order' => 100 + $position,
                    'featured' => false,
                    'published' => $published,
                    'published_at' => $published ? ($playlist->published_at ?: now()) : null,
                    'is_smart' => true,
                    'is_auto_generated' => true,
                    'auto_generation_key' => $proposal['key'],
                    'smart_rules' => $rules,
                    'auto_sync' => true,
                ])->saveQuietly();

                $matched = $sync ? $this->smartPlaylists->sync($playlist) : $proposal['track_count'];
                $matches[] = ['title' => $playlist->title, 'count' => $matched];
            }

            $removed = $existing->count();
            $existing->each->delete();
        });

        return [
            'created' => $created,
            'updated' => $updated,
            'removed' => $removed,
            'playlist_count' => count($matches),
            'track_matches' => collect($matches)->sum('count'),
            'playlists' => $matches,
        ];
    }

    /** @return array{playlist:Playlist,count:int,explanation:string} */
    public function createWithAi(?string $guidance = null, int $minimumTracks = self::DEFAULT_MINIMUM_TRACKS, bool $published = true): array
    {
        $minimumTracks = max(1, min(500, $minimumTracks));
        $tags = $this->availableTagStats();
        if ($tags->isEmpty()) {
            throw new RuntimeException('No tags from published tracks are available yet.');
        }

        $catalog = $tags->take(120)->map(fn (array $tag): string => $tag['slug'].' | '.$tag['name'].' | '.$tag['track_count'].' tracks')->implode("\n");
        $existing = Playlist::query()->orderBy('title')->pluck('title')->implode(', ');
        $direction = filled($guidance) ? trim((string) $guidance) : 'Choose a useful listening mood or genre not already represented.';
        $prompt = trim(<<<PROMPT
Create one useful music playlist from existing track tags.

Creative direction: {$direction}
Existing playlist titles to avoid duplicating: {$existing}
Minimum matching published tracks required: {$minimumTracks}

Available tags are listed as slug | label | published track count:
{$catalog}

Choose up to eight tag slugs from the catalog and use match-any semantics. Do not invent tags. Return a concise title, a one-sentence listener-facing description, and a short explanation.
PROMPT);

        $result = $this->providers->generateStructured($prompt, [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'title' => ['type' => 'string', 'maxLength' => 100],
                'description' => ['type' => 'string', 'maxLength' => 500],
                'tag_slugs' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1, 'maxItems' => 8],
                'explanation' => ['type' => 'string', 'maxLength' => 300],
            ],
            'required' => ['title', 'description', 'tag_slugs', 'explanation'],
        ]);

        $selectedSlugs = collect($result['tag_slugs'] ?? [])->map(fn (mixed $slug): string => Str::slug((string) $slug))->filter()->unique()->take(8);
        $selectedTags = $tags->whereIn('slug', $selectedSlugs)->values();
        if ($selectedTags->isEmpty()) {
            throw new RuntimeException('The AI did not select any tags from the available track catalog.');
        }

        $tagIds = $selectedTags->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $matched = $this->countMatches($tagIds);
        if ($matched < $minimumTracks) {
            throw new RuntimeException("The suggested playlist only matched {$matched} published tracks; {$minimumTracks} are required.");
        }

        $title = Str::of((string) ($result['title'] ?? 'AI Playlist'))->squish()->limit(100, '')->toString() ?: 'AI Playlist';
        $description = Str::of((string) ($result['description'] ?? ''))->squish()->limit(500, '')->toString();
        $explanation = Str::of((string) ($result['explanation'] ?? ''))->squish()->limit(300, '')->toString();
        $playlist = Playlist::query()->create([
            'title' => $title,
            'description' => $description,
            'sort_order' => (int) Playlist::query()->max('sort_order') + 1,
            'published' => $published,
            'published_at' => $published ? now() : null,
            'is_smart' => true,
            'is_auto_generated' => false,
            'smart_rules' => [
                'tag_ids' => $tagIds,
                'match' => 'any',
                'only_published' => true,
                'order' => 'library',
                'source' => 'ai_assisted',
                'ai_explanation' => $explanation,
                'ai_model' => $this->settings->modelDescriptor(),
            ],
            'auto_sync' => true,
        ]);

        return ['playlist' => $playlist->refresh(), 'count' => $this->smartPlaylists->sync($playlist), 'explanation' => $explanation];
    }

    /** @return Collection<int,array<string,mixed>> */
    protected function proposals(int $minimumTracks): Collection
    {
        $tags = $this->availableTagStats();
        $proposals = collect();

        foreach ($this->themes() as $key => $theme) {
            $matchedTags = $tags->filter(fn (array $tag): bool => collect($theme['keywords'])->contains(fn (string $keyword): bool => $this->tagMatches($tag['name'], $keyword)))->take(10)->values();
            if ($matchedTags->isEmpty()) {
                continue;
            }
            $tagIds = $matchedTags->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
            $count = $this->countMatches($tagIds);
            if ($count < $minimumTracks) {
                continue;
            }
            $proposals->push([
                'key' => 'theme:'.$key, 'title' => $theme['title'], 'description' => $theme['description'],
                'tag_ids' => $tagIds, 'track_count' => $count,
                'explanation' => 'Built from recurring music tags: '.$matchedTags->pluck('name')->take(6)->implode(', ').'.',
            ]);
        }

        $usedTagIds = $proposals->pluck('tag_ids')->flatten();
        foreach ($tags as $tag) {
            if ($usedTagIds->contains($tag['id']) || $tag['track_count'] < $minimumTracks) {
                continue;
            }
            $proposals->push([
                'key' => 'tag:'.$tag['slug'], 'title' => Str::headline($tag['name']),
                'description' => 'A continuously updated selection of '.Str::lower($tag['name']).' tracks.',
                'tag_ids' => [$tag['id']], 'track_count' => $tag['track_count'],
                'explanation' => 'Built from the recurring track tag '.$tag['name'].'.',
            ]);
        }

        return $proposals->sortByDesc('track_count')->values();
    }

    /** @return Collection<int,array{id:int,name:string,slug:string,track_count:int}> */
    protected function availableTagStats(): Collection
    {
        return DB::table('tags')->join('track_tag', 'tags.id', '=', 'track_tag.tag_id')->join('tracks', 'tracks.id', '=', 'track_tag.track_id')
            ->where('tracks.published', true)
            ->select(['tags.id', 'tags.name', 'tags.slug'])->selectRaw('COUNT(DISTINCT tracks.id) AS track_count')
            ->groupBy('tags.id', 'tags.name', 'tags.slug')->orderByDesc('track_count')->get()
            ->map(fn (object $tag): array => ['id' => (int) $tag->id, 'name' => (string) $tag->name, 'slug' => (string) $tag->slug, 'track_count' => (int) $tag->track_count]);
    }

    /** @param array<int,int> $tagIds */
    protected function countMatches(array $tagIds): int
    {
        return Track::query()->where('published', true)->whereHas('tags', fn ($query) => $query->whereKey($tagIds))->count();
    }

    protected function tagMatches(string $tag, string $keyword): bool
    {
        $normalize = fn (string $value): string => Str::of($value)->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->squish()->toString();

        return str_contains(' '.$normalize($tag).' ', ' '.$normalize($keyword).' ');
    }

    protected function uniqueSlug(string $title): string
    {
        $base = Str::slug($title) ?: Str::random(8);
        $slug = $base;
        $suffix = 2;
        while (Playlist::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    /** @return array<string,array{title:string,description:string,keywords:array<int,string>}> */
    protected function themes(): array
    {
        return [
            'atmospheric' => ['title' => 'Atmospheric', 'description' => 'Ambient, cinematic, ethereal, and spacious listening.', 'keywords' => ['ambient', 'atmospheric', 'ethereal', 'cinematic', 'dreamy', 'soundscape']],
            'calm-focus' => ['title' => 'Calm & Focus', 'description' => 'Peaceful, reflective music for quiet concentration.', 'keywords' => ['calm', 'peaceful', 'relaxing', 'meditative', 'focus', 'reflective']],
            'energy-motion' => ['title' => 'Energy & Motion', 'description' => 'Upbeat, driving, and intense tracks with momentum.', 'keywords' => ['energetic', 'upbeat', 'driving', 'intense', 'action', 'powerful']],
            'dark-dramatic' => ['title' => 'Dark & Dramatic', 'description' => 'Moody, ominous, melancholic, and dramatic sound worlds.', 'keywords' => ['dark', 'dramatic', 'ominous', 'melancholic', 'moody', 'brooding']],
            'electronic' => ['title' => 'Electronic Worlds', 'description' => 'Electronic, synth-driven, futuristic, and rhythmic music.', 'keywords' => ['electronic', 'synthwave', 'synth', 'techno', 'cyberpunk', 'electronica']],
            'epic-orchestral' => ['title' => 'Epic & Orchestral', 'description' => 'Orchestral, soundtrack, and cinematic music at a grand scale.', 'keywords' => ['epic', 'orchestral', 'soundtrack', 'score', 'trailer', 'cinematic']],
        ];
    }
}
