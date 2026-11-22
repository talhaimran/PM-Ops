<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * /mcp takes an agent's Sanctum token or an MCP client's OAuth token. The OAuth guard can't even be built without
 * Passport's keys (league throws LogicException); then it fails closed: the request is simply not authenticated by
 * it, so strangers get the usual 401 and Sanctum tokens keep working.
 */
class AuthenticateMcp extends Authenticate
{
    private static bool $warned = false;

    protected function authenticate($request, array $guards)
    {
        foreach ($guards as $guard) {
            try {
                if ($this->auth->guard($guard)->check()) {
                    return $this->auth->shouldUse($guard);
                }
            } catch (LogicException $e) {
                if ($guard !== 'oauth') {
                    throw $e;
                }
                if (! self::$warned) {
                    self::$warned = true;
                    Log::warning('MCP OAuth is unavailable: Passport keys are missing or unreadable ('.$e->getMessage().'). Run php artisan passport:keys or set PASSPORT_PRIVATE_KEY / PASSPORT_PUBLIC_KEY.');
                }
            }
        }

        $this->unauthenticated($request, $guards);
    }
}
