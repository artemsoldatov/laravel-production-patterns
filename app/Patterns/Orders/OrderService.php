<?php

namespace App\Patterns\Orders;

use App\Models\Order;
use App\Patterns\Outbox\OutboxWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Places an order. The order row and its "order.placed" event are written in
 * one transaction, so processing is announced atomically with the state change
 * — the outbox relay picks the event up only after the commit is durable.
 */
class OrderService
{
    public function __construct(private readonly OutboxWriter $outbox)
    {
    }

    public function place(string $accountId, int $amountCents, string $reference): Order
    {
        return DB::transaction(function () use ($accountId, $amountCents, $reference) {
            $order = Order::create([
                'account_id' => $accountId,
                'reference' => $reference,
                'amount_cents' => $amountCents,
                'currency' => 'USD',
                'status' => 'pending',
            ]);

            $this->outbox->emit('order.placed', [
                'order_id' => $order->id,
                'idempotency' => (string) Str::uuid(),
            ]);

            return $order;
        });
    }
}
