<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sexo declarado pelo titular da conta (`App\Enums\Gender`).
     *
     * A coluna nasce anulável pelo mesmo motivo de `birth_date`: contas
     * criadas pelo Google, pelo painel administrativo e a importação do banco
     * legado podem não ter o dado. O cadastro por e-mail exige a escolha na
     * validação, então na prática só fica vazio quando a origem não informa.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('gender', 16)->nullable()->after('birth_date');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('gender');
        });
    }
};
