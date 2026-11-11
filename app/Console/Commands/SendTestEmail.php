<?php

namespace App\Console\Commands;

use App\Mail\TestEmailMail;
use App\Support\MailIdentity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Verifica o transporte de e-mail configurado no `.env`.
 *
 * Diferente dos testes automatizados (que usam `Mail::fake()`/mailer `array`) e
 * do mailer `log`, este comando faz um envio real e síncrono — não passa pela
 * fila — pelo mailer de `MAIL_MAILER`. Serve para validar credenciais SMTP,
 * remetente verificado e renderização sem criar uma campanha em /admin/emails.
 *
 * A senha nunca é exibida: o usuário SMTP aparece mascarado. O resumo mostra o
 * host, a porta e a criptografia efetiva — no Laravel 13 quem manda é
 * `MAIL_SCHEME`; `MAIL_ENCRYPTION` não é lida por `config/mail.php`.
 */
class SendTestEmail extends Command
{
    protected $signature = 'arcana:send-test-email
        {email? : Destinatário do e-mail de teste}
        {--mailer= : Mailer de config/mail.php (padrão: o definido em MAIL_MAILER)}
        {--subject= : Assunto da mensagem (padrão: "Teste de e-mail — {APP_NAME}")}';

    protected $description = 'Envia um e-mail de teste pelo provedor configurado no .env';

    public function handle(): int
    {
        $mailerName = trim((string) ($this->option('mailer') ?: config('mail.default')));

        /** @var array<string, array<string, mixed>> $mailers */
        $mailers = (array) config('mail.mailers', []);

        if (! array_key_exists($mailerName, $mailers)) {
            $this->error("Mailer [{$mailerName}] não existe em config/mail.php.");
            $this->line('Disponíveis: '.implode(', ', array_keys($mailers)).'.');

            return self::FAILURE;
        }

        $email = $this->resolveRecipient();

        if ($email === null) {
            return self::FAILURE;
        }

        /** @var array<string, mixed> $config */
        $config = (array) $mailers[$mailerName];
        $transport = (string) ($config['transport'] ?? 'desconhecido');
        $subject = trim((string) $this->option('subject'));

        if ($subject === '') {
            $subject = 'Teste de e-mail — '.MailIdentity::siteName();
        }

        $this->describeTransport($mailerName, $transport, $config);

        try {
            Mail::mailer($mailerName)
                ->to($email)
                ->send(new TestEmailMail(
                    subjectLine: $subject,
                    recipientEmail: $email,
                    mailerName: $mailerName,
                    serverDescription: $this->serverDescription($config),
                    environment: (string) config('app.env'),
                    sentAt: now()->format('d/m/Y H:i:s'),
                ));
        } catch (Throwable $exception) {
            report($exception);

            $this->newLine();
            $this->error('Falha ao enviar o e-mail de teste: '.$exception->getMessage());
            $this->line('Verifique as credenciais SMTP, o remetente verificado no provedor e o log em storage/logs/laravel.log.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info("E-mail de teste enviado para {$email} via mailer [{$mailerName}].");

        $this->line(in_array($transport, ['log', 'array'], true)
            ? 'Sem entrega real: o conteúdo foi para o log (ou para a memória, nos testes).'
            : 'Confira a caixa de entrada (e o spam) e os logs do provedor.');

        return self::SUCCESS;
    }

    /**
     * Destinatário informado no argumento ou perguntado no terminal.
     */
    private function resolveRecipient(): ?string
    {
        $email = trim((string) ($this->argument('email') ?? ''));

        if ($email === '') {
            $email = trim((string) $this->ask('E-mail de destino'));
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error("E-mail inválido: {$email}");

            return null;
        }

        return $email;
    }

    /**
     * Resumo do transporte, sem expor credenciais.
     *
     * @param  array<string, mixed>  $config
     */
    private function describeTransport(string $mailerName, string $transport, array $config): void
    {
        $from = trim((string) config('mail.from.address'));
        $fromName = MailIdentity::siteName();

        $this->newLine();
        $this->line('Transporte configurado:');
        $this->line(sprintf('  mailer ....... %s (%s)', $mailerName, $transport));
        $this->line('  remetente .... '.($from === ''
            ? '(vazio — defina MAIL_FROM_ADDRESS)'
            : ($fromName !== '' ? "{$fromName} <{$from}>" : $from)));

        if (filled($config['host'] ?? null)) {
            $this->line(sprintf('  servidor ..... %s:%s', $config['host'], $config['port'] ?? '?'));
            $this->line('  criptografia . '.$this->encryptionDescription($config));
        }

        if (filled($config['username'] ?? null)) {
            $this->line('  usuário ...... '.$this->maskUsername((string) $config['username']));
        }

        $this->line('  envio ........ síncrono (não passa pela fila)');

        if ($from === '') {
            $this->warn('MAIL_FROM_ADDRESS está vazio: o provedor pode recusar a mensagem.');
        }

        if (in_array($transport, ['log', 'array'], true)) {
            $this->warn("O transporte [{$transport}] não entrega e-mail: a mensagem fica no log/memória.");
        }
    }

    /**
     * Criptografia efetiva do transporte SMTP.
     *
     * O `MailManager` resolve a porta 465 para `smtps` e qualquer outra para
     * `smtp`, que negocia STARTTLS quando o servidor anuncia o recurso.
     *
     * @param  array<string, mixed>  $config
     */
    private function encryptionDescription(array $config): string
    {
        $scheme = $config['scheme'] ?? null;

        if (is_string($scheme) && $scheme !== '') {
            return $scheme === 'smtps'
                ? 'TLS implícito (smtps)'
                : "STARTTLS automático ({$scheme})";
        }

        return (int) ($config['port'] ?? 0) === 465
            ? 'TLS implícito (porta 465)'
            : 'STARTTLS automático quando o servidor anuncia';
    }

    /**
     * Servidor descrito dentro da mensagem enviada.
     *
     * @param  array<string, mixed>  $config
     */
    private function serverDescription(array $config): string
    {
        if (! filled($config['host'] ?? null)) {
            return 'transporte local (sem servidor SMTP)';
        }

        return sprintf(
            '%s:%s — %s',
            $config['host'],
            $config['port'] ?? '?',
            $this->encryptionDescription($config),
        );
    }

    /**
     * Mantém visível apenas o início do usuário SMTP.
     */
    private function maskUsername(string $username): string
    {
        if (! str_contains($username, '@')) {
            return $this->maskSecret($username);
        }

        [$local, $domain] = explode('@', $username, 2);

        return $this->maskSecret($local).'@'.$domain;
    }

    private function maskSecret(string $value): string
    {
        return Str::length($value) > 4 ? Str::mask($value, '*', 2) : str_repeat('*', 4);
    }
}
