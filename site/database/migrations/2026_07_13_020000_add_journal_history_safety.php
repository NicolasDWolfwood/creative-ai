<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    /** @var list<string> */
    private const CONTENT_FIELDS = [
        'title',
        'excerpt',
        'body',
        'cover_image_path',
        'cover_alt_text',
        'seo_title',
        'seo_description',
    ];

    /** @var array<string, string> */
    private const MEDIA_FOREIGN_KEYS = [
        'artwork' => 'artwork_id',
        'collection' => 'collection_id',
        'album' => 'album_id',
        'playlist' => 'playlist_id',
        'track' => 'track_id',
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('posts', 'deleted_at')) {
            Schema::table('posts', function (Blueprint $table) {
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('post_revisions')) {
            Schema::create('post_revisions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('post_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('provenance', 64)->index();
                $table->text('reason')->nullable();
                $table->json('snapshot');
                $table->json('changed_fields');
                $table->char('snapshot_hash', 64);
                $table->timestamp('created_at');

                $table->index(['post_id', 'id']);
                $table->index(['post_id', 'snapshot_hash']);
            });
        }

        if (! Schema::hasTable('post_slug_redirects')) {
            Schema::create('post_slug_redirects', function (Blueprint $table) {
                $table->id();
                $table->string('slug')->unique();
                $table->foreignId('post_id')->nullable()->constrained()->nullOnDelete();
                $table->timestamps();

                $table->index(['post_id', 'id']);
            });
        }

        $this->backfillBaselines();
    }

    public function down(): void
    {
        Schema::dropIfExists('post_slug_redirects');
        Schema::dropIfExists('post_revisions');

        if (Schema::hasColumn('posts', 'deleted_at')) {
            DB::table('posts')
                ->whereNotNull('deleted_at')
                ->update([
                    'status' => 'draft',
                    'scheduled_at' => null,
                    'published' => false,
                    'published_at' => null,
                ]);

            Schema::table('posts', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }

    private function backfillBaselines(): void
    {
        DB::table('posts')
            ->orderBy('id')
            ->chunkById(100, function ($posts): void {
                foreach ($posts as $post) {
                    if (
                        DB::table('post_revisions')
                            ->where('post_id', $post->id)
                            ->where('provenance', 'history_baseline')
                            ->exists()
                    ) {
                        continue;
                    }

                    $snapshot = $this->baselineSnapshot($post);
                    $encoded = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

                    DB::table('post_revisions')->insert([
                        'post_id' => $post->id,
                        'user_id' => null,
                        'provenance' => 'history_baseline',
                        'reason' => 'Baseline captured when Journal history was enabled.',
                        'snapshot' => $encoded,
                        'changed_fields' => json_encode([
                            ...array_map(fn (string $field): string => 'content.'.$field, self::CONTENT_FIELDS),
                            'tags',
                            'media',
                        ], JSON_THROW_ON_ERROR),
                        'snapshot_hash' => hash('sha256', $encoded),
                        'created_at' => now(),
                    ]);
                }
            }, 'id');
    }

    /**
     * @return array{
     *   version: int,
     *   content: array<string, ?string>,
     *   cover: array{path: ?string, source_disk: ?string, size: ?int, sha256: ?string},
     *   tag_ids: list<int>,
     *   media: list<array<string, mixed>>
     * }
     */
    private function baselineSnapshot(object $post): array
    {
        $content = [];

        foreach (self::CONTENT_FIELDS as $field) {
            $value = $post->{$field};
            $content[$field] = $value === null ? null : (string) $value;
        }

        $tagIds = DB::table('post_tag')
            ->where('post_id', $post->id)
            ->reorder('tag_id')
            ->pluck('tag_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $media = DB::table('post_media')
            ->where('post_id', $post->id)
            ->reorder('position')
            ->orderBy('id')
            ->get()
            ->map(fn (object $item): array => $this->mediaAuditReference($item))
            ->all();

        return [
            'version' => 1,
            'content' => $content,
            'cover' => $this->coverIntegrity($content['cover_image_path']),
            'tag_ids' => $tagIds,
            'media' => $media,
        ];
    }

    /** @return array<string, mixed> */
    private function mediaAuditReference(object $item): array
    {
        $references = collect(self::MEDIA_FOREIGN_KEYS)
            ->filter(fn (string $foreignKey): bool => $item->{$foreignKey} !== null)
            ->mapWithKeys(fn (string $foreignKey): array => [
                $foreignKey => (int) $item->{$foreignKey},
            ])
            ->all();

        if (count($references) === 1 && (int) $item->position >= 1) {
            $foreignKey = array_key_first($references);

            return [
                'position' => (int) $item->position,
                'type' => array_search($foreignKey, self::MEDIA_FOREIGN_KEYS, true),
                'id' => $references[$foreignKey],
            ];
        }

        return [
            'position' => (int) $item->position,
            'type' => 'invalid',
            'id' => null,
            'invalid' => true,
            'references' => $references,
        ];
    }

    /** @return array{path: ?string, source_disk: ?string, size: ?int, sha256: ?string} */
    private function coverIntegrity(?string $path): array
    {
        if ($path === null || trim($path) === '') {
            return ['path' => null, 'source_disk' => null, 'size' => null, 'sha256' => null];
        }

        $diskName = Storage::disk('local')->exists($path)
            ? 'local'
            : (Storage::disk('public')->exists($path) ? 'public' : null);

        if ($diskName === null) {
            return ['path' => $path, 'source_disk' => 'missing', 'size' => null, 'sha256' => null];
        }

        $stream = Storage::disk($diskName)->readStream($path);

        if (! is_resource($stream)) {
            return ['path' => $path, 'source_disk' => 'missing', 'size' => null, 'sha256' => null];
        }

        $hash = hash_init('sha256');
        $size = 0;

        try {
            while (! feof($stream)) {
                $chunk = fread($stream, 1024 * 1024);

                if ($chunk === false) {
                    return ['path' => $path, 'source_disk' => 'missing', 'size' => null, 'sha256' => null];
                }

                $size += strlen($chunk);
                hash_update($hash, $chunk);
            }
        } finally {
            fclose($stream);
        }

        return [
            'path' => $path,
            'source_disk' => $diskName,
            'size' => $size,
            'sha256' => hash_final($hash),
        ];
    }
};
