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
         * Em producao a administracao define as tarifas em
         * /admin/financeiro; o preco por minuto e creditos x valor do credito.
         */
        $settings = [
            'credit_unit_price_cents' => '100',
            'rate_credits_per_minute' => '1',
            'rate_credits_per_minute_text' => '1',
            'rate_credits_per_minute_audio' => '2',
            'rate_credits_per_minute_video' => '3',
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
