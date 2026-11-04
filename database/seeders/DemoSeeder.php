<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Base dos seeders de demonstração.
 *
 * Os dados criados aqui são exclusivamente fictícios e destinados a
 * desenvolvimento/homologação. Nenhum seeder desta família pode ser executado
 * com APP_ENV=production, mesmo quando chamado individualmente.
 */
abstract class DemoSeeder extends Seeder
{
    protected function ensureDemoEnvironment(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException(
                'Seeders de demonstração são bloqueados em produção. Use os comandos operacionais para criar contas reais.',
            );
        }
    }
}
