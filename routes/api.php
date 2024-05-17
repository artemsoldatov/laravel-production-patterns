<?php

use App\Http\Controllers\OrderController;
use App\Patterns\Webhooks\StripeWebhookController;
use Illuminate\Support\Facades\Route;

// placing an order is a money-adjacent POST → protect retries with the
// idempotency middleware (send an Idempotency-Key header)
Route::post('/orders', [OrderController::class, 'store'])->middleware('idempotency');
Route::get('/orders/{id}', [OrderController::class, 'show']);

// Stripe posts here; the controller verifies the signature and deduplicates by
// event id — no CSRF, no auth middleware on the API group
Route::post('/webhooks/stripe', StripeWebhookController::class);
