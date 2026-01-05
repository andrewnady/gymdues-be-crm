<?php

use websquids\Gymdirectory\Models\Gym;
use Illuminate\Support\Facades\Route;
use websquids\Gymdirectory\Classes\ApiKeyMiddleware;
use Websquids\Gymdirectory\Controllers\Api\GymsController;
use Websquids\Gymdirectory\Controllers\Api\BlogController;

Route::prefix('api/v1')
  ->middleware([ApiKeyMiddleware::class])
  ->group(function () {
    Route::get('gyms', [GymsController::class, 'index']);
    Route::post('gyms', [GymsController::class, 'store']);
    Route::get('gyms/{slug}', [GymsController::class, 'show']);

    // Blog routes
    Route::get('posts', [BlogController::class, 'index']);
    Route::post('posts', [BlogController::class, 'store']);
    Route::get('posts/{slug}', [BlogController::class, 'show']);
  });
