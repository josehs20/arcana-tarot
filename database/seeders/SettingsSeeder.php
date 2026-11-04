<?php

namespace Database\Seeders;

use App\Models\Setting;

class SettingsSeeder extends DemoSeeder
{
    public function run(): void
    {
        $this->ensureDemoEnvironment();

        /*
         * Valores de demonstracao (somente em ambiente de demonstracao).
         * O preco por minuto e definido por atendente e modalidade em
         * /admin/modalidades (ver AttendantSeeder); aqui ficam apenas o valor
         * do credito usado nas recargas e as regras operacionais.
         */
        $settings = [
            'credit_unit_price_cents' => '100',
            'minimum_balance_minutes' => '1',
            'consultation_inactivity_timeout_minutes' => '5',
            'consultation_reconnection_tolerance_seconds' => '90',
        ];

        foreach ($settings as $key => $value) {
            Setting::query()->firstOrCreate(
                ['key' => $key],
                ['value' => $value, 'type' => 'integer'],
            );
        }
    }
}
