<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_ledger_entries', function (Blueprint $table) {
            // Motivo explícito para ajustes administrativos; operações do
            // sistema podem deixar o campo nulo.
            $table->string('reason', 500)->nullable()->after('balance_after_cents');
            // Autor do lançamento, quando houver intervenção humana.
            $table->foreignId('created_by')
                ->nullable()
                ->after('reason')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('credit_ledger_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn('reason');
        });
    }
};
