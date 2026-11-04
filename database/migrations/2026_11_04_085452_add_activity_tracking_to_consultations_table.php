<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A atividade agregada (`last_activity_at`) alimenta a interrupção por
     * inatividade; os horários por participante servem para diagnóstico e
     * evolução futura. Consultas ativas anteriores à migration começam com a
     * atividade no horário de início, preservando o comportamento anterior.
     */
    public function up(): void
    {
        Schema::table('consultations', function (Blueprint $table) {
            $table->timestamp('last_activity_at')->nullable()->after('started_at');
            $table->timestamp('client_last_activity_at')->nullable()->after('last_activity_at');
            $table->timestamp('attendant_last_activity_at')->nullable()->after('client_last_activity_at');

            $table->index(['status', 'last_activity_at']);
        });

        DB::table('consultations')
            ->whereIn('status', ['requested', 'active'])
            ->update([
                'last_activity_at' => DB::raw('COALESCE(started_at, created_at)'),
                'client_last_activity_at' => DB::raw('COALESCE(started_at, created_at)'),
                'attendant_last_activity_at' => DB::raw('COALESCE(started_at, created_at)'),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('consultations', function (Blueprint $table) {
            $table->dropIndex(['status', 'last_activity_at']);
            $table->dropColumn([
                'last_activity_at',
                'client_last_activity_at',
                'attendant_last_activity_at',
            ]);
        });
    }
};
