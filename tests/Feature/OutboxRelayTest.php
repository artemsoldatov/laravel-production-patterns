<?php

use App\Jobs\ProcessOrderJob;
use App\Models\Account;
use App\Models\Order;
use App\Models\OutboxEvent;
use App\Patterns\Outbox\OutboxWriter;
use App\Patterns\Outbox\RelayOutbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

it('writes an event inside a transaction', function () {
    DB::transaction(function () {
        (new OutboxWriter)->emit('order.placed', ['order_id' => 'abc']);
    });

    expect(OutboxEvent::query()->count())->toBe(1);
    $event = OutboxEvent::query()->first();
    expect($event->topic)->toBe('order.placed')
        ->and($event->payload)->toBe(['order_id' => 'abc'])
        ->and($event->published_at)->toBeNull();
});

function makeOrderWithEvent(): OutboxEvent
{
    $account = Account::create(['name' => 'Acme', 'balance_cents' => 10_000, 'currency' => 'USD']);
    $order = Order::create([
        'account_id' => $account->id,
        'reference' => 'ref-'.uniqid(),
        'amount_cents' => 500,
        'currency' => 'USD',
        'status' => 'pending',
    ]);

    return OutboxEvent::create([
        'topic' => 'order.placed',
        'payload' => ['order_id' => $order->id, 'idempotency' => 'k1'],
    ]);
}

it('relays an unpublished event to the queue and marks it published', function () {
    Queue::fake();
    $event = makeOrderWithEvent();

    $relayed = app(RelayOutbox::class)->tick();

    expect($relayed)->toBe(1);
    Queue::assertPushed(ProcessOrderJob::class, 1);
    expect($event->refresh()->published_at)->not->toBeNull();
});

it('does not relay an event twice', function () {
    Queue::fake();
    makeOrderWithEvent();

    app(RelayOutbox::class)->tick();
    $second = app(RelayOutbox::class)->tick();

    expect($second)->toBe(0);
    Queue::assertPushed(ProcessOrderJob::class, 1);
});

it('recovers an event that was committed but never published', function () {
    Queue::fake();
    // simulates a crash after the domain commit but before the relay ran:
    // the row sits unpublished and must still reach the queue
    $event = makeOrderWithEvent();
    expect($event->published_at)->toBeNull();

    app(RelayOutbox::class)->tick();

    Queue::assertPushed(ProcessOrderJob::class, 1);
    expect($event->refresh()->published_at)->not->toBeNull();
});
