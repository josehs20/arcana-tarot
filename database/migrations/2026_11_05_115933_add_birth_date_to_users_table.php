<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Data de nascimento do cliente.
     *
     * A coluna nasce anulável para não quebrar contas antigas e contas criadas
     * pelo Google (que não informa a data). O cadastro por e-mail/telefone já
     * exige o campo na validação e as telas de atendimento mostram
     * "Não informado" enquanto a conta antiga não tiver o dado.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->date('birth_date')->nullable()->after('phone_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('birth_date');
        });
    }
};
