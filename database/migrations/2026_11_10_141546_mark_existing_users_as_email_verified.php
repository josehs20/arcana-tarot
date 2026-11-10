<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Marca as contas que já existiam como confirmadas.
 *
 * Antes do link por e-mail, `email_verified_at` só era preenchido pelo Google e
 * por ajustes administrativos. Sem este backfill, o middleware `verified`
 * bloquearia todo mundo que já usava a plataforma. A data usada é a última
 * alteração da conta (ou a criação), para não inventar um horário novo.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->whereNull('email_verified_at')
            ->update([
                'email_verified_at' => DB::raw('COALESCE(updated_at, created_at)'),
            ]);
    }

    /**
     * Sem reversão: o `down()` não consegue distinguir as contas marcadas aqui
     * das que confirmaram o e-mail depois da mudança.
     */
    public function down(): void
    {
        //
    }
};
