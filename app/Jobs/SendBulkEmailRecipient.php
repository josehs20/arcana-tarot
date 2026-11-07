<?php

namespace App\Jobs;

use App\Enums\EmailRecipientStatus;
use App\Mail\BulkAnnouncementMail;
use App\Models\EmailCampaignRecipient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Envia um e-mail de disparo para um único destinatário.
 *
 * Um job por destinatário deixa o envio fora da requisição e dá isolamento: a
 * falha de uma caixa postal não interrompe as demais. O status é conferido
 * antes do envio, então o job é idempotente mesmo se for executado duas vezes
 * (fila síncrona em testes, reprocessamento ou reenvio de falhas).
 *
 * Falhas transitórias são tentadas novamente com espera crescente; só depois de
 * esgotar `$tries` o destinatário vira `Failed` e aparece para reenvio manual.
 */
class SendBulkEmailRecipient implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public function __construct(public readonly int $recipientId) {}

    public function handle(): void
    {
        $recipient = EmailCampaignRecipient::query()
            ->with('campaign')
            ->find($this->recipientId);

        if ($recipient === null || $recipient->status !== EmailRecipientStatus::Pending) {
            return;
        }

        try {
            Mail::to($recipient->email, $recipient->name)->send(new BulkAnnouncementMail(
                subjectLine: (string) $recipient->campaign?->subject,
                bodyText: (string) $recipient->campaign?->body,
                recipientName: $recipient->name,
                recipientEmail: $recipient->email,
            ));
        } catch (Throwable $exception) {
            if ($this->attempts() >= $this->tries) {
                $this->markAsFailed($recipient, $exception);
            }

            // Repassa para a fila registrar a tentativa e aplicar o backoff.
            throw $exception;
        }

        $recipient->forceFill([
            'status' => EmailRecipientStatus::Sent,
            'error' => null,
            'sent_at' => now(),
        ])->save();

        $recipient->campaign?->refreshCounters();
    }

    /**
     * Chamado pela fila quando o job esgota as tentativas.
     */
    public function failed(?Throwable $exception): void
    {
        $recipient = EmailCampaignRecipient::query()
            ->with('campaign')
            ->find($this->recipientId);

        if ($recipient === null || $recipient->status !== EmailRecipientStatus::Pending) {
            return;
        }

        $this->markAsFailed($recipient, $exception);
    }

    private function markAsFailed(EmailCampaignRecipient $recipient, ?Throwable $exception): void
    {
        $recipient->forceFill([
            'status' => EmailRecipientStatus::Failed,
            'error' => Str::limit(trim((string) $exception?->getMessage()) ?: 'Falha ao enviar o e-mail.', 500),
            'sent_at' => null,
        ])->save();

        $recipient->campaign?->refreshCounters();
    }
}
