<?php

namespace App\Services;

use App\Models\Album;
use App\Models\Artwork;
use Illuminate\Support\Facades\Storage;

class AlbumCoverService
{
    public function import(Album $album): Artwork
    {
        abort_unless(filled($album->embedded_cover_path) && app(PrivateMediaService::class)->sourceDisk($album->embedded_cover_path)->exists($album->embedded_cover_path), 422, 'No embedded cover is available.');
        $extension = pathinfo($album->embedded_cover_path, PATHINFO_EXTENSION) ?: 'jpg';
        $path = 'artworks/originals/album-'.$album->id.'-'.bin2hex(random_bytes(5)).'.'.$extension;
        $contents = app(PrivateMediaService::class)->sourceDisk($album->embedded_cover_path)->get($album->embedded_cover_path);
        Storage::disk('local')->put($path, $contents);
        $artwork = Artwork::create(['title' => $album->title.' cover', 'image_path' => $path, 'original_filename' => basename($album->embedded_cover_path), 'published' => false]);
        $album->update(['cover_artwork_id' => $artwork->id, 'cover_preference' => 'artwork']);

        return $artwork;
    }
}
