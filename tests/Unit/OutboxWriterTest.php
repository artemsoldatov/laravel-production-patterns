<?php

use App\Patterns\Outbox\OutboxWriter;

it('refuses to emit outside a transaction', function () {
    // no RefreshDatabase here, so there is no ambient transaction and the guard
    // fires — exactly the production condition it protects against
    expect(fn () => (new OutboxWriter)->emit('order.placed', ['x' => 1]))
        ->toThrow(RuntimeException::class, 'must run inside a DB transaction');
});
