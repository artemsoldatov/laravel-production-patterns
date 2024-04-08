<?php

namespace App\Patterns\Outbox;

use App\Models\OutboxEvent;

/**
 * Writes a domain event to the outbox, to be picked up by the relay and
 * dispatched to whichever job handles that topic.
 */
class OutboxWriter
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function emit(string $topic, array $payload): OutboxEvent
    {
        return OutboxEvent::create([
            'topic' => $topic,
            'payload' => $payload,
        ]);
    }
}
