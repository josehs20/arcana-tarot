<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Preferências pessoais de conversa.
     *
     * `chat_send_on_enter` decide se Enter envia a mensagem (Shift+Enter sempre
     * cria uma nova linha). `chat_sound_enabled` liga o aviso sonoro de novas
     * mensagens recebidas. O padrão preserva o comportamento mais comum: enviar
     * com Enter e ouvir o aviso.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('chat_send_on_enter')->default(true)->after('theme_preference');
            $table->boolean('chat_sound_enabled')->default(true)->after('chat_send_on_enter');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['chat_send_on_enter', 'chat_sound_enabled']);
        });
    }
};
