<?php

namespace App\Services;

use App\Models\Post;
use Illuminate\Support\Str;
use InvalidArgumentException;

class PostStructuredData
{
    private const CREATOR_NAME = 'John Reijmer';

    /** @return array<string, mixed> */
    public function forPost(Post $post): array
    {
        if (! $post->isPubliclyPublishedAt()) {
            throw new InvalidArgumentException('Structured data can only be generated for a public post.');
        }

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
