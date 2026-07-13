<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PostSlugRedirect extends Model
{
    protected $fillable = [
        'slug',
        'post_id',
    ];

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Journal slug redirects are immutable outside the redirect service.'));
        static::deleting(fn (): never => throw new LogicException('Journal slug redirects cannot be deleted.'));
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function isTombstone(): bool
    {
        return $this->post_id === null;
    }
}
