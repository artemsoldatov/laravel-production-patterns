<?php

namespace App\Patterns\Idempotency;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Opt-in exactly-once semantics for POST endpoints, mirroring Stripe's API.
 * A client sends an Idempotency-Key header; a retry replays the original
 * response instead of running the handler again.
 *
 * The stored record also carries a fingerprint of the request body. Reusing a
 * key with a different body is a client bug, not a retry, so it gets a 422.
 */
class IdempotencyMiddleware
{
    private const LOCK_TTL = 300;

    private const DONE_TTL = 86400;

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');
        if ($request->getMethod() !== 'POST' || $key === null || $key === '') {
            /** @var Response $passthrough */
            $passthrough = $next($request);

            return $passthrough;
        }

        $fingerprint = hash('sha256', $request->getContent());
        $cacheKey = 'idem:'.$request->path().':'.$key;

        $existing = Cache::get($cacheKey);
        if (is_array($existing)) {
            return $this->handleExisting($existing, $fingerprint);
        }

        // atomic SET NX: only the first request wins the lock
        $locked = Cache::add($cacheKey, [
            'state' => 'in-flight',
            'fingerprint' => $fingerprint,
        ], self::LOCK_TTL);

        if (! $locked) {
            // lost the race between get() and add() — treat as concurrent
            $current = Cache::get($cacheKey);

            return $this->handleExisting(is_array($current) ? $current : [], $fingerprint);
        }

        try {
            /** @var Response $response */
            $response = $next($request);
        } catch (\Throwable $e) {
            // release the lock so the client can retry a failed request
            Cache::forget($cacheKey);
            throw $e;
        }

        Cache::put($cacheKey, [
            'state' => 'done',
            'fingerprint' => $fingerprint,
            'status' => $response->getStatusCode(),
            'body' => $response->getContent(),
        ], self::DONE_TTL);

        return $response;
    }

    /**
     * @param  array<array-key, mixed>  $record
     */
    private function handleExisting(array $record, string $fingerprint): Response
    {
        if (($record['fingerprint'] ?? null) !== $fingerprint) {
            return response()->json(
                ['message' => 'Idempotency-Key was already used with a different request body'],
                422
            );
        }

        if (($record['state'] ?? null) === 'in-flight') {
            return response()->json(
                ['message' => 'A request with this Idempotency-Key is already in flight'],
                409
            );
        }

        return response(
            is_string($record['body'] ?? null) ? $record['body'] : '',
            is_int($record['status'] ?? null) ? $record['status'] : 200,
        )->header('Idempotency-Replayed', 'true')
            ->header('Content-Type', 'application/json');
    }
}
