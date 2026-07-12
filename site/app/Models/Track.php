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
        'published_at',
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
            'published_at' => 'datetime',
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
        return $this->coverArtwork?->thumb_url ?: $this->album?->cover_url;
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
