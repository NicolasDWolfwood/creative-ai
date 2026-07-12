<?php

namespace App\Services;

use App\Models\Album;
use App\Models\Track;
use Illuminate\Support\Facades\DB;

class AlbumOrganizationService
{
    public function __construct(protected AlbumMatchingService $albums) {}

    /** @return array{tracks_organized:int,albums_used:int,empty_imports_removed:int} */
    public function organizeExisting(): array
    {
        $organized = 0;
        $albumIds = collect();
        $sourceAlbumIds = collect();

        DB::transaction(function () use (&$organized, $albumIds, $sourceAlbumIds): void {
            Track::query()->whereNotNull('metadata')->orderBy('id')->each(function (Track $track) use (&$organized, $albumIds, $sourceAlbumIds): void {
                $metadata = data_get($track->metadata, 'audio_import');
                if (! is_array($metadata) || blank($metadata['album'] ?? null)) {
                    return;
                }

                $album = $this->albums->resolve($metadata, $track->artist);
                if (! $album) {
                    return;
                }

                $albumIds->push($album->id);
                if ($track->album_id && $track->album_id !== $album->id) {
                    $sourceAlbumIds->push($track->album_id);
                }
                if ($track->album_id !== $album->id) {
                    $track->album_id = $album->id;
                    app(TrackPublicationService::class)->syncForAlbum($track, $album);
                    $track->saveQuietly();
                    $organized++;
                }
            });
        });

        $removed = 0;
        Album::query()->whereKey($sourceAlbumIds->unique())->whereDoesntHave('tracks')
            ->whereNull('cover_artwork_id')->whereNull('description')->where('featured', false)
            ->each(function (Album $album) use (&$removed): void {
                $album->delete();
                $removed++;
            });

        if ($organized > 0) {
            app(SmartPlaylistService::class)->syncAutomatic();
        }

        return ['tracks_organized' => $organized, 'albums_used' => $albumIds->unique()->count(), 'empty_imports_removed' => $removed];
    }
}
