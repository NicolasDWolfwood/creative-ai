<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PostRevision extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'post_id',
        'user_id',
        'provenance',
        'reason',
        'snapshot',
        'changed_fields',
        'snapshot_hash',
    ];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'changed_fields' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Journal revisions are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Journal revisions are immutable.'));
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
