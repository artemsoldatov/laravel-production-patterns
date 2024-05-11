<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // inbox for queue consumers: a message id seen twice is a no-op, which
        // turns at-least-once delivery into an effectively-once effect
        Schema::create('processed_messages', function (Blueprint $table) {
            $table->string('message_id')->primary();
            $table->string('consumer');
            $table->timestampTz('processed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processed_messages');
    }
};
