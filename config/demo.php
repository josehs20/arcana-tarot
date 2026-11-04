<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Dados de demonstração
    |--------------------------------------------------------------------------
    |
    | A senha abaixo só é usada na criação inicial das contas do seeder. Ela
    | nunca é aplicada em produção (o DatabaseSeeder é bloqueado nesse ambiente)
    | e nunca redefine a senha de uma conta já existente.
    |
    */

    'password' => (string) env('ARCANA_DEMO_PASSWORD', 'secret'),

];
