<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Separa os atendimentos em texto, áudio e vídeo.
     *
     * - consultations.modality: modalidade escolhida pelo cliente;
     * - consultations.price_per_minute_cents: snapshot informativo do preço
     *   aplicado no aceite (a cobrança continua usando rate × unit, também
     *   congelados no aceite);
     * - attendant_profiles.offered_modalities: modalidades realmente
     *   oferecidas pelo profissional;
     * - consultation_calls / consultation_call_signals: sinalização WebRTC
     *   real entre os dois navegadores (oferta, resposta e candidatos ICE).
     */
    public function up(): void
    {
        Schema::table('consultations', function (Blueprint $table) {
            $table->string('modality', 10)->default('text')->after('status');
            $table->unsignedBigInteger('price_per_minute_cents')->nullable()->after('credit_unit_price_cents');

            $table->index(['modality', 'status']);
        });

        DB::table('consultations')
            ->whereNotNull('rate_credits_per_minute')
            ->whereNotNull('credit_unit_price_cents')
            ->update([
                'price_per_minute_cents' => DB::raw('rate_credits_per_minute * credit_unit_price_cents'),
            ]);

        Schema::table('attendant_profiles', function (Blueprint $table) {
            $table->json('offered_modalities')->nullable()->after('specialties');
        });

        DB::table('attendant_profiles')->update([
            'offered_modalities' => json_encode(['text']),
        ]);

        Schema::create('consultation_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consultation_id')->unique()->constrained('consultations')->cascadeOnDelete();
            $table->foreignId('requested_by_id')->constrained('users')->restrictOnDelete();
            $table->string('modality', 10);
            $table->string('status', 20)->default('ringing');
            $table->string('ended_reason', 40)->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('consultation_call_signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consultation_id')->constrained('consultations')->cascadeOnDelete();
            $table->foreignId('from_user_id')->constrained('users')->restrictOnDelete();
            $table->string('type', 20);
            $table->json('payload');
            $table->timestamps();

            $table->index(['consultation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultation_call_signals');
        Schema::dropIfExists('consultation_calls');

        Schema::table('attendant_profiles', function (Blueprint $table) {
            $table->dropColumn('offered_modalities');
        });

        Schema::table('consultations', function (Blueprint $table) {
            $table->dropIndex(['modality', 'status']);
            $table->dropColumn(['modality', 'price_per_minute_cents']);
        });
    }
};
