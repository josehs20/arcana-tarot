<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultation_messages', function (Blueprint $table) {
            // Cartas enviadas na mesma ação compartilham o mesmo lote. O chat
            // agrupa as mensagens do lote em um único bloco com as imagens lado
            // a lado, sem perder a ordem nem o horário de cada uma.
            $table->uuid('batch_id')->nullable()->after('type');

            $table->index(['consultation_id', 'batch_id']);
        });
    }

    public function down(): void
    {
        Schema::table('consultation_messages', function (Blueprint $table) {
            $table->dropIndex(['consultation_id', 'batch_id']);
            $table->dropColumn('batch_id');
        });
    }
};
