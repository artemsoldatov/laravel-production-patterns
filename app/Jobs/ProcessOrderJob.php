<?php

namespace App\Jobs;

use App\Models\Order;
use App\Patterns\Inbox\InboxGuard;
use App\Patterns\OptimisticLocking\AccountService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

/**
 * Idempotent consumer for "order.placed". Delivery is at-least-once, so the job
 * guards its effect with the inbox (message id = outbox row id): a duplicate
 * delivery is a no-op. On repeated failure the job exhausts its retries and
 * Laravel moves it to failed_jobs — the dead-letter queue — from where it can
 * be replayed safely, again because of the inbox guard.
 */
class ProcessOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public array $payload,
        public string $messageId,
    ) {
    }

    /**
     * Exponential backoff between retries (seconds).
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [1, 5, 15];
    }

    public function handle(InboxGuard $inbox, AccountService $accounts): void
    {
        $inbox->once($this->messageId, 'process-order', function () use ($accounts) {
            /** @var string $orderId */
            $orderId = $this->payload['order_id'];
            $order = Order::query()->find($orderId);
            if ($order === null) {
                return;
            }

            // a poisoned payload exercises the retry → DLQ path end to end
            if (($this->payload['poison'] ?? false) === true) {
                throw new RuntimeException('Poisoned order payload');
            }

            $accounts->debit($order->account_id, $order->amount_cents);

            $order->update(['status' => 'processing']);
        });
    }
}
