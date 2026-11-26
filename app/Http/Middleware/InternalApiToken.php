<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 3-R18: the internal admin API exists only with PM_INTERNAL_API_TOKEN set (else every path is a 404) and takes only
 * that token or PM_INTERNAL_API_TOKEN_NEXT, compared in constant time. Anything else is a bare 401.
 */
class InternalApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((string) config('pm.internal_api_token') !== '', 404);

        $given = (string) $request->bearerToken();
        foreach (array_filter([config('pm.internal_api_token'), config('pm.internal_api_token_next')]) as $token) {
            if ($given !== '' && hash_equals((string) $token, $given)) {
                return $next($request);
            }
        }

        return response()->json(['message' => 'Unauthenticated.'], 401);
    }
}
