<?php

namespace App\Models;

use App\Models\Concerns\BuildsSlugs;
use App\Models\Concerns\HasPublicationSchedule;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Album extends Model
{
    use BuildsSlugs;
    use HasFactory;
    use HasPublicationSchedule;

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

        $artworkCover = $this->coverArtwork?->isPubliclyPublished()
            ? $this->coverArtwork->thumb_url
            : null;

        if ($this->cover_preference !== 'embedded' && $artworkCover) {
            return $artworkCover;
        }
        if ($this->cover_preference !== 'artwork' && $this->embedded_cover_path) {
            return route('media.albums.embedded-cover', [$this, 'v' => substr(hash('sha256', $this->embedded_cover_path), 0, 12)]);
        }

        return $artworkCover;
    }

    /**
     * Audio-library health is evaluated before an album is published, so it
     * must inspect the configured cover choice rather than a public URL.
     * Choosing no cover is an intentional opt-out, not missing metadata.
     */
    public function coverChoiceIsConfigured(): bool
    {
        return match ($this->cover_preference ?: 'auto') {
            'none' => true,
            'artwork' => $this->cover_artwork_id !== null,
            'embedded' => filled($this->embedded_cover_path),
            default => $this->cover_artwork_id !== null || filled($this->embedded_cover_path),
        };
    }
}
