<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Replays the stored response when a POST, PUT or DELETE repeats an Idempotency-Key, so retried writes don't
 * duplicate. Keys are scoped per token (or user) and path; the internal admin API's are keyed internal:{path} and
 * required on its writes (3-R18). Only successful responses are stored.
 */
class Idempotent
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');
        $internal = $request->is('internal/*');

        if (! $request->isMethod('post') && ! $request->isMethod('put') && ! $request->isMethod('delete')) {
            return $next($request);
        }
        if (! $key) {
            return $internal ? response()->json(['message' => 'An Idempotency-Key header is required.'], 400) : $next($request);
        }

        if (strlen($key) > 255) {
            return response()->json(['message' => 'Idempotency-Key must be at most 255 characters.'], 400);
        }

        $token = $internal ? null : $request->user()?->currentAccessToken();
        $owner = match (true) {
            $internal => 'internal',
            $token instanceof PersonalAccessToken => "token:{$token->id}",
            default => 'user:'.$request->user()?->id,
        };
        $cacheKey = 'idempotency:'.sha1("{$owner}|{$request->path()}|{$key}");
        $hash = sha1($request->getContent());

        if ($stored = Cache::get($cacheKey)) {
            return $this->replay($stored, $hash);
        }

        $lock = Cache::lock("{$cacheKey}:lock", 30);
        if (! $lock->get()) {
            return response()->json(['message' => 'A request with this Idempotency-Key is still in progress. Retry shortly.'], 409);
        }

        try {
            // Another request may have finished while we waited for the lock.
            if ($stored = Cache::get($cacheKey)) {
                return $this->replay($stored, $hash);
            }

            $response = $next($request);

            if ($response->isSuccessful()) {
                Cache::put($cacheKey, [
                    'hash' => $hash,
                    'status' => $response->getStatusCode(),
                    'body' => $response->getContent(),
                    'type' => $response->headers->get('Content-Type'),
                ], now()->addHours(config('pm.idempotency_ttl_hours')));
            }

            return $response;
        } finally {
            $lock->release();
        }
    }

    private function replay(array $stored, string $hash): Response
    {
        if ($stored['hash'] !== $hash) {
            return response()->json([
                'message' => 'This Idempotency-Key was already used with a different request body. Use a new key for a new request.',
            ], 422);
        }

        return response($stored['body'], $stored['status'], ['Content-Type' => $stored['type'], 'Idempotent-Replayed' => 'true']);
    }
}
