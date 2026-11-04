<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marca a última leitura de cada participante. O chat usa esses instantes
     * para exibir um indicador simples de mensagens novas nos painéis. São
     * colunas controladas pelo model, nunca preenchidas pelo navegador.
     */
    public function up(): void
    {
        Schema::table('consultations', function (Blueprint $table) {
            $table->timestamp('client_last_read_at')->nullable()->after('ended_reason');
            $table->timestamp('attendant_last_read_at')->nullable()->after('client_last_read_at');
        });
    }

    public function down(): void
    {
        Schema::table('consultations', function (Blueprint $table) {
            $table->dropColumn(['client_last_read_at', 'attendant_last_read_at']);
        });
    }
};
