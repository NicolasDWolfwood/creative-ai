<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Tag extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
    ];

    protected static function booted(): void
    {
        static::saving(function (Tag $tag): void {
            $tag->name = Str::of($tag->name)->squish()->lower()->toString();

            if (blank($tag->slug)) {
                $tag->slug = Str::slug($tag->name) ?: Str::random(8);
            }
        });
    }

    public function artworks(): BelongsToMany
    {
        return $this->belongsToMany(Artwork::class)
            ->withPivot('category')
            ->withTimestamps();
    }

    public function tracks(): BelongsToMany
    {
        return $this->belongsToMany(Track::class, 'track_tag')
            ->withPivot('category')
            ->withTimestamps();
    }

    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, 'post_tag')
            ->withTimestamps();
    }
}
