<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            /*
             * A promoção aplicada é registrada no próprio pagamento para que o
             * bônus seja idempotente e auditável mesmo se a promoção for
             * removida depois (exclusão lógica preserva a linha referenciada).
             */
            $table->foreignId('promotion_id')->nullable()->after('package_key')
                ->constrained('promotions')->nullOnDelete();
            $table->unsignedInteger('promotion_bonus_credits')->default(0)->after('promotion_id');
            $table->unsignedBigInteger('promotion_bonus_cents')->default(0)->after('promotion_bonus_credits');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('promotion_id');
            $table->dropColumn(['promotion_bonus_credits', 'promotion_bonus_cents']);
        });
    }
};
