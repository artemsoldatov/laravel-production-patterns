<?php

namespace App\Patterns\Webhooks;

use App\Models\Order;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

/**
 * Stripe webhook sink. Two defences make it safe:
 *  - signature verification rejects forged calls (replay of an old body fails
 *    because the timestamp is part of the signed payload);
 *  - an inbox keyed by the Stripe event id makes handling exactly-once, so the
 *    at-least-once retries Stripe performs never double-apply.
 */
class StripeWebhookController
{
    public function __invoke(Request $request): JsonResponse
    {
        $secret = config('services.stripe.webhook_secret');
        $secret = is_string($secret) ? $secret : '';

        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                (string) $request->header('Stripe-Signature'),
                $secret,
            );
        } catch (SignatureVerificationException|\UnexpectedValueException $e) {
            return response()->json(['message' => 'Invalid signature'], 400);
        }

        $duplicate = false;

        DB::transaction(function () use ($event, &$duplicate) {
            if (! $this->claim($event)) {
                $duplicate = true;

                return;
            }

            $this->apply($event);
        });

        return response()->json(
            $duplicate ? ['received' => true, 'duplicate' => true] : ['received' => true]
        );
    }

    /**
     * Records the event id in its own savepoint. On Postgres a failed insert
     * aborts the whole transaction, so the unique violation is contained in a
     * nested transaction and used as control flow — a replayed event is a no-op.
     */
    private function claim(Event $event): bool
    {
        try {
            DB::transaction(function () use ($event) {
                DB::table('processed_stripe_events')->insert([
                    'event_id' => $event->id,
                    'type' => $event->type,
                    'processed_at' => now(),
                ]);
            });

            return true;
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') { // unique_violation
                return false;
            }
            throw $e;
        }
    }

    private function apply(Event $event): void
    {
        if ($event->type !== 'checkout.session.completed') {
            Log::debug("Unhandled Stripe event {$event->type}");

            return;
        }

        /** @var array<string, mixed> $data */
        $data = $event->data->toArray();
        /** @var array<string, mixed> $session */
        $session = is_array($data['object'] ?? null) ? $data['object'] : [];
        $reference = $session['client_reference_id'] ?? null;
        if (! is_string($reference)) {
            return;
        }

        Order::query()
            ->where('reference', $reference)
            ->where('status', '!=', 'paid')
            ->update([
                'status' => 'paid',
                'stripe_session_id' => is_string($session['id'] ?? null) ? $session['id'] : null,
            ]);
    }
}
