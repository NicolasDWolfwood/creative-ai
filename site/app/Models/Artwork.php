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
        'sort_order',
        'featured',
        'published',
        'generated_at',
        'published_at',
        'metadata',
        'ai_status',
        'ai_queue_token',
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
            'metadata' => 'array',
            'ai_suggestion' => 'array',
            'ai_queued_at' => 'datetime',
            'ai_started_at' => 'datetime',
            'ai_analyzed_at' => 'datetime',
        ];
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
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
        return Storage::disk('public')->url($this->display_path ?: $this->image_path);
    }

    public function getThumbUrlAttribute(): string
    {
        return Storage::disk('public')->url($this->thumb_path ?: $this->display_path ?: $this->image_path);
    }

    public function getImageAltAttribute(): string
    {
        return $this->alt_text ?: $this->title;
    }
}
