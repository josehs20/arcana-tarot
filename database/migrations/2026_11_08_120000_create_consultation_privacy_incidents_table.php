<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ocorrências de dado pessoal bloqueadas no chat.
     *
     * A mensagem recusada nunca é persistida em `consultation_messages`. Para
     * que a administração consiga acompanhar tentativas de contato externo,
     * guardamos apenas metadados, o trecho mascarado e, de forma cifrada, o
     * texto original (visível somente mediante auditoria).
     */
    public function up(): void
    {
        Schema::create('consultation_privacy_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consultation_id')->constrained()->cascadeOnDelete();
            $table->foreignIdFor(User::class)->nullable()->constrained()->nullOnDelete();
            $table->string('category', 30);
            $table->string('status', 20)->default('blocked');
            $table->json('detected_types');
            $table->string('masked_excerpt', 500);
            $table->text('body_original')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_notes', 500)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            // Nomes explícitos: o nome padrão do Laravel pode exceder o limite
            // de 64 caracteres para identificadores do MySQL.
            $table->index(['consultation_id', 'status', 'created_at'], 'privacy_incidents_consultation_status_index');
            $table->index(['consultation_id', 'id'], 'privacy_incidents_consultation_id_index');
            $table->index('category', 'privacy_incidents_category_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('consultation_privacy_incidents');
    }
};
