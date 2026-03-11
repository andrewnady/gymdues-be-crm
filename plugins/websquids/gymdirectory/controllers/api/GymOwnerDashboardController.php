<?php

namespace Websquids\Gymdirectory\Controllers\Api;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Winter\User\Models\User;
use websquids\Gymdirectory\Models\GymClaimRequest;
use websquids\Gymdirectory\Models\GymTeamMember;
use websquids\Gymdirectory\Models\Address;
use websquids\Gymdirectory\Models\Review;

/**
 * GymOwnerDashboardController
 *
 * Provides data for the gym owner dashboard.
 * All endpoints require GymOwnerAuthMiddleware (Bearer session token).
 *
 * Endpoints
 * ---------
 *   GET  /api/v1/gym-owner/dashboard
 *        Full dashboard snapshot: owner profile, gym details, addresses,
 *        review stats, and recent reviews.
 */
class GymOwnerDashboardController extends Controller
{
    /**
     * GET /api/v1/gym-owner/dashboard
     *
     * Returns everything the frontend dashboard needs in a single request.
     */
    public function index(Request $request)
    {
        $userId = $request->input('gym_owner_user_id');

        $user = User::find($userId);

        // Owner path — user has an approved claim
        $claim = GymClaimRequest::where('user_id', $userId)
            ->where('status', GymClaimRequest::STATUS_APPROVED)
            ->whereNull('deleted_at')
            ->with('gym')
            ->latest()
            ->first();

        // Team member path — fall back to accepted team membership
        if (!$claim || !$claim->gym) {
            $membership = GymTeamMember::where('user_id', $userId)
                ->where('status', GymTeamMember::STATUS_ACCEPTED)
                ->whereNull('deleted_at')
                ->with('gym')
                ->latest()
                ->first();

            if (!$membership || !$membership->gym) {
                return response()->json([
                    'success' => false,
                    'message' => 'No active gym found for this account.',
                ], 404);
            }

            $gym = $membership->gym;
        } else {
            $gym = $claim->gym;
        }

        // Eager-load all addresses with their sub-relations
        $gym->load([
            'addresses.contacts',
            'addresses.hours',
            'addresses.pricing',
        ]);

        // Review stats across all addresses of this gym
        $addressIds = $gym->addresses->pluck('id');

        $totalReviews   = Review::whereIn('address_id', $addressIds)->whereNull('deleted_at')->count();
        $approvedReviews = Review::whereIn('address_id', $addressIds)->whereNull('deleted_at')->approved()->count();
        $pendingReviews  = Review::whereIn('address_id', $addressIds)->whereNull('deleted_at')->pending()->count();
        $averageRating   = Review::whereIn('address_id', $addressIds)->whereNull('deleted_at')->approved()->avg('rating');

        // 5 most recent approved reviews
        $recentReviews = Review::whereIn('address_id', $addressIds)
            ->whereNull('deleted_at')
            ->approved()
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get(['id', 'address_id', 'rating', 'review', 'name', 'created_at']);

        return response()->json([
            'success' => true,

            'owner' => [
                'id'         => $user->id,
                'name'       => $user->name,
                'email'      => $user->email,
                'job_title'  => $claim->job_title ?? null,
                'phone'      => $claim->phone_number ?? null,
                'claimed_at' => $claim->verified_at ?? null,
                'role'       => $claim ? 'owner' : ($membership->role ?? 'manager'),
            ],

            'gym' => [
                'id'          => $gym->id,
                'name'        => $gym->name,
                'slug'        => $gym->slug,
                'description' => $gym->description ?? null,
                'logo'        => $gym->logo ? $gym->logo->getPath() : null,
                'featured_image' => $gym->featured_image ? $gym->featured_image->getPath() : null,
            ],

            'addresses' => $gym->addresses->map(function (Address $address) {
                return [
                    'id'         => $address->id,
                    'is_primary' => (bool) $address->is_primary,
                    'address'    => $address->address ?? null,
                    'city'       => $address->city ?? null,
                    'state'      => $address->state ?? null,
                    'zip'        => $address->zip ?? null,
                    'country'    => $address->country ?? null,
                    'latitude'   => $address->latitude ?? null,
                    'longitude'  => $address->longitude ?? null,
                    'contacts'   => $address->contacts->map(fn($c) => [
                        'type'  => $c->type,
                        'value' => $c->value,
                    ]),
                    'hours'    => $address->hours->map(fn($h) => [
                        'day'        => $h->day,
                        'open_time'  => $h->open_time,
                        'close_time' => $h->close_time,
                        'is_closed'  => (bool) ($h->is_closed ?? false),
                    ]),
                    'pricing' => $address->pricing->map(fn($p) => [
                        'id'          => $p->id,
                        'name'        => $p->name,
                        'price'       => $p->price,
                        'period'      => $p->period ?? null,
                        'description' => $p->description ?? null,
                    ]),
                ];
            }),

            'reviews' => [
                'total'    => $totalReviews,
                'approved' => $approvedReviews,
                'pending'  => $pendingReviews,
                'average_rating' => $averageRating ? round((float) $averageRating, 1) : null,
                'recent'   => $recentReviews,
            ],
        ]);
    }
}
