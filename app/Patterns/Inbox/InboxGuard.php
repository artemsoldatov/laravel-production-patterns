<?php

namespace App\Patterns\Inbox;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Turns at-least-once delivery into an effectively-once effect. A consumer
 * claims a message id by inserting it; the insert and the business effect run
 * in one transaction, so either both commit or neither does. A second delivery
 * of the same id fails the primary-key insert and is skipped.
 */
class InboxGuard
{
    /**
     * Runs $effect exactly once for the given message id. Returns true if the
     * effect ran, false if the message was already processed.
     */
    public function once(string $messageId, string $consumer, callable $effect): bool
    {
        return DB::transaction(function () use ($messageId, $consumer, $effect) {
            if (! $this->claim($messageId, $consumer)) {
                return false; // already processed — no-op
            }

            $effect();

            return true;
        });
    }

    /**
     * Inserts the claim inside its own savepoint. On Postgres a failed statement
     * aborts the whole transaction, so the unique violation must be contained in
     * a nested transaction to be usable as control flow — otherwise the caller's
     * transaction is poisoned.
     */
    private function claim(string $messageId, string $consumer): bool
    {
        try {
            DB::transaction(function () use ($messageId, $consumer) {
                DB::table('processed_messages')->insert([
                    'message_id' => $messageId,
                    'consumer' => $consumer,
                    'processed_at' => now(),
                ]);
            });

            return true;
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') { // unique_violation
                return false;
            }
            throw $e;
        }
    }
}
