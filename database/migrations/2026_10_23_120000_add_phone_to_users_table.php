<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adiciona o telefone do usuário e o marcador de confirmação.
     *
     * A coluna é nullable para preservar usuários existentes; novos cadastros
     * exigem o telefone na validação da aplicação. `phone_verified_at`
     * permanece nulo até a futura verificação por código SMS.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 20)->nullable()->after('email');
            $table->timestamp('phone_verified_at')->nullable()->after('phone');
            $table->index('phone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['phone']);
            $table->dropColumn(['phone', 'phone_verified_at']);
        });
    }
};
