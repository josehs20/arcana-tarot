<?php

/**
 * Configuração dos produtos vendidos na home.
 *
 * A imagem segue o mesmo fluxo dos demais uploads do painel: o arquivo é
 * normalizado por `ImageProfile::Product` e gravado no disco público com nome
 * aleatório dentro do diretório abaixo.
 */
return [

    'image_directory' => (string) env('PRODUCT_IMAGE_DIRECTORY', 'products'),

];
