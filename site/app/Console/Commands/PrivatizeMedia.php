<?php

namespace App\Console\Commands;

use App\Models\Album;
use App\Models\Artwork;
use App\Models\Post;
use App\Models\Track;
use App\Services\PrivateMediaService;
use Illuminate\Console\Command;
use Throwable;

class PrivatizeMedia extends Command
{
    protected $signature = 'creative-ai:media:privatize {--dry-run : Report candidate files without moving them}';

    protected $description = 'Move artwork originals and track audio from public to private persistent storage.';

    public function handle(PrivateMediaService $media): int
    {
        $paths = Artwork::query()->get(['image_path', 'display_path', 'thumb_path'])
            ->flatMap(fn (Artwork $artwork): array => [$artwork->image_path, $artwork->display_path, $artwork->thumb_path])
            ->concat(Track::query()->whereNotNull('audio_path')->pluck('audio_path'))
            ->concat(Album::query()->whereNotNull('embedded_cover_path')->pluck('embedded_cover_path'))
            ->concat(Post::query()->whereNotNull('cover_image_path')->pluck('cover_image_path'))
            ->filter()->unique()->values();
        $moved = 0;
        $failed = 0;

        foreach ($paths as $path) {
            if ($this->option('dry-run')) {
                $this->line($path);

                continue;
            }

            try {
                $moved += $media->privatize($path) ? 1 : 0;
            } catch (Throwable $exception) {
                $failed++;
                $this->error($path.': '.$exception->getMessage());
            }
        }

        $this->info($this->option('dry-run')
            ? $paths->count().' media file(s) inspected.'
            : $moved.' media file(s) moved to private storage.');

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
