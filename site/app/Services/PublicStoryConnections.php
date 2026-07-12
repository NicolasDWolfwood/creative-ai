<?php

namespace App\Services;

use App\Enums\PostMediaType;
use App\Models\Album;
use App\Models\Artwork;
use App\Models\Collection;
use App\Models\Playlist;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\Track;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;

class PublicStoryConnections
{
    /** @return EloquentCollection<int, PostMedia> */
    public function mediaForPost(Post $post): EloquentCollection
    {
        if (! $post->isPubliclyPublishedAt()) {
            return new EloquentCollection;
        }

        return PostMedia::query()
            ->whereBelongsTo($post)
            ->valid()
            ->where(function (Builder $query): void {
                $query
                    ->where(fn (Builder $query) => $query
                        ->whereNotNull('artwork_id')
                        ->whereHas('artwork', fn (Builder $query) => $query->published()))
                    ->orWhere(fn (Builder $query) => $query
                        ->whereNotNull('collection_id')
                        ->whereHas('collection', fn (Builder $query) => $query->published()))
                    ->orWhere(fn (Builder $query) => $query
                        ->whereNotNull('album_id')
                        ->whereHas('album', fn (Builder $query) => $query->published()))
                    ->orWhere(fn (Builder $query) => $query
                        ->whereNotNull('playlist_id')
                        ->whereHas('playlist', fn (Builder $query) => $query->published()))
                    ->orWhere(fn (Builder $query) => $query
                        ->whereNotNull('track_id')
                        ->whereHas('track', fn (Builder $query) => $query->publiclyAvailable()));
            })
            ->with(['artwork', 'collection', 'album', 'playlist', 'track'])
            ->orderBy('position')
            ->get();
    }

    /** @return EloquentCollection<int, Post> */
    public function postsForMedia(Model $media): EloquentCollection
    {
        if (! $this->mediaIsPublic($media)) {
            return new EloquentCollection;
        }

        return Post::query()
            ->latestPublished()
            ->whereHas('mediaItems', fn (Builder $query) => $query
                ->valid()
                ->forMedia($media))
            ->get();
    }

    public function mediaIsPublic(Model $media): bool
    {
        return match (PostMediaType::forModel($media)) {
            PostMediaType::Track => $media instanceof Track && $media->isPubliclyAvailable(),
            PostMediaType::Artwork => $media instanceof Artwork && $media->isPubliclyPublished(),
            PostMediaType::Collection => $media instanceof Collection && $media->isPubliclyPublished(),
            PostMediaType::Album => $media instanceof Album && $media->isPubliclyPublished(),
            PostMediaType::Playlist => $media instanceof Playlist && $media->isPubliclyPublished(),
            null => false,
        };
    }

    /** @return array<string, array<int, CarbonInterface>> */
    public function latestPostUpdatesByMedia(): array
    {
        $updates = collect(PostMediaType::cases())
            ->mapWithKeys(fn (PostMediaType $type): array => [$type->value => []])
            ->all();

        $items = PostMedia::query()
            ->valid()
            ->whereHas('post', fn (Builder $query) => $query->published())
            ->with('post')
            ->get();

        foreach ($items as $item) {
            $type = $item->type();
            $updatedAt = $item->post?->effectivePublicContentUpdatedAt();

            if ($type === null || $updatedAt === null) {
                continue;
            }

            $mediaId = (int) $item->getAttribute($type->foreignKey());
            $current = $updates[$type->value][$mediaId] ?? null;

            if ($current === null || $updatedAt->gt($current)) {
                $updates[$type->value][$mediaId] = $updatedAt;
            }
        }

        return $updates;
    }
}
