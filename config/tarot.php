<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Armazenamento das imagens do catálogo
    |--------------------------------------------------------------------------
    |
    | As imagens ficam em um disco privado, fora de public/. O caminho gravado
    | no banco é relativo à raiz do disco e nunca é exposto ao navegador.
    | A pré-visualização passa por uma rota autorizada.
    |
    */

    'image_disk' => 'tarot_private',
    'image_directory' => 'cards',

    /*
    | Diretório privado das cópias enviadas no chat. O arquivo é copiado pelo
    | servidor no momento do envio e preservado mesmo se a carta for editada,
    | substituída ou inativada.
    */
    'snapshot_directory' => 'consultation-cards',

    /*
    |--------------------------------------------------------------------------
    | Validação de upload
    |--------------------------------------------------------------------------
    |
    | Apenas formatos raster seguros. SVG e formatos executáveis são rejeitados
    | pela validação `image` e pela lista explícita de MIME types. O limite é
    | configurável por ambiente (em kilobytes).
    |
    */

    'image_max_kb' => (int) env('TAROT_IMAGE_MAX_KB', 5120),

    /*
    |--------------------------------------------------------------------------
    | Miniaturas do seletor de cartas
    |--------------------------------------------------------------------------
    |
    | A grade do atendente exibe dezenas de cartas por baralho. Servir o arquivo
    | original (até 5 MB) em uma célula de 76 px multiplica o tráfego e o tempo
    | de abertura do seletor. A miniatura é gerada uma única vez com a extensão
    | GD do servidor e gravada ao lado do original no mesmo disco privado; as
    | requisições seguintes leem o WebP já reduzido.
    |
    | `width` é a largura em pixels (a proporção é preservada) e `quality` é a
    | qualidade do WebP (0–100).
    |
    */

    /**
     * A grade do seletor de cartas exibe dezenas de células de ~90 px. Os
     * originais do catálogo já são pequenos em pixels (320 px de largura), mas
     * pesam 17–60 KB cada; a 220 px o WebP fica entre 2 e 27 KB, sem perda
     * visível na miniatura. Ambientes com baralhos em resolução maior podem
     * aumentar pelo `.env`.
     */
    'thumbnail' => [
        'width' => (int) env('TAROT_THUMBNAIL_WIDTH', 220),
        'quality' => (int) env('TAROT_THUMBNAIL_QUALITY', 76),
    ],

    /*
    |--------------------------------------------------------------------------
    | Miniatura das cartas do chat
    |--------------------------------------------------------------------------
    |
    | O balão da conversa desenha a carta em no máximo 104 px de largura
    | (`.chat-card`), mas o histórico é recarregado por inteiro a cada página.
    | Servir o snapshot original custava 30–60 KB por carta; a 180 px o WebP
    | fica em torno de 15 KB sem perda perceptível nesse tamanho. A imagem cheia
    | continua disponível no link do balão. O snapshot nunca é alterado, então a
    | miniatura é gerada uma única vez e reaproveitada nas próximas leituras.
    |
    */

    'snapshot_thumbnail' => [
        'width' => (int) env('TAROT_SNAPSHOT_THUMBNAIL_WIDTH', 180),
        'quality' => (int) env('TAROT_SNAPSHOT_THUMBNAIL_QUALITY', 72),
    ],

    /** @var array<string, string> MIME real => extensão usada no nome aleatório. */
    'allowed_image_mimes' => [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ],

];
