<?php

namespace Websquids\Gymdirectory\Controllers\Api;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Winter\User\Models\User;
use websquids\Gymdirectory\Models\GymOwnerToken;
use websquids\Gymdirectory\Models\GymClaimRequest;

/**
 * GymOwnerAuthController
 *
 * Handles authentication for approved gym owners.
 *
 * Endpoints
 * ---------
 *   POST  /api/v1/gym-owner/auth/magic-login
 *         Body: { "token": "<raw_magic_token>" }
 *         First-time login via link from the approval email.
 *         Returns session token + must_set_password flag.
 *
 *   POST  /api/v1/gym-owner/auth/set-password  (requires GymOwnerAuthMiddleware)
 *         Body: { "password": "...", "password_confirmation": "..." }
 *         Sets the owner's password so they can log in with email/password next time.
 *
 *   POST  /api/v1/gym-owner/auth/login
 *         Body: { "email": "...", "password": "..." }
 *         Standard email + password login for returning owners.
 *
 *   POST  /api/v1/gym-owner/auth/forgot-password
 *         Body: { "email": "..." }
 *         Sends a password-reset link to the owner's email. Always returns 200.
 *
 *   POST  /api/v1/gym-owner/auth/reset-password
 *         Body: { "token": "...", "password": "...", "password_confirmation": "..." }
 *         Validates the reset token and sets a new password.
 *
 *   GET   /api/v1/gym-owner/auth/me        (requires GymOwnerAuthMiddleware)
 *         Returns the authenticated owner's profile and linked gym.
 *
 *   POST  /api/v1/gym-owner/auth/logout    (requires GymOwnerAuthMiddleware)
 *         Revokes the current session token.
 */
class GymOwnerAuthController extends Controller
{
    /**
     * POST /api/v1/gym-owner/auth/magic-login
     *
     * Exchanges a one-time magic token (from the approval email) for a session
     * bearer token. The response includes must_set_password: true so the
     * frontend can redirect to the set-password screen on first login.
     */
    public function magicLogin(Request $request)
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
                'message' => 'This link is invalid or has already been used. Please log in with your email and password.',
            ], 401);
        }

        // Mark magic token as consumed (one-time use)
        $magicToken->used_at = now();
        $magicToken->save();

        $user  = User::find($magicToken->user_id);
        $claim = $this->getApprovedClaim($magicToken->user_id);

        $rawSession = $this->createSessionToken($magicToken->user_id);

        Log::info("GymOwnerAuthController@magicLogin: user #{$user->id} ({$user->email}) logged in via magic link.");

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
            'gym' => $claim ? [
                'id'   => $claim->gym_id,
                'name' => $claim->gym->name ?? null,
            ] : null,
        ]);
    }

    /**
     * POST /api/v1/gym-owner/auth/set-password
     * Requires GymOwnerAuthMiddleware.
     *
     * Allows the owner to set a real password after their first magic-link login.
     */
    public function setPassword(Request $request)
    {
        $request->validate([
            'password'              => 'required|string|min:8|confirmed',
            'password_confirmation' => 'required|string',
        ]);

        $userId = $request->input('gym_owner_user_id');
        $user   = User::find($userId);

        $user->password              = $request->input('password');
        $user->password_confirmation = $request->input('password_confirmation');
        $user->forceSave();

        Log::info("GymOwnerAuthController@setPassword: user #{$user->id} set their password.");

        return response()->json([
            'success' => true,
            'message' => 'Password set successfully. You can now log in with your email and password.',
        ]);
    }

    /**
     * POST /api/v1/gym-owner/auth/login
     *
     * Standard email + password login for returning owners.
     * The user must have an active approved gym claim to access the dashboard.
     */
    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', strtolower(trim($request->input('email'))))->first();

        // Generic error — don't reveal whether email exists
        $invalidCredentials = response()->json([
            'success' => false,
            'message' => 'Invalid email or password.',
        ], 401);

        if (!$user) {
            return $invalidCredentials;
        }

        // Verify password using the hashed value stored by Winter.User
        if (!Hash::check($request->input('password'), $user->password)) {
            return $invalidCredentials;
        }

        // Only gym owners with an active approved claim may log in here
        $claim = $this->getApprovedClaim($user->id);

        if (!$claim) {
            return response()->json([
                'success' => false,
                'message' => 'No approved gym claim found for this account.',
            ], 403);
        }

        $rawSession = $this->createSessionToken($user->id);

        Log::info("GymOwnerAuthController@login: user #{$user->id} ({$user->email}) logged in via email/password.");

        return response()->json([
            'success'      => true,
            'access_token' => $rawSession,
            'token_type'   => 'Bearer',
            'expires_in'   => 30 * 24 * 60 * 60,
            'user' => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
            ],
            'gym' => [
                'id'   => $claim->gym_id,
                'name' => $claim->gym->name ?? null,
            ],
        ]);
    }

    /**
     * GET /api/v1/gym-owner/auth/me
     * Requires GymOwnerAuthMiddleware.
     */
    public function me(Request $request)
    {
        $userId = $request->input('gym_owner_user_id');
        $user   = User::find($userId);
        $claim  = $this->getApprovedClaim($userId);

        return response()->json([
            'success' => true,
            'user' => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
            ],
            'gym' => $claim ? [
                'id'   => $claim->gym_id,
                'name' => $claim->gym->name ?? null,
            ] : null,
        ]);
    }

    /**
     * POST /api/v1/gym-owner/auth/logout
     * Requires GymOwnerAuthMiddleware.
     */
    public function logout(Request $request)
    {
        $rawToken = $request->bearerToken();

        if ($rawToken) {
            GymOwnerToken::where('token', hash('sha256', $rawToken))
                ->where('type', GymOwnerToken::TYPE_SESSION)
                ->delete();
        }

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully.',
        ]);
    }

    /**
     * POST /api/v1/gym-owner/auth/forgot-password
     *
     * Sends a password-reset link to the owner's email.
     * Always returns the same 200 response to prevent user enumeration.
     */
    public function forgotPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $email = strtolower(trim($request->input('email')));
        $user  = User::where('email', $email)->first();

        if ($user && $this->getApprovedClaim($user->id)) {
            // Invalidate any previous unused reset tokens for this user
            GymOwnerToken::where('user_id', $user->id)
                ->where('type', GymOwnerToken::TYPE_PASSWORD_RESET)
                ->whereNull('used_at')
                ->delete();

            $rawToken = Str::random(64);

            GymOwnerToken::create([
                'user_id'    => $user->id,
                'token'      => hash('sha256', $rawToken),
                'type'       => GymOwnerToken::TYPE_PASSWORD_RESET,
                'expires_at' => now()->addHours(1),
                'created_at' => now(),
            ]);

            $resetUrl = rtrim(env('FRONTEND_URL', env('APP_URL', 'https://gymdues.com')), '/')
                . '/dashboard/auth/reset-password?token=' . urlencode($rawToken);

            $this->dispatchPasswordResetEmail($user, $resetUrl);

            Log::info("GymOwnerAuthController@forgotPassword: reset link sent to {$email}");
        } else {
            Log::info("GymOwnerAuthController@forgotPassword: {$email} not found or has no approved claim — silently ignored.");
        }

        return response()->json([
            'success' => true,
            'message' => 'If this email belongs to a verified gym owner, a password reset link has been sent.',
        ]);
    }

    /**
     * POST /api/v1/gym-owner/auth/reset-password
     *
     * Validates the password-reset token and sets the new password.
     */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'token'                 => 'required|string',
            'password'              => 'required|string|min:8|confirmed',
            'password_confirmation' => 'required|string',
        ]);

        $hashed = hash('sha256', $request->input('token'));

        $resetToken = GymOwnerToken::where('token', $hashed)
            ->where('type', GymOwnerToken::TYPE_PASSWORD_RESET)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();

        if (!$resetToken) {
            return response()->json([
                'success' => false,
                'message' => 'This reset link is invalid or has expired. Please request a new one.',
            ], 401);
        }

        $user = User::find($resetToken->user_id);

        // Mark token as used before changing the password
        $resetToken->used_at = now();
        $resetToken->save();

        $user->password              = $request->input('password');
        $user->password_confirmation = $request->input('password_confirmation');
        $user->forceSave();

        // Invalidate all existing session tokens so old sessions cannot continue
        GymOwnerToken::where('user_id', $user->id)
            ->where('type', GymOwnerToken::TYPE_SESSION)
            ->delete();

        Log::info("GymOwnerAuthController@resetPassword: user #{$user->id} reset their password. All sessions invalidated.");

        return response()->json([
            'success' => true,
            'message' => 'Password reset successfully. Please log in with your new password.',
        ]);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private function createSessionToken(int $userId): string
    {
        $rawSession = Str::random(64);

        GymOwnerToken::create([
            'user_id'    => $userId,
            'token'      => hash('sha256', $rawSession),
            'type'       => GymOwnerToken::TYPE_SESSION,
            'expires_at' => now()->addDays(30),
            'created_at' => now(),
        ]);

        return $rawSession;
    }

    private function dispatchPasswordResetEmail(User $user, string $resetUrl): void
    {
        try {
            $fullName = $user->name;
            $toEmail  = $user->email;

            Mail::send(
                'websquids.gymdirectory::mail.owner_password_reset',
                compact('fullName', 'resetUrl'),
                function ($message) use ($toEmail, $fullName) {
                    $message->to($toEmail, $fullName)
                            ->subject('Reset Your GymDues Dashboard Password');
                }
            );
        } catch (\Exception $e) {
            Log::error('GymOwnerAuthController@dispatchPasswordResetEmail: ' . $e->getMessage());
        }
    }

    private function getApprovedClaim(int $userId): ?GymClaimRequest
    {
        return GymClaimRequest::where('user_id', $userId)
            ->where('status', GymClaimRequest::STATUS_APPROVED)
            ->whereNull('deleted_at')
            ->with('gym')
            ->latest()
            ->first();
    }
}
