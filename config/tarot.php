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

    /** @var array<string, string> MIME real => extensão usada no nome aleatório. */
    'allowed_image_mimes' => [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ],

];
