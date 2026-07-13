<?php

namespace App\Services;

use App\Enums\PostMediaType;
use App\Enums\PostStatus;
use App\Models\Album;
use App\Models\Artwork;
use App\Models\Collection;
use App\Models\Playlist;
use App\Models\Post;
use App\Models\PostSlugRedirect;
use App\Models\PostTemplate;
use App\Models\Tag;
use App\Models\Track;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class JournalDraftPlanningService
{
    public function __construct(
        private readonly PublicStoryConnections $publicConnections,
    ) {}

    public function createFromPublicSource(
        Model $source,
        ?PostTemplate $template = null,
        bool $copySharedTags = false,
    ): Post {
        $type = PostMediaType::forModel($source);

        if ($type === null || ! $source->exists) {
            throw new DomainException('A saved artwork, collection, album, playlist, or track is required.');
        }

        return DB::transaction(function () use ($source, $type, $template, $copySharedTags): Post {
            $lockedSource = $type->modelClass()::query()
                ->lockForUpdate()
                ->find($source->getKey());

            if ($lockedSource instanceof Track && $lockedSource->album_id !== null) {
                Album::query()
                    ->lockForUpdate()
                    ->find($lockedSource->album_id);
            }

            if (! $lockedSource instanceof Model || ! $this->publicConnections->mediaIsPublic($lockedSource)) {
                throw new DomainException('Journal drafts can only be created from currently public source media.');
            }

            $lockedTemplate = $this->lockActiveTemplate($template);
            $sourceTitle = trim((string) $lockedSource->getAttribute('title'));

            if ($sourceTitle === '') {
                throw new DomainException('The selected source needs a title before creating a Journal draft.');
            }

            $title = $this->templateText($lockedTemplate?->title, $sourceTitle, $type)
                ?: 'Story: '.$sourceTitle;
            $excerpt = $this->templateText($lockedTemplate?->excerpt, $sourceTitle, $type);

            if (Str::length($title) > 255) {
                throw new DomainException('The generated Journal title is longer than 255 characters. Shorten the source title or template.');
            }

            if ($excerpt !== null && Str::length($excerpt) > 500) {
                throw new DomainException('The selected Journal template creates an excerpt longer than 500 characters.');
            }

            $post = new Post;
            $post->fill([
                'title' => $title,
                'slug' => $this->draftSlug($title, $type, (int) $lockedSource->getKey()),
                'excerpt' => $excerpt,
                'body' => $this->templateText($lockedTemplate?->body, $sourceTitle, $type),
                'editorial_brief' => $this->templateText($lockedTemplate?->editorial_brief, $sourceTitle, $type),
                'featured' => false,
            ]);
            $post->forceFill([
                'status' => PostStatus::Draft,
                'scheduled_at' => null,
                'published' => false,
                'published_at' => null,
            ]);
            $post->save();

            $post->mediaItems()->create([
                'position' => 1,
                $type->foreignKey() => $lockedSource->getKey(),
            ]);

            $tagIds = collect($lockedTemplate?->tags()->pluck('tags.id')->all() ?? []);

            if ($copySharedTags) {
                $tagIds = $tagIds->merge($this->sharedTagIdsFor($lockedSource));
            }

            $safeTagIds = $this->onlyPublicSharedTagIds(
                $tagIds->map(fn (mixed $id): int => (int) $id)->unique()->all(),
            );

            if ($safeTagIds !== []) {
                $post->tags()->attach($safeTagIds);
            }

            app(PostRevisionService::class)->capture($post, 'draft_planning');

            return $post->refresh()->load([
                'tags',
                'mediaItems.artwork',
                'mediaItems.collection',
                'mediaItems.album',
                'mediaItems.playlist',
                'mediaItems.track',
            ]);
        });
    }

    private function lockActiveTemplate(?PostTemplate $template): ?PostTemplate
    {
        if ($template === null) {
            return null;
        }

        if (! $template->exists) {
            throw new DomainException('The selected Journal template is no longer available.');
        }

        $locked = PostTemplate::query()
            ->active()
            ->lockForUpdate()
            ->find($template->getKey());

        if (! $locked instanceof PostTemplate) {
            throw new DomainException('The selected Journal template is no longer active.');
        }

        return $locked;
    }

    private function templateText(?string $value, string $sourceTitle, PostMediaType $type): ?string
    {
        if ($value === null) {
            return null;
        }

        return str_replace(
            ['{{ source_title }}', '{{ source_type }}'],
            [$sourceTitle, $type->label()],
            $value,
        );
    }

    private function draftSlug(string $title, PostMediaType $type, int $sourceId): string
    {
        $sourceSuffix = '-'.$type->value.'-'.$sourceId;
        $base = Str::slug($title) ?: 'journal-draft';
        $base = Str::substr($base, 0, 255 - Str::length($sourceSuffix));
        $slug = $base.$sourceSuffix;
        $duplicate = 2;

        while (
            Post::query()->withTrashed()->where('slug', $slug)->exists()
            || PostSlugRedirect::query()->where('slug', $slug)->exists()
        ) {
            $duplicateSuffix = '-'.$duplicate;
            $slug = Str::substr(
                $base,
                0,
                255 - Str::length($sourceSuffix) - Str::length($duplicateSuffix),
            ).$sourceSuffix.$duplicateSuffix;
            $duplicate++;
        }

        return $slug;
    }

    /** @return list<int> */
    private function sharedTagIdsFor(Model $source): array
    {
        $query = Tag::query();

        match (true) {
            $source instanceof Artwork => $query->whereHas('artworks', fn (Builder $query) => $query
                ->published()
                ->whereKey($source->getKey())),
            $source instanceof Collection => $query->whereHas('artworks', fn (Builder $query) => $query
                ->published()
                ->whereHas('collections', fn (Builder $query) => $query->whereKey($source->getKey()))),
            $source instanceof Album => $query->whereHas('tracks', fn (Builder $query) => $query
                ->publiclyAvailable()
                ->where('album_id', $source->getKey())),
            $source instanceof Playlist => $query->whereHas('tracks', fn (Builder $query) => $query
                ->publiclyAvailable()
                ->whereHas('playlists', fn (Builder $query) => $query->whereKey($source->getKey()))),
            $source instanceof Track => $query->whereHas('tracks', fn (Builder $query) => $query
                ->publiclyAvailable()
                ->whereKey($source->getKey())),
            default => $query->whereRaw('1 = 0'),
        };

        return $query
            ->orderBy('tags.id')
            ->pluck('tags.id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function onlyPublicSharedTagIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Tag::query()
            ->whereIntegerInRaw('id', $ids)
            ->where(function (Builder $query): void {
                $query
                    ->whereHas('posts', fn (Builder $query) => $query->published())
                    ->orWhereHas('artworks', fn (Builder $query) => $query->published())
                    ->orWhereHas('tracks', fn (Builder $query) => $query->publiclyAvailable());
            })
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }
}
