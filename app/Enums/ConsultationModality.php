<?php

namespace App\Enums;

/**
 * Modalidade do atendimento.
 *
 * A modalidade é escolhida pelo cliente antes da solicitação e congela o
 * preço aplicado no aceite. Texto e áudio compartilham o mesmo chat, com
 * mensagens digitadas e cartas do tarô; o áudio acrescenta as gravações de voz
 * feitas no navegador. O vídeo não tem conversa persistida — acontece inteiro
 * dentro da chamada WebRTC.
 */
enum ConsultationModality: string
{
    case Text = 'text';
    case Audio = 'audio';
    case Video = 'video';

    public function label(): string
    {
        return match ($this) {
            self::Text => 'Texto',
            self::Audio => 'Áudio',
            self::Video => 'Vídeo',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Text => 'Mensagens em tempo real com envio de cartas de Tarot pelo chat.',
            self::Audio => 'Conversa por mensagens, áudios gravados e cartas de Tarot pelo chat.',
            self::Video => 'Chamada de áudio e vídeo entre cliente e atendente.',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Text => 'message-circle',
            self::Audio => 'mic',
            self::Video => 'video',
        };
    }

    /**
     * Áudio e vídeo dependem de captura de mídia no navegador: o microfone do
     * áudio grava a mensagem de voz e o vídeo negocia a chamada WebRTC.
     */
    public function requiresMedia(): bool
    {
        return $this !== self::Text;
    }

    public function requiresVideo(): bool
    {
        return $this === self::Video;
    }

    /**
     * Chamada ao vivo (WebRTC). O atendimento por áudio não liga: a voz circula
     * pelo chat como mensagem gravada.
     */
    public function requiresCall(): bool
    {
        return $this === self::Video;
    }

    /**
     * Conversa digitada. O atendimento por áudio mantém o campo de texto no
     * chat, ao lado do botão de gravar.
     */
    public function allowsChat(): bool
    {
        return $this !== self::Video;
    }

    /**
     * Cartas do tarô entram nos atendimentos com conversa persistida — texto e
     * áudio compartilham o mesmo chat. A chamada de vídeo não recebe cartas
     * porque não existe histórico onde publicá-las.
     */
    public function allowsTarotCards(): bool
    {
        return $this !== self::Video;
    }

    /**
     * Mensagens de voz gravadas no navegador são exclusivas do atendimento por
     * áudio e entram no mesmo chat do texto.
     */
    public function allowsVoiceMessages(): bool
    {
        return $this === self::Audio;
    }

    /**
     * A modalidade possui conversa persistida (texto e/ou voz). O vídeo não
     * tem: a comunicação acontece somente dentro da chamada.
     */
    public function allowsMessageFeed(): bool
    {
        return $this->allowsChat() || $this->allowsVoiceMessages();
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $modality): string => $modality->value, self::cases());
    }
}
