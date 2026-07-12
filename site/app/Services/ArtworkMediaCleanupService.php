<?php

namespace App\Services;

use App\Models\Artwork;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ArtworkMediaCleanupService
{
    /** @var array<int, string> */
    protected const MANAGED_PREFIXES = [
        'artworks/originals/',
        'artworks/display/',
        'artworks/thumbs/',
    ];

    /** @param array<int, string|null> $paths */
    public function deleteUnreferenced(array $paths): void
    {
        $disk = Storage::disk('public');

        foreach (array_unique(array_filter($paths, 'is_string')) as $path) {
            if (! $this->isManagedPath($path) || $this->isReferenced($path)) {
                continue;
            }

            $deleted = $disk->delete($path);

            if (! $deleted && $disk->exists($path)) {
                throw new RuntimeException("Unable to delete unreferenced artwork media: {$path}");
            }
        }
    }

    protected function isManagedPath(string $path): bool
    {
        if (str_starts_with($path, '/') || str_contains($path, '..') || str_contains($path, '\\')) {
            return false;
        }

        foreach (self::MANAGED_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    protected function isReferenced(string $path): bool
    {
        return Artwork::query()
            ->where('image_path', $path)
            ->orWhere('display_path', $path)
            ->orWhere('thumb_path', $path)
            ->exists();
    }
}
