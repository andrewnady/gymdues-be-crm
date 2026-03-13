<?php

namespace Websquids\Gymdirectory\Controllers\Api;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use websquids\Gymdirectory\Models\Gym;
use websquids\Gymdirectory\Models\GymClaimRequest;
use websquids\Gymdirectory\Models\GymClaimDispute;
use websquids\Gymdirectory\Classes\GymOwnerService;
use Winter\User\Models\User;
use Websquids\Gymdirectory\Jobs\SendDisputeReceivedEmailJob;
use Websquids\Gymdirectory\Jobs\SendDisputeApprovedEmailJob;
use Websquids\Gymdirectory\Jobs\SendDisputeRejectedEmailJob;
use Websquids\Gymdirectory\Jobs\SendClaimRevokedEmailJob;

/**
 * GymDisputesController
 *
 * Handles disputes filed against an already-approved gym claim.
 * A user who believes a gym was fraudulently claimed can file a dispute,
 * upload ownership evidence, and have our team review it within 48 hours.
 * If approved, the original claim is revoked and the disputer becomes owner.
 *
 * Endpoints
 * ---------
 *   POST  /api/v1/gym-disputes                   Initiate a dispute (collect owner info)
 *   POST  /api/v1/gym-disputes/{id}/upload-document  Upload ownership document
 *   GET   /api/v1/gym-disputes/{id}               Poll status
 *
 * Admin endpoints (same API-key middleware, intended for internal tooling)
 *   POST  /api/v1/gym-disputes/{id}/approve       Approve: transfer claim, revoke original
 *   POST  /api/v1/gym-disputes/{id}/reject         Reject the dispute
 */
class GymDisputesController extends Controller
{
    // =========================================================================
    // Initiate dispute
    // =========================================================================

    /**
     * POST /api/v1/gym-disputes
     *
     * Body:
     *   gym_id          int    required
     *   full_name       string required
     *   job_title       string required
     *   business_email  string required
     *   phone_number    string required
     *
     * Response:
     *   dispute_id  int
     *   message     string
     */
    public function initiate(Request $request)
    {
        $validated = $request->validate([
            'gym_id'         => 'required|integer|exists:websquids_gymdirectory_gyms,id',
            'full_name'      => 'required|string|max:255',
            'job_title'      => 'required|string|max:255',
            'business_email' => 'required|email|max:255',
            'phone_number'   => 'required|string|max:50',
        ]);

        $gym = Gym::findOrFail($validated['gym_id']);

        // The gym must actually have an approved claim to dispute
        $existingClaim = GymClaimRequest::where('gym_id', $gym->id)
            ->where('status', GymClaimRequest::STATUS_APPROVED)
            ->whereNull('deleted_at')
            ->first();

        if (!$existingClaim) {
            return response()->json([
                'success' => false,
                'message' => 'This gym does not have an active claim to dispute. Please use the standard claim process.',
            ], 422);
        }

        // Guard: active dispute already filed for this gym
        $activeDispute = GymClaimDispute::where('gym_id', $gym->id)
            ->whereIn('status', [GymClaimDispute::STATUS_PENDING, GymClaimDispute::STATUS_UNDER_REVIEW])
            ->whereNull('deleted_at')
            ->first();

        if ($activeDispute) {
            return response()->json([
                'success'    => false,
                'dispute_id' => $activeDispute->id,
                'message'    => 'A dispute for this gym is already under review. We will notify you within 48 hours.',
            ], 409);
        }

        $dispute = GymClaimDispute::create([
            'gym_id'            => $gym->id,
            'existing_claim_id' => $existingClaim->id,
            'full_name'         => $validated['full_name'],
            'job_title'         => $validated['job_title'],
            'business_email'    => $validated['business_email'],
            'phone_number'      => $validated['phone_number'],
            'status'            => GymClaimDispute::STATUS_PENDING,
            'ip_address'        => $request->ip(),
        ]);

        return response()->json([
            'success'    => true,
            'dispute_id' => $dispute->id,
            'message'    => 'Dispute initiated. Please upload your ownership documents to begin the review.',
        ], 201);
    }

    // =========================================================================
    // Upload document
    // =========================================================================

    /**
     * POST /api/v1/gym-disputes/{id}/upload-document
     * Multipart field: document (PDF / JPG / PNG, max 10 MB)
     *
     * Transitions status to under_review and sends confirmation email.
     */
    public function uploadDocument(Request $request, $id)
    {
        $request->validate([
            'document' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        $dispute = $this->findActiveDispute($id);
        if (!$dispute) {
            return $this->notFound();
        }

        if ($dispute->status === GymClaimDispute::STATUS_UNDER_REVIEW) {
            return response()->json([
                'success' => false,
                'message' => 'Your dispute is already under review. We will notify you within 48 hours.',
            ], 409);
        }

        try {
            $file = $request->file('document');
            $ext  = $file->getClientOriginalExtension();
            $path = $file->storeAs(
                'gym-disputes/' . $dispute->gym_id,
                'dispute-' . $dispute->id . '-' . time() . '.' . $ext,
                'local'
            );

            $dispute->document_path = $path;
            $dispute->status        = GymClaimDispute::STATUS_UNDER_REVIEW;
            $dispute->save();

        } catch (\Exception $e) {
            Log::error('GymDisputesController@uploadDocument: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Document upload failed. Please try again.',
            ], 500);
        }

        $gym = Gym::findOrFail($dispute->gym_id);
        $this->dispatchDisputeReceivedEmail($dispute, $gym);

        return response()->json([
            'success' => true,
            'message' => 'Your dispute documents are under review. We will notify you within 48 hours.',
            'status'  => GymClaimDispute::STATUS_UNDER_REVIEW,
        ]);
    }

    // =========================================================================
    // Status poll
    // =========================================================================

    /**
     * GET /api/v1/gym-disputes/{id}
     */
    public function status(Request $request, $id)
    {
        $dispute = GymClaimDispute::whereNull('deleted_at')->find($id);

        if (!$dispute) {
            return $this->notFound();
        }

        $messages = [
            GymClaimDispute::STATUS_PENDING      => 'Dispute created. Please upload your ownership documents.',
            GymClaimDispute::STATUS_UNDER_REVIEW => 'Your documents are under review. We will notify you within 48 hours.',
            GymClaimDispute::STATUS_APPROVED     => 'Your dispute has been approved. You are now the verified owner of this gym.',
            GymClaimDispute::STATUS_REJECTED     => 'Your dispute was not approved. Please contact support for more information.',
        ];

        return response()->json([
            'dispute_id'   => $dispute->id,
            'gym_id'       => $dispute->gym_id,
            'status'       => $dispute->status,
            'message'      => $messages[$dispute->status] ?? '',
            'reviewed_at'  => $dispute->reviewed_at,
        ]);
    }

    // =========================================================================
    // Admin: approve
    // =========================================================================

    /**
     * POST /api/v1/gym-disputes/{id}/approve
     *
     * Optional body:
     *   admin_notes  string
     *
     * Actions:
     *   1. Soft-delete (revoke) the original fraudulent claim.
     *   2. Create a new approved GymClaimRequest for the disputer.
     *   3. Mark the dispute as approved.
     *   4. Email the disputer (approved) and the original claimant (revoked).
     */
    public function approve(Request $request, $id)
    {
        $request->validate([
            'admin_notes' => 'nullable|string|max:1000',
        ]);

        $dispute = GymClaimDispute::whereNull('deleted_at')
            ->whereIn('status', [GymClaimDispute::STATUS_PENDING, GymClaimDispute::STATUS_UNDER_REVIEW])
            ->find($id);

        if (!$dispute) {
            return response()->json(['success' => false, 'message' => 'Dispute not found or already resolved.'], 404);
        }

        $gym           = Gym::findOrFail($dispute->gym_id);
        $originalClaim = GymClaimRequest::whereNull('deleted_at')->find($dispute->existing_claim_id);

        // 1. Revoke the original claim and soft-delete the old owner's user account
        if ($originalClaim) {
            $originalClaim->status = GymClaimRequest::STATUS_REJECTED;
            $originalClaim->save();
            $originalClaim->delete(); // soft-delete

            if ($originalClaim->user_id) {
                $oldOwner = User::find($originalClaim->user_id);
                if ($oldOwner) {
                    $oldOwner->is_activated = false;
                    $oldOwner->save();
                    $oldOwner->delete(); // soft-delete
                    Log::info("GymDisputesController@approve: Soft-deleted old owner user #{$oldOwner->id} ({$oldOwner->email})");
                } else {
                    Log::warning("GymDisputesController@approve: Original claim has user_id={$originalClaim->user_id} but user not found.");
                }
            } else {
                Log::warning("GymDisputesController@approve: Original claim #{$originalClaim->id} has no linked user_id — skipping user soft-delete.");
            }
        }

        // 2. Create new approved claim for the disputer
        $newClaim = GymClaimRequest::create([
            'gym_id'         => $gym->id,
            'full_name'      => $dispute->full_name,
            'job_title'      => $dispute->job_title,
            'business_email' => $dispute->business_email,
            'phone_number'   => $dispute->phone_number,
            'status'         => GymClaimRequest::STATUS_APPROVED,
            'verification_method' => GymClaimRequest::METHOD_DOCUMENT,
            'ip_address'     => $dispute->ip_address,
            'verified_at'    => now(),
        ]);

        // 3. Mark dispute approved
        $dispute->status      = GymClaimDispute::STATUS_APPROVED;
        $dispute->admin_notes = $request->input('admin_notes');
        $dispute->reviewed_at = now();
        $dispute->save();

        // 4. Provision user account and generate magic login token for the new owner
        $gymOwnerService = new GymOwnerService();
        $magicToken      = $gymOwnerService->provisionAndGenerateMagicToken($newClaim);
        $dashboardUrl    = $gymOwnerService->buildMagicLoginUrl($magicToken);

        // Verify disputer user account was actually created/linked
        $newClaim->refresh();
        if (!$newClaim->user_id || !User::find($newClaim->user_id)) {
            Log::error("GymDisputesController@approve: Disputer user account not found after provisioning for claim #{$newClaim->id}.");
        } else {
            Log::info("GymDisputesController@approve: Disputer user #{$newClaim->user_id} confirmed for claim #{$newClaim->id}.");
        }

        // 5. Send notifications
        $this->dispatchDisputeApprovedEmail($dispute, $gym, $dashboardUrl);
        if ($originalClaim) {
            $this->dispatchClaimRevokedEmail($originalClaim, $gym);
        }

        return response()->json([
            'success'      => true,
            'new_claim_id' => $newClaim->id,
            'message'      => 'Dispute approved. Original claim revoked and ownership transferred to the disputer.',
        ]);
    }

    // =========================================================================
    // Admin: reject
    // =========================================================================

    /**
     * POST /api/v1/gym-disputes/{id}/reject
     *
     * Optional body:
     *   admin_notes  string
     */
    public function reject(Request $request, $id)
    {
        $request->validate([
            'admin_notes' => 'nullable|string|max:1000',
        ]);

        $dispute = GymClaimDispute::whereNull('deleted_at')
            ->whereIn('status', [GymClaimDispute::STATUS_PENDING, GymClaimDispute::STATUS_UNDER_REVIEW])
            ->find($id);

        if (!$dispute) {
            return response()->json(['success' => false, 'message' => 'Dispute not found or already resolved.'], 404);
        }

        $dispute->status      = GymClaimDispute::STATUS_REJECTED;
        $dispute->admin_notes = $request->input('admin_notes');
        $dispute->reviewed_at = now();
        $dispute->save();

        $gym = Gym::findOrFail($dispute->gym_id);
        $this->dispatchDisputeRejectedEmail($dispute, $gym);

        return response()->json([
            'success' => true,
            'message' => 'Dispute rejected.',
        ]);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private function findActiveDispute($id): ?GymClaimDispute
    {
        return GymClaimDispute::whereNull('deleted_at')
            ->whereNotIn('status', [GymClaimDispute::STATUS_APPROVED, GymClaimDispute::STATUS_REJECTED])
            ->find($id);
    }

    private function dispatchDisputeReceivedEmail(GymClaimDispute $dispute, Gym $gym): void
    {
        SendDisputeReceivedEmailJob::dispatch(
            $dispute->business_email,
            $dispute->full_name,
            $gym->name
        );
    }

    private function dispatchDisputeApprovedEmail(GymClaimDispute $dispute, Gym $gym, string $dashboardUrl): void
    {
        SendDisputeApprovedEmailJob::dispatch(
            $dispute->business_email,
            $dispute->full_name,
            $gym->name,
            $dashboardUrl
        );
    }

    private function dispatchDisputeRejectedEmail(GymClaimDispute $dispute, Gym $gym): void
    {
        SendDisputeRejectedEmailJob::dispatch(
            $dispute->business_email,
            $dispute->full_name,
            $gym->name
        );
    }

    private function dispatchClaimRevokedEmail(GymClaimRequest $claim, Gym $gym): void
    {
        SendClaimRevokedEmailJob::dispatch(
            $claim->business_email,
            $claim->full_name,
            $gym->name
        );
    }

    private function notFound()
    {
        return response()->json(['success' => false, 'message' => 'Dispute not found.'], 404);
    }
}
