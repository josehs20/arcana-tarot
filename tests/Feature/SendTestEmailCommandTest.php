<?php

namespace Tests\Feature;

use App\Mail\TestEmailMail;
use App\Support\SiteContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Comando de verificação do transporte de e-mail (arcana:send-test-email).
 *
 * O phpunit fixa `MAIL_MAILER=array`, então nenhum teste aqui abre conexão
 * SMTP: o foco é o mailer escolhido, o destinatário, o assunto, a renderização
 * e o mascaramento das credenciais. O envio real para o Brevo é manual.
 */
class SendTestEmailCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_sends_the_email_through_the_configured_mailer(): void
    {
        Mail::fake();

        $this->artisan('arcana:send-test-email', ['email' => 'voce@example.com'])
            ->expectsOutputToContain('E-mail de teste enviado para voce@example.com')
            ->assertExitCode(0);

        Mail::assertSent(TestEmailMail::class, function (TestEmailMail $mail): bool {
            return $mail->hasTo('voce@example.com')
                && $mail->subjectLine === 'Teste de e-mail — '.app(SiteContent::class)->name()
                && $mail->mailerName === config('mail.default');
        });
    }

    public function test_command_delivers_a_fully_rendered_message_through_the_array_transport(): void
    {
        $this->artisan('arcana:send-test-email', [
            'email' => 'voce@example.com',
            '--subject' => 'Prova de transporte',
        ])->assertExitCode(0);

        $transport = Mail::mailer('array')->getSymfonyTransport();

        $this->assertInstanceOf(ArrayTransport::class, $transport);
        $this->assertCount(1, $transport->messages());

        $body = quoted_printable_decode($transport->messages()->sole()->getOriginalMessage()->toString());

        $this->assertStringContainsString('Transporte de e-mail funcionando', $body);
        $this->assertStringContainsString('voce@example.com', $body);
        $this->assertStringContainsString('Mailer: array', $body);
    }

    public function test_command_rejects_an_invalid_recipient(): void
    {
        Mail::fake();

        $this->artisan('arcana:send-test-email', ['email' => 'sem-arroba'])
            ->expectsOutputToContain('E-mail inválido: sem-arroba')
            ->assertExitCode(1);

        Mail::assertNothingSent();
    }

    public function test_command_fails_for_an_unknown_mailer_and_lists_the_available_ones(): void
    {
        Mail::fake();

        $this->artisan('arcana:send-test-email', [
            'email' => 'voce@example.com',
            '--mailer' => 'inexistente',
        ])
            ->expectsOutputToContain('Mailer [inexistente] não existe em config/mail.php.')
            ->expectsOutputToContain('Disponíveis:')
            ->assertExitCode(1);

        Mail::assertNothingSent();
    }

    public function test_command_masks_the_smtp_username_and_never_prints_the_password(): void
    {
        Mail::fake();

        config(['mail.mailers.prova' => [
            'transport' => 'smtp',
            'host' => 'smtp-relay.brevo.com',
            'port' => 587,
            'username' => 'c263d2001@smtp-brevo.com',
            'password' => 'chave-smtp-secreta',
        ]]);

        $this->artisan('arcana:send-test-email', [
            'email' => 'voce@example.com',
            '--mailer' => 'prova',
        ])
            ->expectsOutputToContain('smtp-relay.brevo.com:587')
            ->expectsOutputToContain('@smtp-brevo.com')
            ->doesntExpectOutputToContain('c263d2001')
            ->doesntExpectOutputToContain('chave-smtp-secreta')
            ->assertExitCode(0);

        Mail::assertSent(TestEmailMail::class, 1);
    }

    public function test_command_warns_when_the_mailer_does_not_deliver(): void
    {
        Mail::fake();

        config(['mail.default' => 'log']);

        $this->artisan('arcana:send-test-email', ['email' => 'voce@example.com'])
            ->expectsOutputToContain('O transporte [log] não entrega e-mail')
            ->assertExitCode(0);

        Mail::assertSent(TestEmailMail::class, 1);
    }
}
