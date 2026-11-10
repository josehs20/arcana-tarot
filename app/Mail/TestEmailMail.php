<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Mensagem de verificação do transporte de e-mail.
 *
 * Enviada pelo comando `arcana:send-test-email` pelo mailer escolhido no `.env`.
 * Usa as mesmas identidade e views dos disparos reais para que o teste valide a
 * renderização (HTML e texto) e não apenas a autenticação SMTP.
 */
class TestEmailMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $subjectLine,
        public readonly string $recipientEmail,
        public readonly string $mailerName,
        public readonly string $serverDescription,
        public readonly string $environment,
        public readonly string $sentAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.test-email',
            text: 'mail.test-email-text',
        );
    }
}
