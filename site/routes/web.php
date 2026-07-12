<?php

use App\Http\Controllers\DiscoveryController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\MusicController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\ShowcaseController;
use Illuminate\Support\Facades\Route;

Route::get('/', [ShowcaseController::class, 'index'])->name('home');
Route::get('/gallery', [ShowcaseController::class, 'gallery'])->name('gallery');
Route::get('/music', [MusicController::class, 'index'])->name('music.index');
Route::get('/music/albums/{album:slug}', [MusicController::class, 'album'])->name('music.albums.show');
Route::get('/music/tracks/{track:slug}', [MusicController::class, 'track'])->name('music.tracks.show');
Route::get('/artworks/{artwork:slug}/image', [MediaController::class, 'artwork'])->defaults('variant', 'original')->name('artworks.image');
Route::get('/media/artworks/{artwork}/{variant}', [MediaController::class, 'artwork'])->whereIn('variant', ['original', 'display', 'thumb'])->name('media.artworks.show');
Route::get('/media/tracks/{track}/audio', [MediaController::class, 'trackAudio'])->name('media.tracks.audio');
Route::get('/media/albums/{album}/embedded-cover', [MediaController::class, 'albumEmbeddedCover'])->name('media.albums.embedded-cover');
Route::get('/media/posts/{post}/cover', [MediaController::class, 'postCover'])->name('media.posts.cover');
Route::get('/collections/{collection:slug}', [ShowcaseController::class, 'collection'])->name('collections.show');
Route::get('/journal', [PostController::class, 'index'])->name('posts.index');
Route::get('/journal/{post:slug}', [PostController::class, 'show'])->name('posts.show');
Route::get('/robots.txt', [DiscoveryController::class, 'robots'])->name('robots');
Route::get('/sitemap.xml', [DiscoveryController::class, 'sitemap'])->name('sitemap');
Route::get('/feed.xml', [DiscoveryController::class, 'feed'])->name('feed');
Route::get('/ready', [HealthController::class, 'ready'])->middleware('throttle:60,1')->name('ready');
