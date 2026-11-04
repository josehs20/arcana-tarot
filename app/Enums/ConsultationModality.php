<?php

namespace App\Enums;

/**
 * Modalidade do atendimento.
 *
 * A modalidade é escolhida pelo cliente antes da solicitação e congela o
 * preço aplicado no aceite. O chat e o envio de cartas pertencem ao
 * atendimento por texto; áudio e vídeo usam chamadas WebRTC.
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
            self::Audio => 'Chamada de voz entre cliente e atendente, sem vídeo.',
            self::Video => 'Chamada de áudio e vídeo entre cliente e atendente.',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Text => 'message-circle',
            self::Audio => 'phone',
            self::Video => 'video',
        };
    }

    /**
     * Áudio e vídeo dependem de captura de mídia no navegador e de
     * infraestrutura WebRTC (STUN/TURN).
     */
    public function requiresMedia(): bool
    {
        return $this !== self::Text;
    }

    public function requiresVideo(): bool
    {
        return $this === self::Video;
    }

    public function allowsChat(): bool
    {
        return $this === self::Text;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $modality): string => $modality->value, self::cases());
    }
}
