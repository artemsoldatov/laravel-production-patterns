<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

uses(TestCase::class)
    ->beforeEach(function () {
        // isolated redis DBs (14/15) — wipe them so cache/queue state never
        // bleeds between tests
        Redis::connection('cache')->flushdb();
        Redis::connection('default')->flushdb();
    })
    ->in('Feature', 'Unit');

// RefreshDatabase wraps each test in a transaction; that would hide the
// OutboxWriter "must run in a transaction" guard, so it is Feature-only.
uses(RefreshDatabase::class)->in('Feature');

/**
 * Builds a valid Stripe-Signature header for a raw payload, the same way the
 * Stripe SDK verifies it: sign "{timestamp}.{payload}" with the webhook secret.
 */
function stripeSignature(string $payload, string $secret): string
{
    $timestamp = time();
    $signed = "{$timestamp}.{$payload}";
    $signature = hash_hmac('sha256', $signed, $secret);

    return "t={$timestamp},v1={$signature}";
}
