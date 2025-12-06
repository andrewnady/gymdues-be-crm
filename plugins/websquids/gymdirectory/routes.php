<?php

use websquids\Gymdirectory\Models\Gym;
use Illuminate\Support\Facades\Route;
use websquids\Gymdirectory\Classes\ApiKeyMiddleware;

Route::prefix('api/v1')
  ->middleware([ApiKeyMiddleware::class])
  ->group(function () {

    // 1. GET ALL GYMS (Read)
    Route::get('gyms', function () {
      // Fetch all gyms with their Pricing Tiers and Images loaded
      $gyms = Gym::with(['logo', 'gallery'])
        ->withCount('reviews')
        ->withAvg('reviews', 'rate')
        ->filter(request()->all())
        ->paginate(10);

      // Transform the paginated results to include rating and reviewCount
      $gyms->getCollection()->transform(function ($gym) {
        $gym->rating = $gym->reviews_avg_rate ? round((float)$gym->reviews_avg_rate, 2) : 0;
        $gym->reviewCount = $gym->reviews_count ?? 0;
        // Only show specific fields in the response
        $gym->setVisible(['id', 'slug', 'trending', 'name', 'description', 'city', 'state', 'rating', 'reviewCount', 'logo', 'gallery']);
        return $gym;
      });

      return $gyms;
    });

    // 2. GET SINGLE GYM
    Route::get('gyms/{slug}', function ($slug) {
      return Gym::with(['hours', 'reviews', 'faqs', 'pricing', 'logo', 'gallery'])
        ->withAvg('reviews as rating', 'rate')
        ->where('slug', $slug)
        ->firstOrFail();
    });
  });
