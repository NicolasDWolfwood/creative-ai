<?php

use App\Http\Controllers\DiscoveryController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\ShowcaseController;
use Illuminate\Support\Facades\Route;

Route::get('/', [ShowcaseController::class, 'index'])->name('home');
Route::get('/gallery', [ShowcaseController::class, 'gallery'])->name('gallery');
Route::get('/collections/{collection:slug}', [ShowcaseController::class, 'collection'])->name('collections.show');
Route::get('/journal', [PostController::class, 'index'])->name('posts.index');
Route::get('/journal/{post:slug}', [PostController::class, 'show'])->name('posts.show');
Route::get('/robots.txt', [DiscoveryController::class, 'robots'])->name('robots');
Route::get('/sitemap.xml', [DiscoveryController::class, 'sitemap'])->name('sitemap');
Route::get('/feed.xml', [DiscoveryController::class, 'feed'])->name('feed');
Route::get('/ready', [HealthController::class, 'ready'])->name('ready');
