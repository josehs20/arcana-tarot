<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mensagens de voz do atendimento por áudio: caminho privado da gravação e
     * a duração informada pelo navegador (usada só na legenda do balão).
     */
    public function up(): void
    {
        Schema::table('consultation_messages', function (Blueprint $table): void {
            $table->string('audio_path')->nullable()->after('image_path');
            $table->unsignedSmallInteger('audio_duration_seconds')->nullable()->after('audio_path');
        });
    }

    public function down(): void
    {
        Schema::table('consultation_messages', function (Blueprint $table): void {
            $table->dropColumn(['audio_path', 'audio_duration_seconds']);
        });
    }
};
