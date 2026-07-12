<?php

namespace App\Http\Controllers;

use App\Models\Album;
use App\Models\Artwork;
use App\Models\Collection;
use App\Models\Playlist;
use App\Models\Post;
use App\Models\Tag;
use App\Models\Track;
use App\Services\PublicMediaService;
use App\Services\SharedTagPageService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;

class TagController extends Controller
{
    public function show(
        Tag $tag,
        SharedTagPageService $tagPages,
        PublicMediaService $media,
    ): View {
        $content = $tagPages->contentFor($tag);
        abort_unless(
            collect($content)->contains(fn ($items): bool => $items->isNotEmpty()),
            404,
        );

        $items = collect($content)
            ->flatMap(fn ($paginator) => $paginator->getCollection())
            ->values();
        $canonical = route('tags.show', $tag);
        $description = 'Explore public Journal stories, artwork, collections, albums, playlists, and tracks connected by the “'.$tag->name.'” tag.';

        return view('tags.show', [
            'tag' => $tag,
            ...$content,
            'playerPayload' => $media->libraryPlayerPayload(),
            'seo' => [
                'title' => ucfirst($tag->name).' | Creative-Ai',
                'description' => $description,
                'canonical' => $canonical,
                'type' => 'website',
            ],
            'structured_data' => [
                '@context' => 'https://schema.org',
                '@graph' => [
                    [
                        '@type' => 'CollectionPage',
                        '@id' => $canonical.'#collection',
                        'name' => ucfirst($tag->name).' | Creative-Ai',
                        'description' => $description,
                        'url' => $canonical,
                        'mainEntity' => ['@id' => $canonical.'#items'],
                    ],
                    [
                        '@type' => 'ItemList',
                        '@id' => $canonical.'#items',
                        'numberOfItems' => $items->count(),
                        'itemListElement' => $items
                            ->map(fn (Model $item, int $index): array => [
                                '@type' => 'ListItem',
                                'position' => $index + 1,
                                'item' => $this->structuredReference($item),
                            ])
                            ->all(),
                    ],
                ],
            ],
        ]);
    }

    /** @return array<string, string> */
    private function structuredReference(Model $item): array
    {
        return match (true) {
            $item instanceof Post => [
                '@type' => 'BlogPosting',
                '@id' => route('posts.show', $item).'#article',
                'name' => $item->title,
                'url' => route('posts.show', $item),
            ],
            $item instanceof Artwork => [
                '@type' => 'VisualArtwork',
                '@id' => route('artworks.show', $item).'#artwork',
                'name' => $item->title,
                'url' => route('artworks.show', $item),
            ],
            $item instanceof Collection => [
                '@type' => 'CollectionPage',
                '@id' => route('collections.show', $item).'#collection',
                'name' => $item->title,
                'url' => route('collections.show', $item),
            ],
            $item instanceof Album => [
                '@type' => 'MusicAlbum',
                '@id' => route('music.albums.show', $item).'#album',
                'name' => $item->title,
                'url' => route('music.albums.show', $item),
            ],
            $item instanceof Playlist => [
                '@type' => 'MusicPlaylist',
                '@id' => route('music.playlists.show', $item).'#playlist',
                'name' => $item->title,
                'url' => route('music.playlists.show', $item),
            ],
            $item instanceof Track => [
                '@type' => 'MusicRecording',
                '@id' => route('music.tracks.show', $item).'#recording',
                'name' => $item->title,
                'url' => route('music.tracks.show', $item),
            ],
            default => [],
        };
    }
}
