<?php

use App\Http\Controllers\ShowcaseController;
use Illuminate\Support\Facades\Route;

Route::get('/', [ShowcaseController::class, 'index'])->name('home');
Route::get('/gallery', [ShowcaseController::class, 'gallery'])->name('gallery');
Route::get('/collections/{collection:slug}', [ShowcaseController::class, 'collection'])->name('collections.show');
