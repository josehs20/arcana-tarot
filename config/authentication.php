<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Login com Google (OAuth 2.0)
    |--------------------------------------------------------------------------
    |
    | O fluxo só é exibido quando existe a chave "Ativar login com Google" e as
    | credenciais GOOGLE_CLIENT_ID/GOOGLE_CLIENT_SECRET. Sem isso o botão não
    | aparece e as rotas respondem com erro amigável, mantendo o login por
    | e-mail e senha como caminho principal.
    |
    */

    'google' => [
        'enabled' => (bool) env('GOOGLE_LOGIN_ENABLED', false),
        'scopes' => ['openid', 'profile', 'email'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Confirmação de celular por SMS
    |--------------------------------------------------------------------------
    |
    | Com a confirmação ativa, o cadastro por e-mail só é concluído depois que
    | o cliente digita o código recebido por SMS. O código é enviado pelo
    | provedor configurado (Twilio Verify) e nunca é gerado pela aplicação.
    |
    */

    'sms' => [
        'enabled' => (bool) env('SMS_VERIFICATION_ENABLED', false),
        'provider' => env('SMS_VERIFICATION_PROVIDER', 'twilio_verify'),

        // Código do país aplicado ao converter DDD + número para E.164.
        'country_code' => env('SMS_VERIFICATION_COUNTRY_CODE', '55'),

        // Quantidade de dígitos do código (o Twilio Verify usa 6 por padrão).
        'code_length' => (int) env('SMS_VERIFICATION_CODE_LENGTH', 6),

        // Validade local do código e tentativas antes de invalidar a verificação.
        'ttl_minutes' => (int) env('SMS_VERIFICATION_TTL_MINUTES', 10),
        'max_attempts' => (int) env('SMS_VERIFICATION_MAX_ATTEMPTS', 5),

        // Intervalo mínimo entre envios (protege o custo por SMS).
        'resend_cooldown_seconds' => (int) env('SMS_VERIFICATION_RESEND_COOLDOWN_SECONDS', 60),

        // Chave de sessão que aponta para a verificação pendente.
        'session_key' => 'auth.pending_phone_verification',
    ],

    /*
    |--------------------------------------------------------------------------
    | Bônus de primeiro acesso
    |--------------------------------------------------------------------------
    |
    | Valores de instalação. Depois que a administração salva a configuração em
    | /admin/financeiro/bonus, os valores gravados em `settings` prevalecem.
    |
    */

    'first_access_bonus' => [
        'enabled' => (bool) env('FIRST_ACCESS_BONUS_ENABLED', false),
        'credits' => (int) env('FIRST_ACCESS_BONUS_CREDITS', 0),
    ],

];
