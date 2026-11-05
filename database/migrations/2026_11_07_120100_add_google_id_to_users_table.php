<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vínculo da conta local com a identidade Google.
 *
 * O e-mail continua sendo o identificador de login; `google_id` guarda o `sub`
 * do Google (imutável) para reconhecer a mesma pessoa mesmo se ela trocar o
 * e-mail na conta Google.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('google_id')->nullable()->after('email_verified_at');
            $table->unique('google_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['google_id']);
            $table->dropColumn('google_id');
        });
    }
};
