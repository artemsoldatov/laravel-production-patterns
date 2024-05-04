<?php

use App\Models\Account;
use App\Models\Order;
use Illuminate\Testing\TestResponse;

function paidOrder(): Order
{
    $account = Account::create(['name' => 'Acme', 'balance_cents' => 10_000, 'currency' => 'USD']);

    return Order::create([
        'account_id' => $account->id,
        'reference' => 'ref-web-1',
        'amount_cents' => 500,
        'currency' => 'USD',
        'status' => 'pending',
    ]);
}

function checkoutEvent(string $reference, string $eventId = 'evt_test_1'): string
{
    return json_encode([
        'id' => $eventId,
        'object' => 'event',
        'type' => 'checkout.session.completed',
        'data' => ['object' => [
            'id' => 'cs_test_1',
            'object' => 'checkout.session',
            'client_reference_id' => $reference,
        ]],
    ], JSON_THROW_ON_ERROR);
}

function postStripe(string $payload, ?string $signature = null): TestResponse
{
    $signature ??= stripeSignature($payload, 'whsec_test_secret');

    return test()->call(
        'POST',
        '/api/webhooks/stripe',
        [],
        [],
        [],
        ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
        $payload,
    );
}

it('rejects a forged signature', function () {
    $payload = checkoutEvent('ref-web-1');

    postStripe($payload, 't=1,v1=deadbeef')->assertStatus(400);
});

it('marks the order paid on a signed event', function () {
    $order = paidOrder();
    $payload = checkoutEvent($order->reference);

    postStripe($payload)->assertOk()->assertJson(['received' => true]);

    expect($order->refresh()->status)->toBe('paid');
});

it('deduplicates a replayed event', function () {
    $order = paidOrder();
    $payload = checkoutEvent($order->reference, 'evt_replay');

    postStripe($payload)->assertOk();
    $replay = postStripe($payload)->assertOk();

    expect($replay->json('duplicate'))->toBeTrue()
        ->and($order->refresh()->status)->toBe('paid');
    // the event was recorded exactly once
    expect(DB::table('processed_stripe_events')->where('event_id', 'evt_replay')->count())->toBe(1);
});
