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

class Track extends Model
{
    use BuildsSlugs;
    use HasFactory;

    protected $fillable = [
        'cover_artwork_id',
        'title',
        'artist',
        'slug',
        'description',
        'audio_path',
        'original_filename',
        'duration_seconds',
        'sort_order',
        'featured',
        'published',
        'published_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'featured' => 'boolean',
            'published' => 'boolean',
            'published_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function coverArtwork(): BelongsTo
    {
        return $this->belongsTo(Artwork::class, 'cover_artwork_id');
    }

    public function playlists(): BelongsToMany
    {
        return $this->belongsToMany(Playlist::class, 'playlist_tracks')
            ->withPivot('position')
            ->withTimestamps()
            ->orderByPivot('position');
    }

    #[Scope]
    protected function published(Builder $query): void
    {
        $query->where('published', true);
    }

    public function getAudioUrlAttribute(): string
    {
        return Storage::disk('public')->url($this->audio_path);
    }

    public function getCoverUrlAttribute(): ?string
    {
        return $this->coverArtwork?->thumb_url;
    }
}
