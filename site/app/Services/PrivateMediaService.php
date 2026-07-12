<?php

namespace App\Services;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class PrivateMediaService
{
    public function sourceDisk(string $path): FilesystemAdapter
    {
        $private = Storage::disk('local');

        return $private->exists($path) ? $private : Storage::disk('public');
    }

    public function absolutePath(string $path): string
    {
        return $this->sourceDisk($path)->path($path);
    }

    public function privatize(string $path): bool
    {
        $private = Storage::disk('local');
        $public = Storage::disk('public');

        if ($private->exists($path)) {
            if (! $public->exists($path)) {
                return false;
            }

            if (hash_file('sha256', $private->path($path)) !== hash_file('sha256', $public->path($path))) {
                throw new RuntimeException("Public and private media differ; refusing to overwrite either copy: {$path}");
            }

            if (! $public->delete($path)) {
                throw new RuntimeException("Unable to remove duplicate public media: {$path}");
            }

            return true;
        }

        if (! $public->exists($path)) {
            throw new RuntimeException("Media file is missing: {$path}");
        }

        $stream = $public->readStream($path);

        if (! is_resource($stream)) {
            throw new RuntimeException("Unable to read public media: {$path}");
        }

        try {
            if (! $private->writeStream($path, $stream)) {
                throw new RuntimeException("Unable to write private media: {$path}");
            }
        } finally {
            fclose($stream);
        }

        if (hash_file('sha256', $private->path($path)) !== hash_file('sha256', $public->path($path))) {
            $private->delete($path);
            throw new RuntimeException("Private media verification failed: {$path}");
        }

        if (! $public->delete($path)) {
            throw new RuntimeException("Unable to remove public media after migration: {$path}");
        }

        return true;
    }
}
