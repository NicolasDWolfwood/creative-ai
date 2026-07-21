<?php

namespace App\Models;

use App\Models\Concerns\BuildsSlugs;
use App\Models\Concerns\HasPublicationSchedule;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        'publishes_members',
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
            'publishes_members' => 'boolean',
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

    /**
     * Published-only smart collections mirror standalone availability and do
     * not independently grant access. This keeps a stale pivot fail-closed if
     * a later synchronization cannot remove an unpublished artwork.
     */
    #[Scope]
    protected function memberPublicationGrants(Builder $query): void
    {
        $query
            ->where('publishes_members', true)
            ->where(fn (Builder $query) => $query
                ->where('is_smart', false)
                ->orWhere('smart_rules->only_published', false));
    }

    public function grantsMemberPublication(): bool
    {
        return (bool) $this->publishes_members
            && (! (bool) $this->is_smart
                || data_get($this->smart_rules, 'only_published', true) === false);
    }

    public function journalMediaItems(): HasMany
    {
        return $this->hasMany(PostMedia::class);
    }

    public function getHeroImageUrlAttribute(): ?string
    {
        return $this->hero_image_path ? Storage::disk('public')->url($this->hero_image_path) : null;
    }
}
