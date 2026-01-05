<?php

use Illuminate\Support\Facades\Route;
use websquids\Gymdirectory\Classes\ApiKeyMiddleware;
use Winter\Blog\Controllers\Api\BlogController;

Route::prefix('api/v1')
  ->middleware([ApiKeyMiddleware::class])
  ->group(function () {
    // Blog routes
    Route::get('posts', [BlogController::class, 'index']);
    Route::post('posts', [BlogController::class, 'store']);
    Route::get('posts/{slug}', [BlogController::class, 'show']);
  });

