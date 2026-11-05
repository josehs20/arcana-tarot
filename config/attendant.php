<?php

/**
 * Preferências e uploads do cadastro de atendentes.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | MP3 personalizado do aviso de nova solicitação
    |--------------------------------------------------------------------------
    |
    | O arquivo é público (o navegador do atendente precisa baixá-lo), mas o
    | nome é aleatório e a extensão vem do MIME real detectado no servidor.
    | A duração é conferida no PHP, sem depender de ffmpeg/getID3.
    |
    */

    'request_sound_directory' => 'attendant-sounds',
    'request_sound_max_seconds' => 5,
    'request_sound_tolerance_seconds' => 0.1,
    'request_sound_max_kb' => (int) env('ATTENDANT_SOUND_MAX_KB', 1024),

    /** @var array<string, string> MIME real => extensão usada no nome aleatório. */
    'allowed_request_sound_mimes' => [
        'audio/mpeg' => 'mp3',
        'audio/mp3' => 'mp3',
        'audio/x-mpeg' => 'mp3',
    ],

    /*
    |--------------------------------------------------------------------------
    | Foto do perfil público
    |--------------------------------------------------------------------------
    |
    | A foto aparece no diretório público e no perfil do atendente. Também é
    | pública, com nome aleatório e extensão derivada do MIME real detectado
    | no servidor. O upload é opcional: sem foto o diretório mostra as iniciais.
    |
    */

    'photo_directory' => 'attendant-photos',
    'photo_max_kb' => (int) env('ATTENDANT_PHOTO_MAX_KB', 2048),

    /** @var array<string, string> MIME real => extensão usada no nome aleatório. */
    'allowed_photo_mimes' => [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ],

];
