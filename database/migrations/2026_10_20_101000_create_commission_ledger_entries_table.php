<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendant_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('consultation_id')->nullable()->constrained('consultations')->restrictOnDelete();
            $table->unsignedInteger('commission_rate_bps');
            $table->unsignedBigInteger('base_amount_cents');
            $table->unsignedBigInteger('commission_amount_cents');
            $table->string('type', 20)->default('accrual');
            $table->text('reason')->nullable();
            $table->string('idempotency_key')->unique();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['attendant_id', 'created_at']);
            $table->index(['consultation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_ledger_entries');
    }
};
