<?php

use App\Http\Controllers\OrderController;
use App\Patterns\Webhooks\StripeWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/orders', [OrderController::class, 'store']);
Route::get('/orders/{id}', [OrderController::class, 'show']);

// Stripe posts here; the controller verifies the signature and deduplicates by
// event id — no CSRF, no auth middleware on the API group
Route::post('/webhooks/stripe', StripeWebhookController::class);
