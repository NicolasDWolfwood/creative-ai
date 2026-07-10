<?php

namespace App\Models;

use App\Models\Concerns\BuildsSlugs;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Post extends Model
{
    use BuildsSlugs;
    use HasFactory;

    protected $fillable = [
        'title',
        'slug',
        'excerpt',
        'body',
        'cover_image_path',
        'seo_title',
        'seo_description',
        'featured',
        'published',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'featured' => 'boolean',
            'published' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    #[Scope]
    protected function published(Builder $query): void
    {
        $query
            ->where('published', true)
            ->where(function (Builder $query): void {
                $query->whereNull('published_at')->orWhere('published_at', '<=', now());
            });
    }

    public function getCoverUrlAttribute(): ?string
    {
        return $this->cover_image_path
            ? Storage::disk('public')->url($this->cover_image_path)
            : null;
    }

    public function getReadingMinutesAttribute(): int
    {
        return max(1, (int) ceil(str_word_count(strip_tags($this->body)) / 220));
    }

    public function getSummaryAttribute(): string
    {
        return $this->excerpt ?: Str::of(strip_tags($this->body))->squish()->limit(180)->toString();
    }
}
