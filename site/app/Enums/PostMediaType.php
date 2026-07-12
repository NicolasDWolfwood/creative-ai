<?php

namespace App\Enums;

use App\Models\Album;
use App\Models\Artwork;
use App\Models\Collection;
use App\Models\Playlist;
use App\Models\Track;
use Illuminate\Database\Eloquent\Model;

enum PostMediaType: string
{
    case Artwork = 'artwork';
    case Collection = 'collection';
    case Album = 'album';
    case Playlist = 'playlist';
    case Track = 'track';

    public function label(): string
    {
        return match ($this) {
            self::Artwork => 'Artwork',
            self::Collection => 'Collection',
            self::Album => 'Album',
            self::Playlist => 'Playlist',
            self::Track => 'Track',
        };
    }

    /** @return class-string<Model> */
    public function modelClass(): string
    {
        return match ($this) {
            self::Artwork => Artwork::class,
            self::Collection => Collection::class,
            self::Album => Album::class,
            self::Playlist => Playlist::class,
            self::Track => Track::class,
        };
    }

    public function foreignKey(): string
    {
        return $this->value.'_id';
    }

    public static function forModel(Model $model): ?self
    {
        return match (true) {
            $model instanceof Artwork => self::Artwork,
            $model instanceof Collection => self::Collection,
            $model instanceof Album => self::Album,
            $model instanceof Playlist => self::Playlist,
            $model instanceof Track => self::Track,
            default => null,
        };
    }
}
