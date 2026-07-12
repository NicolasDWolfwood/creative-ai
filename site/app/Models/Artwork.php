<?php

namespace App\Models;

use App\Models\Concerns\BuildsSlugs;
use App\Models\Concerns\HasPublicationSchedule;
use App\Services\PrivateMediaService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Artwork extends Model
{
    use BuildsSlugs;
    use HasFactory;
    use HasPublicationSchedule;

    public const AI_STATUS_IDLE = 'idle';

    public const AI_STATUS_QUEUED = 'queued';

    public const AI_STATUS_PROCESSING = 'processing';

    public const AI_STATUS_READY = 'ready';

    public const AI_STATUS_FAILED = 'failed';

    public const AI_STATUS_APPLIED = 'applied';

    public const VARIANT_STATUS_PENDING = 'pending';

    public const VARIANT_STATUS_QUEUED = 'queued';

    public const VARIANT_STATUS_PROCESSING = 'processing';

    public const VARIANT_STATUS_READY = 'ready';

    public const VARIANT_STATUS_FAILED = 'failed';

    /** Upload services can set this before save so the observer captures the intent. */
    public bool $analyzeAfterVariantGeneration = false;

    protected $fillable = [
        'collection_id',
        'title',
        'slug',
        'description',
        'alt_text',
        'prompt',
        'image_path',
        'display_path',
        'thumb_path',
        'original_filename',
        'width',
        'height',
        'variant_status',
        'variant_generation_token',
        'variant_error',
        'variant_queued_at',
        'variant_started_at',
        'variants_generated_at',
        'sort_order',
        'featured',
        'published',
        'generated_at',
        'published_at',
        'metadata',
        'ai_status',
        'ai_queue_token',
        'ai_apply_after_analysis',
        'ai_suggestion',
        'ai_model',
        'ai_error',
        'ai_queued_at',
        'ai_started_at',
        'ai_analyzed_at',
    ];

    protected function casts(): array
    {
        return [
            'featured' => 'boolean',
            'published' => 'boolean',
            'generated_at' => 'datetime',
            'published_at' => 'datetime',
            'variant_queued_at' => 'datetime',
            'variant_started_at' => 'datetime',
            'variants_generated_at' => 'datetime',
            'metadata' => 'array',
            'ai_suggestion' => 'array',
            'ai_apply_after_analysis' => 'boolean',
            'ai_queued_at' => 'datetime',
            'ai_started_at' => 'datetime',
            'ai_analyzed_at' => 'datetime',
        ];
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }

    public function collections(): BelongsToMany
    {
        return $this->belongsToMany(Collection::class)
            ->withTimestamps()
            ->orderBy('sort_order');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)
            ->withPivot('category')
            ->withTimestamps()
            ->orderBy('name');
    }

    public function getImageUrlAttribute(): string
    {
        return route('media.artworks.show', [$this, 'variant' => 'original', 'v' => $this->mediaVersion($this->image_path)]);
    }

    public function getPublicImageUrlAttribute(): string
    {
        return route('artworks.image', ['artwork' => $this, 'v' => $this->mediaVersion($this->image_path)]);
    }

    public function getDisplayUrlAttribute(): string
    {
        return route('media.artworks.show', [$this, 'variant' => 'display', 'v' => $this->mediaVersion($this->availableDisplayPath())]);
    }

    public function getThumbUrlAttribute(): string
    {
        return route('media.artworks.show', [$this, 'variant' => 'thumb', 'v' => $this->mediaVersion($this->availableThumbPath())]);
    }

    public function availableDisplayPath(): string
    {
        return $this->firstExistingPath([
            $this->display_path,
            $this->image_path,
        ]);
    }

    public function availableThumbPath(): string
    {
        return $this->firstExistingPath([
            $this->thumb_path,
            $this->display_path,
            $this->image_path,
        ]);
    }

    public function imageVariantsExist(): bool
    {
        if (blank($this->display_path) || blank($this->thumb_path)) {
            return false;
        }

        $disk = app(PrivateMediaService::class);

        return $disk->sourceDisk($this->display_path)->exists($this->display_path)
            && $disk->sourceDisk($this->thumb_path)->exists($this->thumb_path);
    }

    public function hasAvailableImage(): bool
    {
        foreach ([$this->thumb_path, $this->display_path, $this->image_path] as $path) {
            if (blank($path)) {
                continue;
            }

            $disk = app(PrivateMediaService::class)->sourceDisk((string) $path);

            if ($disk->exists($path)) {
                return true;
            }
        }

        return false;
    }

    public function getImageAltAttribute(): string
    {
        return $this->alt_text ?: $this->title;
    }

    /** @param array<int, string|null> $paths */
    protected function firstExistingPath(array $paths): string
    {
        foreach ($paths as $path) {
            if (blank($path)) {
                continue;
            }

            $disk = app(PrivateMediaService::class)->sourceDisk((string) $path);

            if ($disk->exists($path)) {
                return $path;
            }
        }

        return (string) ($this->image_path ?: collect($paths)->first(fn (?string $path): bool => filled($path)));
    }

    protected function mediaVersion(?string $path): string
    {
        return substr(hash('sha256', (string) $path), 0, 12);
    }
}
