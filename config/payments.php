<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Provedor de pagamentos
    |--------------------------------------------------------------------------
    |
    | O MVP não possui provedor PIX escolhido (Q-01). Os drivers implementados
    | são `pix_sandbox`, que não movimenta dinheiro real e não usa credenciais
    | bancárias, e `infinitepay`, que delega a cobrança ao Checkout Integrado da
    | InfinitePay (cartão/PIX) e confirma o pagamento apenas no servidor.
    |
    | Um provedor PIX de produção deve ser adicionado como nova implementação de
    | App\Support\Payments\Contracts\PixProvider antes de alterar esta chave.
    |
    | Qualquer driver desconhecido falha ao ser resolvido, em vez de cair
    | silenciosamente em um comportamento inseguro.
    |
    */

    'provider' => env('PAYMENT_PROVIDER', 'pix_sandbox'),

    /*
    |--------------------------------------------------------------------------
    | Pacotes de créditos do checkout
    |--------------------------------------------------------------------------
    |
    | Catálogo de FALLBACK. A fonte principal é o setting `credit_packages`,
    | mantido pela administração em /admin/financeiro (cartão "Pacotes de
    | créditos do checkout"). Este mapa só é usado enquanto nenhum catálogo
    | tiver sido salvo pelo admin.
    |
    | Cada entrada é `chave => quantidade de créditos`. O valor em reais é
    | sempre calculado no servidor (quantidade × preço do crédito definido pela
    | administração) e nunca vem do navegador. Pacotes cujo total fique fora dos
    | limites de recarga são ignorados automaticamente.
    |
    */

    'packages' => [
        'credits_50' => 50,
        'credits_100' => 100,
        'credits_250' => 250,
        'credits_500' => 500,
    ],

    /*
    |--------------------------------------------------------------------------
    | InfinitePay (Checkout Integrado)
    |--------------------------------------------------------------------------
    |
    | `handle` é a InfiniteTag da conta que recebe o pagamento. A `api_key` é
    | opcional (habilita opções avançadas do link) e nunca é enviada ao
    | navegador. `webhook_url` deve apontar para uma URL pública deste sistema;
    | quando vazia, a rota nomeada `webhooks.infinitepay` é usada.
    |
    */

    'infinitepay' => [
        'handle' => env('INFINITEPAY_HANDLE'),
        'api_key' => env('INFINITEPAY_API_KEY'),
        'base_url' => env('INFINITEPAY_BASE_URL', 'https://api.checkout.infinitepay.io'),
        'webhook_url' => env('INFINITEPAY_WEBHOOK_URL'),
        'charge_ttl_minutes' => (int) env('INFINITEPAY_CHARGE_TTL_MINUTES', 1440),
        'timeout_seconds' => (int) env('INFINITEPAY_TIMEOUT_SECONDS', 15),
    ],

    'sandbox' => [
        // Em produção o padrão é desabilitado. Habilite apenas em local/testing.
        'enabled' => (bool) env('PAYMENT_SANDBOX_ENABLED', env('APP_ENV', 'production') !== 'production'),

        // Segredo HMAC do webhook mock. Gere com `openssl rand -hex 32`.
        // Nunca reutilize este valor em um provedor real.
        'webhook_secret' => env('PAYMENT_SANDBOX_WEBHOOK_SECRET'),

        // Janela máxima entre o timestamp assinado e o recebimento.
        'webhook_tolerance_seconds' => (int) env('PAYMENT_SANDBOX_WEBHOOK_TOLERANCE_SECONDS', 300),

        // Validade da cobrança mock, em minutos.
        'charge_ttl_minutes' => (int) env('PAYMENT_SANDBOX_CHARGE_TTL_MINUTES', 30),
    ],

    // Limites de segurança para recargas (valores inteiros em centavos).
    'min_amount_cents' => (int) env('PAYMENT_MIN_AMOUNT_CENTS', 100),
    'max_amount_cents' => (int) env('PAYMENT_MAX_AMOUNT_CENTS', 1_000_000),

];
