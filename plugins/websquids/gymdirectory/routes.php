<?php

use Illuminate\Support\Facades\Route;
use websquids\Gymdirectory\Classes\ApiKeyMiddleware;
use Websquids\Gymdirectory\Controllers\Api\GymsController;
use Websquids\Gymdirectory\Controllers\Api\GymClaimsController;
use Websquids\Gymdirectory\Controllers\Api\GymDisputesController;
use Websquids\Gymdirectory\Controllers\Api\GymOwnerAuthController;
use Websquids\Gymdirectory\Controllers\Api\GymOwnerDashboardController;
use Websquids\Gymdirectory\Controllers\Api\GymOwnerProfileController;
use Websquids\Gymdirectory\Controllers\Api\GymTeamController;
use websquids\Gymdirectory\Classes\GymOwnerAuthMiddleware;
use Websquids\Gymdirectory\Controllers\Api\GymsdataController;
use Websquids\Gymdirectory\Controllers\Api\ReviewsController;
use Websquids\Gymdirectory\Controllers\Api\StaticPagesController;
use Websquids\Gymdirectory\Controllers\Api\ContactSubmissionsController;
use Websquids\Gymdirectory\Controllers\Api\NewsletterSubscriptionsController;
use Websquids\Gymdirectory\Controllers\Api\BlogController;
use Websquids\Gymdirectory\Controllers\Api\CommentsController;

// Stripe webhook (no API key – Stripe calls this with signature)
Route::post('api/v1/webhooks/stripe/gymsdata-purchase', [GymsdataController::class, 'stripeWebhook']);

Route::prefix('api/v1')
  ->middleware([ApiKeyMiddleware::class])
  ->group(function () {

    // Gymsdata (second database) – list page / gymsdata.gymdues.com
    Route::prefix('gymsdata')->group(function () {
      Route::post('sample-download', [GymsdataController::class, 'sampleDownload']);
      Route::post('checkout', [GymsdataController::class, 'createCheckout']);
      Route::post('resend-purchase-email', [GymsdataController::class, 'resendPurchaseEmail']);
      Route::get('sitemap', [GymsdataController::class, 'sitemap']);
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
    Route::get('popular-gyms-state-city', [GymsController::class, 'popularGymsStateCity']);
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
    Route::get('gyms/filter-state',[GymsController::class,'filteredStateGyms']);
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

    // Gym claim routes
    Route::post('gym-claims/initiate', [GymClaimsController::class, 'initiate']);
    Route::get('gym-claims/{id}', [GymClaimsController::class, 'status'])->where('id', '[0-9]+');
    Route::post('gym-claims/{id}/send-email-code', [GymClaimsController::class, 'sendEmailCode'])->where('id', '[0-9]+');
    Route::post('gym-claims/{id}/verify-email', [GymClaimsController::class, 'verifyEmail'])->where('id', '[0-9]+');
    Route::post('gym-claims/{id}/send-phone-code', [GymClaimsController::class, 'sendPhoneCode'])->where('id', '[0-9]+');
    Route::post('gym-claims/{id}/verify-phone', [GymClaimsController::class, 'verifyPhone'])->where('id', '[0-9]+');
    Route::post('gym-claims/{id}/upload-document', [GymClaimsController::class, 'uploadDocument'])->where('id', '[0-9]+');

    // Gym dispute routes (filed when gym is already claimed)
    Route::post('gym-disputes', [GymDisputesController::class, 'initiate']);
    Route::get('gym-disputes/{id}', [GymDisputesController::class, 'status'])->where('id', '[0-9]+');
    Route::post('gym-disputes/{id}/upload-document', [GymDisputesController::class, 'uploadDocument'])->where('id', '[0-9]+');
    // Admin-only dispute resolution
    Route::post('gym-disputes/{id}/approve', [GymDisputesController::class, 'approve'])->where('id', '[0-9]+');
    Route::post('gym-disputes/{id}/reject', [GymDisputesController::class, 'reject'])->where('id', '[0-9]+');

    // Team invitation acceptance (public — uses magic token from invitation email)
    Route::post('gym-owner/team/accept-invite', [GymTeamController::class, 'acceptInvite']);

    // Gym owner authentication
    // Public endpoints
    Route::post('gym-owner/auth/magic-login', [GymOwnerAuthController::class, 'magicLogin']);
    Route::post('gym-owner/auth/login', [GymOwnerAuthController::class, 'login']);
    Route::post('gym-owner/auth/forgot-password', [GymOwnerAuthController::class, 'forgotPassword']);
    Route::post('gym-owner/auth/reset-password', [GymOwnerAuthController::class, 'resetPassword']);

    // Protected endpoints (require a valid session bearer token)
    Route::middleware(GymOwnerAuthMiddleware::class)->group(function () {
        Route::post('gym-owner/auth/set-password', [GymOwnerAuthController::class, 'setPassword']);
        Route::get('gym-owner/auth/me', [GymOwnerAuthController::class, 'me']);
        Route::post('gym-owner/auth/logout', [GymOwnerAuthController::class, 'logout']);

        Route::get('gym-owner/dashboard', [GymOwnerDashboardController::class, 'index']);

        // Gym owner profile — gym-level
        Route::get('gym-owner/locations', [GymOwnerProfileController::class, 'getLocations']);
        Route::get('gym-owner/profile/description', [GymOwnerProfileController::class, 'getDescription']);
        Route::put('gym-owner/profile/description', [GymOwnerProfileController::class, 'updateDescription']);
        Route::get('gym-owner/profile/photos', [GymOwnerProfileController::class, 'getPhotos']);
        Route::post('gym-owner/profile/photos', [GymOwnerProfileController::class, 'uploadPhotos']);
        Route::delete('gym-owner/profile/photos/{id}', [GymOwnerProfileController::class, 'deletePhoto'])->where('id', '[0-9]+');

        // Gym owner profile — per location (address)
        Route::get('gym-owner/locations/{address_id}/pricing', [GymOwnerProfileController::class, 'getPricing'])->where('address_id', '[0-9]+');
        Route::post('gym-owner/locations/{address_id}/pricing', [GymOwnerProfileController::class, 'addPricing'])->where('address_id', '[0-9]+');
        Route::put('gym-owner/locations/{address_id}/pricing/{plan_id}', [GymOwnerProfileController::class, 'updatePricing'])->where(['address_id' => '[0-9]+', 'plan_id' => '[0-9]+']);
        Route::delete('gym-owner/locations/{address_id}/pricing/{plan_id}', [GymOwnerProfileController::class, 'deletePricing'])->where(['address_id' => '[0-9]+', 'plan_id' => '[0-9]+']);
        Route::get('gym-owner/locations/{address_id}/hours', [GymOwnerProfileController::class, 'getHours'])->where('address_id', '[0-9]+');
        Route::put('gym-owner/locations/{address_id}/hours', [GymOwnerProfileController::class, 'updateHours'])->where('address_id', '[0-9]+');
        Route::get('gym-owner/locations/{address_id}/reviews', [GymOwnerProfileController::class, 'getReviews'])->where('address_id', '[0-9]+');

        // Review response (not location-scoped — the review ID is globally unique)
        Route::post('gym-owner/reviews/{id}/respond', [GymOwnerProfileController::class, 'respondToReview'])->where('id', '[0-9]+');

        // Team management (invite/list/revoke — owner only)
        Route::get('gym-owner/team', [GymTeamController::class, 'index']);
        Route::post('gym-owner/team/invite', [GymTeamController::class, 'invite']);
        Route::delete('gym-owner/team/{id}', [GymTeamController::class, 'revoke'])->where('id', '[0-9]+');
    });
  });
