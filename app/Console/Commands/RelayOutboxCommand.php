<?php

namespace App\Console\Commands;

use App\Patterns\Outbox\RelayOutbox;
use Illuminate\Console\Command;

/**
 * Runs the outbox relay. `--once` drains a single batch (used by tests and
 * cron); without it the command loops as a lightweight daemon.
 */
class RelayOutboxCommand extends Command
{
    protected $signature = 'outbox:relay {--once : Drain a single batch and exit}';

    protected $description = 'Relay unpublished outbox events to the queue';

    public function handle(RelayOutbox $relay): int
    {
        if ($this->option('once')) {
            $count = $relay->tick();
            $this->info("Relayed {$count} event(s)");

            return self::SUCCESS;
        }

        $pollMs = (int) env('OUTBOX_POLL_MS', 1000);
        $this->info('Outbox relay started; polling every '.$pollMs.'ms. Ctrl+C to stop.');

        while (true) {
            $relay->tick();
            usleep($pollMs * 1000);
        }
    }
}
