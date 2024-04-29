<?php

use App\Models\Account;
use App\Patterns\OptimisticLocking\AccountService;
use App\Patterns\OptimisticLocking\InsufficientFundsException;
use Illuminate\Support\Facades\DB;

it('debits an account and bumps the version', function () {
    $account = Account::create(['name' => 'A', 'balance_cents' => 1_000, 'currency' => 'USD']);

    app(AccountService::class)->debit($account->id, 400);

    $account->refresh();
    expect($account->balance_cents)->toBe(600)
        ->and($account->version)->toBe(1);
});

it('never goes negative and reports insufficient funds', function () {
    $account = Account::create(['name' => 'A', 'balance_cents' => 300, 'currency' => 'USD']);

    expect(fn () => app(AccountService::class)->debit($account->id, 500))
        ->toThrow(InsufficientFundsException::class);

    expect($account->refresh()->balance_cents)->toBe(300);
});

it('lets only one writer win a version race at the SQL level', function () {
    // both writers read the same version, then both try the conditional update.
    // exactly one matches version = 0; the other updates zero rows.
    $account = Account::create(['name' => 'A', 'balance_cents' => 1_000, 'currency' => 'USD']);

    $update = fn () => DB::table('accounts')
        ->where('id', $account->id)
        ->where('version', 0)
        ->where('balance_cents', '>=', 100)
        ->update([
            'balance_cents' => DB::raw('balance_cents - 100'),
            'version' => DB::raw('version + 1'),
        ]);

    expect($update())->toBe(1);
    expect($update())->toBe(0); // stale version → no silent overwrite

    expect($account->refresh()->balance_cents)->toBe(900)
        ->and($account->version)->toBe(1);
});

it('keeps the ledger consistent across many sequential debits', function () {
    $account = Account::create(['name' => 'A', 'balance_cents' => 1_000, 'currency' => 'USD']);
    $service = app(AccountService::class);

    for ($i = 0; $i < 10; $i++) {
        $service->debit($account->id, 50);
    }

    expect($account->refresh()->balance_cents)->toBe(500)
        ->and($account->version)->toBe(10);
});
