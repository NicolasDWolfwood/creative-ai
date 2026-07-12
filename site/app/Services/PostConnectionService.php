<?php

namespace App\Services;

use App\Enums\PostMediaType;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\Tag;
use DomainException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

class PostConnectionService
{
    /**
     * @param  list<int|string>  $tagIds
     */
    public function syncTags(Post $post, array $tagIds, ?array $expectedTagIds = null): Post
    {
        $ids = $this->normalizeIds($tagIds, 'tag', 'Journal tags must be unique.');
        $expected = $expectedTagIds === null
            ? null
            : $this->normalizeIds($expectedTagIds, 'tag', 'Expected Journal tags must be unique.');

        return DB::transaction(function () use ($post, $ids, $expected): Post {
            $locked = $this->lockPost($post);
            $found = Tag::query()->whereIntegerInRaw('id', $ids)->count();

            if ($found !== count($ids)) {
                throw new DomainException('One or more selected Journal tags no longer exist.');
            }

            $current = $locked->tags()
                ->pluck('tags.id')
                ->map(fn (mixed $id): int => (int) $id)
                ->sort()
                ->values()
                ->all();

            if ($expected !== null && $current !== $expected) {
                throw new DomainException('Journal tags changed in another session. Reload the page before saving.');
            }

            if ($current !== $ids) {
                $locked->tags()->sync($ids);
                $this->touchPublicContent($locked);
            }

            return $locked->refresh()->load('tags');
        });
    }

    /**
     * @param  list<array{type: PostMediaType|string, id: int|string}>  $references
     */
    public function syncMedia(Post $post, array $references, ?array $expectedMediaItemIds = null): Post
    {
        $normalized = $this->normalizeMediaReferences($references);
        $expected = $expectedMediaItemIds === null
            ? null
            : $this->normalizeIds(
                $expectedMediaItemIds,
                'connection',
                'Expected Journal media connections must be unique.',
            );

        return DB::transaction(function () use ($post, $normalized, $expected): Post {
            $locked = $this->lockPost($post);
            $this->assertMediaExist($normalized);

            $currentItems = $locked->mediaItems()->get();

            if (
                $expected !== null
                && $currentItems->pluck('id')->map(fn (mixed $id): int => (int) $id)->sort()->values()->all() !== $expected
            ) {
                throw new DomainException('Journal media connections changed in another session. Reload the page before saving.');
            }

            $current = $currentItems
                ->map(fn ($item): array => [
                    'type' => $item->type()?->value,
                    'id' => $item->type() ? (int) $item->getAttribute($item->type()->foreignKey()) : null,
                ])
                ->all();

            $comparable = collect($normalized)
                ->map(fn (array $reference): array => [
                    'type' => $reference['type']->value,
                    'id' => $reference['id'],
                ])
                ->all();

            if ($current !== $comparable) {
                if ($locked->isPubliclyPublishedAt()) {
                    $this->touchMediaItems($currentItems);
                }

                $locked->mediaItems()->delete();

                foreach ($normalized as $index => $reference) {
                    $locked->mediaItems()->create([
                        'position' => $index + 1,
                        $reference['type']->foreignKey() => $reference['id'],
                    ]);
                }

                if ($locked->isPubliclyPublishedAt()) {
                    $this->touchConnectedMedia($locked);
                }

                $this->touchPublicContent($locked);
            }

            return $locked->refresh()->load([
                'mediaItems.artwork',
                'mediaItems.collection',
                'mediaItems.album',
                'mediaItems.playlist',
                'mediaItems.track',
            ]);
        });
    }

    /**
     * @param  list<array{type: PostMediaType|string, id: int|string}>  $references
     * @return list<array{type: PostMediaType, id: int}>
     */
    private function normalizeMediaReferences(array $references): array
    {
        $seen = [];
        $normalized = [];

        foreach ($references as $reference) {
            if (! is_array($reference) || ! array_key_exists('type', $reference) || ! array_key_exists('id', $reference)) {
                throw new DomainException('Every Journal media connection needs a supported type and record.');
            }

            $type = $reference['type'] instanceof PostMediaType
                ? $reference['type']
                : PostMediaType::tryFrom((string) $reference['type']);
            $id = $this->positiveInteger($reference['id'], 'media');

            if ($type === null) {
                throw new DomainException('The selected Journal media type is not supported.');
            }

            $key = $type->value.':'.$id;

            if (isset($seen[$key])) {
                throw new DomainException('The same media record cannot be connected to a Journal post twice.');
            }

            $seen[$key] = true;
            $normalized[] = ['type' => $type, 'id' => $id];
        }

        return $normalized;
    }

    /**
     * @param  list<array{type: PostMediaType, id: int}>  $references
     */
    private function assertMediaExist(array $references): void
    {
        foreach (collect($references)->groupBy(fn (array $reference): string => $reference['type']->value) as $items) {
            /** @var PostMediaType $type */
            $type = $items->first()['type'];
            $ids = $items->pluck('id')->all();
            $model = $type->modelClass();
            $found = $model::query()->whereIntegerInRaw('id', $ids)->count();

            if ($found !== count($ids)) {
                throw new DomainException('One or more selected Journal media records no longer exist.');
            }
        }
    }

    private function lockPost(Post $post): Post
    {
        if (! $post->exists) {
            throw new DomainException('Save the Journal post before managing its connections.');
        }

        return Post::query()->lockForUpdate()->findOrFail($post->getKey());
    }

    private function touchPublicContent(Post $post): void
    {
        Post::query()->whereKey($post->getKey())->update([
            'public_content_updated_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function touchConnectedMedia(Post $post): void
    {
        $this->touchMediaItems(
            PostMedia::query()
                ->whereBelongsTo($post)
                ->valid()
                ->get(),
        );
    }

    /** @param EloquentCollection<int, PostMedia> $items */
    private function touchMediaItems(EloquentCollection $items): void
    {
        foreach (PostMediaType::cases() as $type) {
            $ids = $items
                ->filter(fn (PostMedia $item): bool => $item->type() === $type)
                ->pluck($type->foreignKey())
                ->filter()
                ->unique()
                ->values()
                ->all();

            if ($ids === []) {
                continue;
            }

            $model = $type->modelClass();
            $model::query()->whereIntegerInRaw('id', $ids)->update(['updated_at' => now()]);
        }
    }

    private function positiveInteger(mixed $value, string $label): int
    {
        if (! is_int($value) && ! is_string($value)) {
            throw new DomainException("The selected {$label} record is invalid.");
        }

        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($id === false) {
            throw new DomainException("The selected {$label} record is invalid.");
        }

        return $id;
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<int>
     */
    private function normalizeIds(array $values, string $label, string $duplicateMessage): array
    {
        $ids = collect($values)
            ->map(fn (mixed $id): int => $this->positiveInteger($id, $label))
            ->unique()
            ->sort()
            ->values()
            ->all();

        if (count($ids) !== count($values)) {
            throw new DomainException($duplicateMessage);
        }

        return $ids;
    }
}
