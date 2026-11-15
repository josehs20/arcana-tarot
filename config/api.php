<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API móvel (Sanctum)
    |--------------------------------------------------------------------------
    |
    | Limites por minuto dos endpoints `/api/*`. O limite geral usa o usuário
    | autenticado quando há token válido; sem identidade, cai para o IP. O
    | limite de autenticação é mais restrito e será aplicado aos endpoints de
    | login, cadastro e recuperação de senha da etapa 03.
    |
    */

    'rate_limit_per_minute' => (int) env('API_RATE_LIMIT_PER_MINUTE', 60),

    'auth_rate_limit_per_minute' => (int) env('API_AUTH_RATE_LIMIT_PER_MINUTE', 10),

];
