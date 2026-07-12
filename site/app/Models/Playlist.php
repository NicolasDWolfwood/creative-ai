<?php

namespace App\Models;

use App\Models\Concerns\BuildsSlugs;
use App\Models\Concerns\HasPublicationSchedule;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Playlist extends Model
{
    use BuildsSlugs;
    use HasFactory;
    use HasPublicationSchedule;

    protected $fillable = [
        'cover_artwork_id',
        'title',
        'slug',
        'description',
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

    public function coverArtwork(): BelongsTo
    {
        return $this->belongsTo(Artwork::class, 'cover_artwork_id');
    }

    public function tracks(): BelongsToMany
    {
        return $this->belongsToMany(Track::class, 'playlist_tracks')
            ->withPivot('position')
            ->withTimestamps()
            ->orderByPivot('position');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(PlaylistTrack::class)->orderBy('position');
    }

    public function getCoverUrlAttribute(): ?string
    {
        return $this->coverArtwork?->thumb_url;
    }
}
