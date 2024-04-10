<?php

namespace App\Patterns\Outbox;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Drains unpublished outbox rows into the queue. One tick opens a transaction,
 * locks a batch with FOR UPDATE SKIP LOCKED (so concurrent relays never fight
 * over the same row and never block), dispatches each event to its routed job,
 * and marks the batch published in the same transaction.
 *
 * Delivery is at-least-once: if the process dies after dispatch but before the
 * mark commits, the row is picked up again. The job id is derived from the
 * outbox row id, so a duplicate dispatch is deduplicated by the queue and the
 * consumer stays idempotent regardless.
 */
class RelayOutbox
{
    public function __construct(private readonly Dispatcher $dispatcher)
    {
    }

    /**
     * @return int number of events relayed this tick
     */
    public function tick(int $batchSize = 50): int
    {
        return DB::transaction(function () use ($batchSize) {
            $rows = DB::table('outbox_events')
                ->whereNull('published_at')
                ->orderBy('created_at')
                ->limit($batchSize)
                // FOR UPDATE SKIP LOCKED: concurrent relays take disjoint rows
                // and never block on each other
                ->lock('for update skip locked')
                ->get(['id', 'topic', 'payload']);

            $routes = OutboxRoutes::all();
            $publishedIds = [];

            foreach ($rows as $row) {
                /** @var \stdClass $row */
                // columns are non-null strings; the guard also keeps PHPStan happy
                if (! is_string($row->id) || ! is_string($row->topic) || ! is_string($row->payload)) {
                    continue;
                }

                $jobClass = $routes[$row->topic] ?? null;
                if ($jobClass === null) {
                    Log::warning("No route for outbox topic {$row->topic}, skipping");

                    continue;
                }

                /** @var array<string, mixed> $payload */
                $payload = json_decode($row->payload, true);

                // the outbox row id is the message id → a duplicate dispatch
                // after a crash carries the same id and the consumer's inbox
                // dedup collapses it to a no-op
                $job = new $jobClass($payload, $row->id);
                $this->dispatcher->dispatch($job);

                $publishedIds[] = $row->id;
            }

            if ($publishedIds !== []) {
                DB::table('outbox_events')
                    ->whereIn('id', $publishedIds)
                    ->update(['published_at' => now()]);
            }

            return count($rows);
        });
    }
}
