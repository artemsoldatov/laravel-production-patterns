<?php

namespace App\Patterns\Outbox;

use App\Models\OutboxEvent;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Writes a domain event to the outbox. Must be called inside a database
 * transaction so the event commits atomically with the state change it
 * announces — that atomicity is the whole point of the pattern.
 */
class OutboxWriter
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function emit(string $topic, array $payload): OutboxEvent
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException(
                'OutboxWriter::emit must run inside a DB transaction so the event '.
                'commits atomically with the domain change.'
            );
        }

        return OutboxEvent::create([
            'topic' => $topic,
            'payload' => $payload,
        ]);
    }
}
