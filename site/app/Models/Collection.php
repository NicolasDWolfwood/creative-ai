<?php

namespace App\Models;

use App\Models\Concerns\BuildsSlugs;
use App\Models\Concerns\HasPublicationSchedule;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;

class Collection extends Model
{
    use BuildsSlugs;
    use HasFactory;
    use HasPublicationSchedule;

    protected $fillable = [
        'title',
        'slug',
        'description',
        'hero_image_path',
        'sort_order',
        'featured',
        'published',
        'published_at',
        'is_smart',
        'is_auto_generated',
        'auto_generation_key',
        'smart_rules',
        'auto_sync',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'featured' => 'boolean',
            'published' => 'boolean',
            'published_at' => 'datetime',
            'is_smart' => 'boolean',
            'is_auto_generated' => 'boolean',
            'smart_rules' => 'array',
            'auto_sync' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    public function artworks(): BelongsToMany
    {
        return $this->belongsToMany(Artwork::class)
            ->withTimestamps()
            ->orderByDesc('sort_order')
            ->latest('artworks.created_at');
    }

    public function getHeroImageUrlAttribute(): ?string
    {
        return $this->hero_image_path ? Storage::disk('public')->url($this->hero_image_path) : null;
    }
}
