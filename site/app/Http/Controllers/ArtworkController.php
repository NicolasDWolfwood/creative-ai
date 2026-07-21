<?php

namespace App\Http\Controllers;

use App\Models\Artwork;
use App\Models\Collection;
use App\Services\CrossMediaRecommendationService;
use App\Services\PublicMediaService;
use App\Services\PublicStoryConnections;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ArtworkController extends Controller
{
    public function show(
        Artwork $artwork,
        Request $request,
        PublicMediaService $media,
        CrossMediaRecommendationService $recommendations,
        PublicStoryConnections $storyConnections,
    ): View {
        abort_unless($artwork->isPubliclyAvailable(), 404);

        $collectionContext = $this->collectionContext($artwork, $request);

        $artwork->load([
            'collections' => fn ($query) => $query->published(),
            'tags',
        ]);

        $tracks = $recommendations->tracksForArtwork($artwork);
        $tracks->loadMissing(['coverArtwork', 'album.coverArtwork']);
        $recommendationPlaylistId = 'artwork-'.$artwork->id.'-recommendations';
        $recommendationDescription = $artwork->tags->isNotEmpty()
            ? 'Tracks connected to this artwork through shared tags.'
            : 'A selection from the public listening room.';
        $playerPayload = $media->libraryPlayerPayload();

        if ($tracks->isNotEmpty()) {
            $playerPayload[] = [
                'id' => $recommendationPlaylistId,
                'type' => 'artwork',
                'title' => 'Music for '.$artwork->title,
                'description' => $recommendationDescription,
                'cover' => $artwork->thumb_url,
                'tracks' => $tracks
                    ->map(fn ($track): array => [
                        ...$media->trackPayload($track),
                        'cover' => $track->cover_url ?: $artwork->thumb_url,
                    ])
                    ->values()
                    ->all(),
            ];
        }

        $canonical = route('artworks.show', $artwork);
        $stories = $storyConnections->postsForMedia($artwork);
        $imageUrl = url($artwork->public_image_url);
        $publishedAt = $artwork->effectivePublishedAt();
        $description = Str::of($artwork->description ?: 'A generative artwork from the Creative-Ai archive.')
            ->stripTags()
            ->squish()
            ->limit(200, '')
            ->toString();

        $imageObject = array_filter([
            '@type' => 'ImageObject',
            '@id' => $canonical.'#image',
            'contentUrl' => $imageUrl,
            'thumbnailUrl' => url($artwork->thumb_url),
            'caption' => $artwork->image_alt,
            'width' => $artwork->width,
            'height' => $artwork->height,
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        $structuredData = [
            '@context' => 'https://schema.org',
            '@graph' => [
                array_filter([
                    '@type' => 'VisualArtwork',
                    '@id' => $canonical.'#artwork',
                    'name' => $artwork->title,
                    'description' => $description,
                    'url' => $canonical,
                    'image' => ['@id' => $canonical.'#image'],
                    'creator' => ['@type' => 'Person', 'name' => 'John Reijmer'],
                    'dateCreated' => $artwork->generated_at?->toIso8601String() ?: $artwork->created_at?->toIso8601String(),
                    'datePublished' => $publishedAt?->toIso8601String(),
                    'keywords' => $artwork->tags->pluck('name')->implode(', ') ?: null,
                    'subjectOf' => $stories
                        ->map(fn ($post): array => ['@id' => route('posts.show', $post).'#article'])
                        ->values()
                        ->all() ?: null,
                ], fn (mixed $value): bool => $value !== null && $value !== ''),
                $imageObject,
            ],
        ];

        return view('artworks.show', [
            'artwork' => $artwork,
            'stories' => $stories,
            'collectionContext' => $collectionContext,
            'previousArtwork' => $collectionContext || $artwork->isPubliclyPublished()
                ? $this->adjacentArtwork($artwork, before: true, collection: $collectionContext)
                : null,
            'nextArtwork' => $collectionContext || $artwork->isPubliclyPublished()
                ? $this->adjacentArtwork($artwork, before: false, collection: $collectionContext)
                : null,
            'tracks' => $tracks,
            'recommendationPlaylistId' => $recommendationPlaylistId,
            'recommendationDescription' => $recommendationDescription,
            'playerPayload' => $playerPayload,
            'seo' => [
                'title' => $artwork->title.' | Creative-Ai',
                'description' => $description,
                'image' => $imageUrl,
                'canonical' => $canonical,
                'type' => 'article',
                'published_at' => $publishedAt?->toIso8601String(),
            ],
            'structured_data' => $structuredData,
        ]);
    }

    private function collectionContext(Artwork $artwork, Request $request): ?Collection
    {
        $slug = trim((string) $request->query('collection'));

        if ($slug !== '') {
            $collection = Collection::query()
                ->published()
                ->where('slug', $slug)
                ->whereHas('artworks', fn (Builder $query) => $query->whereKey($artwork->getKey()))
                ->first();

            abort_unless($collection, 404);

            return $collection;
        }

        if ($artwork->isPubliclyPublished()) {
            return null;
        }

        return $artwork->collections()
            ->published()
            ->memberPublicationGrants()
            ->orderBy('collections.id')
            ->first();
    }

    private function adjacentArtwork(
        Artwork $artwork,
        bool $before,
        ?Collection $collection = null,
    ): ?Artwork {
        $comparison = $before ? '>' : '<';
        $direction = $before ? 'asc' : 'desc';

        $query = Artwork::query();

        if ($collection) {
            $query
                ->publiclyAvailable()
                ->whereHas('collections', fn (Builder $query) => $query->whereKey($collection->getKey()));
        } else {
            $query->published();
        }

        return $query
            ->where(function (Builder $query) use ($artwork, $comparison): void {
                $query
                    ->where('sort_order', $comparison, $artwork->sort_order)
                    ->orWhere(function (Builder $query) use ($artwork, $comparison): void {
                        $query
                            ->where('sort_order', $artwork->sort_order)
                            ->where('created_at', $comparison, $artwork->created_at);
                    })
                    ->orWhere(function (Builder $query) use ($artwork, $comparison): void {
                        $query
                            ->where('sort_order', $artwork->sort_order)
                            ->where('created_at', $artwork->created_at)
                            ->where('id', $comparison, $artwork->id);
                    });
            })
            ->orderBy('sort_order', $direction)
            ->orderBy('created_at', $direction)
            ->orderBy('id', $direction)
            ->first();
    }
}
