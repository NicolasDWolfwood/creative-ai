<?php

namespace App\Services;

use App\Models\Artwork;
use App\Models\Collection as ArtworkCollection;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection as SupportCollection;

class CollectionCoverService
{
    public const PLACEHOLDER_PATH = 'images/collection-placeholder.svg';

    protected const SECONDS_PER_DAY = 86_400;

    public const CANDIDATE_BATCH_SIZE = 96;

    public const MAX_SCANNED_CANDIDATES_PER_COLLECTION = 384;

    protected const MAX_USABLE_CANDIDATES_PER_COLLECTION = 24;

    /**
     * Resolve a collection against the complete ordered public showcase set so
     * uniqueness reassignment matches the cover visitors see today.
     */
    public function selectPublicCover(
        ArtworkCollection $collection,
        ?CarbonInterface $forDay = null,
    ): ?Artwork {
        $collections = ArtworkCollection::query()
            ->published()
            ->orderByDesc('featured')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return $this->select($collections, $forDay)->get($collection->getKey());
    }

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
        $dayNumber = $this->dayNumber($forDay ?: CarbonImmutable::now());
        $candidatesByCollection = [];
        $artworkByCollectionAndMedia = [];
        $availabilityByArtwork = [];

        foreach ($collections as $collection) {
            $collectionId = (int) $collection->getKey();
            $candidateArtwork = $this->coverCandidates($collection, $availabilityByArtwork)
                ->sortBy(fn (Artwork $artwork): int => (int) $artwork->getKey(), SORT_NUMERIC)
                ->values();
            $artworkByMedia = [];

            foreach ($candidateArtwork as $artwork) {
                $artworkByMedia[$this->mediaIdentity($artwork)] ??= $artwork;
            }

            $featuredMedia = collect(array_keys($artworkByMedia))
                ->filter(fn (string $media): bool => $artworkByMedia[$media]->featured)
                ->values();
            $otherMedia = collect(array_keys($artworkByMedia))
                ->reject(fn (string $media): bool => $artworkByMedia[$media]->featured)
                ->values();
            $offset = $dayNumber + $this->collectionOffset($collectionId);
            $candidateMedia = $this->rotate($featuredMedia, $offset)
                ->concat($this->rotate($otherMedia, $offset))
                ->values();

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
     * Scan a bounded number of prioritized rows in batches. A missing-file run
     * in the first batch therefore cannot hide a usable later fallback, while
     * public requests still have a predictable upper bound on filesystem work.
     *
     * @param  array<int, bool>  $availabilityByArtwork
     * @return SupportCollection<int, Artwork>
     */
    protected function coverCandidates(ArtworkCollection $collection, array &$availabilityByArtwork): SupportCollection
    {
        $candidates = collect();
        $seenMedia = [];

        for ($offset = 0; $offset < self::MAX_SCANNED_CANDIDATES_PER_COLLECTION; $offset += self::CANDIDATE_BATCH_SIZE) {
            $limit = min(
                self::CANDIDATE_BATCH_SIZE,
                self::MAX_SCANNED_CANDIDATES_PER_COLLECTION - $offset,
            );
            $batch = $collection->artworks()
                ->publiclyAvailable()
                ->where(fn (Builder $query): Builder => $query
                    ->whereNotNull('artworks.thumb_path')
                    ->orWhereNotNull('artworks.display_path')
                    ->orWhereNotNull('artworks.image_path'))
                ->reorder('artworks.featured', 'desc')
                ->orderByDesc('artworks.sort_order')
                ->orderByDesc('artworks.created_at')
                ->orderByDesc('artworks.id')
                ->offset($offset)
                ->limit($limit)
                ->get();

            foreach ($batch as $artwork) {
                $artworkId = (int) $artwork->getKey();

                if (! ($availabilityByArtwork[$artworkId] ??= $artwork->hasAvailableImage())) {
                    continue;
                }

                $media = $this->mediaIdentity($artwork);

                if (isset($seenMedia[$media])) {
                    continue;
                }

                $seenMedia[$media] = true;
                $candidates->push($artwork);

                if ($candidates->count() >= self::MAX_USABLE_CANDIDATES_PER_COLLECTION) {
                    break 2;
                }
            }

            if ($batch->count() < $limit) {
                break;
            }
        }

        return $candidates;
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

    /** @param  SupportCollection<int, string>  $media */
    protected function rotate(SupportCollection $media, int $offset): SupportCollection
    {
        if ($media->isEmpty()) {
            return $media;
        }

        $offset %= $media->count();

        return $media->slice($offset)
            ->concat($media->take($offset))
            ->values();
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
