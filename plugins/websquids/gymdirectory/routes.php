<?php

use websquids\Gymdirectory\Models\Gym;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->group(function () {

  // 1. GET ALL GYMS (Read)
  Route::get('gyms', function () {
    // Fetch all gyms with their Pricing Tiers and Images loaded
    return Gym::with(['hours', 'reviews', 'faqs', 'logo', 'gallery'])->get();
  });

  // 2. GET SINGLE GYM
  Route::get('gyms/{id}', function ($id) {
    return Gym::with(['hours', 'reviews', 'faqs', 'pricing', 'logo', 'gallery'])->find($id);
  });
});
