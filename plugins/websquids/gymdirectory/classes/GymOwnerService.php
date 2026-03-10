<?php

namespace websquids\Gymdirectory\Classes;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Winter\User\Models\User;
use websquids\Gymdirectory\Models\GymClaimRequest;
use websquids\Gymdirectory\Models\GymOwnerToken;

/**
 * GymOwnerService
 *
 * Called whenever a claim or dispute is approved. Responsibilities:
 *   1. Find or create the Winter.User record for the owner's email.
 *   2. Store user_id back on the GymClaimRequest so we can look up the owner later.
 *   3. Generate a one-time magic login token and return the raw value so
 *      the caller can embed it in the approval email.
 */
class GymOwnerService
{
    /**
     * Provision a user account for the approved claimant, link it to the claim,
     * and return a raw magic-login token to embed in the approval email.
     *
     * @param  GymClaimRequest $claim  The newly-approved claim record.
     * @return string                  Raw (un-hashed) magic token.
     */
    public function provisionAndGenerateMagicToken(GymClaimRequest $claim): string
    {
        // ------------------------------------------------------------------
        // 1. Find or create the Winter.User for this email
        // ------------------------------------------------------------------
        $user = User::where('email', $claim->business_email)->first();

        if (!$user) {
            $password = Str::random(32);

            $user = new User();
            $user->name     = $claim->full_name;
            $user->email    = $claim->business_email;
            $user->username = $claim->business_email;
            $user->password = $password;
            $user->password_confirmation = $password;
            $user->is_activated  = true;
            $user->activated_at  = now();
            $user->forceSave();

            Log::info("GymOwnerService: Created new user #{$user->id} for {$claim->business_email}");
        } else {
            Log::info("GymOwnerService: Found existing user #{$user->id} for {$claim->business_email}");
        }

        // ------------------------------------------------------------------
        // 2. Link user_id to the claim record
        // ------------------------------------------------------------------
        $claim->user_id = $user->id;
        $claim->save();

        // ------------------------------------------------------------------
        // 3. Generate a one-time magic login token (stored hashed)
        // ------------------------------------------------------------------
        $rawToken = Str::random(64);

        GymOwnerToken::create([
            'user_id'    => $user->id,
            'token'      => hash('sha256', $rawToken),
            'type'       => GymOwnerToken::TYPE_MAGIC,
            'expires_at' => now()->addHours(48),
            'created_at' => now(),
        ]);

        return $rawToken;
    }

    /**
     * Build the full magic-link URL to include in the approval email.
     *
     * The frontend is responsible for calling POST /api/v1/gym-owner/auth/magic-login
     * with the token to exchange it for a session bearer token.
     *
     * @param  string $rawToken
     * @return string
     */
    public function buildMagicLoginUrl(string $rawToken): string
    {
        $base = rtrim(env('FRONTEND_URL', env('APP_URL', 'https://gymdues.com')), '/');

        return $base . '/dashboard/auth/magic-login?token=' . urlencode($rawToken);
    }
}
