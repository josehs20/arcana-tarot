<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('attendant_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->default('requested');
            // Snapshots imutáveis capturados no início oficial da sessão.
            $table->unsignedInteger('commission_rate_bps')->nullable();
            $table->unsignedInteger('rate_credits_per_minute')->nullable();
            $table->unsignedBigInteger('credit_unit_price_cents')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('ended_reason', 40)->nullable();
            $table->timestamps();

            $table->index(['attendant_id', 'status']);
            $table->index(['client_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultations');
    }
};
