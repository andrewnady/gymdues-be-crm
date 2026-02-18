<?php

namespace Websquids\Gymdirectory\Controllers\Api;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;
use websquids\Gymdirectory\Models\Review;

class ReviewsController extends Controller {
  /**
   * GET /api/v1/reviews
   * List all reviews with filters and pagination
   */
  public function index(Request $request) {
    // Query reviews with gym relationship - only show approved by default
    $query = Review::with(['address.gym'])
      ->approved()
      ->whereNotNull('text')
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
   * POST /api/v1/reviews
   * Submit a new review (requires admin approval)
   */
  public function store(Request $request) {
    try {
      $validator = Validator::make($request->all(), [
        'name'       => 'required|string|max:255',
        'rate'       => 'required|integer|min:1|max:5',
        'text'       => 'required|string|max:5000',
        'address_id' => 'required|integer|exists:websquids_gymdirectory_addresses,id',
        'email'      => 'nullable|email|max:255',
      ]);

      if ($validator->fails()) {
        return response()->json([
          'success' => false,
          'errors'  => $validator->errors(),
        ], 422);
      }

      $review = Review::create([
        'reviewer'    => $request->input('name'),
        'rate'        => $request->input('rate'),
        'text'        => $request->input('text'),
        'address_id'  => $request->input('address_id'),
        'email'       => $request->input('email'),
        'status'      => 'pending',
        'source'      => 'user',
        'reviewed_at' => now(),
      ]);

      return response()->json([
        'success' => true,
        'message' => 'Review submitted successfully. It will be visible after admin approval.',
        'data'    => [
          'id'     => $review->id,
          'status' => $review->status,
        ],
      ], 201);
    } catch (\Exception $e) {
      Log::error('Review store error: ' . $e->getMessage());
      return response()->json([
        'success' => false,
        'message' => 'An error occurred while submitting the review.',
      ], 500);
    }
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
