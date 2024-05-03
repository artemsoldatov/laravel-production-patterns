<?php

use App\Patterns\Webhooks\StripeWebhookController;
use Illuminate\Support\Facades\Route;

// Stripe posts here; the controller verifies the signature and deduplicates by
// event id — no CSRF, no auth middleware on the API group
Route::post('/webhooks/stripe', StripeWebhookController::class);
