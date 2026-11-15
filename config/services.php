<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    | Login com Google (Socialite). O botão só aparece quando as duas
    | credenciais estão preenchidas (ver App\Support\AuthenticationSettings).
    */
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
        /*
         * IDs nativos (Android/iOS) aceitos ao validar o ID token do SDK
         * mobile. O client ID web acima também é aceito automaticamente,
         * porque o Android costuma enviá-lo como `serverClientId`. Lista
         * separada por vírgulas; vazio desliga o login Google da API.
         */
        'mobile_client_ids' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('GOOGLE_MOBILE_CLIENT_IDS', '')),
        ))),
        'jwks_uri' => env('GOOGLE_JWKS_URI', 'https://www.googleapis.com/oauth2/v3/certs'),
    ],

];
