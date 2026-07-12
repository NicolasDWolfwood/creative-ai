<?php

namespace App\Services;

use App\Models\Tag;
use App\Models\Track;
use Illuminate\Support\Str;

class TrackAiMetadataService
{
    public function __construct(
        protected AiProviderManager $providers,
        protected AiSettings $settings,
        protected SmartPlaylistService $smartPlaylists,
    ) {}

    /** @return array<string, array<int, string>> */
    public function analyze(Track $track): array
    {
        return $this->providers->generateStructured(trim(<<<PROMPT
Create concise music-library metadata from the available track information. Do not claim to have heard audio.
Title: {$track->title}
Artist: {$track->artist}
Description: {$track->description}
Original filename: {$track->original_filename}

Return lowercase genre, mood, and descriptive tags. Keep each list focused and avoid inventing factual production details.
PROMPT), [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'genre_tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                'mood_tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['genre_tags', 'mood_tags', 'tags'],
        ]);
    }

    public function analyzeAndApply(Track $track): Track
    {
        $track->forceFill(['ai_suggestion' => $this->analyze($track)])->saveQuietly();

        return $this->applySuggestion($track->refresh());
    }

    public function applySuggestion(Track $track): Track
    {
        $result = $track->ai_suggestion ?? [];

        if ($result === []) {
            return $track;
        }

        $track->tags()->detach();
        $attached = [];

        foreach (['genre' => $result['genre_tags'] ?? [], 'mood' => $result['mood_tags'] ?? [], 'other' => $result['tags'] ?? []] as $category => $names) {
            foreach (collect($names)->take(10) as $name) {
                $name = Str::of((string) $name)->replace(['#', '_'], ['', ' '])->squish()->lower()->limit(80, '')->toString();
                $slug = Str::slug($name);

                if (blank($slug) || isset($attached[$slug])) {
                    continue;
                }

                $tag = Tag::query()->firstOrCreate(['slug' => $slug], ['name' => $name]);
                $track->tags()->attach($tag->id, ['category' => $category]);
                $attached[$slug] = true;
            }
        }

        $track->forceFill([
            'ai_status' => Track::AI_STATUS_APPLIED,
            'ai_model' => $this->settings->modelDescriptor(),
            'ai_analyzed_at' => now(),
            'ai_error' => null,
        ])->saveQuietly();

        $this->smartPlaylists->syncAutomatic();

        return $track->refresh();
    }

    public function applyReadySuggestions(int $limit = 0): int
    {
        $query = Track::query()->where('ai_status', Track::AI_STATUS_READY)->whereNotNull('ai_suggestion')->orderBy('id');
        if ($limit > 0) {
            $query->limit($limit);
        }
        $count = 0;
        $query->get()->each(function (Track $track) use (&$count): void {
            $this->applySuggestion($track);
            $count++;
        });

        return $count;
    }
}
