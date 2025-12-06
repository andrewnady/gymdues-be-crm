<?php

namespace websquids\Gymdirectory\Classes;

use Closure;
use Response;

class ApiKeyMiddleware {
  public function handle($request, Closure $next) {
    // 1. Get the key from the Header
    $token = $request->header('X-API-KEY');

    // 2. Compare it with the .env value (defaulting to empty if not set)
    if (env('APP_DEBUG') || $token === env('GYM_API_KEY')) {
      return $next($request);
    }

    return Response::json(['error' => 'Unauthorized'], 401);
  }
}
