<?php

use Illuminate\Support\Facades\Route;
use websquids\Gymdirectory\Classes\ApiKeyMiddleware;
use Websquids\Gymdirectory\Controllers\Api\GymsController;
use Websquids\Gymdirectory\Controllers\Api\ReviewsController;
use Websquids\Gymdirectory\Controllers\Api\StaticPagesController;
use Websquids\Gymdirectory\Controllers\Api\ContactSubmissionsController;
use Websquids\Gymdirectory\Controllers\Api\NewsletterSubscriptionsController;
use Websquids\Gymdirectory\Controllers\Api\BlogController;
use Websquids\Gymdirectory\Controllers\Api\CommentsController;

Route::prefix('api/v1')
  ->middleware([ApiKeyMiddleware::class])
  ->group(function () {
    Route::get('gyms', [GymsController::class, 'index']);
    Route::post('gyms', [GymsController::class, 'store']);
    Route::get('gyms/addresses-by-location', [GymsController::class, 'addressesByLocation']);
    Route::get('gyms/states', [GymsController::class, 'states']);
    Route::get('gyms/locations', [GymsController::class, 'locations']);
    Route::get('gyms/{gym_id}/addresses', [GymsController::class, 'addresses']);
    Route::get('addresses/{address_id}', [GymsController::class, 'address']);
    Route::get('gyms/cities-and-states', [GymsController::class, 'citiesAndStates']);
    Route::get('gyms/highly-rated', [GymsController::class, 'highlyRated']);
    Route::get('gyms/filtered-top-gyms', [GymsController::class, 'filteredTopGyms']);
    Route::get('gyms/{slug}', [GymsController::class, 'show']);
    
    // Reviews routes
    Route::get('reviews', [ReviewsController::class, 'index']);
    Route::post('reviews', [ReviewsController::class, 'store']);
    Route::get('reviews/{id}', [ReviewsController::class, 'show']);
    
    // Static pages routes
    Route::get('static-pages', [StaticPagesController::class, 'index']);
    Route::get('static-pages/{slug}', [StaticPagesController::class, 'show']);
    
    // Blog routes (registered here so they work on production; Winter Blog plugin may load after)
    Route::get('posts', [BlogController::class, 'index']);
    Route::get('posts/{slug}/comments', [CommentsController::class, 'index']);
    Route::post('posts/{slug}/comments', [CommentsController::class, 'store']);
    Route::get('posts/{slug}', [BlogController::class, 'show']);
    
    // Contact submissions routes
    Route::post('contact-submissions', [ContactSubmissionsController::class, 'store']);
    
    // Newsletter subscriptions routes
    Route::post('newsletter-subscriptions', [NewsletterSubscriptionsController::class, 'store']);
  });
