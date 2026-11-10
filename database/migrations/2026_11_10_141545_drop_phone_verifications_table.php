<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remove a verificação de celular por SMS.
 *
 * O cadastro passou a confirmar o e-mail (link assinado) e o telefone virou
 * apenas um dado de contato: nada mais grava nesta tabela. `users.phone` e
 * `users.phone_verified_at` continuam existindo — o segundo como histórico das
 * contas confirmadas por SMS na época em que o fluxo estava ativo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('phone_verifications');
    }

    /**
     * Recria a estrutura original, para que o rollback volte ao esquema em que
     * o fluxo por SMS ainda existia.
     */
    public function down(): void
    {
        if (Schema::hasTable('phone_verifications')) {
            return;
        }

        Schema::create('phone_verifications', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 20);
            $table->string('status', 20)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->json('payload')->nullable();
            $table->string('code_hash', 64)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['phone', 'status']);
            $table->index('expires_at');
        });
    }
};
