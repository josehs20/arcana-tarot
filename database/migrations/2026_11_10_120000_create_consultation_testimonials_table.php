<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Depoimentos dos clientes sobre atendimentos concluídos.
     *
     * Cada consulta gera no máximo um depoimento: a chave estrangeira única é a
     * garantia final contra envios duplicados (o formulário também bloqueia).
     * `client_id` e `attendant_id` são copiados da consulta para que a leitura
     * pública não dependa de joins adicionais, mas a consulta continua sendo a
     * fonte da verdade dos participantes.
     */
    public function up(): void
    {
        Schema::create('consultation_testimonials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consultation_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('attendant_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->string('message', 500);
            $table->boolean('is_hidden')->default(false);
            $table->foreignId('hidden_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('hidden_at')->nullable();
            $table->timestamps();

            // Nomes explícitos: o nome padrão do Laravel pode exceder o limite
            // de 64 caracteres para identificadores do MySQL.
            $table->index(['attendant_id', 'is_hidden', 'created_at'], 'testimonials_attendant_visibility_index');
            $table->index(['client_id', 'created_at'], 'testimonials_client_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('consultation_testimonials');
    }
};
