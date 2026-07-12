<?php

namespace App\Models;

use App\Models\Concerns\BuildsSlugs;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;

class Artwork extends Model
{
    use BuildsSlugs;
    use HasFactory;

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

    #[Scope]
    protected function published(Builder $query): void
    {
        $query->where('published', true);
    }

    public function getImageUrlAttribute(): string
    {
        return Storage::disk('public')->url($this->image_path);
    }

    public function getDisplayUrlAttribute(): string
    {
        return Storage::disk('public')->url($this->availableDisplayPath());
    }

    public function getThumbUrlAttribute(): string
    {
        return Storage::disk('public')->url($this->availableThumbPath());
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

        $disk = Storage::disk('public');

        return $disk->exists($this->display_path) && $disk->exists($this->thumb_path);
    }

    public function hasAvailableImage(): bool
    {
        $disk = Storage::disk('public');

        foreach ([$this->thumb_path, $this->display_path, $this->image_path] as $path) {
            if (filled($path) && $disk->exists($path)) {
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
        $disk = Storage::disk('public');

        foreach ($paths as $path) {
            if (filled($path) && $disk->exists($path)) {
                return $path;
            }
        }

        return (string) ($this->image_path ?: collect($paths)->first(fn (?string $path): bool => filled($path)));
    }
}
