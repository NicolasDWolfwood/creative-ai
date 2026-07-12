<?php

namespace App\Services;

use App\Models\Artwork;
use App\Models\Collection as ArtworkCollection;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection as SupportCollection;

class CollectionCoverService
{
    public const PLACEHOLDER_PATH = 'images/collection-placeholder.svg';

    protected const SECONDS_PER_DAY = 86_400;

    /**
     * Select one cover per collection. Unique source media is assigned whenever
     * the collection/media graph permits it; duplicates are used only when every
     * unique option has already been exhausted.
     *
     * @param  EloquentCollection<int, ArtworkCollection>  $collections
     * @return SupportCollection<int, Artwork|null>
     */
    public function select(EloquentCollection $collections, ?CarbonInterface $forDay = null): SupportCollection
    {
        $collections->loadMissing([
            'artworks' => fn (BelongsToMany $query): BelongsToMany => $query
                ->published()
                ->where('featured', true),
        ]);

        $dayNumber = $this->dayNumber($forDay ?: CarbonImmutable::now());
        $candidatesByCollection = [];
        $artworkByCollectionAndMedia = [];

        foreach ($collections as $collection) {
            $collectionId = (int) $collection->getKey();
            $candidateArtwork = $collection->artworks
                ->filter(fn (Artwork $artwork): bool => $artwork->published
                    && $artwork->featured
                    && $artwork->hasAvailableImage())
                ->sortBy(fn (Artwork $artwork): int => (int) $artwork->getKey(), SORT_NUMERIC)
                ->values();
            $artworkByMedia = [];

            foreach ($candidateArtwork as $artwork) {
                $artworkByMedia[$this->mediaIdentity($artwork)] ??= $artwork;
            }

            $candidateMedia = collect(array_keys($artworkByMedia));

            if ($candidateMedia->isNotEmpty()) {
                $offset = ($dayNumber + $this->collectionOffset($collectionId)) % $candidateMedia->count();
                $candidateMedia = $candidateMedia->slice($offset)
                    ->concat($candidateMedia->take($offset))
                    ->values();
            }

            $candidatesByCollection[$collectionId] = $candidateMedia->all();
            $artworkByCollectionAndMedia[$collectionId] = $artworkByMedia;
        }

        $mediaOwners = [];
        $assignments = [];

        foreach (array_keys($candidatesByCollection) as $collectionId) {
            $visitedMedia = [];
            $this->assignUniqueCover(
                $collectionId,
                $candidatesByCollection,
                $mediaOwners,
                $assignments,
                $visitedMedia,
            );
        }

        foreach ($candidatesByCollection as $collectionId => $candidateMedia) {
            if (! isset($assignments[$collectionId]) && $candidateMedia !== []) {
                $assignments[$collectionId] = $candidateMedia[0];
            }
        }

        return $collections->mapWithKeys(function (ArtworkCollection $collection) use ($assignments, $artworkByCollectionAndMedia): array {
            $collectionId = (int) $collection->getKey();
            $media = $assignments[$collectionId] ?? null;

            return [
                $collectionId => $media
                    ? ($artworkByCollectionAndMedia[$collectionId][$media] ?? null)
                    : null,
            ];
        });
    }

    /**
     * @param  array<int, array<int, string>>  $candidatesByCollection
     * @param  array<string, int>  $mediaOwners
     * @param  array<int, string>  $assignments
     * @param  array<string, bool>  $visitedMedia
     */
    protected function assignUniqueCover(
        int $collectionId,
        array $candidatesByCollection,
        array &$mediaOwners,
        array &$assignments,
        array &$visitedMedia,
    ): bool {
        foreach ($candidatesByCollection[$collectionId] as $media) {
            if (isset($visitedMedia[$media])) {
                continue;
            }

            $visitedMedia[$media] = true;
            $currentOwner = $mediaOwners[$media] ?? null;

            if ($currentOwner !== null && ! $this->assignUniqueCover(
                $currentOwner,
                $candidatesByCollection,
                $mediaOwners,
                $assignments,
                $visitedMedia,
            )) {
                continue;
            }

            $mediaOwners[$media] = $collectionId;
            $assignments[$collectionId] = $media;

            return true;
        }

        return false;
    }

    protected function mediaIdentity(Artwork $artwork): string
    {
        return (string) ($artwork->image_path ?: $artwork->availableThumbPath());
    }

    protected function dayNumber(CarbonInterface $date): int
    {
        $utcDate = CarbonImmutable::parse($date->format('Y-m-d').' 00:00:00', 'UTC');

        return intdiv($utcDate->getTimestamp(), self::SECONDS_PER_DAY);
    }

    protected function collectionOffset(int $collectionId): int
    {
        return (int) hexdec(substr(hash('sha256', (string) $collectionId), 0, 8));
    }
}
