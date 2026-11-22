<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Compras de produtos feitas por clientes.
     *
     * Os campos do produto são uma cópia do momento da compra: o histórico não
     * depende de o produto continuar existindo ou com o mesmo preço. O status
     * só muda por confirmação server-side do provedor de pagamento.
     */
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_title', 120);
            $table->unsignedInteger('amount_cents');
            $table->unsignedInteger('credits_amount');
            $table->unsignedInteger('credit_unit_price_cents')->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('provider', 40)->nullable();
            $table->string('external_reference', 64)->unique();
            $table->string('idempotency_key', 120)->nullable()->unique();
            $table->text('checkout_url')->nullable();
            $table->string('invoice_slug')->nullable();
            $table->string('transaction_nsu')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchases');
    }
};
