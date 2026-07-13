<?php

namespace App\Services;

use App\Models\Post;
use App\Models\PostSlugRedirect;
use App\Models\User;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class PostSlugRedirectService
{
    private static int $slugMutationDepth = 0;

    public function resolvePublic(string $slug): ?Post
    {
        $redirect = PostSlugRedirect::query()
            ->where('slug', $slug)
            ->first();

        if ($redirect?->post_id === null) {
            return null;
        }

        return Post::query()
            ->published()
            ->whereKey($redirect->post_id)
            ->where('slug', '!=', $slug)
            ->first();
    }

    public function changeSlug(Post $post, string $newSlug, ?User $actor = null): Post
    {
        if (! $post->exists) {
            throw new LogicException('Save the Journal post before changing its slug.');
        }

        $slug = $this->canonicalSlug($newSlug);

        try {
            return DB::transaction(function () use ($post, $slug, $actor): Post {
                $locked = Post::query()
                    ->lockForUpdate()
                    ->findOrFail($post->getKey());

                if (hash_equals((string) $locked->slug, $slug)) {
                    return $locked;
                }

                $this->assertAvailable($slug, $locked);
                $oldSlug = (string) $locked->slug;

                PostSlugRedirect::query()->create([
                    'slug' => $oldSlug,
                    'post_id' => $locked->getKey(),
                ]);

                self::$slugMutationDepth++;

                try {
                    $locked->slug = $slug;
                    $locked->saveOrFail();
                } finally {
                    self::$slugMutationDepth--;
                }

                app(PostRevisionService::class)->capture(
                    $locked,
                    provenance: 'slug_change',
                    actor: $actor,
                    reason: "Changed Journal slug from {$oldSlug} to {$slug}.",
                );

                return $locked->refresh();
            }, 3);
        } catch (QueryException $exception) {
            if ($this->isUniqueConstraintViolation($exception)) {
                throw new DomainException('That Journal slug is already in use or permanently reserved.', 0, $exception);
            }

            throw $exception;
        }
    }

    public function assertModelSaveAllowed(Post $post): void
    {
        if (! $post->exists) {
            $this->assertAvailable((string) $post->slug);

            return;
        }

        if ($post->isDirty('slug') && self::$slugMutationDepth === 0) {
            throw new DomainException('Journal slugs must be changed through the history-safe slug service.');
        }
    }

    public function assertAvailable(string $slug, ?Post $except = null): void
    {
        $slug = $this->canonicalSlug($slug);
        $exceptPostId = $except?->exists ? (int) $except->getKey() : null;
        $postConflict = Post::query()
            ->withTrashed()
            ->where('slug', $slug)
            ->when($exceptPostId !== null, fn ($query) => $query->whereKeyNot($exceptPostId))
            ->exists();

        if ($postConflict || PostSlugRedirect::query()->where('slug', $slug)->exists()) {
            throw new DomainException('That Journal slug is already in use or permanently reserved.');
        }
    }

    public function tombstoneCurrentSlug(Post $post): void
    {
        if (! $post->exists || blank($post->slug)) {
            throw new LogicException('A saved Journal post with a slug is required.');
        }

        $redirect = PostSlugRedirect::query()
            ->where('slug', $post->slug)
            ->lockForUpdate()
            ->first();

        if ($redirect === null) {
            PostSlugRedirect::query()->create([
                'slug' => $post->slug,
                'post_id' => null,
            ]);

            return;
        }

        if ($redirect->post_id !== null && (int) $redirect->post_id !== (int) $post->getKey()) {
            throw new DomainException('The Journal slug is already reserved for another post.');
        }

        if ($redirect->post_id !== null) {
            PostSlugRedirect::withoutEvents(function () use ($redirect): void {
                $redirect->forceFill(['post_id' => null])->saveOrFail();
            });
        }
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        return in_array((string) $exception->getCode(), ['19', '23000', '23505'], true)
            || str_contains(strtolower($exception->getMessage()), 'unique constraint');
    }

    private function canonicalSlug(string $value): string
    {
        $value = trim($value);
        $slug = Str::slug($value);

        if ($slug === '' || ! hash_equals($value, $slug)) {
            throw new DomainException('Journal slugs must use lowercase letters, numbers, and hyphens.');
        }

        if (Str::length($slug) > 255) {
            throw new DomainException('Journal slugs cannot be longer than 255 characters.');
        }

        return $slug;
    }
}
