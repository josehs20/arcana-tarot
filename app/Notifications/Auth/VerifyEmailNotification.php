<?php

namespace App\Notifications\Auth;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Link de confirmação de e-mail enviado no cadastro.
 *
 * Estende a notificação do framework para reaproveitar a geração da URL
 * assinada (validade definida em `auth.verification.expire`) e troca apenas a
 * apresentação: o template segue a identidade visual usada nos disparos
 * administrativos, em vez do layout neutro do Laravel.
 *
 * As chaves passadas à view não repetem propriedades públicas do MailMessage
 * (`subject`, `view`, ...) — essas seriam sobrescritas pelos dados da mensagem.
 */
class VerifyEmailNotification extends VerifyEmail
{
    /**
     * @param  mixed  $notifiable
     */
    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->subject('Confirme seu e-mail | '.config('app.name'))
            ->view('mail.verify-email', [
                'recipientName' => $notifiable->name,
                'recipientEmail' => $notifiable->email,
                'verificationUrl' => $this->verificationUrl($notifiable),
                'expiresInMinutes' => (int) config('auth.verification.expire', 60),
            ]);
    }
}
