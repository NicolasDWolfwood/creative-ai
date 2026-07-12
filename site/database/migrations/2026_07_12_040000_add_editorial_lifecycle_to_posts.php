<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->longText('body')->nullable()->change();
            $table->string('status')->default('draft')->after('featured');
            $table->timestamp('scheduled_at')->nullable()->index()->after('status');
            $table->longText('editorial_brief')->nullable()->after('body');
            $table->longText('editorial_notes')->nullable()->after('editorial_brief');
            $table->string('cover_alt_text')->nullable()->after('cover_image_path');
            $table->timestamp('public_content_updated_at')->nullable()->after('published_at');
            $table->index(['status', 'published_at']);
        });

        $now = now();

        DB::table('posts')
            ->select(['id', 'published', 'published_at', 'created_at', 'updated_at'])
            ->orderBy('id')
            ->chunkById(100, function ($posts) use ($now): void {
                foreach ($posts as $post) {
                    $publishedAt = $post->published_at ? Carbon::parse($post->published_at) : null;
                    $createdAt = $post->created_at ? Carbon::parse($post->created_at) : null;
                    $publicContentUpdatedAt = $post->updated_at ?: $post->created_at ?: $now;

                    if (! $post->published) {
                        DB::table('posts')->where('id', $post->id)->update([
                            'status' => 'draft',
                            'scheduled_at' => null,
                            'published' => false,
                            'published_at' => $publishedAt,
                            'public_content_updated_at' => $publicContentUpdatedAt,
                        ]);

                        continue;
                    }

                    if ($publishedAt?->isAfter($now)) {
                        DB::table('posts')->where('id', $post->id)->update([
                            'status' => 'scheduled',
                            'scheduled_at' => $publishedAt,
                            'published' => true,
                            'published_at' => $publishedAt,
                            'public_content_updated_at' => $publicContentUpdatedAt,
                        ]);

                        continue;
                    }

                    DB::table('posts')->where('id', $post->id)->update([
                        'status' => 'published',
                        'scheduled_at' => null,
                        'published' => true,
                        'published_at' => $publishedAt ?: $createdAt ?: $now,
                        'public_content_updated_at' => $publicContentUpdatedAt,
                    ]);
                }
            });
    }

    public function down(): void
    {
        DB::table('posts')->whereNull('body')->update(['body' => '']);

        Schema::table('posts', function (Blueprint $table) {
            $table->longText('body')->nullable(false)->change();
            $table->dropIndex(['scheduled_at']);
            $table->dropIndex(['status', 'published_at']);
            $table->dropColumn([
                'status',
                'scheduled_at',
                'editorial_brief',
                'editorial_notes',
                'cover_alt_text',
                'public_content_updated_at',
            ]);
        });
    }
};
