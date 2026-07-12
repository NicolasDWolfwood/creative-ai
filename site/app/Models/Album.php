<?php

namespace App\Models;

use App\Models\Concerns\BuildsSlugs;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Album extends Model
{
    use BuildsSlugs;
    use HasFactory;

    protected $fillable = [
        'cover_artwork_id', 'title', 'artist', 'album_artist', 'slug', 'import_key', 'description',
        'embedded_cover_path', 'cover_preference', 'release_year', 'sort_order', 'featured', 'published', 'published_at',
    ];

    protected function casts(): array
    {
        return ['featured' => 'boolean', 'published' => 'boolean', 'published_at' => 'datetime'];
    }

    public function coverArtwork(): BelongsTo
    {
        return $this->belongsTo(Artwork::class, 'cover_artwork_id');
    }

    public function tracks(): HasMany
    {
        return $this->hasMany(Track::class)->orderBy('disc_number')->orderBy('track_number')->orderBy('id');
    }

    public function getCoverUrlAttribute(): ?string
    {
        if ($this->cover_preference === 'none') {
            return null;
        }
        if ($this->cover_preference !== 'embedded' && $this->coverArtwork) {
            return $this->coverArtwork->thumb_url;
        }
        if ($this->cover_preference !== 'artwork' && $this->embedded_cover_path) {
            return Storage::disk('public')->url($this->embedded_cover_path);
        }

        return $this->coverArtwork?->thumb_url;
    }
}
