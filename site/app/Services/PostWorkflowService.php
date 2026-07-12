<?php

namespace App\Services;

use App\Enums\PostStatus;
use App\Models\Post;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use DomainException;
use Illuminate\Support\Facades\DB;
use LogicException;

class PostWorkflowService
{
    public function __construct(
        private readonly PostReadiness $readiness,
        private readonly PostConnectionService $connections,
    ) {}

    public function markReady(Post $post): Post
    {
        return $this->transition($post, function (Post $post): void {
            $this->assertStoredStatus($post, [PostStatus::Draft], 'mark ready');
            $this->assertReady($post);
            $this->setUnpublishedState($post, PostStatus::Ready);
        });
    }

    public function revertToDraft(Post $post): Post
    {
        return $this->transition($post, function (Post $post): void {
            $this->assertStoredStatus($post, [PostStatus::Ready], 'revert to draft');
            $this->setUnpublishedState($post, PostStatus::Draft);
        });
    }

    public function schedule(Post $post, CarbonInterface $scheduledAt): Post
    {
        $scheduledAt = CarbonImmutable::instance($scheduledAt);

        if ($scheduledAt->lte(now())) {
            throw new DomainException('A journal post must be scheduled for a future date.');
        }

        return $this->transition($post, function (Post $post) use ($scheduledAt): void {
            $this->assertStoredStatus($post, [PostStatus::Ready, PostStatus::Scheduled], 'schedule');

            if ($post->isPubliclyPublishedAt()) {
                throw new DomainException('A due scheduled post is already public; unpublish it before scheduling again.');
            }

            if ($scheduledAt->lte(now())) {
                throw new DomainException('A journal post must be scheduled for a future date.');
            }

            $this->assertReady($post);

            $post->status = PostStatus::Scheduled;
            $post->scheduled_at = $scheduledAt;
            $post->published = true;
            $post->published_at = $scheduledAt;
        });
    }

    public function publishNow(Post $post): Post
    {
        return $this->transition($post, function (Post $post): void {
            $this->assertStoredStatus($post, [PostStatus::Ready, PostStatus::Scheduled], 'publish');
            $this->assertReady($post);

            $publishedAt = $this->storedStatus($post) === PostStatus::Scheduled
                && $post->scheduled_at?->lte(now())
                    ? $post->scheduled_at
                    : now();

            $post->status = PostStatus::Published;
            $post->scheduled_at = null;
            $post->published = true;
            $post->published_at = $publishedAt;
        });
    }

    public function cancelSchedule(Post $post): Post
    {
        return $this->transition($post, function (Post $post): void {
            $this->assertStoredStatus($post, [PostStatus::Scheduled], 'cancel schedule');

            if ($post->isPubliclyPublishedAt()) {
                throw new DomainException('A due scheduled post is already public; unpublish it instead.');
            }

            $post->status = PostStatus::Ready;
            $post->scheduled_at = null;
            $post->published = false;
            $post->published_at = null;
        });
    }

    public function unpublish(Post $post): Post
    {
        return $this->transition($post, function (Post $post): void {
            if (! $post->isPubliclyPublishedAt()) {
                throw new DomainException('Cannot unpublish a journal post that is not public.');
            }

            $post->status = PostStatus::Ready;
            $post->scheduled_at = null;
            $post->published = false;
        });
    }

    /**
     * @param  Closure(Post): void  $callback
     */
    private function transition(Post $post, Closure $callback): Post
    {
        if (! $post->exists) {
            throw new LogicException('Journal workflow transitions require a persisted post.');
        }

        return DB::transaction(function () use ($post, $callback): Post {
            $locked = Post::query()->lockForUpdate()->findOrFail($post->getKey());
            $wasPublic = $locked->isPubliclyPublishedAt();

            $callback($locked);
            $locked->saveOrFail();

            if ($wasPublic !== $locked->isPubliclyPublishedAt()) {
                $this->connections->touchConnectedMedia($locked);
            }

            return $locked->refresh();
        }, 3);
    }

    /** @param list<PostStatus> $allowed */
    private function assertStoredStatus(Post $post, array $allowed, string $transition): void
    {
        $status = $this->storedStatus($post);

        if (
            $status === null
            || ! in_array($status, $allowed, true)
            || ! $this->hasValidStoredState($post, $status)
        ) {
            throw new DomainException("Cannot {$transition} a journal post from its current state.");
        }
    }

    private function assertReady(Post $post): void
    {
        $report = $this->readiness->evaluate($post);

        if ($report->hasBlockers()) {
            throw new DomainException('The journal post is not ready: '.implode(' ', $report->blockers()));
        }
    }

    private function setUnpublishedState(Post $post, PostStatus $status): void
    {
        $publishedAt = $post->published_at;

        $post->status = $status;
        $post->scheduled_at = null;
        $post->published = false;
        $post->published_at = $publishedAt?->isFuture() ? null : $publishedAt;
    }

    private function storedStatus(Post $post): ?PostStatus
    {
        $status = $post->getRawOriginal('status');

        return is_string($status) ? PostStatus::tryFrom($status) : null;
    }

    private function hasValidStoredState(Post $post, PostStatus $status): bool
    {
        return match ($status) {
            PostStatus::Draft, PostStatus::Ready => ! $post->published
                && $post->scheduled_at === null,
            PostStatus::Scheduled => (bool) $post->published
                && $post->scheduled_at !== null
                && $post->published_at !== null
                && $post->scheduled_at->equalTo($post->published_at),
            PostStatus::Published => (bool) $post->published
                && $post->scheduled_at === null
                && $post->published_at !== null
                && $post->published_at->lte(now()),
        };
    }
}
