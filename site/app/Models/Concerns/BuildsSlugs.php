<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

trait BuildsSlugs
{
    protected static function bootBuildsSlugs(): void
    {
        static::saving(function (Model $model): void {
            if (blank($model->slug) && filled($model->title)) {
                $model->slug = static::uniqueSlug($model, $model->title);
            }
        });
    }

    protected static function uniqueSlug(Model $model, string $source): string
    {
        $base = Str::slug($source) ?: Str::random(8);
        $slug = $base;
        $suffix = 2;

        while (
            $model::query()
                ->where('slug', $slug)
                ->when($model->exists, fn ($query) => $query->whereKeyNot($model->getKey()))
                ->exists()
        ) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
