<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_ledger_entries', function (Blueprint $table) {
            // Autor de ajustes/estornos. O accrual automático não possui autor.
            $table->foreignId('actor_id')
                ->nullable()
                ->after('type')
                ->constrained('users')
                ->nullOnDelete();

            // Vincula o estorno ao lançamento original; o índice único impede
            // que a mesma comissão seja estornada duas vezes.
            $table->foreignId('reversed_entry_id')
                ->nullable()
                ->after('consultation_id')
                ->unique()
                ->constrained('commission_ledger_entries')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('commission_ledger_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reversed_entry_id');
            $table->dropConstrainedForeignId('actor_id');
        });
    }
};
