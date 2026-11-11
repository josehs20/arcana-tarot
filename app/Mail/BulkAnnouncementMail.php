<?php

namespace App\Mail;

use App\Support\BulkEmailTemplate;
use App\Support\MailIdentity;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * E-mail institucional de um disparo administrativo.
 *
 * O corpo é texto puro escrito pela administração e os marcadores
 * (`{{nome}}`, `{{email}}`, `{{link}}`) são substituídos aqui — a substituição
 * acontece antes da renderização, sobre uma string comum, portanto o conteúdo
 * nunca é interpretado como Blade. A view HTML escapa o texto e converte as
 * quebras de linha; a versão texto vai sem escape, pois não há HTML.
 *
 * A view recebe a chave `renderedBody` (e não `bodyText`) de propósito: o
 * Mailable expõe as propriedades públicas da classe como dados da view, então
 * uma chave com o mesmo nome seria sobrescrita pelo texto ainda com marcadores.
 */
class BulkAnnouncementMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $subjectLine,
        public readonly string $bodyText,
        public readonly ?string $recipientName = null,
        public readonly ?string $recipientEmail = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectLine,
            from: MailIdentity::from(),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.bulk-announcement',
            text: 'mail.bulk-announcement-text',
            with: [
                'subjectLine' => $this->subjectLine,
                'renderedBody' => BulkEmailTemplate::render(
                    $this->bodyText,
                    $this->recipientName,
                    $this->recipientEmail,
                ),
            ],
        );
    }
}
