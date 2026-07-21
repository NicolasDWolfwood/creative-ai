<?php

namespace App\Services;

use App\Data\JournalDraftBatchPlanningResult;
use App\Data\JournalDraftPlanningResult;
use App\Enums\PostMediaType;
use App\Enums\PostStatus;
use App\Models\Album;
use App\Models\Artwork;
use App\Models\Collection;
use App\Models\Playlist;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostSlugRedirect;
use App\Models\PostTemplate;
use App\Models\Tag;
use App\Models\Track;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class JournalDraftPlanningService
{
    public function __construct(
        private readonly PublicStoryConnections $publicConnections,
        private readonly JournalSourceImageResolver $sourceImages,
        private readonly JournalCoverService $covers,
    ) {}

    public function createFromPublicSource(
        Model $source,
        ?PostTemplate $template = null,
        bool $copySharedTags = false,
        bool $useSourceArtwork = false,
    ): Post {
        return $this->createOne(
            source: $source,
            template: $template,
            copySharedTags: $copySharedTags,
            useSourceArtwork: $useSourceArtwork,
            requirePublicSource: true,
            skipConnectedSource: false,
            requireRequestedArtwork: true,
        )->post;
    }

    public function createFromSavedSource(
        Model $source,
        ?PostTemplate $template = null,
        bool $copySharedTags = false,
        bool $useSourceArtwork = false,
    ): Post {
        return $this->createOne(
            source: $source,
            template: $template,
            copySharedTags: $copySharedTags,
            useSourceArtwork: $useSourceArtwork,
            requirePublicSource: false,
            skipConnectedSource: false,
            requireRequestedArtwork: true,
        )->post;
    }

    public function createIfUnconnected(
        Model $source,
        ?PostTemplate $template = null,
        bool $copySharedTags = false,
        bool $useSourceArtwork = false,
    ): JournalDraftPlanningResult {
        return $this->createOne(
            source: $source,
            template: $template,
            copySharedTags: $copySharedTags,
            useSourceArtwork: $useSourceArtwork,
            requirePublicSource: false,
            skipConnectedSource: true,
            requireRequestedArtwork: false,
        );
    }

    /**
     * Create one private Journal draft for the as-yet-unconnected sources.
     *
     * @param  iterable<int, Model>  $sources
     */
    public function createBatchIfUnconnected(
        iterable $sources,
        ?PostTemplate $template = null,
        bool $copySharedTags = false,
        bool $useSourceArtwork = false,
    ): JournalDraftBatchPlanningResult {
        $requested = collect($sources)
            ->map(function (mixed $source): array {
                if (! $source instanceof Model) {
                    throw new DomainException('A Journal batch can contain only saved media records.');
                }

                $type = $this->sourceType($source);

                return ['type' => $type, 'id' => (int) $source->getKey()];
            })
            ->unique(fn (array $source): string => $source['type']->value.':'.$source['id'])
            ->values();

        if ($requested->isEmpty()) {
            throw new DomainException('Choose at least one saved source before creating a Journal draft.');
        }

        $copiedCoverPath = null;

        try {
            return DB::transaction(function () use (
                $requested,
                $template,
                $copySharedTags,
                $useSourceArtwork,
                &$copiedCoverPath,
            ): JournalDraftBatchPlanningResult {
                $locked = $this->lockSources($requested);
                $available = $locked->reject(fn (Model $source): bool => $this->connectedPost($source) instanceof Post)->values();
                $skipped = $locked->count() - $available->count();

                if ($available->isEmpty()) {
                    return new JournalDraftBatchPlanningResult(null, 0, $skipped);
                }

                $post = $this->createLockedDraft(
                    sources: $available,
                    template: $template,
                    copySharedTags: $copySharedTags,
                    useSourceArtwork: $useSourceArtwork,
                    requireRequestedArtwork: false,
                    copiedCoverPath: $copiedCoverPath,
                );

                return new JournalDraftBatchPlanningResult($post, $available->count(), $skipped);
            });
        } catch (Throwable $exception) {
            $this->covers->cleanup($copiedCoverPath);

            throw $exception;
        }
    }

    private function createOne(
        Model $source,
        ?PostTemplate $template,
        bool $copySharedTags,
        bool $useSourceArtwork,
        bool $requirePublicSource,
        bool $skipConnectedSource,
        bool $requireRequestedArtwork,
    ): JournalDraftPlanningResult {
        $type = $this->sourceType($source);
        $copiedCoverPath = null;

        try {
            return DB::transaction(function () use (
                $source,
                $type,
                $template,
                $copySharedTags,
                $useSourceArtwork,
                $requirePublicSource,
                $skipConnectedSource,
                $requireRequestedArtwork,
                &$copiedCoverPath,
            ): JournalDraftPlanningResult {
                $lockedSource = $this->lockSource($type, (int) $source->getKey());

                if ($requirePublicSource && ! $this->publicConnections->mediaIsPublic($lockedSource)) {
                    throw new DomainException('Journal drafts can only be created from currently public source media.');
                }

                if ($skipConnectedSource && ($existing = $this->connectedPost($lockedSource)) instanceof Post) {
                    return new JournalDraftPlanningResult($existing, false);
                }

                $post = $this->createLockedDraft(
                    sources: collect([$lockedSource]),
                    template: $template,
                    copySharedTags: $copySharedTags,
                    useSourceArtwork: $useSourceArtwork,
                    requireRequestedArtwork: $requireRequestedArtwork,
                    copiedCoverPath: $copiedCoverPath,
                );

                return new JournalDraftPlanningResult($post, true);
            });
        } catch (Throwable $exception) {
            $this->covers->cleanup($copiedCoverPath);

            throw $exception;
        }
    }

    /**
     * @param  SupportCollection<int, Model>  $sources
     */
    private function createLockedDraft(
        SupportCollection $sources,
        ?PostTemplate $template,
        bool $copySharedTags,
        bool $useSourceArtwork,
        bool $requireRequestedArtwork,
        ?string &$copiedCoverPath,
    ): Post {
        /** @var Model $primary */
        $primary = $sources->first();
        $primaryType = $this->sourceType($primary);
        $lockedTemplate = $this->lockActiveTemplate($template);
        $primaryTitle = trim((string) $primary->getAttribute('title'));

        if ($primaryTitle === '') {
            throw new DomainException('The selected source needs a title before creating a Journal draft.');
        }

        $sourceTitle = $sources->count() === 1
            ? $primaryTitle
            : $primaryTitle.' and '.($sources->count() - 1).' more';
        $defaultTitle = $sources->count() === 1
            ? 'Story: '.$primaryTitle
            : 'New '.Str::plural($primaryType->label(), $sources->count()).': '.$sourceTitle;
        $title = $this->templateText($lockedTemplate?->title, $sourceTitle, $primaryType) ?: $defaultTitle;
        $excerpt = $this->templateText($lockedTemplate?->excerpt, $sourceTitle, $primaryType);

        if (Str::length($title) > 255) {
            throw new DomainException('The generated Journal title is longer than 255 characters. Shorten the source title or template.');
        }

        if ($excerpt !== null && Str::length($excerpt) > 500) {
            throw new DomainException('The selected Journal template creates an excerpt longer than 500 characters.');
        }

        $post = new Post;
        $post->fill([
            'title' => $title,
            'slug' => $this->draftSlug($title, $primaryType, (int) $primary->getKey()),
            'excerpt' => $excerpt,
            'body' => $this->templateText($lockedTemplate?->body, $sourceTitle, $primaryType),
            'editorial_brief' => $this->templateText($lockedTemplate?->editorial_brief, $sourceTitle, $primaryType),
            'featured' => false,
        ]);

        if ($useSourceArtwork) {
            $coverSource = $sources->first(function (Model $source): bool {
                return $this->publicConnections->mediaIsPublic($source)
                    && $this->sourceImages->resolve($source) !== null;
            });
            $candidate = $coverSource instanceof Model ? $this->sourceImages->resolve($coverSource) : null;

            if ($candidate !== null) {
                $copiedCoverPath = $this->covers->copy($candidate);
                $post->cover_image_path = $copiedCoverPath;
                $post->cover_alt_text = $candidate->altText;
            } elseif ($requireRequestedArtwork && $sources->contains(
                fn (Model $source): bool => $this->publicConnections->mediaIsPublic($source),
            )) {
                throw new DomainException('The selected source artwork is no longer available. Reload and try again.');
            }
        }

        $post->forceFill([
            'status' => PostStatus::Draft,
            'scheduled_at' => null,
            'published' => false,
            'published_at' => null,
        ]);
        $post->save();

        foreach ($sources->values() as $position => $source) {
            $type = $this->sourceType($source);
            $post->mediaItems()->create([
                'position' => $position + 1,
                $type->foreignKey() => $source->getKey(),
            ]);
        }

        $tagIds = collect($lockedTemplate?->tags()->pluck('tags.id')->all() ?? []);

        if ($copySharedTags) {
            foreach ($sources as $source) {
                $tagIds = $tagIds->merge($this->sharedTagIdsFor($source));
            }
        }

        $safeTagIds = $this->onlyPublicSharedTagIds(
            $tagIds->map(fn (mixed $id): int => (int) $id)->unique()->all(),
        );

        if ($safeTagIds !== []) {
            $post->tags()->attach($safeTagIds);
        }

        app(PostRevisionService::class)->capture($post, 'draft_planning');

        return $post->refresh()->load($this->draftRelations());
    }

    private function sourceType(Model $source): PostMediaType
    {
        $type = PostMediaType::forModel($source);

        if ($type === null || ! $source->exists || (int) $source->getKey() < 1) {
            throw new DomainException('A saved artwork, collection, album, playlist, or track is required.');
        }

        return $type;
    }

    private function lockSource(PostMediaType $type, int $id): Model
    {
        $lockedSource = $type->modelClass()::query()->lockForUpdate()->find($id);

        if (! $lockedSource instanceof Model) {
            throw new DomainException('The selected source is no longer available.');
        }

        if ($lockedSource instanceof Track && $lockedSource->album_id !== null) {
            Album::query()->lockForUpdate()->find($lockedSource->album_id);
            $lockedSource->unsetRelation('album');
        }

        return $lockedSource;
    }

    /**
     * @param  SupportCollection<int, array{type: PostMediaType, id: int}>  $requested
     * @return SupportCollection<int, Model>
     */
    private function lockSources(SupportCollection $requested): SupportCollection
    {
        $lockedByKey = collect();

        foreach (PostMediaType::cases() as $type) {
            $ids = $requested->where('type', $type)->pluck('id')->all();

            if ($ids === []) {
                continue;
            }

            $models = $type->modelClass()::query()
                ->whereKey($ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($models->count() !== count($ids)) {
                throw new DomainException('One or more selected Journal sources are no longer available.');
            }

            if ($type === PostMediaType::Track) {
                Album::query()
                    ->whereKey($models->pluck('album_id')->filter()->unique()->all())
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                $models->each->unsetRelation('album');
            }

            foreach ($models as $model) {
                $lockedByKey->put($type->value.':'.$model->getKey(), $model);
            }
        }

        return $requested->map(function (array $source) use ($lockedByKey): Model {
            $model = $lockedByKey->get($source['type']->value.':'.$source['id']);

            if (! $model instanceof Model) {
                throw new DomainException('One or more selected Journal sources are no longer available.');
            }

            return $model;
        });
    }

    private function connectedPost(Model $source): ?Post
    {
        $type = $this->sourceType($source);

        return PostMedia::query()
            ->where($type->foreignKey(), $source->getKey())
            ->whereHas('post')
            ->with('post')
            ->oldest('id')
            ->first()
            ?->post;
    }

    /** @return list<string> */
    private function draftRelations(): array
    {
        return [
            'tags',
            'mediaItems.artwork',
            'mediaItems.collection',
            'mediaItems.album',
            'mediaItems.playlist',
            'mediaItems.track',
        ];
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
                ->publiclyAvailable()
                ->whereKey($source->getKey())),
            $source instanceof Collection => $query->whereHas('artworks', fn (Builder $query) => $query
                ->publiclyAvailable()
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
                    ->orWhereHas('artworks', fn (Builder $query) => $query->publiclyAvailable())
                    ->orWhereHas('tracks', fn (Builder $query) => $query->publiclyAvailable());
            })
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }
}
