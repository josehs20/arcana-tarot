<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultation_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consultation_id')->constrained('consultations')->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->restrictOnDelete();
            $table->string('type', 20)->default('text');
            $table->text('body')->nullable();
            // Referência à carta quando aplicável + snapshots para preservar o histórico.
            $table->foreignId('tarot_card_id')->nullable()->constrained('tarot_cards')->nullOnDelete();
            $table->string('deck_name')->nullable();
            $table->string('card_name')->nullable();
            $table->string('image_path')->nullable();
            $table->timestamps();

            $table->index(['consultation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultation_messages');
    }
};
