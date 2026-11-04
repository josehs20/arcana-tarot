<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Travas de concorrência mantidas pelo model Consultation:
     * - open_client_id: no máximo uma consulta requested/active por cliente;
     * - active_attendant_id: no máximo uma consulta active por atendente.
     *
     * As colunas são nullable: vários NULL não conflitam em índices únicos,
     * o que permite guardar o histórico completo sem violar as invariantes.
     */
    public function up(): void
    {
        Schema::table('attendant_profiles', function (Blueprint $table) {
            $table->timestamp('last_seen_at')->nullable()->after('availability');
            $table->index('last_seen_at');
        });

        Schema::table('consultations', function (Blueprint $table) {
            $table->unsignedBigInteger('open_client_id')->nullable()->after('client_id');
            $table->unsignedBigInteger('active_attendant_id')->nullable()->after('attendant_id');
        });

        DB::table('consultations')
            ->where('status', 'active')
            ->update([
                'open_client_id' => DB::raw('client_id'),
                'active_attendant_id' => DB::raw('attendant_id'),
            ]);

        DB::table('consultations')
            ->where('status', 'requested')
            ->update(['open_client_id' => DB::raw('client_id')]);

        Schema::table('consultations', function (Blueprint $table) {
            $table->unique('open_client_id');
            $table->unique('active_attendant_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('consultations', function (Blueprint $table) {
            $table->dropUnique(['open_client_id']);
            $table->dropUnique(['active_attendant_id']);
            $table->dropIndex(['status']);
            $table->dropColumn(['open_client_id', 'active_attendant_id']);
        });

        Schema::table('attendant_profiles', function (Blueprint $table) {
            $table->dropIndex(['last_seen_at']);
            $table->dropColumn('last_seen_at');
        });
    }
};
