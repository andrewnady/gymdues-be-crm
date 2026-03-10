<?php

namespace websquids\Gymdirectory\Classes;

use Closure;
use Response;
use websquids\Gymdirectory\Models\GymOwnerToken;

/**
 * GymOwnerAuthMiddleware
 *
 * Validates the session bearer token that is returned after the magic-login
 * flow completes. Protected gym-owner routes should use this middleware.
 *
 * Expects header:  Authorization: Bearer <session_token>
 *
 * On success it injects 'gym_owner_user_id' into the request so downstream
 * controllers can identify the authenticated owner without re-querying the token.
 */
class GymOwnerAuthMiddleware
{
    public function handle($request, Closure $next)
    {
        $rawToken = $request->bearerToken();

        if (!$rawToken) {
            return Response::json(['error' => 'Unauthorized – no token provided.'], 401);
        }

        $hashed = hash('sha256', $rawToken);

        $tokenRecord = GymOwnerToken::where('token', $hashed)
            ->where('type', GymOwnerToken::TYPE_SESSION)
            ->where('expires_at', '>', now())
            ->first();

        if (!$tokenRecord) {
            return Response::json(['error' => 'Unauthorized – invalid or expired session token.'], 401);
        }

        // Make the authenticated user ID available to the controller
        $request->merge(['gym_owner_user_id' => $tokenRecord->user_id]);

        return $next($request);
    }
}
