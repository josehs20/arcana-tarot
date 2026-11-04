<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Cada aba/dispositivo mantém uma sessão de presença própria. O token é
     * opaco e devolvido pelo servidor; nenhuma decisão de encerramento confia
     * em um horário enviado pelo navegador.
     */
    public function up(): void
    {
        Schema::create('consultation_presence_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consultation_id')->constrained()->cascadeOnDelete();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->string('role', 20);
            $table->string('session_token', 64)->unique();
            $table->timestamp('connected_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('disconnected_at')->nullable();
            $table->string('disconnect_reason', 40)->nullable();
            $table->timestamps();

            // Nomes explícitos: o nome padrão do Laravel excede o limite de
            // 64 caracteres para identificadores do MySQL.
            $table->index(['consultation_id', 'role', 'last_seen_at'], 'presence_sessions_consultation_role_seen_index');
            $table->index(['consultation_id', 'user_id', 'disconnected_at'], 'presence_sessions_consultation_user_disconnected_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('consultation_presence_sessions');
    }
};
