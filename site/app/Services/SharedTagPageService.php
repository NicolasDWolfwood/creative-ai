<?php

namespace App\Services;

use App\Models\Album;
use App\Models\Artwork;
use App\Models\Collection;
use App\Models\Playlist;
use App\Models\Post;
use App\Models\Tag;
use App\Models\Track;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\CursorPaginator;

class SharedTagPageService
{
    /**
     * @return array{
     *     posts: CursorPaginator<int, Post>,
     *     artworks: CursorPaginator<int, Artwork>,
     *     collections: CursorPaginator<int, Collection>,
     *     albums: CursorPaginator<int, Album>,
     *     playlists: CursorPaginator<int, Playlist>,
     *     tracks: CursorPaginator<int, Track>
     * }
     */
    public function contentFor(Tag $tag): array
    {
        return [
            'posts' => Post::query()
                ->latestPublished()
                ->whereHas('tags', fn (Builder $query) => $query->whereKey($tag->getKey()))
                ->cursorPaginate(12, ['*'], 'posts_cursor')
                ->withQueryString(),
            'artworks' => Artwork::query()
                ->published()
                ->whereHas('tags', fn (Builder $query) => $query->whereKey($tag->getKey()))
                ->orderByDesc('sort_order')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->cursorPaginate(12, ['*'], 'artworks_cursor')
                ->withQueryString(),
            'collections' => Collection::query()
                ->published()
                ->whereHas('artworks', fn (Builder $query) => $query
                    ->published()
                    ->whereHas('tags', fn (Builder $query) => $query->whereKey($tag->getKey())))
                ->orderByDesc('featured')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->cursorPaginate(12, ['*'], 'collections_cursor')
                ->withQueryString(),
            'albums' => Album::query()
                ->published()
                ->whereHas('tracks', fn (Builder $query) => $query
                    ->publiclyAvailable()
                    ->whereHas('tags', fn (Builder $query) => $query->whereKey($tag->getKey())))
                ->orderByDesc('featured')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->cursorPaginate(12, ['*'], 'albums_cursor')
                ->withQueryString(),
            'playlists' => Playlist::query()
                ->published()
                ->whereHas('tracks', fn (Builder $query) => $query
                    ->publiclyAvailable()
                    ->whereHas('tags', fn (Builder $query) => $query->whereKey($tag->getKey())))
                ->orderByDesc('featured')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->cursorPaginate(12, ['*'], 'playlists_cursor')
                ->withQueryString(),
            'tracks' => Track::query()
                ->publiclyAvailable()
                ->whereHas('tags', fn (Builder $query) => $query->whereKey($tag->getKey()))
                ->with(['album', 'coverArtwork'])
                ->orderByDesc('featured')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->cursorPaginate(12, ['*'], 'tracks_cursor')
                ->withQueryString(),
        ];
    }

    public function hasContent(Tag $tag): bool
    {
        return collect($this->contentFor($tag))->contains(fn (CursorPaginator $items): bool => $items->isNotEmpty());
    }

    /** @return EloquentCollection<int, Tag> */
    public function publicTags(): EloquentCollection
    {
        return Tag::query()
            ->where(function (Builder $query): void {
                $query
                    ->whereHas('posts', fn (Builder $query) => $query->published())
                    ->orWhereHas('artworks', fn (Builder $query) => $query->published())
                    ->orWhereHas('tracks', fn (Builder $query) => $query->publiclyAvailable());
            })
            ->orderBy('name')
            ->get();
    }
}
