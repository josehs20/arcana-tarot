<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Toque individual do atendente para novas solicitações de clientes.
     *
     * O padrão é a sirene historicamente usada pelo painel; registros
     * existentes passam a apontar para ela sem qualquer ação manual.
     */
    public function up(): void
    {
        Schema::table('attendant_profiles', function (Blueprint $table) {
            $table->string('request_sound', 20)->default('siren')->after('offered_modalities');
        });
    }

    public function down(): void
    {
        Schema::table('attendant_profiles', function (Blueprint $table) {
            $table->dropColumn('request_sound');
        });
    }
};
