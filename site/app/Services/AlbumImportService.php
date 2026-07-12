<?php

namespace App\Services;

use App\Models\Album;
use App\Models\Track;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AlbumImportService
{
    public function __construct(
        protected AudioMetadataService $metadata,
        protected AlbumMatchingService $albums,
    ) {}

    /** @param array<int, string> $paths
     * @param  array<string, string>  $originalNames
     * @return Collection<int, Track>
     */
    public function import(array $paths, array $originalNames = [], ?int $albumId = null, bool $standalonePublished = false): Collection
    {
        return DB::transaction(function () use ($paths, $originalNames, $albumId, $standalonePublished): Collection {
            $created = collect();

            foreach ($paths as $path) {
                $original = $originalNames[$path] ?? basename($path);
                $data = $this->metadata->extract($path, $original);
                $album = $albumId
                    ? Album::query()->findOrFail($albumId)
                    : $this->albums->resolve($data, $data['artist'] ?? null);
                $track = Track::query()->create([
                    'album_id' => $album?->id,
                    'title' => $data['title'] ?? pathinfo($original, PATHINFO_FILENAME),
                    'artist' => $data['artist'] ?? $album?->artist,
                    'audio_path' => $path,
                    'original_filename' => $original,
                    'duration_seconds' => $data['duration_seconds'] ?? null,
                    'disc_number' => $data['disc_number'] ?? 1,
                    'track_number' => $data['track_number'] ?? null,
                    'release_year' => $data['release_year'] ?? $album?->release_year,
                    'metadata' => ['audio_import' => $data],
                    'standalone_published' => $standalonePublished,
                ]);
                $created->push($track);
            }

            return $created;
        });
    }
}
