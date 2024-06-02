# laravel-production-patterns

Production reliability patterns in idiomatic Laravel, each backed by tests that exercise the failure path instead of the happy path: outbox, idempotency, optimistic locking, webhook deduplication, and an inbox that turns at-least-once delivery into an effectively-once effect.

The patterns are threaded through one thin demo domain, placing and settling an order, so they compose the way they would in a real service instead of sitting isolated in five unrelated examples.

## What's in here

A transactional outbox writes in the same transaction as the domain change, and a relay drains it with FOR UPDATE SKIP LOCKED, including recovery for rows that got committed but never made it to the broker.

Idempotency-Key middleware checks a body fingerprint against the key, so retrying with the same key and a different body returns a 422 instead of silently overwriting the first request.

Optimistic locking uses a version column plus a conditional update that also enforces the balance floor, so two concurrent writers can't stomp on each other's balance change.

Stripe webhook handling verifies the signature and keeps an inbox keyed by event id, so a redelivered or out-of-order webhook only gets handled once.

The consumer side dedupes on its own inbox and retries a bounded number of times before a job lands in the dead-letter queue (failed_jobs), with a safe replay path back in.

## Stack

Laravel 11, PHP 8.3, PostgreSQL 16, Redis 7, the Stripe SDK, Pest for tests, Larastan at PHPStan level max, Pint for formatting, GitHub Actions for CI.

## Running it

```bash
docker compose up -d          # Postgres 16 (55434) + Redis 7 (56381)
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
./vendor/bin/pest             # runs against the real Postgres + Redis
```

The same checks CI runs:

```bash
./vendor/bin/pint --test      # formatting
vendor/bin/phpstan analyse    # static analysis at level max
./vendor/bin/pest             # tests
```

The outbox relay runs as either a daemon or a single batch:

```bash
php artisan outbox:relay          # loop
php artisan outbox:relay --once   # one batch, good for cron
```
