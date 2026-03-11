<?php

namespace Websquids\Gymdirectory\Controllers\Api;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use websquids\Gymdirectory\Models\Gym;
use websquids\Gymdirectory\Models\Address;
use websquids\Gymdirectory\Models\GymClaimRequest;
use websquids\Gymdirectory\Models\Pricing;
use websquids\Gymdirectory\Models\Hour;
use websquids\Gymdirectory\Models\Review;

/**
 * GymOwnerProfileController
 *
 * Allows an authenticated gym owner to manage their gym's public profile.
 * All endpoints require GymOwnerAuthMiddleware (Bearer session token).
 *
 * Gym description and photos are gym-level (shared across all locations).
 * Pricing, hours, and reviews are scoped to a specific location (address_id).
 *
 * Endpoints
 * ---------
 *   GET    /api/v1/gym-owner/locations
 *   GET    /api/v1/gym-owner/profile/description
 *   PUT    /api/v1/gym-owner/profile/description
 *   GET    /api/v1/gym-owner/profile/photos
 *   POST   /api/v1/gym-owner/profile/photos
 *   DELETE /api/v1/gym-owner/profile/photos/{id}
 *   GET    /api/v1/gym-owner/locations/{address_id}/pricing
 *   POST   /api/v1/gym-owner/locations/{address_id}/pricing
 *   PUT    /api/v1/gym-owner/locations/{address_id}/pricing/{plan_id}
 *   DELETE /api/v1/gym-owner/locations/{address_id}/pricing/{plan_id}
 *   GET    /api/v1/gym-owner/locations/{address_id}/hours
 *   PUT    /api/v1/gym-owner/locations/{address_id}/hours
 *   GET    /api/v1/gym-owner/locations/{address_id}/reviews
 *   POST   /api/v1/gym-owner/reviews/{id}/respond
 */
class GymOwnerProfileController extends Controller
{
    // =========================================================================
    // Locations listing (address selector for the dashboard)
    // =========================================================================

    /**
     * GET /api/v1/gym-owner/locations
     *
     * Returns all addresses (locations) for the owner's gym so the frontend
     * can render a location picker before showing per-location data.
     */
    public function getLocations(Request $request)
    {
        $gym = $this->resolveGym($request);
        if (!$gym) {
            return $this->noGym();
        }

        $addresses = $gym->addresses()
            ->whereNull('deleted_at')
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get();

        return response()->json([
            'success'   => true,
            'gym_id'    => $gym->id,
            'gym_name'  => $gym->name,
            'locations' => $addresses->map(fn(Address $a) => [
                'id'         => $a->id,
                'is_primary' => (bool) $a->is_primary,
                'address'    => $a->address ?? null,
                'city'       => $a->city ?? null,
                'state'      => $a->state ?? null,
                'zip'        => $a->zip ?? null,
                'country'    => $a->country ?? null,
            ]),
        ]);
    }

    // =========================================================================
    // Description (gym-level — common across all locations)
    // =========================================================================

    /**
     * GET /api/v1/gym-owner/profile/description
     */
    public function getDescription(Request $request)
    {
        $gym = $this->resolveGym($request);
        if (!$gym) {
            return $this->noGym();
        }

        return response()->json([
            'success'     => true,
            'gym_id'      => $gym->id,
            'name'        => $gym->name,
            'description' => $gym->description ?? '',
        ]);
    }

    /**
     * PUT /api/v1/gym-owner/profile/description
     *
     * Body: { "description": "..." }
     */
    public function updateDescription(Request $request)
    {
        $request->validate([
            'description' => 'required|string|max:5000',
        ]);

        $gym = $this->resolveGym($request);
        if (!$gym) {
            return $this->noGym();
        }

        $gym->description = $request->input('description');
        $gym->save();

        return response()->json([
            'success'     => true,
            'message'     => 'Gym description updated successfully.',
            'description' => $gym->description,
        ]);
    }

    // =========================================================================
    // Photos (gym-level — gallery shared across all locations)
    // =========================================================================

    /**
     * GET /api/v1/gym-owner/profile/photos
     */
    public function getPhotos(Request $request)
    {
        $gym = $this->resolveGym($request);
        if (!$gym) {
            return $this->noGym();
        }

        $gym->load('gallery');

        $photos = $gym->gallery->map(fn($file) => [
            'id'         => $file->id,
            'file_name'  => $file->file_name,
            'url'        => $file->getPath(),
            'thumb_url'  => $file->getThumb(400, 300),
            'created_at' => $file->created_at,
        ]);

        return response()->json([
            'success' => true,
            'gym_id'  => $gym->id,
            'photos'  => $photos,
        ]);
    }

    /**
     * POST /api/v1/gym-owner/profile/photos
     *
     * Multipart: photos[] (jpeg/png/gif/webp, max 5 MB each, up to 10 at a time)
     */
    public function uploadPhotos(Request $request)
    {
        $request->validate([
            'photos'   => 'required|array|max:10',
            'photos.*' => 'required|file|mimes:jpeg,jpg,png,gif,webp|max:5120',
        ]);

        $gym = $this->resolveGym($request);
        if (!$gym) {
            return $this->noGym();
        }

        $uploaded = [];

        foreach ($request->file('photos') as $file) {
            try {
                $fileModel = new \System\Models\File();
                $fileModel->fromPost($file);
                $gym->gallery()->add($fileModel);

                $uploaded[] = [
                    'id'        => $fileModel->id,
                    'file_name' => $fileModel->file_name,
                    'url'       => $fileModel->getPath(),
                    'thumb_url' => $fileModel->getThumb(400, 300),
                ];
            } catch (\Exception $e) {
                Log::error('GymOwnerProfileController@uploadPhotos: ' . $e->getMessage());
            }
        }

        return response()->json([
            'success'  => true,
            'message'  => count($uploaded) . ' photo(s) uploaded successfully.',
            'uploaded' => $uploaded,
        ], 201);
    }

    /**
     * DELETE /api/v1/gym-owner/profile/photos/{id}
     */
    public function deletePhoto(Request $request, $id)
    {
        $gym = $this->resolveGym($request);
        if (!$gym) {
            return $this->noGym();
        }

        $gym->load('gallery');

        $file = $gym->gallery->firstWhere('id', (int) $id);
        if (!$file) {
            return response()->json(['success' => false, 'message' => 'Photo not found.'], 404);
        }

        $file->delete();

        return response()->json([
            'success' => true,
            'message' => 'Photo deleted successfully.',
        ]);
    }

    // =========================================================================
    // Pricing / Membership Plans (per location)
    // =========================================================================

    /**
     * GET /api/v1/gym-owner/locations/{address_id}/pricing
     */
    public function getPricing(Request $request, $addressId)
    {
        $pricing = Pricing::where('address_id', $addressId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get(['id', 'tier_name', 'price', 'frequency', 'description']);

        return response()->json([
            'success'    => true,
            'address_id' => $addressId,
            'pricing'    => $pricing,
        ]);
    }

    /**
     * POST /api/v1/gym-owner/locations/{address_id}/pricing
     *
     * Body: { "tier_name": "Monthly", "price": 49.99, "frequency": "month", "description": "..." }
     */
    public function addPricing(Request $request, $addressId)
    {
        $request->validate([
            'tier_name'   => 'required|string|max:255',
            'price'       => 'required|numeric|min:0',
            'frequency'   => 'nullable|string|max:100',
            'description' => 'nullable|string|max:1000',
        ]);

        $plan = Pricing::create([
            'address_id'  => $addressId,
            'tier_name'   => $request->input('tier_name'),
            'price'       => $request->input('price'),
            'frequency'   => $request->input('frequency'),
            'description' => $request->input('description'),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Pricing plan added successfully.',
            'plan'    => [
                'id'          => $plan->id,
                'tier_name'   => $plan->tier_name,
                'price'       => $plan->price,
                'frequency'   => $plan->frequency,
                'description' => $plan->description,
            ],
        ], 201);
    }

    /**
     * PUT /api/v1/gym-owner/locations/{address_id}/pricing/{plan_id}
     *
     * Body: { "tier_name": "...", "price": ..., "frequency": "...", "description": "..." }
     */
    public function updatePricing(Request $request, $addressId, $planId)
    {
        $request->validate([
            'tier_name'        => 'sometimes|required|string|max:255',
            'price'       => 'sometimes|required|numeric|min:0',
            'frequency'      => 'nullable|string|max:100',
            'description' => 'nullable|string|max:1000',
        ]);

        $plan = Pricing::where('address_id', $addressId)
            ->whereNull('deleted_at')
            ->find($planId);

        if (!$plan) {
            return response()->json(['success' => false, 'message' => 'Pricing plan not found.'], 404);
        }

        if ($request->has('tier_name'))   $plan->tier_name        = $request->input('name');
        if ($request->has('price'))       $plan->price       = $request->input('price');
        if ($request->has('frequency'))   $plan->frequency      = $request->input('period');
        if ($request->has('description')) $plan->description = $request->input('description');
        $plan->save();

        return response()->json([
            'success' => true,
            'message' => 'Pricing plan updated successfully.',
            'plan'    => [
                'id'          => $plan->id,
                'tier_name'   => $plan->tier_name,
                'price'       => $plan->price,
                'frequency'   => $plan->frequency,
                'description' => $plan->description,
            ],
        ]);
    }

    /**
     * DELETE /api/v1/gym-owner/locations/{address_id}/pricing/{plan_id}
     */
    public function deletePricing(Request $request, $addressId, $planId)
    {
        $plan = Pricing::where('address_id', $addressId)
            ->whereNull('deleted_at')
            ->find($planId);

        if (!$plan) {
            return response()->json(['success' => false, 'message' => 'Pricing plan not found.'], 404);
        }

        $plan->delete();

        return response()->json([
            'success' => true,
            'message' => 'Pricing plan deleted successfully.',
        ]);
    }

    // =========================================================================
    // Operating Hours (per location)
    // =========================================================================

    /**
     * GET /api/v1/gym-owner/locations/{address_id}/hours
     */
    public function getHours(Request $request, $addressId)
    {
        $hours = Hour::where('address_id', $addressId)
            ->whereNull('deleted_at')
            ->orderByRaw("FIELD(day, 'monday','tuesday','wednesday','thursday','friday','saturday','sunday')")
            ->get(['id', 'day', 'from', 'to']);

        return response()->json([
            'success'    => true,
            'address_id' => $addressId,
            'hours'      => $hours->map(fn($h) => [
                'id'         => $h->id,
                'day'        => $h->day,
                'from'       => $h->from,
                'to'         => $h->to,
            ]),
        ]);
    }

    /**
     * PUT /api/v1/gym-owner/locations/{address_id}/hours
     *
     * Body:
     * {
     *   "hours": [
     *     { "day": "monday", "from": "06:00", "to": "22:00" },
     *     { "day": "sunday" },
     *     ...
     *   ]
     * }
     *
     * Upserts each day: creates if not present, updates if already exists.
     */
    public function updateHours(Request $request, $addressId)
    {
        $request->validate([
            'hours'              => 'required|array|min:1',
            'hours.*.day'        => 'required|string|in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
            'hours.*.from'  => 'nullable|string|max:10',
            'hours.*.to' => 'nullable|string|max:10',
        ]);

        $saved = [];

        foreach ($request->input('hours') as $entry) {

            $hour = Hour::where('address_id', $addressId)
                ->where('day', $entry['day'])
                ->whereNull('deleted_at')
                ->first();

            if ($hour) {
                $hour->from  = $entry['from'] ?? $hour->from;
                $hour->to = $entry['to'] ?? $hour->to;
                $hour->save();
            } else {
                $hour = Hour::create([
                    'address_id' => $addressId,
                    'day'        => $entry['day'],
                    'from'  => $entry['from'] ?? null,
                    'to' => $entry['to'] ?? null,
                ]);
            }

            $saved[] = [
                'id'         => $hour->id,
                'day'        => $hour->day,
                'from'       => $hour->from,
                'to'         => $hour->to,
            ];
        }

        return response()->json([
            'success' => true,
            'message' => 'Operating hours updated successfully.',
            'hours'   => $saved,
        ]);
    }

    // =========================================================================
    // Reviews (per location)
    // =========================================================================

    /**
     * GET /api/v1/gym-owner/locations/{address_id}/reviews
     *
     * Query params:
     *   status   string  all|pending|approved  (default: all)
     *   per_page int     (default: 15)
     *   page     int
     */
    public function getReviews(Request $request, $addressId)
    {
        $query = Review::where('address_id', $addressId)
            ->whereNull('deleted_at')
            ->orderBy('created_at', 'desc');

        $status = $request->input('status', 'all');
        if ($status === 'pending') {
            $query->pending();
        } elseif ($status === 'approved') {
            $query->approved();
        }

        $perPage = min((int) $request->input('per_page', 15), 100);
        $reviews = $query->paginate($perPage);

        $reviews->getCollection()->transform(fn($r) => [
            'id'                 => $r->id,
            'reviewer'           => $r->reviewer ?? '',
            'rating'             => $r->rate ?? 0,
            'text'               => $r->text ?? '',
            'status'             => $r->status ?? 'approved',
            'source'             => $r->source ?? 'google',
            'reviewed_at'        => $r->reviewed_at,
            'created_at'         => $r->created_at,
            'owner_response'     => $r->owner_response ?? null,
            'owner_responded_at' => $r->owner_responded_at ?? null,
        ]);

        return response()->json([
            'success' => true,
            'data'    => $reviews->items(),
            'meta'    => [
                'total'        => $reviews->total(),
                'per_page'     => $reviews->perPage(),
                'current_page' => $reviews->currentPage(),
                'last_page'    => $reviews->lastPage(),
            ],
        ]);
    }

    /**
     * POST /api/v1/gym-owner/reviews/{id}/respond
     *
     * Body: { "response": "Thank you for your feedback..." }
     * Re-calling this endpoint updates the existing response.
     */
    public function respondToReview(Request $request, $reviewId)
    {
        $request->validate([
            'response' => 'required|string|max:2000',
        ]);

        $gym = $this->resolveGym($request);
        if (!$gym) {
            return $this->noGym();
        }

        // Ensure the review belongs to one of this owner's locations
        $addressIds = $gym->addresses()->whereNull('deleted_at')->pluck('id');

        $review = Review::whereIn('address_id', $addressIds)
            ->whereNull('deleted_at')
            ->find($reviewId);

        if (!$review) {
            return response()->json(['success' => false, 'message' => 'Review not found.'], 404);
        }

        $review->owner_response     = $request->input('response');
        $review->owner_responded_at = now();
        $review->save();

        return response()->json([
            'success'            => true,
            'message'            => 'Response posted successfully.',
            'owner_response'     => $review->owner_response,
            'owner_responded_at' => $review->owner_responded_at,
        ]);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Resolve the gym owned by the authenticated user.
     */
    private function resolveGym(Request $request): ?Gym
    {
        $userId = $request->input('gym_owner_user_id');

        $claim = GymClaimRequest::where('user_id', $userId)
            ->where('status', GymClaimRequest::STATUS_APPROVED)
            ->whereNull('deleted_at')
            ->latest()
            ->first();

        if (!$claim) {
            return null;
        }

        return Gym::whereNull('deleted_at')->find($claim->gym_id);
    }

    private function noGym()
    {
        return response()->json([
            'success' => false,
            'message' => 'No active gym found for this account.',
        ], 404);
    }
}
