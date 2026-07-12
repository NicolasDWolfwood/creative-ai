<?php

namespace App\Services;

use App\Models\Post;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Str;
use InvalidArgumentException;

class PostStructuredData
{
    private const CREATOR_NAME = 'John Reijmer';

    public function __construct(
        private readonly PublicStoryConnections $connections,
    ) {}

    /** @return array<string, mixed> */
    public function forPost(Post $post, ?EloquentCollection $connectedMedia = null): array
    {
        if (! $post->isPubliclyPublishedAt()) {
            throw new InvalidArgumentException('Structured data can only be generated for a public post.');
        }

        $post->loadMissing('tags');
        $connectedMedia ??= $this->connections->mediaForPost($post);
        $canonical = route('posts.show', $post);
        $publishedAt = $post->effectivePublishedAt();
        $modifiedAt = $post->effectivePublicContentUpdatedAt();
        $description = Str::of($post->summary)
            ->stripTags()
            ->squish()
            ->limit(200, '')
            ->toString();
        $imageId = $post->cover_url ? $canonical.'#cover' : null;

        $graph = [
            $this->withoutEmpty([
                '@type' => 'BlogPosting',
                '@id' => $canonical.'#article',
                'headline' => $post->title,
                'name' => $post->title,
                'description' => $description,
                'url' => $canonical,
                'mainEntityOfPage' => [
                    '@type' => 'WebPage',
                    '@id' => $canonical,
                ],
                'image' => $imageId ? ['@id' => $imageId] : null,
                'author' => ['@id' => $this->creatorId()],
                'creator' => ['@id' => $this->creatorId()],
                'datePublished' => $publishedAt?->toIso8601String(),
                'dateModified' => $modifiedAt?->toIso8601String(),
                'inLanguage' => str_replace('_', '-', app()->getLocale()),
                'wordCount' => str_word_count(strip_tags((string) $post->body)),
                'keywords' => $post->tags->pluck('name')->values()->all(),
                'about' => $connectedMedia
                    ->map(fn ($connection): array => $this->mediaReference(
                        $connection->type()?->value,
                        $connection->mediaTitle(),
                        $connection->mediaUrl(),
                    ))
                    ->filter()
                    ->values()
                    ->all(),
            ]),
        ];

        if ($imageId) {
            $graph[] = $this->withoutEmpty([
                '@type' => 'ImageObject',
                '@id' => $imageId,
                'contentUrl' => $post->cover_url,
                'caption' => $post->cover_alt_text,
            ]);
        }

        $graph[] = [
            '@type' => 'Person',
            '@id' => $this->creatorId(),
            'name' => self::CREATOR_NAME,
            'url' => route('home'),
        ];

        return [
            '@context' => 'https://schema.org',
            '@graph' => $graph,
        ];
    }

    private function creatorId(): string
    {
        return route('home').'#creator';
    }

    /** @return array<string, string> */
    private function mediaReference(?string $type, string $title, ?string $url): array
    {
        if (! $type || ! $url) {
            return [];
        }

        [$schemaType, $fragment] = match ($type) {
            'artwork' => ['VisualArtwork', 'artwork'],
            'collection' => ['CollectionPage', 'collection'],
            'album' => ['MusicAlbum', 'album'],
            'playlist' => ['MusicPlaylist', 'playlist'],
            'track' => ['MusicRecording', 'recording'],
            default => [null, null],
        };

        if (! $schemaType || ! $fragment) {
            return [];
        }

        return [
            '@type' => $schemaType,
            '@id' => $url.'#'.$fragment,
            'name' => $title,
            'url' => $url,
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function withoutEmpty(array $values): array
    {
        return array_filter(
            $values,
            fn (mixed $value): bool => $value !== null && $value !== '' && $value !== [],
        );
    }
}
