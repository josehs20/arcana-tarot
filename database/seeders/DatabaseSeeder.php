<?php

namespace Database\Seeders;

/**
 * Popula um banco local/homologação com dados fictícios e relacionados.
 *
 * A ordem respeita as dependências financeiras e de ciclo de vida:
 * configurações → contas → perfis → candidaturas → catálogo → pagamentos →
 * créditos → consultas/chat → comissões.
 */
class DatabaseSeeder extends DemoSeeder
{
    public function run(): void
    {
        $this->ensureDemoEnvironment();

        $this->call([
            SettingsSeeder::class,
            AdminSeeder::class,
            AttendantSeeder::class,
            ClientSeeder::class,
            AttendantApplicationSeeder::class,
            TarotDeckSeeder::class,
            PaymentSeeder::class,
            WalletSeeder::class,
            ConsultationSeeder::class,
            CommissionSeeder::class,
        ]);

        $this->command?->info('Dados de demonstração criados/verificados com sucesso.');
        $this->command?->line('Consulte docs/SEEDERS_E_DADOS_DE_TESTE.md para contas e cenários.');
    }
}
