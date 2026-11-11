<?php

namespace Tests\Feature;

use App\Mail\BulkAnnouncementMail;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\Auth\VerifyEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * Identidade do remetente dos e-mails.
 *
 * O nome exibido acompanha o campo "Nome do site" de /admin/conteudo sem exigir
 * alteração no `.env`; o endereço continua vindo de MAIL_FROM_ADDRESS, porque é
 * ele que precisa estar verificado no provedor.
 */
class MailSenderIdentityTest extends TestCase
{
    use RefreshDatabase;

    private function saveSiteName(string $name): void
    {
        Setting::query()->updateOrCreate(
            ['key' => 'site_name'],
            ['value' => $name, 'type' => 'string'],
        );
    }

    /**
     * Última mensagem entregue ao transporte `array` (o mailer dos testes).
     */
    private function lastSentMessage(): Email
    {
        $transport = Mail::mailer('array')->getSymfonyTransport();

        $this->assertInstanceOf(ArrayTransport::class, $transport);
        $this->assertCount(1, $transport->messages());

        return $transport->messages()->sole()->getOriginalMessage();
    }

    /**
     * Confere o remetente da última mensagem: endereço do `.env` e nome do site.
     */
    private function assertSender(string $expectedName): void
    {
        $from = $this->lastSentMessage()->getFrom();

        $this->assertCount(1, $from);
        $this->assertInstanceOf(Address::class, $from[0]);
        $this->assertSame((string) config('mail.from.address'), $from[0]->getAddress());
        $this->assertSame($expectedName, $from[0]->getName());
    }

    public function test_bulk_email_uses_the_site_name_as_the_sender_name(): void
    {
        $this->saveSiteName('Astros21');

        Mail::to('cliente@example.com')->send(new BulkAnnouncementMail(
            subjectLine: 'Novidades',
            bodyText: 'Mensagem de teste.',
        ));

        $message = $this->lastSentMessage();

        $this->assertSame('Novidades', $message->getSubject());
        $this->assertSender('Astros21');
    }

    public function test_verification_email_uses_the_site_name_as_the_sender_name(): void
    {
        $this->saveSiteName('Astros21');

        $user = User::factory()->unverified()->create();

        $user->notify(new VerifyEmailNotification);

        $message = $this->lastSentMessage();

        $this->assertSame('Confirme seu e-mail | Astros21', $message->getSubject());
        $this->assertSender('Astros21');
    }

    public function test_sender_name_falls_back_to_the_configured_site_name_without_a_setting(): void
    {
        Mail::to('cliente@example.com')->send(new BulkAnnouncementMail(
            subjectLine: 'Novidades',
            bodyText: 'Mensagem de teste.',
        ));

        $this->assertSender((string) config('site.profile_keys.site_name'));
    }

    public function test_updating_the_site_name_in_the_panel_changes_the_next_sender(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->patch(route('admin.site.identity.update'), [
                'site_name' => 'Astros21',
                'site_description' => 'Consultas de tarô com atendentes especializados.',
            ])
            ->assertRedirect();

        Mail::to('cliente@example.com')->send(new BulkAnnouncementMail(
            subjectLine: 'Novidades',
            bodyText: 'Mensagem de teste.',
        ));

        $this->assertSender('Astros21');
    }

    public function test_test_email_command_uses_the_site_name_in_the_sender_and_subject(): void
    {
        $this->saveSiteName('Astros21');

        $this->artisan('arcana:send-test-email', ['email' => 'voce@example.com'])
            ->assertExitCode(0);

        $message = $this->lastSentMessage();

        $this->assertSame('Teste de e-mail — Astros21', $message->getSubject());
        $this->assertSender('Astros21');
    }
}
