<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Registro de acessos
    |--------------------------------------------------------------------------
    |
    | A tela "Acessos" do painel administrativo depende desta coleta. Desligar
    | a opção interrompe novos registros e o heartbeat, mas não apaga o
    | histórico existente. A coleta segue a política de privacidade do MVP:
    | cookie anônimo, IP pseudonimizado e nenhum dado de conversa ou pagamento.
    |
    */

    'enabled' => filter_var(env('ACCESS_LOG_ENABLED', true), FILTER_VALIDATE_BOOL),

    /*
    |--------------------------------------------------------------------------
    | Retenção
    |--------------------------------------------------------------------------
    |
    | O comando arcana:prune-site-visits apaga visitas mais antigas que este
    | prazo. A retenção é deliberadamente curta: a tela serve para acompanhar
    | tráfego recente, não para formar um histórico permanente de navegação.
    |
    */

    'retention_days' => max(1, (int) env('ACCESS_LOG_RETENTION_DAYS', 90)),

    /*
    |--------------------------------------------------------------------------
    | Janela de inatividade
    |--------------------------------------------------------------------------
    |
    | Depois deste tempo sem página nova nem heartbeat, a próxima requisição do
    | mesmo navegador abre uma nova visita. O valor também é usado pelo comando
    | de expurgo para encerrar visitas abandonadas.
    |
    */

    'inactivity_minutes' => max(1, (int) env('ACCESS_LOG_INACTIVITY_MINUTES', 30)),

    /*
    |--------------------------------------------------------------------------
    | Visita em andamento
    |--------------------------------------------------------------------------
    |
    | Uma visita aberta só aparece como "Em andamento" se teve sinal de
    | atividade dentro desta janela. Sem o sinal, o fim exibido é o último
    | instante registrado — evita mostrar como online quem fechou o navegador.
    |
    */

    'online_window_minutes' => max(1, (int) env('ACCESS_LOG_ONLINE_WINDOW_MINUTES', 5)),

    /*
    |--------------------------------------------------------------------------
    | Heartbeat
    |--------------------------------------------------------------------------
    |
    | O JavaScript envia um batimento periódico enquanto a aba está visível.
    | Sem ele, o tempo no site seria apenas o intervalo entre page views e uma
    | leitura longa apareceria com duração próxima de zero.
    |
    */

    'heartbeat_seconds' => max(15, (int) env('ACCESS_LOG_HEARTBEAT_SECONDS', 60)),

    /*
    |--------------------------------------------------------------------------
    | Identidade anônima
    |--------------------------------------------------------------------------
    |
    | Cookie de longa duração com UUID aleatório. Não guarda nome, e-mail, IP
    | nem sessão do Laravel. O nome é prefixado para não colidir com outros
    | cookies da aplicação.
    |
    */

    'cookie' => 'arcana_visitor',

    'cookie_lifetime_days' => max(1, (int) env('ACCESS_LOG_COOKIE_DAYS', 730)),

    /*
    |--------------------------------------------------------------------------
    | Rotas ignoradas
    |--------------------------------------------------------------------------
    |
    | O painel administrativo fica de fora para que a equipe não infle os
    | números. Assets, API, webhooks, health check e endpoints de polling
    | também não são visitas. A checagem de Content-Type ainda descarta
    | imagens e downloads que passem por estas exceções.
    |
    */

    'excluded_paths' => [
        'admin',
        'admin/*',
        'api/*',
        'webhooks/*',
        'acessos/heartbeat',
        'up',
        'storage/*',
        'build/*',
        '*.js',
        '*.css',
        '*.map',
        '*.png',
        '*.jpg',
        '*.jpeg',
        '*.webp',
        '*.svg',
        '*.ico',
        '*.woff',
        '*.woff2',
    ],

    /*
    |--------------------------------------------------------------------------
    | Robôs
    |--------------------------------------------------------------------------
    |
    | Crawlers, monitores e clientes HTTP não entram na lista nem no gráfico.
    | O reconhecimento é conservador: na dúvida, a visita é registrada.
    |
    */

    'ignore_bots' => filter_var(env('ACCESS_LOG_IGNORE_BOTS', true), FILTER_VALIDATE_BOOL),

];
