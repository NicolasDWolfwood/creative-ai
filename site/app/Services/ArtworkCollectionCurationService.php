<?php

namespace App\Services;

use App\Models\Artwork;
use App\Models\Collection;
use Illuminate\Support\Facades\DB;

class ArtworkCollectionCurationService
{
    /**
     * @param  array<int, int|string>  $artworkIds
     * @return array{collection:Collection|null,selected:int,attached:int,skipped:int}
     */
    public function createDraftFromUncollected(
        array $artworkIds,
        string $title,
        ?string $description = null,
    ): array {
        $ids = collect($artworkIds)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();
        $selected = $ids->count();

        if ($selected === 0) {
            return $this->emptyResult();
        }

        return DB::transaction(function () use ($description, $ids, $selected, $title): array {
            $eligibleIds = Artwork::query()
                ->whereKey($ids->all())
                ->whereDoesntHave('collections')
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id');
            $attached = $eligibleIds->count();

            if ($attached === 0) {
                return [
                    'collection' => null,
                    'selected' => $selected,
                    'attached' => 0,
                    'skipped' => $selected,
                ];
            }

            $collection = Collection::query()->create([
                'title' => $title,
                'description' => $description,
                'featured' => false,
                'published' => false,
                'published_at' => null,
                'publishes_members' => false,
                'is_smart' => false,
                'is_auto_generated' => false,
                'auto_sync' => false,
            ]);

            $collection->artworks()->attach($eligibleIds->all());

            return [
                'collection' => $collection,
                'selected' => $selected,
                'attached' => $attached,
                'skipped' => $selected - $attached,
            ];
        });
    }

    /** @return array{collection:null,selected:0,attached:0,skipped:0} */
    protected function emptyResult(): array
    {
        return [
            'collection' => null,
            'selected' => 0,
            'attached' => 0,
            'skipped' => 0,
        ];
    }
}
