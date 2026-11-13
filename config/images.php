<?php

/**
 * Limites globais do upload de imagens do painel administrativo.
 *
 * O mesmo teto vale para cartas do catálogo, fotos de atendentes, logotipo,
 * favicon e mídia das seções. Depois de receber o arquivo, o servidor
 * normaliza a imagem (orientação do EXIF, redução para o perfil de saída,
 * recomposição em WebP/PNG e remoção de metadados) antes de gravá-la.
 *
 * As dimensões e a qualidade de cada perfil de saída ficam em
 * `App\Support\Images\ImageProfile`; aqui estão apenas as guardas que valem
 * para qualquer upload.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Driver de processamento
    |--------------------------------------------------------------------------
    |
    | `gd` é o padrão: já está presente na imagem Docker e cobre JPEG, PNG e
    | WebP. `imagick` é aceito quando a extensão estiver instalada, mas não é
    | necessário para o fluxo normal. Sem nenhum dos dois o servidor grava o
    | arquivo recebido sem normalizar e registra um aviso no log.
    |
    */

    'driver' => (string) env('IMAGE_DRIVER', 'gd'),

    /*
    |--------------------------------------------------------------------------
    | Teto de recebimento
    |--------------------------------------------------------------------------
    |
    | Limite em kilobytes aplicado pela validação (`max:`) e exibido nas telas.
    | Precisa ser compatível com `upload_max_filesize`/`post_max_size` do PHP
    | (ver docker/php/uploads.ini) e com o `client_max_body_size` do Nginx.
    |
    */

    'max_upload_kb' => (int) env('IMAGE_UPLOAD_MAX_KB', 25600),

    /*
    |--------------------------------------------------------------------------
    | Guarda contra "decompression bomb"
    |--------------------------------------------------------------------------
    |
    | O tamanho em bytes não limita a memória necessária para decodificar: um
    | PNG de poucos KB pode declarar 50000x50000 pixels. O teto de pixels é
    | checado no cabeçalho, antes de qualquer decodificação.
    |
    */

    'max_pixels' => (int) env('IMAGE_MAX_PIXELS', 40_000_000),

    /*
    |--------------------------------------------------------------------------
    | Memória do processamento
    |--------------------------------------------------------------------------
    |
    | O normalizador eleva o limite apenas quando o valor configurado é maior
    | que o atual do processo e recusa a imagem quando nem esse teto cobre a
    | decodificação. Cada pixel consome cerca de 12 bytes entre origem, cópia
    | redimensionada e codificação.
    |
    */

    'memory_limit' => (string) env('IMAGE_MEMORY_LIMIT', '512M'),

];
