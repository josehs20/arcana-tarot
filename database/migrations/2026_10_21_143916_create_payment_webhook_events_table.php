<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->string('provider', 40);
            $table->string('provider_event_id', 120)->unique();
            $table->string('event_type', 60);
            $table->string('provider_payment_id', 120)->nullable();
            $table->string('status', 20);
            $table->string('failure_reason', 80)->nullable();
            $table->char('payload_hash', 64);
            $table->unsignedBigInteger('amount_cents')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['provider_payment_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_events');
    }
};
