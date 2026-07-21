<?php

namespace App\Models;

use App\Enums\PostMediaType;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostMedia extends Model
{
    protected $table = 'post_media';

    protected $fillable = [
        'post_id',
        'position',
        'artwork_id',
        'collection_id',
        'album_id',
        'playlist_id',
        'track_id',
    ];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saving(function (PostMedia $item): void {
            if ($item->type() === null) {
                throw new DomainException('A Journal media connection must reference exactly one supported record.');
            }

            if ((int) $item->position < 1) {
                throw new DomainException('Journal media positions must start at one.');
            }
        });
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function artwork(): BelongsTo
    {
        return $this->belongsTo(Artwork::class);
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }

    public function album(): BelongsTo
    {
        return $this->belongsTo(Album::class);
    }

    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }

    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }

    public function type(): ?PostMediaType
    {
        $types = collect(PostMediaType::cases())
            ->filter(fn (PostMediaType $type): bool => $this->getAttribute($type->foreignKey()) !== null)
            ->values();

        return $types->count() === 1 ? $types->first() : null;
    }

    public function media(): ?Model
    {
        $type = $this->type();
        $media = $type ? $this->getRelationValue($type->value) : null;

        return $media instanceof Model ? $media : null;
    }

    public function mediaTitle(): string
    {
        return (string) ($this->media()?->getAttribute('title') ?: 'Unavailable media');
    }

    public function mediaUrl(): ?string
    {
        $media = $this->media();

        return match ($this->type()) {
            PostMediaType::Artwork => $media ? route('artworks.show', $media) : null,
            PostMediaType::Collection => $media ? route('collections.show', $media) : null,
            PostMediaType::Album => $media ? route('music.albums.show', $media) : null,
            PostMediaType::Playlist => $media ? route('music.playlists.show', $media) : null,
            PostMediaType::Track => $media ? route('music.tracks.show', $media) : null,
            null => null,
        };
    }

    public function mediaIsPublic(): bool
    {
        $media = $this->media();

        return match ($this->type()) {
            PostMediaType::Track => $media instanceof Track && $media->isPubliclyAvailable(),
            PostMediaType::Artwork => $media instanceof Artwork && $media->isPubliclyAvailable(),
            PostMediaType::Collection,
            PostMediaType::Album,
            PostMediaType::Playlist => $media !== null && $media->isPubliclyPublished(),
            null => false,
        };
    }

    public function scopeValid(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            foreach (PostMediaType::cases() as $index => $type) {
                $method = $index === 0 ? 'where' : 'orWhere';
                $query->{$method}(function (Builder $query) use ($type): void {
                    $query->whereNotNull($type->foreignKey());

                    foreach (PostMediaType::cases() as $otherType) {
                        if ($otherType !== $type) {
                            $query->whereNull($otherType->foreignKey());
                        }
                    }
                });
            }
        })->where('position', '>=', 1);
    }

    public function scopeForMedia(Builder $query, Model $media): Builder
    {
        $type = PostMediaType::forModel($media);

        if ($type === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where($type->foreignKey(), $media->getKey());
    }
}
