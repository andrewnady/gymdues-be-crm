<?php

namespace Websquids\Gymdirectory\Controllers\Api;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Winter\User\Models\User;
use websquids\Gymdirectory\Models\Gym;
use websquids\Gymdirectory\Models\GymClaimRequest;
use websquids\Gymdirectory\Models\GymOwnerToken;
use websquids\Gymdirectory\Models\GymTeamMember;
use websquids\Gymdirectory\Classes\GymOwnerService;

/**
 * GymTeamController
 *
 * Allows gym owners to invite other users to co-manage their gym listing.
 * Invited users do NOT need to go through the claim verification flow.
 *
 * Endpoints
 * ---------
 *   GET    /api/v1/gym-owner/team                    List all team members
 *   POST   /api/v1/gym-owner/team/invite             Invite a new member
 *   DELETE /api/v1/gym-owner/team/{id}               Revoke a member's access
 *   POST   /api/v1/gym-owner/team/accept-invite      Accept an invitation (public)
 */
class GymTeamController extends Controller
{
    /**
     * GET /api/v1/gym-owner/team
     *
     * Returns all team members (pending, accepted, revoked) for the owner's gym.
     * Only the gym owner (claim holder) can list team members.
     */
    public function index(Request $request)
    {
        $userId = $request->input('gym_owner_user_id');

        $gym = $this->resolveGymForOwner($userId);
        if (!$gym) {
            return $this->notOwner();
        }

        $members = GymTeamMember::where('gym_id', $gym->id)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'success' => true,
            'gym_id'  => $gym->id,
            'members' => $members->map(fn(GymTeamMember $m) => [
                'id'          => $m->id,
                'email'       => $m->email,
                'name'        => $m->name,
                'role'        => $m->role,
                'status'      => $m->status,
                'invited_at'  => $m->invited_at,
                'accepted_at' => $m->accepted_at,
            ]),
        ]);
    }

    /**
     * POST /api/v1/gym-owner/team/invite
     *
     * Body: { "email": "...", "name": "...", "role": "manager" }
     *
     * Sends an invitation email with a magic link. Only the gym owner (the
     * user with an approved claim) can invite members.
     */
    public function invite(Request $request)
    {
        $request->validate([
            'email' => 'required|email|max:255',
            'name'  => 'nullable|string|max:255',
            'role'  => 'nullable|string|in:manager,staff',
        ]);

        $userId = $request->input('gym_owner_user_id');

        $gym = $this->resolveGymForOwner($userId);
        if (!$gym) {
            return $this->notOwner();
        }

        $inviteEmail = strtolower(trim($request->input('email')));

        // Prevent owner from inviting their own email
        $ownerUser = User::find($userId);
        if ($ownerUser && strtolower($ownerUser->email) === $inviteEmail) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot invite yourself.',
            ], 422);
        }

        // Prevent duplicate active invitations
        $existing = GymTeamMember::where('gym_id', $gym->id)
            ->where('email', $inviteEmail)
            ->whereIn('status', [GymTeamMember::STATUS_PENDING, GymTeamMember::STATUS_ACCEPTED])
            ->whereNull('deleted_at')
            ->first();

        if ($existing) {
            $message = $existing->status === GymTeamMember::STATUS_ACCEPTED
                ? 'This person is already a team member.'
                : 'An invitation has already been sent to this email.';

            return response()->json([
                'success' => false,
                'message' => $message,
            ], 422);
        }

        // Create the pending team member record
        $member = GymTeamMember::create([
            'gym_id'              => $gym->id,
            'invited_by_user_id'  => $userId,
            'email'               => $inviteEmail,
            'name'                => $request->input('name'),
            'role'                => $request->input('role', 'manager'),
            'status'              => GymTeamMember::STATUS_PENDING,
            'invited_at'          => now(),
        ]);

        // Provision the user account and generate a magic token
        $service  = new GymOwnerService();
        $rawToken = $service->provisionAndGenerateMagicTokenForMember($member);
        $inviteUrl = $service->buildTeamInviteUrl($rawToken);

        // Send invitation email
        $this->dispatchInvitationEmail($member, $gym, $ownerUser, $inviteUrl);

        Log::info("GymTeamController@invite: owner #{$userId} invited {$inviteEmail} to gym #{$gym->id}");

        return response()->json([
            'success' => true,
            'message' => "Invitation sent to {$inviteEmail}.",
            'member'  => [
                'id'         => $member->id,
                'email'      => $member->email,
                'name'       => $member->name,
                'role'       => $member->role,
                'status'     => $member->status,
                'invited_at' => $member->invited_at,
            ],
        ], 201);
    }

    /**
     * DELETE /api/v1/gym-owner/team/{id}
     *
     * Revokes a team member's access. Only the gym owner can do this.
     */
    public function revoke(Request $request, $id)
    {
        $userId = $request->input('gym_owner_user_id');

        $gym = $this->resolveGymForOwner($userId);
        if (!$gym) {
            return $this->notOwner();
        }

        $member = GymTeamMember::where('gym_id', $gym->id)
            ->whereNull('deleted_at')
            ->find($id);

        if (!$member) {
            return response()->json(['success' => false, 'message' => 'Team member not found.'], 404);
        }

        if ($member->status === GymTeamMember::STATUS_REVOKED) {
            return response()->json(['success' => false, 'message' => 'Access is already revoked.'], 422);
        }

        // Invalidate any active session tokens for this user so they are
        // immediately logged out of the dashboard
        if ($member->user_id) {
            GymOwnerToken::where('user_id', $member->user_id)
                ->where('type', GymOwnerToken::TYPE_SESSION)
                ->delete();
        }

        $member->revoke();

        Log::info("GymTeamController@revoke: owner #{$userId} revoked access for member #{$member->id} ({$member->email}) on gym #{$gym->id}");

        return response()->json([
            'success' => true,
            'message' => 'Team member access revoked.',
        ]);
    }

    /**
     * POST /api/v1/gym-owner/team/accept-invite   (PUBLIC — no auth required)
     *
     * Body: { "token": "<raw_magic_token>" }
     *
     * Called when an invited user clicks their invitation link.
     * Validates the magic token, marks the team membership as accepted,
     * and returns a session bearer token.
     */
    public function acceptInvite(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
        ]);

        $hashed = hash('sha256', $request->input('token'));

        $magicToken = GymOwnerToken::where('token', $hashed)
            ->where('type', GymOwnerToken::TYPE_MAGIC)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();

        if (!$magicToken) {
            return response()->json([
                'success' => false,
                'message' => 'This invitation link is invalid or has expired. Please ask the gym owner to resend.',
            ], 401);
        }

        // Find the pending team membership for this user
        $member = GymTeamMember::where('user_id', $magicToken->user_id)
            ->where('status', GymTeamMember::STATUS_PENDING)
            ->whereNull('deleted_at')
            ->with('gym')
            ->latest()
            ->first();

        if (!$member) {
            return response()->json([
                'success' => false,
                'message' => 'No pending invitation found for this link.',
            ], 404);
        }

        // Consume the magic token (one-time use)
        $magicToken->used_at = now();
        $magicToken->save();

        // Accept the team membership
        $member->accept($magicToken->user_id);

        $user = User::find($magicToken->user_id);

        // Create a session bearer token
        $rawSession = Str::random(64);
        GymOwnerToken::create([
            'user_id'    => $user->id,
            'token'      => hash('sha256', $rawSession),
            'type'       => GymOwnerToken::TYPE_SESSION,
            'expires_at' => now()->addDays(30),
            'created_at' => now(),
        ]);

        Log::info("GymTeamController@acceptInvite: user #{$user->id} ({$user->email}) accepted team invitation for gym #{$member->gym_id}");

        return response()->json([
            'success'           => true,
            'access_token'      => $rawSession,
            'token_type'        => 'Bearer',
            'expires_in'        => 30 * 24 * 60 * 60,
            'must_set_password' => true,
            'user' => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
            ],
            'gym' => $member->gym ? [
                'id'   => $member->gym->id,
                'name' => $member->gym->name,
                'slug' => $member->gym->slug,
            ] : null,
        ]);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Resolve the gym only if the current user is the actual owner (claim holder).
     * Team members cannot manage team — only the original owner can.
     */
    private function resolveGymForOwner(int $userId): ?Gym
    {
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

    private function notOwner()
    {
        return response()->json([
            'success' => false,
            'message' => 'Only the verified gym owner can manage team members.',
        ], 403);
    }

    private function dispatchInvitationEmail(
        GymTeamMember $member,
        Gym $gym,
        ?User $inviter,
        string $inviteUrl
    ): void {
        try {
            $inviteeName = $member->name ?: $member->email;
            $gymName     = $gym->name;
            $inviterName = $inviter ? $inviter->name : 'The Gym Owner';
            $toEmail     = $member->email;

            Mail::send(
                'websquids.gymdirectory::mail.team_invitation',
                compact('inviteeName', 'gymName', 'inviterName', 'inviteUrl'),
                function ($message) use ($toEmail, $inviteeName) {
                    $message->to($toEmail, $inviteeName)
                            ->subject("You've been invited to manage a gym on GymDues");
                }
            );
        } catch (\Exception $e) {
            Log::error('GymTeamController@dispatchInvitationEmail: ' . $e->getMessage());
        }
    }
}
