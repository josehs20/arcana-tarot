<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('provider', 40);
            $table->string('provider_payment_id')->nullable()->unique();
            $table->unsignedBigInteger('amount_cents');
            $table->unsignedInteger('credits');
            $table->unsignedBigInteger('credit_unit_price_cents');
            $table->string('status', 20)->default('pending');
            $table->string('raw_status')->nullable();
            $table->string('idempotency_key')->unique();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
