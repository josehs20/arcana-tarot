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
    | Cadastro por e-mail e senha
    |--------------------------------------------------------------------------
    |
    | Só estes provedores são aceitos no cadastro por formulário. A lista é
    | aplicada pelo App\Rules\AllowedEmailDomain e aparece no texto de ajuda do
    | formulário; a comparação é por domínio exato, sem subdomínios nem aliases.
    |
    | Contas criadas pelo Google não passam pela regra: o endereço já vem
    | verificado pelo provedor e pode estar em qualquer domínio.
    |
    */

    'registration' => [
        'allowed_email_domains' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env(
                'REGISTRATION_ALLOWED_EMAIL_DOMAINS',
                'gmail.com,hotmail.com,outlook.com,ig.com.br',
            )),
        ))),
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
