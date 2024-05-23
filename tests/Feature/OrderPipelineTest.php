<?php

use App\Jobs\ProcessOrderJob;
use App\Models\Account;
use App\Models\Order;
use App\Patterns\Inbox\InboxGuard;
use App\Patterns\OptimisticLocking\AccountService;
use App\Patterns\Orders\OrderService;
use App\Patterns\Outbox\RelayOutbox;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

function seedAccount(int $balance = 10_000): Account
{
    return Account::create(['name' => 'Acme', 'balance_cents' => $balance, 'currency' => 'USD']);
}

function drainQueue(int $maxJobs = 5): void
{
    for ($i = 0; $i < $maxJobs; $i++) {
        Artisan::call('queue:work', [
            'connection' => 'redis',
            '--once' => true,
            '--tries' => 3,
            '--backoff' => 0,
        ]);

        // queue:work attaches a fresh failed-job listener on every call; looping
        // it in one test process would log a dead-letter once per pass, so drop
        // the listener between runs to mimic separate worker processes
        Event::forget(JobFailed::class);
    }
}

it('runs place -> relay -> process end to end', function () {
    $account = seedAccount(1_000);
    $order = app(OrderService::class)->place($account->id, 400, 'ord-e2e');

    app(RelayOutbox::class)->tick();   // outbox -> queue
    drainQueue();                       // worker processes the job

    $order->refresh();
    expect($order->status)->toBe('processing')
        ->and($account->refresh()->balance_cents)->toBe(600);
});

it('processes a duplicated delivery exactly once', function () {
    $account = seedAccount(1_000);
    $order = Order::create([
        'account_id' => $account->id,
        'reference' => 'ord-dup',
        'amount_cents' => 300,
        'currency' => 'USD',
        'status' => 'pending',
    ]);

    // same message id delivered twice — the inbox guard collapses the second
    $job = new ProcessOrderJob(['order_id' => $order->id], 'msg-1');
    app()->call([$job, 'handle']);
    app()->call([$job, 'handle']);

    expect($account->refresh()->balance_cents)->toBe(700) // debited once, not twice
        ->and(DB::table('processed_messages')->where('message_id', 'msg-1')->count())->toBe(1);
});

it('dead-letters a poisoned job after exhausting retries', function () {
    $account = seedAccount(1_000);
    $order = Order::create([
        'account_id' => $account->id,
        'reference' => 'ord-poison',
        'amount_cents' => 300,
        'currency' => 'USD',
        'status' => 'pending',
    ]);

    dispatch(new ProcessOrderJob(['order_id' => $order->id, 'poison' => true], 'msg-poison'));
    drainQueue(6);

    expect(DB::table('failed_jobs')->count())->toBe(1)
        ->and($account->refresh()->balance_cents)->toBe(1_000); // never debited
});

it('replays a dead-lettered job safely once the fault is fixed', function () {
    // a non-poisoned job that the inbox already marked processed must be a
    // no-op on replay — proving DLQ replay can't double-apply
    $account = seedAccount(1_000);
    $order = Order::create([
        'account_id' => $account->id,
        'reference' => 'ord-replay',
        'amount_cents' => 200,
        'currency' => 'USD',
        'status' => 'pending',
    ]);

    $inbox = app(InboxGuard::class);
    $accounts = app(AccountService::class);

    $effect = function () use ($order, $accounts) {
        $accounts->debit($order->account_id, $order->amount_cents);
        $order->update(['status' => 'processing']);
    };

    expect($inbox->once('msg-replay', 'process-order', $effect))->toBeTrue();
    expect($inbox->once('msg-replay', 'process-order', $effect))->toBeFalse(); // replay = no-op

    expect($account->refresh()->balance_cents)->toBe(800);
});
