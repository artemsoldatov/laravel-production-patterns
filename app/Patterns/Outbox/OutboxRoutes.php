<?php

namespace App\Patterns\Outbox;

/**
 * Maps an outbox topic to the queued job that handles it. Keeping this as data
 * makes adding a new event a one-line change and keeps the relay generic.
 */
class OutboxRoutes
{
    /**
     * @return array<string, class-string>
     */
    public static function all(): array
    {
        return [];
    }
}
