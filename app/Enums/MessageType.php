<?php

namespace App\Enums;

/**
 * Tipo de mensagem exibida no histórico da consulta.
 *
 * `Voice` guarda apenas o caminho privado da gravação e a duração informada no
 * envio: o áudio em si nunca sai do disco privado sem passar pela rota
 * autorizada, e nenhum texto do navegador é aceito.
 */
enum MessageType: string
{
    case Text = 'text';
    case TarotCard = 'tarot_card';
    case Voice = 'voice';
}
