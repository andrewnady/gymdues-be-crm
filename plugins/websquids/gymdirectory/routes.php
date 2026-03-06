<?php

use Illuminate\Support\Facades\Route;
use websquids\Gymdirectory\Classes\ApiKeyMiddleware;
use Websquids\Gymdirectory\Controllers\Api\GymsController;
use Websquids\Gymdirectory\Controllers\Api\GymsdataController;
use Websquids\Gymdirectory\Controllers\Api\ReviewsController;
use Websquids\Gymdirectory\Controllers\Api\StaticPagesController;
use Websquids\Gymdirectory\Controllers\Api\ContactSubmissionsController;
use Websquids\Gymdirectory\Controllers\Api\NewsletterSubscriptionsController;
use Websquids\Gymdirectory\Controllers\Api\BlogController;
use Websquids\Gymdirectory\Controllers\Api\CommentsController;

Route::prefix('api/v1')
  ->middleware([ApiKeyMiddleware::class])
  ->group(function () {

    // Gymsdata (second database) – list page / gymsdata.gymdues.com
    Route::prefix('gymsdata')->group(function () {
      Route::get('list-page', [GymsdataController::class, 'listPage']);
      Route::get('state-comparison', [GymsdataController::class, 'stateComparison']);
      Route::get('state-page/{state}', [GymsdataController::class, 'statePage'])->where('state', '[a-z0-9\-]+');
      Route::get('state-page', [GymsdataController::class, 'statePage']);
      Route::get('city-page/{state}/{city}', [GymsdataController::class, 'cityPage'])->where(['state' => '[a-z0-9\-]+', 'city' => '[a-z0-9\-]+']);
      Route::get('city-page', [GymsdataController::class, 'cityPage']);
      Route::get('chain-comparison', [GymsdataController::class, 'chainComparison']);
      Route::get('testimonials', [GymsdataController::class, 'testimonials']);
      Route::get('top-cities', [GymsdataController::class, 'topCities']);
      Route::get('industry-trends', [GymsdataController::class, 'industryTrends']);
      Route::get('states', [GymsdataController::class, 'states']);
      Route::get('locations', [GymsdataController::class, 'locations']);
      Route::get('cities-and-states', [GymsdataController::class, 'citiesAndStates']);
      Route::get('cities', [GymsdataController::class, 'cities']);
      Route::get('', [GymsdataController::class, 'index']);
    });

    Route::get('best-gyms-sitemaps', [GymsController::class, 'bestGymsSitemap']);
    Route::get('gyms', [GymsController::class, 'index']);
    Route::post('gyms', [GymsController::class, 'store']);
    Route::get('gyms/addresses-by-location', [GymsController::class, 'addressesByLocation']);
    Route::get('gyms/states', [GymsController::class, 'states']);
    Route::get('gyms/locations', [GymsController::class, 'locations']);
    Route::get('gyms/{gym_id}/addresses', [GymsController::class, 'addresses']);
    Route::get('addresses/{address_id}', [GymsController::class, 'address']);
    Route::get('gyms/cities-and-states', [GymsController::class, 'citiesAndStates']);
    Route::get('gyms/cities', [GymsController::class, 'cities']);
    Route::get('gyms/highly-rated', [GymsController::class, 'highlyRated']);
    Route::get('gyms/filter-state/{state}',[GymsController::class,'filteredStateGyms']);
    Route::get('gyms/filtered-top-gyms', [GymsController::class, 'filteredTopGyms']);
    Route::get('gyms/next-favourite-gyms', [GymsController::class, 'nextFavouriteGyms']);
    Route::get('gyms/{slug}/nearby', [GymsController::class, 'nearby']);
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
