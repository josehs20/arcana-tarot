<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('external_reference', 80)->nullable()->unique()->after('provider_payment_id');
            $table->string('failure_reason', 120)->nullable()->after('raw_status');
            $table->text('pix_copy_paste')->nullable()->after('raw_status');
            $table->timestamp('expires_at')->nullable()->after('paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['external_reference']);
            $table->dropColumn(['external_reference', 'failure_reason', 'pix_copy_paste', 'expires_at']);
        });
    }
};
