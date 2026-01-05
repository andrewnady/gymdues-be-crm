<?php

namespace websquids\Gymdirectory\Classes;

use Closure;
use Response;

class ApiKeyMiddleware {
  public function handle($request, Closure $next) {
    // Handle CORS preflight requests
    if ($request->getMethod() === 'OPTIONS') {
      return Response::make('', 200, [
        'Access-Control-Allow-Origin' => '*',
        'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, OPTIONS',
        'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-API-KEY, X-Requested-With',
        'Access-Control-Max-Age' => '86400',
      ]);
    }

    // 1. Get the key from the Header
    $token = $request->header('X-API-KEY');

    // 2. Compare it with the .env value (defaulting to empty if not set)
    if (env('APP_DEBUG') || $token === env('GYM_API_KEY')) {
      $response = $next($request);
      
      // Add CORS headers to the response
      $response->headers->set('Access-Control-Allow-Origin', '*');
      $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
      $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-API-KEY, X-Requested-With');
      
      return $response;
    }

    return Response::json(['error' => 'Unauthorized'], 401, [
      'Access-Control-Allow-Origin' => '*',
    ]);
  }
}
