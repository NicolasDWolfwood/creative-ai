<?php

namespace App\Services;

use App\Data\JournalSourceImage;
use App\Enums\PostMediaType;
use App\Models\Album;
use App\Models\Artwork;
use App\Models\Collection;
use App\Models\Playlist;
use App\Models\Track;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class JournalSourceImageResolver
{
    public function __construct(
        private readonly CollectionCoverService $collectionCovers,
        private readonly PrivateMediaService $media,
        private readonly PublicStoryConnections $publicConnections,
    ) {}

    public function resolve(Model $source): ?JournalSourceImage
    {
        $type = PostMediaType::forModel($source);

        if ($type === null || ! $source->exists || ! $this->publicConnections->mediaIsPublic($source)) {
            return null;
        }

        return match ($type) {
            PostMediaType::Artwork => $source instanceof Artwork
                ? $this->artworkCandidate($source, $type, (int) $source->getKey())
                : null,
            PostMediaType::Collection => $source instanceof Collection
                ? $this->collectionCandidate($source)
                : null,
            PostMediaType::Album => $source instanceof Album
                ? $this->albumCandidate($source, $type, (int) $source->getKey())
                : null,
            PostMediaType::Playlist => $source instanceof Playlist
                ? $this->playlistCandidate($source)
                : null,
            PostMediaType::Track => $source instanceof Track
                ? $this->trackCandidate($source)
                : null,
        };
    }

    private function collectionCandidate(Collection $collection): ?JournalSourceImage
    {
        // Resolve against the full public showcase set. Cover uniqueness can
        // reassign a shared candidate between collections, so selecting this
        // collection in isolation could snapshot a different public image.
        $artwork = $this->collectionCovers->selectPublicCover($collection);

        return $artwork instanceof Artwork
            ? $this->artworkCandidate(
                $artwork,
                PostMediaType::Collection,
                (int) $collection->getKey(),
            )
            : null;
    }

    private function albumCandidate(
        Album $album,
        PostMediaType $sourceType,
        int $sourceId,
    ): ?JournalSourceImage {
        if ($album->cover_preference === 'none') {
            return null;
        }

        $coverArtwork = $album->coverArtwork()->first();
        $publicArtwork = $coverArtwork?->isPubliclyPublished() ? $coverArtwork : null;

        // Preserve Album::cover_url precedence exactly. If a configured source
        // exists but its bytes are missing, fail closed instead of silently
        // selecting a different image from the one the public album presents.
        if ($album->cover_preference !== 'embedded' && $publicArtwork instanceof Artwork) {
            return $this->artworkCandidate($publicArtwork, $sourceType, $sourceId);
        }

        if ($album->cover_preference !== 'artwork' && filled($album->embedded_cover_path)) {
            $path = (string) $album->embedded_cover_path;

            return $this->pathExists($path)
                ? new JournalSourceImage(
                    sourcePath: $path,
                    thumbnailUrl: route('media.albums.embedded-cover', [
                        $album,
                        'v' => substr(hash('sha256', $path), 0, 12),
                    ]),
                    altText: $this->altText('Cover of '.$album->title, 'Album cover'),
                    sourceType: $sourceType,
                    sourceId: $sourceId,
                )
                : null;
        }

        return $publicArtwork instanceof Artwork
            ? $this->artworkCandidate($publicArtwork, $sourceType, $sourceId)
            : null;
    }

    private function playlistCandidate(Playlist $playlist): ?JournalSourceImage
    {
        $artwork = $playlist->coverArtwork()->first();

        if (! $artwork?->isPubliclyPublished()) {
            return null;
        }

        return $this->artworkCandidate(
            $artwork,
            PostMediaType::Playlist,
            (int) $playlist->getKey(),
        );
    }

    private function trackCandidate(Track $track): ?JournalSourceImage
    {
        $artwork = $track->coverArtwork()->first();

        if ($artwork?->isPubliclyPublished()) {
            return $this->artworkCandidate(
                $artwork,
                PostMediaType::Track,
                (int) $track->getKey(),
            );
        }

        $album = $track->album()->first();

        if (! $album?->isPubliclyPublished()) {
            return null;
        }

        return $this->albumCandidate(
            $album,
            PostMediaType::Track,
            (int) $track->getKey(),
        );
    }

    private function artworkCandidate(
        Artwork $artwork,
        PostMediaType $sourceType,
        int $sourceId,
    ): ?JournalSourceImage {
        $path = $artwork->availableDisplayPath();

        if (! $this->pathExists($path)) {
            return null;
        }

        return new JournalSourceImage(
            sourcePath: $path,
            thumbnailUrl: $artwork->thumb_url,
            altText: $this->altText($artwork->image_alt, $sourceType->label().' artwork'),
            sourceType: $sourceType,
            sourceId: $sourceId,
        );
    }

    private function pathExists(string $path): bool
    {
        return filled($path) && $this->media->sourceDisk($path)->exists($path);
    }

    private function altText(mixed $value, string $fallback): string
    {
        $text = Str::of((string) $value)
            ->squish()
            ->limit(500, '')
            ->toString();

        return $text !== '' ? $text : $fallback;
    }
}
