<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // inbox for Stripe webhooks: the event id is the primary key, so a
        // replayed event can be inserted only once — exactly-once handling
        Schema::create('processed_stripe_events', function (Blueprint $table) {
            $table->string('event_id')->primary();
            $table->string('type');
            $table->timestampTz('processed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processed_stripe_events');
    }
};
