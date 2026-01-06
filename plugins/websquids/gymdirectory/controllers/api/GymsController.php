<?php

namespace Websquids\Gymdirectory\Controllers\Api;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use websquids\Gymdirectory\Models\Faq;
use Websquids\Gymdirectory\Models\Gym;
use websquids\Gymdirectory\Models\Hour;
use Websquids\Gymdirectory\Models\Pricing;
use websquids\Gymdirectory\Models\Review;

class GymsController extends Controller {
  /**
   * GET /api/v1/gyms
   * List all gyms with filters and pagination
   */
  public function index(Request $request) {
    // 1. Query & Filter
    $gyms = Gym::with(['logo', 'gallery'])
      ->withCount('reviews')
      ->withAvg('reviews', 'rate')
      ->filter($request->all()) // Uses the scopeFilter in your Model
      ->paginate(9);

    // 2. Transform Data (Add calculated rating, hide internal fields)
    $gyms->getCollection()->transform(function ($gym) {
      // Calculate Rating
      $gym->rating = $gym->reviews_avg_rate
        ? round((float)$gym->reviews_avg_rate, 2)
        : 0;

      $gym->reviewCount = $gym->reviews_count ?? 0;

      // Cleanup Output
      $gym->setVisible([
        'id',
        'slug',
        'trending',
        'name',
        'description',
        'city',
        'state',
        'rating',
        'reviewCount',
        'logo',
        'gallery'
      ]);

      return $gym;
    });

    return $gyms;
  }

  /**
   * GET /api/v1/gyms/{slug}
   * Get single gym details
   */
  public function show($slug) {
    return Gym::with(['hours', 'reviews', 'faqs', 'pricing', 'logo', 'gallery'])
      ->withAvg('reviews as rating', 'rate')
      ->where('slug', $slug)
      ->firstOrFail();
  }

  /**
   * POST /api/v1/gyms
   * Create a new gym
   */
  public function store(Request $request) {
    // Validate gym and all related records (hours, reviews, faqs, pricing)
    $data = $request->validate([
      // Gym
      'name'        => 'required|string|max:255',
      'description' => 'nullable|string',
      'city'        => 'required|string|max:255',
      'state'       => 'required|string|max:255',
      'trending'    => 'sometimes|boolean',

      // Hours (hasMany)
      'hours'            => 'sometimes|array',
      'hours.*.day'      => 'required_with:hours|string|in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
      'hours.*.from'     => 'required_with:hours|date_format:H:i',
      'hours.*.to'       => 'required_with:hours|date_format:H:i',

      // Reviews (hasMany)
      'reviews'              => 'sometimes|array',
      'reviews.*.reviewer'   => 'required_with:reviews|string|max:255',
      'reviews.*.rate'       => 'required_with:reviews|numeric|min:0|max:5',
      'reviews.*.text'       => 'nullable|string',
      'reviews.*.reviewed_at' => 'sometimes|date',

      // FAQs (hasMany)
      'faqs'               => 'sometimes|array',
      'faqs.*.category'    => 'required_with:faqs|string',
      'faqs.*.question'    => 'required_with:faqs|string',
      'faqs.*.answer'      => 'required_with:faqs|string',

      // Pricing (hasMany)
      'pricing'               => 'sometimes|array',
      'pricing.*.tier_name'   => 'required_with:pricing|string|max:255',
      'pricing.*.price'       => 'required_with:pricing|numeric|min:0',
      'pricing.*.frequency'   => 'required_with:pricing|string',
      'pricing.*.description' => 'nullable|string',
    ]);

    // Create Gym
    $gym = new Gym;
    $gym->name = $data['name'];
    $gym->description = $data['description'] ?? '';
    $gym->city = $data['city'] ?? '';
    $gym->state = $data['state'] ?? '';
    $gym->trending = $data['trending'] ?? false;
    $gym->save();

    // Create Hours
    if (!empty($data['hours']) && is_array($data['hours'])) {
      foreach ($data['hours'] as $hourData) {
        $hour = new Hour;
        $hour->day = $hourData['day'] ?? 'monday';
        $hour->from = $hourData['from'] ?? '09:00';
        $hour->to = $hourData['to'] ?? '17:00';
        $hour->gym_id = $gym->id;
        $hour->save();
      }
    }

    // Create Reviews
    if (!empty($data['reviews']) && is_array($data['reviews'])) {
      foreach ($data['reviews'] as $reviewData) {
        $review = new Review;
        $review->reviewer = $reviewData['reviewer'] ?? '';
        $review->rate = $reviewData['rate'] ?? 0;
        $review->text = $reviewData['text'] ?? '';
        $review->reviewed_at = $reviewData['reviewed_at'] ?? now();
        $review->gym_id = $gym->id;
        $review->save();
      }
    }

    // Create FAQs
    if (!empty($data['faqs']) && is_array($data['faqs'])) {
      foreach ($data['faqs'] as $faqData) {
        $faq = new Faq;
        $faq->category = $faqData['category'] ?? 'general';
        $faq->question = $faqData['question'] ?? '';
        $faq->answer = $faqData['answer'] ?? '';
        $faq->gym_id = $gym->id;
        $faq->save();
      }
    }

    // Create Pricing Tiers
    if (!empty($data['pricing']) && is_array($data['pricing'])) {
      foreach ($data['pricing'] as $tier) {
        $price = new Pricing;
        $price->tier_name = $tier['tier_name'] ?? 'Standard';
        $price->price = $tier['price'] ?? '0';
        $price->frequency = $tier['frequency'] ?? 'month';
        $price->description = $tier['description'] ?? '';
        $price->gym_id = $gym->id;
        $price->save();
      }
    }

    return response()->json([
      'message' => 'Gym Created Successfully',
      'id' => $gym->id,
      'slug' => $gym->slug
    ], 201);
  }
}
