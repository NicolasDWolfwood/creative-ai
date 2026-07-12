<?php

namespace App\Http\Controllers;

use App\Models\Artwork;
use App\Services\CrossMediaRecommendationService;
use App\Services\PublicMediaService;
use App\Services\PublicStoryConnections;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class ArtworkController extends Controller
{
    public function show(
        Artwork $artwork,
        PublicMediaService $media,
        CrossMediaRecommendationService $recommendations,
        PublicStoryConnections $storyConnections,
    ): View {
        abort_unless($artwork->isPubliclyPublished(), 404);

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
                    'datePublished' => $artwork->published_at?->toIso8601String(),
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
            'previousArtwork' => $this->adjacentArtwork($artwork, before: true),
            'nextArtwork' => $this->adjacentArtwork($artwork, before: false),
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
                'published_at' => $artwork->published_at?->toIso8601String(),
            ],
            'structured_data' => $structuredData,
        ]);
    }

    private function adjacentArtwork(Artwork $artwork, bool $before): ?Artwork
    {
        $comparison = $before ? '>' : '<';
        $direction = $before ? 'asc' : 'desc';

        return Artwork::query()
            ->published()
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
