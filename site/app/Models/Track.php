<?php

namespace App\Models;

use App\Models\Concerns\BuildsSlugs;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Track extends Model
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
        'cover_artwork_id',
        'album_id',
        'title',
        'artist',
        'slug',
        'description',
        'audio_path',
        'original_filename',
        'duration_seconds',
        'disc_number',
        'track_number',
        'release_year',
        'sort_order',
        'featured',
        'published',
        'standalone_published',
        'published_at',
        'standalone_published_at',
        'metadata',
        'metadata_reviewed_at',
        'ai_model',
        'ai_analyzed_at',
        'ai_status',
        'ai_suggestion',
        'ai_error',
        'analysis_status', 'analysis_error', 'analyzed_at', 'audio_hash', 'audio_codec',
        'bitrate', 'sample_rate', 'channels', 'waveform', 'health_status', 'health_issues',
    ];

    protected function casts(): array
    {
        return [
            'featured' => 'boolean',
            'published' => 'boolean',
            'standalone_published' => 'boolean',
            'published_at' => 'datetime',
            'standalone_published_at' => 'datetime',
            'metadata' => 'array',
            'metadata_reviewed_at' => 'datetime',
            'ai_analyzed_at' => 'datetime',
            'ai_suggestion' => 'array',
            'analyzed_at' => 'datetime',
            'waveform' => 'array',
            'health_issues' => 'array',
        ];
    }

    public function coverArtwork(): BelongsTo
    {
        return $this->belongsTo(Artwork::class, 'cover_artwork_id');
    }

    public function album(): BelongsTo
    {
        return $this->belongsTo(Album::class);
    }

    public function playlists(): BelongsToMany
    {
        return $this->belongsToMany(Playlist::class, 'playlist_tracks')
            ->withPivot('position')
            ->withTimestamps()
            ->orderByPivot('position');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'track_tag')
            ->withPivot('category')
            ->withTimestamps()
            ->orderBy('name');
    }

    /**
     * Track publication means an intentional standalone release. The legacy
     * published columns are maintained separately as a rollback mirror.
     */
    #[Scope]
    protected function published(Builder $query): void
    {
        $query
            ->where('standalone_published', true)
            ->where(function (Builder $query): void {
                $query->whereNull('standalone_published_at')->orWhere('standalone_published_at', '<=', now());
            });
    }

    public function isPubliclyPublished(): bool
    {
        return (bool) $this->standalone_published
            && (! $this->standalone_published_at || $this->standalone_published_at->isPast());
    }

    #[Scope]
    protected function publiclyAvailable(Builder $query): void
    {
        $query->where(function (Builder $query): void {
            $query
                ->published()
                ->orWhereHas('album', fn (Builder $query) => $query->published());
        });
    }

    public function isPubliclyAvailable(): bool
    {
        return $this->isPubliclyPublished()
            || ($this->album_id !== null && $this->album()->published()->exists());
    }

    public function getAudioUrlAttribute(): string
    {
        return route('media.tracks.audio', [$this, 'v' => substr(hash('sha256', (string) $this->audio_path), 0, 12)]);
    }

    public function getCoverUrlAttribute(): ?string
    {
        if ($cover = $this->coverArtwork?->thumb_url) {
            return $cover;
        }

        return $this->album?->isPubliclyPublished()
            ? $this->album->cover_url
            : null;
    }

    public function coverChoiceIsConfigured(): bool
    {
        if ($this->cover_artwork_id !== null) {
            return true;
        }

        return $this->album?->coverChoiceIsConfigured() ?? false;
    }

    public function markTechnicalAnalysisPending(): void
    {
        $this->forceFill([
            'analysis_status' => 'pending',
            'analysis_error' => null,
            'health_status' => 'unknown',
            'health_issues' => null,
        ])->saveQuietly();
    }

    public function healthExplanation(): string
    {
        if ($this->analysis_status === 'failed') {
            return filled($this->analysis_error)
                ? 'Technical analysis failed: '.$this->analysis_error
                : 'Technical analysis failed. Retry the audio health analysis.';
        }

        if ($this->analysis_status === 'pending' || $this->analysis_status === 'processing') {
            return $this->analysis_status === 'processing'
                ? 'Technical analysis is currently running.'
                : 'Technical analysis has not completed yet.';
        }

        $issues = collect($this->health_issues)->filter()->values();

        return $issues->isEmpty()
            ? 'No audio-library health issues were detected.'
            : $issues->implode(' · ');
    }

    public function technicalSummary(): string
    {
        $parts = collect([
            filled($this->audio_codec) ? strtoupper($this->audio_codec) : null,
            $this->bitrate ? round($this->bitrate / 1000).' kbps' : null,
            $this->sample_rate ? number_format($this->sample_rate / 1000, 1).' kHz' : null,
            $this->channels ? $this->channels.' channel'.($this->channels === 1 ? '' : 's') : null,
            $this->duration_seconds ? gmdate($this->duration_seconds >= 3600 ? 'G:i:s' : 'i:s', $this->duration_seconds) : null,
        ])->filter();

        return $parts->isEmpty() ? 'No technical audio details are available yet.' : $parts->implode(' · ');
    }
}
