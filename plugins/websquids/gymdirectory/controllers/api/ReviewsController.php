<?php

namespace Websquids\Gymdirectory\Controllers\Api;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use websquids\Gymdirectory\Models\Review;

class ReviewsController extends Controller {
  /**
   * GET /api/v1/reviews
   * List all reviews with filters and pagination
   */
  public function index(Request $request) {
    // Query reviews with gym relationship
    $query = Review::with(['address.gym'])
      ->orderBy('reviewed_at', 'desc');

    // Filter by gym slug
    if ($request->has('gym_slug')) {
      $query->whereHas('address.gym', function ($q) use ($request) {
        $q->where('slug', $request->input('gym_slug'));
      });
    }

    // Filter by gym ID
    if ($request->has('gym_id')) {
      $query->whereHas('address.gym', function ($q) use ($request) {
        $q->where('id', $request->input('gym_id'));
      });
    }

    // Filter by minimum rate
    if ($request->has('min_rate')) {
      $query->where('rate', '>=', $request->input('min_rate'));
    }

    // Filter by maximum rate
    if ($request->has('max_rate')) {
      $query->where('rate', '<=', $request->input('max_rate'));
    }

    // Pagination
    $perPage = $request->input('per_page', 10);
    $reviews = $query->paginate($perPage);

    // Transform data
    $reviews->getCollection()->transform(function ($review) {
      return $this->transformReview($review);
    });

    return $reviews;
  }

  /**
   * GET /api/v1/reviews/{id}
   * Get single review details
   */
  public function show($id) {
    $review = Review::with(['address.gym'])
      ->findOrFail($id);

    return $this->transformReview($review);
  }

  /**
   * Transform review data for API response
   */
  private function transformReview($review) {
    $gym = $review->address->gym;

    return [
      'id' => $review->id,
      'rate' => $review->rate ?? 0,
      'reviewer' => $review->reviewer ?? '',
      'reviewed_at' => $review->reviewed_at,
      'comment' => $review->text ?? '',
      'gym' => $gym ? [
        'id' => $gym->id,
        'name' => $gym->name ?? '',
        'slug' => $gym->slug ?? '',
        'logo' => $gym->logo?->path ?? '',
      ] : [],
    ];
  }
}
