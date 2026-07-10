<?php

namespace App\Models;

use App\Models\Concerns\BuildsSlugs;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Collection extends Model
{
    use BuildsSlugs;
    use HasFactory;

    protected $fillable = [
        'title',
        'slug',
        'description',
        'hero_image_path',
        'sort_order',
        'featured',
        'published',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'featured' => 'boolean',
            'published' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function artworks(): HasMany
    {
        return $this->hasMany(Artwork::class)->orderBy('sort_order')->latest();
    }

    #[Scope]
    protected function published(Builder $query): void
    {
        $query->where('published', true);
    }

    public function getHeroImageUrlAttribute(): ?string
    {
        return $this->hero_image_path ? Storage::disk('public')->url($this->hero_image_path) : null;
    }
}
