<?php

namespace App\Services;

use App\Models\Album;
use App\Models\Artwork;
use App\Models\Track;
use Illuminate\Support\Collection;

class MusicArtworkSuggestionService
{
    /** @return Collection<int, array{artwork:Artwork,score:int,shared:array<int,string>}> */
    public function forTrack(Track $track, int $limit = 10): Collection
    {
        return $this->rank($track->tags()->pluck('tags.id')->all(), $limit);
    }

    /** @return Collection<int, array{artwork:Artwork,score:int,shared:array<int,string>}> */
    public function forAlbum(Album $album, int $limit = 10): Collection
    {
        $tagIds = $album->tracks()->with('tags:id')->get()->flatMap->tags->pluck('id')->unique()->all();

        return $this->rank($tagIds, $limit);
    }

    /** @param array<int, int> $tagIds
     * @return Collection<int, array{artwork:Artwork,score:int,shared:array<int,string>}>
     */
    protected function rank(array $tagIds, int $limit): Collection
    {
        if ($tagIds === []) {
            return collect();
        }

        return Artwork::query()
            ->published()
            ->whereHas('tags', fn ($query) => $query->whereKey($tagIds))
            ->with(['tags' => fn ($query) => $query->whereKey($tagIds)])
            ->get()
            ->map(function (Artwork $artwork): array {
                $weight = ['mood' => 4, 'style' => 3, 'color' => 2, 'medium' => 1, 'other' => 1];
                $score = $artwork->tags->sum(fn ($tag): int => $weight[$tag->pivot->category] ?? 1);
                if ($artwork->featured) {
                    $score++;
                }

                return ['artwork' => $artwork, 'score' => $score, 'shared' => $artwork->tags->pluck('name')->all()];
            })
            ->sortByDesc('score')
            ->take($limit)
            ->values();
    }

    /** @return array<int, string> */
    public function trackOptions(Track $track): array
    {
        return $this->forTrack($track)->mapWithKeys(fn (array $match): array => [
            $match['artwork']->id => $match['artwork']->title.' — '.$match['score'].' points ('.implode(', ', $match['shared']).')',
        ])->all();
    }

    /** @return array<int, string> */
    public function albumOptions(Album $album): array
    {
        return $this->forAlbum($album)->mapWithKeys(fn (array $match): array => [
            $match['artwork']->id => $match['artwork']->title.' — '.$match['score'].' points ('.implode(', ', $match['shared']).')',
        ])->all();
    }

    /** @param Collection<int, Track> $tracks
     * @return array{applied:int,skipped:int}
     */
    public function applyBestToTracks(Collection $tracks, bool $replaceExisting = false): array
    {
        $applied = 0;
        $skipped = 0;

        foreach ($tracks as $track) {
            if ($track->cover_artwork_id && ! $replaceExisting) {
                $skipped++;

                continue;
            }

            $match = $this->forTrack($track, 1)->first();

            if (! $match) {
                $skipped++;

                continue;
            }

            $track->update(['cover_artwork_id' => $match['artwork']->id]);
            $applied++;
        }

        return compact('applied', 'skipped');
    }
}
