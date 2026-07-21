<?php

namespace App\Services;

use App\Models\Artwork;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

class ArtworkBulkEditorialService
{
    public function __construct(
        protected SmartCollectionService $smartCollections,
    ) {}

    /**
     * @param  EloquentCollection<int, Artwork>  $records
     * @return array{changed:int,selected:int}
     */
    public function publishNow(EloquentCollection $records): array
    {
        $ids = $this->selectedIds($records);

        return DB::transaction(function () use ($ids): array {
            $now = now();
            $artworks = Artwork::query()
                ->whereKey($ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $changed = 0;

            foreach ($artworks as $artwork) {
                $alreadyCurrent = (bool) $artwork->published
                    && (! $artwork->published_at || $artwork->published_at->lte($now));

                if ($alreadyCurrent) {
                    continue;
                }

                $attributes = ['published' => true];

                if (! $artwork->published_at || $artwork->published_at->gt($now)) {
                    $attributes['published_at'] = $now;
                }

                $artwork->forceFill($attributes)->saveQuietly();
                $changed++;
            }

            if ($changed > 0) {
                // Model events are deliberately suppressed above so a large
                // batch performs one global smart-collection refresh instead
                // of repeating it once per artwork.
                $this->smartCollections->syncAutomatic();
            }

            return [
                'changed' => $changed,
                'selected' => $artworks->count(),
            ];
        });
    }

    /**
     * @param  EloquentCollection<int, Artwork>  $records
     * @return array{changed:int,selected:int,still_available_via_collection:int}
     */
    public function removeStandalonePublication(EloquentCollection $records): array
    {
        $ids = $this->selectedIds($records);

        return DB::transaction(function () use ($ids): array {
            $artworks = Artwork::query()
                ->whereKey($ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $changed = 0;

            foreach ($artworks as $artwork) {
                if (! $artwork->published) {
                    continue;
                }

                // Keep published_at: a future value is also the fail-closed
                // embargo for collection-only availability, while a past
                // value preserves the artwork's publication history.
                $artwork->forceFill(['published' => false])->saveQuietly();
                $changed++;
            }

            if ($changed > 0) {
                $this->smartCollections->syncAutomatic();
            }

            $stillAvailable = Artwork::query()
                ->whereKey($ids)
                ->where('published', false)
                ->publiclyAvailable()
                ->count();

            return [
                'changed' => $changed,
                'selected' => $artworks->count(),
                'still_available_via_collection' => $stillAvailable,
            ];
        });
    }

    /**
     * @param  EloquentCollection<int, Artwork>  $records
     * @return array{changed:int,selected:int}
     */
    public function setFeatured(EloquentCollection $records, bool $featured): array
    {
        $ids = $this->selectedIds($records);

        return [
            'changed' => Artwork::query()
                ->whereKey($ids)
                ->where('featured', ! $featured)
                ->update(['featured' => $featured]),
            'selected' => count($ids),
        ];
    }

    /**
     * @param  EloquentCollection<int, Artwork>  $records
     * @return array<int, int|string>
     */
    protected function selectedIds(EloquentCollection $records): array
    {
        return array_values(array_unique($records->modelKeys()));
    }
}
