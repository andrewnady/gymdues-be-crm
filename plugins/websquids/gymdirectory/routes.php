<?php

use Illuminate\Support\Facades\Route;
use websquids\Gymdirectory\Classes\ApiKeyMiddleware;
use Websquids\Gymdirectory\Controllers\Api\GymsController;
use Websquids\Gymdirectory\Controllers\Api\ReviewsController;
use Websquids\Gymdirectory\Controllers\Api\StaticPagesController;

Route::prefix('api/v1')
  ->middleware([ApiKeyMiddleware::class])
  ->group(function () {
    Route::get('gyms', [GymsController::class, 'index']);
    Route::post('gyms', [GymsController::class, 'store']);
    Route::get('gyms/{slug}', [GymsController::class, 'show']);
    
    // Reviews routes
    Route::get('reviews', [ReviewsController::class, 'index']);
    Route::get('reviews/{id}', [ReviewsController::class, 'show']);
    
    // Static pages routes
    Route::get('static-pages', [StaticPagesController::class, 'index']);
    Route::get('static-pages/{slug}', [StaticPagesController::class, 'show']);
  });
