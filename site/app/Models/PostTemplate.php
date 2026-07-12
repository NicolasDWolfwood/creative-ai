<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class PostTemplate extends Model
{
    use HasFactory;

    protected $attributes = [
        'is_active' => true,
    ];

    protected $fillable = [
        'name',
        'title',
        'excerpt',
        'body',
        'editorial_brief',
        'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(function (PostTemplate $template): void {
            $template->name = Str::of((string) $template->name)->squish()->toString();

            if (blank($template->name)) {
                throw new DomainException('A Journal template needs a name.');
            }
        });
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'post_template_tag')
            ->withTimestamps()
            ->orderBy('name');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
