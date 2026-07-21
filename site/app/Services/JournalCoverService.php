<?php

namespace App\Services;

use App\Data\JournalSourceImage;
use App\Models\Post;
use App\Models\PostRevision;
use DomainException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class JournalCoverService
{
    private const MAX_BYTES = 25 * 1024 * 1024;

    private const MAX_PIXELS = 20_000_000;

    public function __construct(
        private readonly PrivateMediaService $media,
    ) {}

    /**
     * Copy a source image into an independently owned private Journal cover.
     *
     * The caller must invoke discardUncommitted() if its surrounding database
     * transaction fails. Existing and committed Journal covers are retained so
     * immutable revisions can continue to restore their recorded bytes.
     */
    public function copy(JournalSourceImage $source): string
    {
        if (
            $source->sourceId < 1
            || trim($source->sourcePath) === ''
            || str_starts_with($source->sourcePath, '/')
            || str_contains($source->sourcePath, '..')
            || str_contains($source->sourcePath, '\\')
        ) {
            throw new DomainException('The selected Journal source artwork path is invalid.');
        }

        $sourceDisk = $this->media->sourceDisk($source->sourcePath);

        if (! $sourceDisk->exists($source->sourcePath)) {
            throw new DomainException('The selected source artwork is no longer available.');
        }

        $absoluteSource = $sourceDisk->path($source->sourcePath);
        $extension = $this->validatedExtension($absoluteSource);
        $expectedSize = filesize($absoluteSource);

        if (! is_int($expectedSize) || $expectedSize < 1 || $expectedSize > self::MAX_BYTES) {
            throw new DomainException('The selected source artwork exceeds the Journal cover size limit.');
        }

        $expectedHash = hash_file('sha256', $absoluteSource);

        if (! is_string($expectedHash)) {
            throw new RuntimeException('Unable to verify the selected source artwork.');
        }

        $destination = sprintf(
            'posts/covers/source-%s-%d-%s.%s',
            $source->sourceType->value,
            $source->sourceId,
            Str::uuid(),
            $extension,
        );
        $private = Storage::disk('local');
        $stream = $sourceDisk->readStream($source->sourcePath);

        if (! is_resource($stream)) {
            throw new RuntimeException('Unable to read the selected source artwork.');
        }

        try {
            if (! $private->writeStream($destination, $stream)) {
                throw new RuntimeException('Unable to create the private Journal cover.');
            }
        } catch (\Throwable $exception) {
            $private->delete($destination);

            throw $exception;
        } finally {
            fclose($stream);
        }

        try {
            $absoluteDestination = $private->path($destination);
            $actualSize = is_file($absoluteDestination) ? filesize($absoluteDestination) : false;
            $actualHash = is_file($absoluteDestination) ? hash_file('sha256', $absoluteDestination) : false;

            if (
                $actualSize !== $expectedSize
                || ! is_string($actualHash)
                || ! hash_equals($expectedHash, $actualHash)
                || $this->validatedExtension($absoluteDestination) !== $extension
            ) {
                throw new RuntimeException('The private Journal cover could not be verified.');
            }
        } catch (\Throwable $exception) {
            $private->delete($destination);

            throw $exception;
        }

        return $destination;
    }

    /**
     * Remove only a just-created copy after its database transaction failed.
     * Never use this method as lifecycle cleanup for a committed Journal cover.
     */
    public function discardUncommitted(?string $path): bool
    {
        if (! is_string($path) || ! $this->isManagedCopy($path)) {
            return false;
        }

        if (Post::query()->withTrashed()->where('cover_image_path', $path)->exists()) {
            return false;
        }

        if (PostRevision::query()->where('snapshot->content->cover_image_path', $path)->exists()) {
            return false;
        }

        $private = Storage::disk('local');

        if (! $private->exists($path)) {
            return false;
        }

        if (! $private->delete($path) && $private->exists($path)) {
            throw new RuntimeException('Unable to discard the uncommitted Journal cover.');
        }

        return true;
    }

    public function cleanup(?string $path): bool
    {
        return $this->discardUncommitted($path);
    }

    private function validatedExtension(string $absolutePath): string
    {
        $image = is_file($absolutePath) ? @getimagesize($absolutePath) : false;

        if (! is_array($image)
            || ! isset($image[0], $image[1], $image[2])
            || (int) $image[0] < 1
            || (int) $image[1] < 1
            || (int) $image[0] > intdiv(self::MAX_PIXELS, (int) $image[1])) {
            throw new DomainException('Journal source artwork must be a safe image no larger than 20 megapixels.');
        }

        return match ((int) $image[2]) {
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_WEBP => 'webp',
            default => throw new DomainException('Journal source artwork must be a JPEG, PNG, or WebP image.'),
        };
    }

    private function isManagedCopy(string $path): bool
    {
        return preg_match(
            '/\Aposts\/covers\/source-(?:artwork|collection|album|playlist|track)-[1-9][0-9]*-[0-9a-f-]{36}\.(?:jpg|png|webp)\z/',
            $path,
        ) === 1;
    }
}
