<?php

namespace Tests\Feature;

use App\Enums\EmailRecipientStatus;
use App\Jobs\SendBulkEmailRecipient;
use App\Mail\BulkAnnouncementMail;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\Setting;
use App\Models\User;
use App\Support\BulkEmailTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Disparo de e-mails em massa (/admin/emails).
 *
 * Cobre o painel administrativo: quem pode entrar, o que é validado, quais
 * destinatários entram de fato na campanha (sem repetição e sem endereço
 * inválido), o envio em fila, o modelo padrão e o reenvio de falhas.
 */
class AdminEmailCampaignTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_and_clients_cannot_reach_the_panel(): void
    {
        $this->get(route('admin.emails.index'))->assertRedirect(route('login'));

        $client = User::factory()->create();

        $this->actingAs($client)->get(route('admin.emails.index'))->assertForbidden();
        $this->actingAs($client)
            ->post(route('admin.emails.store'), [
                'subject' => 'Assunto',
                'body' => 'Corpo',
                'recipients' => [$client->id],
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('email_campaigns', 0);
    }

    public function test_index_lists_recipients_and_the_saved_template(): void
    {
        $admin = User::factory()->admin()->create();
        $client = User::factory()->create(['name' => 'Clara Nogueira']);

        Setting::query()->create([
            'key' => BulkEmailTemplate::SUBJECT_SETTING_KEY,
            'value' => 'Assunto padrão salvo',
            'type' => 'string',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.emails.index'))
            ->assertOk()
            ->assertSee('Disparo de e-mails')
            ->assertSee('Clara Nogueira')
            ->assertSee($client->email)
            ->assertSee('Assunto padrão salvo');
    }

    public function test_dispatching_queues_one_job_per_selected_recipient(): void
    {
        Queue::fake();

        $admin = User::factory()->admin()->create();
        $first = User::factory()->create();
        $second = User::factory()->create();

        $response = $this->actingAs($admin)->post(route('admin.emails.store'), [
            'subject' => 'Novidades de outono',
            'body' => 'Olá, {{nome}}! Novidades no Arcana Tarot.',
            'recipients' => [$first->id, $second->id],
        ]);

        $campaign = EmailCampaign::query()->sole();

        $response
            ->assertRedirect(route('admin.emails.show', $campaign))
            ->assertSessionHas('success');

        $this->assertSame('Novidades de outono', $campaign->subject);
        $this->assertSame(2, $campaign->recipients_count);
        $this->assertSame(0, $campaign->sent_count);
        $this->assertSame($admin->id, $campaign->created_by);

        $recipients = $campaign->recipients()->get();

        $this->assertEqualsCanonicalizing(
            [$first->email, $second->email],
            $recipients->pluck('email')->all(),
        );

        $recipients->each(function (EmailCampaignRecipient $recipient): void {
            $this->assertSame(EmailRecipientStatus::Pending, $recipient->status);

            Queue::assertPushed(
                SendBulkEmailRecipient::class,
                fn (SendBulkEmailRecipient $job): bool => $job->recipientId === $recipient->id,
            );
        });

        Queue::assertPushed(SendBulkEmailRecipient::class, 2);

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'email_campaign.created',
            'subject_id' => $campaign->id,
        ]);
    }

    public function test_repeated_and_invalid_addresses_are_ignored(): void
    {
        Queue::fake();

        $admin = User::factory()->admin()->create();
        $original = User::factory()->create(['email' => 'repetido@example.com']);
        $duplicated = User::factory()->create(['email' => 'Repetido@Example.com']);
        $invalid = User::factory()->create(['email' => 'sem-arroba']);

        $this->actingAs($admin)
            ->post(route('admin.emails.store'), [
                'subject' => 'Assunto',
                'body' => 'Corpo',
                'recipients' => [$original->id, $duplicated->id, $invalid->id],
            ])
            ->assertRedirect();

        $campaign = EmailCampaign::query()->sole();

        $this->assertSame(1, $campaign->recipients_count);
        $this->assertSame('repetido@example.com', $campaign->recipients()->sole()->email);
        Queue::assertPushed(SendBulkEmailRecipient::class, 1);
    }

    public function test_selection_is_required(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->from(route('admin.emails.index'))
            ->post(route('admin.emails.store'), [
                'subject' => 'Assunto',
                'body' => 'Corpo',
            ])
            ->assertRedirect(route('admin.emails.index'))
            ->assertSessionHasErrors('recipients');

        $this->assertDatabaseCount('email_campaigns', 0);
    }

    public function test_email_is_sent_with_placeholders_replaced_and_status_recorded(): void
    {
        Mail::fake();

        $admin = User::factory()->admin()->create();
        $client = User::factory()->create(['name' => 'Marina Duarte']);

        $this->actingAs($admin)
            ->post(route('admin.emails.store'), [
                'subject' => 'Uma mensagem para você',
                'body' => 'Olá, {{nome}}! Seu cadastro usa {{email}}.',
                'recipients' => [$client->id],
            ])
            ->assertRedirect();

        Mail::assertSent(BulkAnnouncementMail::class, 1);

        $mailable = Mail::sent(BulkAnnouncementMail::class)->sole();

        $this->assertTrue($mailable->hasTo($client->email));
        $this->assertSame('Uma mensagem para você', $mailable->subjectLine);

        $html = $mailable->render();

        $this->assertStringContainsString('Olá, Marina!', $html);
        $this->assertStringContainsString($client->email, $html);
        $this->assertStringNotContainsString('{{nome}}', $html);

        $recipient = EmailCampaignRecipient::query()->sole();

        $this->assertSame(EmailRecipientStatus::Sent, $recipient->status);
        $this->assertNotNull($recipient->sent_at);
        $this->assertNull($recipient->error);

        $campaign = $recipient->campaign;

        $this->assertSame(1, $campaign->sent_count);
        $this->assertSame(0, $campaign->failed_count);
        $this->assertSame('Concluído', $campaign->statusLabel());
    }

    public function test_admin_can_save_the_default_template(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('admin.emails.template.update'), [
                'subject' => 'Corpo padrão novo',
                'body' => "Linha um\nLinha dois",
            ])
            ->assertRedirect(route('admin.emails.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('settings', [
            'key' => BulkEmailTemplate::SUBJECT_SETTING_KEY,
            'value' => 'Corpo padrão novo',
        ]);

        $this->assertDatabaseHas('settings', [
            'key' => BulkEmailTemplate::BODY_SETTING_KEY,
            'value' => "Linha um\nLinha dois",
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'email_template.updated',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.emails.index'))
            ->assertOk()
            ->assertSee('Corpo padrão novo');
    }

    public function test_admin_can_resend_the_failed_recipients(): void
    {
        Mail::fake();

        $admin = User::factory()->admin()->create();
        $campaign = EmailCampaign::factory()->create(['created_by' => $admin->id]);

        $failed = EmailCampaignRecipient::factory()->failed()->create([
            'email_campaign_id' => $campaign->id,
        ]);

        EmailCampaignRecipient::factory()->sent()->create([
            'email_campaign_id' => $campaign->id,
        ]);

        $campaign->refreshCounters();
        $this->assertSame(1, $campaign->failed_count);

        $this->actingAs($admin)
            ->post(route('admin.emails.resend', $campaign))
            ->assertRedirect(route('admin.emails.show', $campaign))
            ->assertSessionHas('success');

        $failed->refresh();
        $campaign->refresh();

        $this->assertSame(EmailRecipientStatus::Sent, $failed->status);
        $this->assertNull($failed->error);
        $this->assertSame(2, $campaign->sent_count);
        $this->assertSame(0, $campaign->failed_count);

        Mail::assertSent(BulkAnnouncementMail::class, 1);

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'email_campaign.failures_requeued',
            'subject_id' => $campaign->id,
        ]);
    }

    public function test_resend_without_failures_reports_an_error(): void
    {
        $admin = User::factory()->admin()->create();
        $campaign = EmailCampaign::factory()->create(['created_by' => $admin->id]);

        EmailCampaignRecipient::factory()->sent()->create([
            'email_campaign_id' => $campaign->id,
        ]);

        $campaign->refreshCounters();

        $this->actingAs($admin)
            ->from(route('admin.emails.show', $campaign))
            ->post(route('admin.emails.resend', $campaign))
            ->assertRedirect(route('admin.emails.show', $campaign))
            ->assertSessionHasErrors('campaign');
    }

    public function test_campaign_detail_lists_each_recipient_with_status(): void
    {
        $admin = User::factory()->admin()->create();
        $campaign = EmailCampaign::factory()->create([
            'created_by' => $admin->id,
            'subject' => 'Mensagem de inverno',
            'body' => 'Corpo do disparo',
        ]);

        EmailCampaignRecipient::factory()->failed()->create([
            'email_campaign_id' => $campaign->id,
            'email' => 'falhou@example.com',
            'error' => 'Mailbox full',
        ]);

        EmailCampaignRecipient::factory()->sent()->create([
            'email_campaign_id' => $campaign->id,
            'email' => 'entregue@example.com',
        ]);

        $campaign->refreshCounters();

        $this->actingAs($admin)
            ->get(route('admin.emails.show', $campaign))
            ->assertOk()
            ->assertSee('Mensagem de inverno')
            ->assertSee('falhou@example.com')
            ->assertSee('entregue@example.com')
            ->assertSee('Mailbox full')
            ->assertSee('Reenviar falhas (1)');
    }
}
