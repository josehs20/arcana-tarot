<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O mesmo livro de eventos idempotentes atende recargas e compras de
     * produto. Cada linha aponta para o pedido que originou a notificação.
     */
    public function up(): void
    {
        Schema::table('payment_webhook_events', function (Blueprint $table) {
            $table->foreignId('purchase_id')
                ->nullable()
                ->after('payment_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payment_webhook_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_id');
        });
    }
};
