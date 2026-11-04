<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->string('package_key', 40)->nullable()->after('credit_unit_price_cents');
            $table->text('checkout_url')->nullable()->after('pix_copy_paste');
            $table->string('invoice_slug', 120)->nullable()->after('checkout_url');
            $table->string('transaction_nsu', 120)->nullable()->after('invoice_slug');
            $table->string('capture_method', 20)->nullable()->after('transaction_nsu');
            $table->timestamp('verified_at')->nullable()->after('paid_at');

            $table->unique('transaction_nsu');
            $table->index('invoice_slug');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropUnique(['transaction_nsu']);
            $table->dropIndex(['invoice_slug']);
            $table->dropColumn([
                'package_key',
                'checkout_url',
                'invoice_slug',
                'transaction_nsu',
                'capture_method',
                'verified_at',
            ]);
        });
    }
};
