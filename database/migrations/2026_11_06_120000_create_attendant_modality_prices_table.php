<?php

use App\Enums\ConsultationModality;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Preço por minuto definido por atendente e modalidade.
     *
     * Substitui a tarifa global em créditos por modalidade
     * (`rate_credits_per_minute_text|audio|video`): o valor efetivamente
     * cobrado passa a ser o preço em centavos configurado para o profissional.
     * A tabela guarda apenas a configuração corrente — consultas já aceitas
     * mantêm o snapshot em `consultations.price_per_minute_cents` e nunca são
     * recalculadas.
     *
     * A migration copia as tarifas globais vigentes (créditos × valor do
     * crédito) para os perfis existentes, de modo que nenhum atendente em
     * produção fique sem preço imediatamente após a atualização. Em bancos
     * novos as tabelas estão vazias e o backfill não grava nada.
     */
    public function up(): void
    {
        Schema::create('attendant_modality_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendant_profile_id')->constrained('attendant_profiles')->cascadeOnDelete();
            $table->string('modality', 10);
            $table->unsignedBigInteger('price_per_minute_cents');
            $table->timestamps();

            $table->unique(['attendant_profile_id', 'modality']);
            $table->index(['modality', 'price_per_minute_cents']);
        });

        $this->backfillFromGlobalRates();
    }

    public function down(): void
    {
        Schema::dropIfExists('attendant_modality_prices');
    }

    /**
     * Converte as tarifas globais anteriores em preços por atendente.
     */
    private function backfillFromGlobalRates(): void
    {
        $unitPriceCents = $this->settingAsInteger('credit_unit_price_cents');

        if ($unitPriceCents === null) {
            return;
        }

        $fallbackRate = $this->settingAsInteger('rate_credits_per_minute');
        $now = now();

        DB::table('attendant_profiles')
            ->select(['id', 'offered_modalities'])
            ->orderBy('id')
            ->chunk(100, function ($profiles) use ($unitPriceCents, $fallbackRate, $now): void {
                $rows = [];

                foreach ($profiles as $profile) {
                    foreach ($this->offeredModalities($profile->offered_modalities) as $modality) {
                        $rate = $this->settingAsInteger('rate_credits_per_minute_'.$modality->value)
                            ?? $fallbackRate;

                        if ($rate === null || $rate <= 0) {
                            continue;
                        }

                        $rows[] = [
                            'attendant_profile_id' => $profile->id,
                            'modality' => $modality->value,
                            'price_per_minute_cents' => $rate * $unitPriceCents,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }

                if ($rows !== []) {
                    DB::table('attendant_modality_prices')->insertOrIgnore($rows);
                }
            });
    }

    /**
     * @return array<int, ConsultationModality>
     */
    private function offeredModalities(mixed $raw): array
    {
        $values = is_string($raw) ? json_decode($raw, true) : $raw;

        if (! is_array($values) || $values === []) {
            return [ConsultationModality::Text];
        }

        $modalities = [];

        foreach (ConsultationModality::cases() as $modality) {
            if (in_array($modality->value, $values, true)) {
                $modalities[] = $modality;
            }
        }

        return $modalities === [] ? [ConsultationModality::Text] : $modalities;
    }

    private function settingAsInteger(string $key): ?int
    {
        if (! Schema::hasTable('settings')) {
            return null;
        }

        $value = DB::table('settings')->where('key', $key)->value('value');

        return is_numeric($value) ? (int) $value : null;
    }
};
