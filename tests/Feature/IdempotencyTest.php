<?php

use App\Models\Account;
use App\Models\Order;

function account(): Account
{
    return Account::create(['name' => 'Acme', 'balance_cents' => 10_000, 'currency' => 'USD']);
}

it('replays the same response for a repeated Idempotency-Key', function () {
    $account = account();
    $body = ['account_id' => $account->id, 'amount_cents' => 500, 'reference' => 'ord-1'];

    $first = $this->withHeader('Idempotency-Key', 'key-1')->postJson('/api/orders', $body);
    $first->assertCreated();
    $firstId = $first->json('id');

    $second = $this->withHeader('Idempotency-Key', 'key-1')->postJson('/api/orders', $body);
    $second->assertCreated();
    $second->assertHeader('Idempotency-Replayed', 'true');

    expect($second->json('id'))->toBe($firstId)
        ->and(Order::query()->count())->toBe(1);
});

it('rejects a reused key with a different body', function () {
    $account = account();

    $this->withHeader('Idempotency-Key', 'key-2')
        ->postJson('/api/orders', ['account_id' => $account->id, 'amount_cents' => 500, 'reference' => 'ord-2'])
        ->assertCreated();

    $this->withHeader('Idempotency-Key', 'key-2')
        ->postJson('/api/orders', ['account_id' => $account->id, 'amount_cents' => 999, 'reference' => 'ord-2b'])
        ->assertStatus(422);
});

it('creates a distinct resource when no key is sent', function () {
    $account = account();

    $this->postJson('/api/orders', ['account_id' => $account->id, 'amount_cents' => 500, 'reference' => 'ord-3'])
        ->assertCreated();
    $this->postJson('/api/orders', ['account_id' => $account->id, 'amount_cents' => 500, 'reference' => 'ord-4'])
        ->assertCreated();

    expect(Order::query()->count())->toBe(2);
});
