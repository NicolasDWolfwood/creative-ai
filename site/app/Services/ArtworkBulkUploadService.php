<?php

namespace App\Services;

use App\Models\Artwork;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class ArtworkBulkUploadService
{
    public function __construct(
        protected AiSettings $settings,
        protected ArtworkAiQueueService $queue,
    ) {}

    /**
     * @param  array<int, string>  $paths
     * @param  array<string, string>  $originalNames
     * @param  array<int, int|string>  $collectionIds
     * @return Collection<int, Artwork>
     */
    public function create(
        array $paths,
        array $originalNames = [],
        array $collectionIds = [],
        bool $published = true,
        bool $analyze = false,
    ): Collection {
        $created = new Collection;
        $nextSortOrder = ((int) Artwork::query()->max('sort_order')) + 1;
        $collectionIds = collect($collectionIds)->map(fn (mixed $id): int => (int) $id)->filter()->unique()->values()->all();

        foreach (array_values(array_filter($paths)) as $path) {
            $originalName = $originalNames[$path] ?? basename($path);
            $title = Str::of(pathinfo($originalName, PATHINFO_FILENAME))
                ->replace(['_', '-'], ' ')
                ->squish()
                ->headline()
                ->limit(120, '')
                ->toString();

            $artwork = Artwork::query()->create([
                'collection_id' => $collectionIds[0] ?? null,
                'title' => $title ?: 'Untitled Artwork',
                'image_path' => $path,
                'original_filename' => $originalName,
                'sort_order' => $nextSortOrder++,
                'published' => $published,
                'published_at' => $published ? now() : null,
            ]);

            $artwork->collections()->sync($collectionIds);

            if ($analyze && ! $this->settings->autoAnalyzeUploads()) {
                $this->queue->queue($artwork);
            }

            $created->push($artwork);
        }

        return $created;
    }
}
