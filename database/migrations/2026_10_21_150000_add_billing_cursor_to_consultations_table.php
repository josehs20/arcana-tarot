<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultations', function (Blueprint $table) {
            // Cursor de cobrança controlado exclusivamente pelo servidor.
            // `billed_minutes` nunca é aceito do navegador e avança junto com
            // o lançamento idempotente do livro-razão.
            $table->unsignedInteger('billed_minutes')->default(0)->after('started_at');

            $table->index(['status', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::table('consultations', function (Blueprint $table) {
            $table->dropIndex(['status', 'started_at']);
            $table->dropColumn('billed_minutes');
        });
    }
};
