<?php

namespace App\Patterns\OptimisticLocking;

use App\Models\Account;
use Illuminate\Support\Facades\DB;

/**
 * Debits an account with optimistic concurrency control. Instead of locking the
 * row for the whole business operation, we read the version, then commit with a
 * conditional UPDATE that only matches if the version is unchanged. A lost race
 * updates zero rows and we retry — no write is ever silently overwritten.
 *
 * The same conditional UPDATE enforces the balance floor, so a debit can never
 * push an account negative even under concurrency.
 */
class AccountService
{
    public function __construct(private readonly int $maxRetries = 3)
    {
    }

    public function debit(string $accountId, int $amountCents): Account
    {
        for ($attempt = 0; $attempt < $this->maxRetries; $attempt++) {
            /** @var Account $account */
            $account = Account::query()->findOrFail($accountId);

            $affected = DB::table('accounts')
                ->where('id', $accountId)
                ->where('version', $account->version)
                ->where('balance_cents', '>=', $amountCents)
                ->update([
                    'balance_cents' => DB::raw('balance_cents - '.(int) $amountCents),
                    'version' => DB::raw('version + 1'),
                    'updated_at' => now(),
                ]);

            if ($affected === 1) {
                return $account->refresh();
            }

            // zero rows: either the version moved (a concurrent writer) or the
            // balance floor blocked us — distinguish by re-reading
            $fresh = Account::query()->findOrFail($accountId);
            if ($fresh->balance_cents < $amountCents) {
                throw new InsufficientFundsException(
                    "Account {$accountId} has {$fresh->balance_cents}, needs {$amountCents}"
                );
            }
            // else it was a version race — loop and retry against fresh state
        }

        throw new StaleVersionException(
            "Account {$accountId} could not be debited after {$this->maxRetries} attempts"
        );
    }
}
