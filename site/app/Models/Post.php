<?php

namespace App\Models;

use App\Enums\PostStatus;
use App\Models\Concerns\BuildsSlugs;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Post extends Model
{
    use BuildsSlugs;
    use HasFactory;

    protected $attributes = [
        'status' => PostStatus::Draft->value,
        'published' => false,
    ];

    /** @var list<string> */
    private const PUBLIC_CONTENT_FIELDS = [
        'title',
        'slug',
        'excerpt',
        'body',
        'cover_image_path',
        'cover_alt_text',
        'seo_title',
        'seo_description',
    ];

    protected $fillable = [
        'title',
        'slug',
        'excerpt',
        'body',
        'cover_image_path',
        'cover_alt_text',
        'seo_title',
        'seo_description',
        'editorial_brief',
        'editorial_notes',
        'featured',
    ];

    protected $hidden = [
        'editorial_brief',
        'editorial_notes',
    ];

    protected function casts(): array
    {
        return [
            'featured' => 'boolean',
            'status' => PostStatus::class,
            'scheduled_at' => 'datetime',
            'published' => 'boolean',
            'published_at' => 'datetime',
            'public_content_updated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Post $post): void {
            if (! $post->exists || $post->isDirty(self::PUBLIC_CONTENT_FIELDS)) {
                $post->public_content_updated_at = now();

                return;
            }

            if ($post->isDirty('public_content_updated_at')) {
                $post->public_content_updated_at = $post->getOriginal('public_content_updated_at');
            }
        });
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'post_tag')
            ->withTimestamps()
            ->orderBy('name');
    }

    public function mediaItems(): HasMany
    {
        return $this->hasMany(PostMedia::class)->orderBy('position');
    }

    public function scopePublished(Builder $query, ?CarbonInterface $at = null): Builder
    {
        $at ??= now();

        return $query->where(function (Builder $query) use ($at): void {
            $query->where(function (Builder $query) use ($at): void {
                $query
                    ->where('status', PostStatus::Published->value)
                    ->where('published', true)
                    ->whereNull('scheduled_at')
                    ->whereNotNull('published_at')
                    ->where('published_at', '<=', $at);
            })->orWhere(function (Builder $query) use ($at): void {
                $query
                    ->where('status', PostStatus::Scheduled->value)
                    ->where('published', true)
                    ->whereNotNull('scheduled_at')
                    ->whereNotNull('published_at')
                    ->whereColumn('published_at', 'scheduled_at')
                    ->where('scheduled_at', '<=', $at);
            });
        });
    }

    public function scopeLatestPublished(Builder $query, ?CarbonInterface $at = null): Builder
    {
        return $this->scopePublished($query, $at)
            ->orderByDesc('published_at')
            ->orderByDesc($this->qualifyColumn($this->getKeyName()));
    }

    public function isPubliclyPublished(): bool
    {
        return $this->isPubliclyPublishedAt();
    }

    public function isPubliclyPublishedAt(?CarbonInterface $at = null): bool
    {
        return $this->effectivePublishedAt($at) !== null;
    }

    public function effectiveStatusAt(?CarbonInterface $at = null): PostStatus
    {
        $at ??= now();
        $status = $this->storedStatus();

        if ($status === PostStatus::Scheduled && $this->hasValidScheduledState()) {
            return $this->scheduled_at->lte($at)
                ? PostStatus::Published
                : PostStatus::Scheduled;
        }

        if ($status === PostStatus::Published && $this->hasValidPublishedState()) {
            return $this->published_at->lte($at)
                ? PostStatus::Published
                : PostStatus::Draft;
        }

        if ($status === PostStatus::Ready && $this->hasValidUnpublishedState()) {
            return PostStatus::Ready;
        }

        return PostStatus::Draft;
    }

    public function effectivePublishedAt(?CarbonInterface $at = null): ?CarbonInterface
    {
        $at ??= now();
        $status = $this->storedStatus();

        if (
            $status === PostStatus::Scheduled
            && $this->hasValidScheduledState()
            && $this->scheduled_at->lte($at)
        ) {
            return $this->scheduled_at;
        }

        if (
            $status === PostStatus::Published
            && $this->hasValidPublishedState()
            && $this->published_at->lte($at)
        ) {
            return $this->published_at;
        }

        return null;
    }

    public function effectivePublicContentUpdatedAt(?CarbonInterface $at = null): ?CarbonInterface
    {
        $publishedAt = $this->effectivePublishedAt($at);

        if ($publishedAt === null) {
            return null;
        }

        if (
            $this->public_content_updated_at !== null
            && $this->public_content_updated_at->gt($publishedAt)
        ) {
            return $this->public_content_updated_at;
        }

        return $publishedAt;
    }

    public function getCoverUrlAttribute(): ?string
    {
        return $this->cover_image_path
            ? route('media.posts.cover', [$this, 'v' => substr(hash('sha256', $this->cover_image_path), 0, 12)])
            : null;
    }

    public function getReadingMinutesAttribute(): int
    {
        return max(1, (int) ceil(str_word_count(strip_tags((string) $this->body)) / 220));
    }

    public function getSummaryAttribute(): string
    {
        return $this->excerpt ?: Str::of(strip_tags((string) $this->body))->squish()->limit(180)->toString();
    }

    private function storedStatus(): ?PostStatus
    {
        $status = $this->getRawOriginal('status');

        return is_string($status) ? PostStatus::tryFrom($status) : null;
    }

    private function hasValidScheduledState(): bool
    {
        return (bool) $this->published
            && $this->scheduled_at !== null
            && $this->published_at !== null
            && $this->scheduled_at->equalTo($this->published_at);
    }

    private function hasValidPublishedState(): bool
    {
        return (bool) $this->published
            && $this->scheduled_at === null
            && $this->published_at !== null;
    }

    private function hasValidUnpublishedState(): bool
    {
        return ! $this->published
            && $this->scheduled_at === null;
    }
}
