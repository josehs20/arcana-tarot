<?php

namespace Tests\Feature;

use App\Enums\ConsultationEndReason;
use App\Enums\ConsultationStatus;
use App\Models\AttendantProfile;
use App\Models\AuditLog;
use App\Models\Consultation;
use App\Models\ConsultationPresenceSession;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use App\Models\Wallet;
use App\Support\ConsultationLifecycleManager;
use App\Support\ConsultationSafety;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Segurança e usabilidade do atendimento em tempo real.
 *
 * A presença do navegador é só um sinal: atividade, inatividade, tolerância de
 * reconexão, motivo do encerramento e retorno da compra de créditos são sempre
 * decididos e registrados no servidor.
 */
class ConsultationSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::query()->create(['key' => 'rate_credits_per_minute', 'value' => '2', 'type' => 'integer']);
        Setting::query()->create(['key' => 'credit_unit_price_cents', 'value' => '150', 'type' => 'integer']);
        Setting::query()->create(['key' => 'minimum_balance_minutes', 'value' => '1', 'type' => 'integer']);

        // O aviso local não vem de `settings`: é configuração de ambiente.
        // Fixar aqui deixa as asserções independentes do `.env` da máquina.
        config(['consultation.navigation_warning_seconds' => 20]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function client(int $balanceCents = 2500): User
    {
        $client = User::factory()->create();
        Wallet::factory()->create(['user_id' => $client->id, 'balance_cents' => $balanceCents]);

        return $client;
    }

    private function attendant(): User
    {
        $attendant = User::factory()->attendant()->create();
        AttendantProfile::factory()->online()->for($attendant)->create();

        return $attendant;
    }

    private function activeConsultation(
        User $client,
        User $attendant,
        array $attributes = [],
    ): Consultation {
        return Consultation::factory()->active()->create($attributes + [
            'client_id' => $client->id,
            'attendant_id' => $attendant->id,
            'rate_credits_per_minute' => 2,
            'credit_unit_price_cents' => 150,
            'price_per_minute_cents' => 300,
        ]);
    }

    private function heartbeat(User $user, Consultation $consultation, array $payload = [])
    {
        return $this->actingAs($user)->postJson(
            route('consultations.presence.heartbeat', $consultation),
            $payload,
        );
    }

    public function test_heartbeat_opens_session_and_returns_server_calculated_state(): void
    {
        Carbon::setTestNow('2026-11-04 10:00:00');

        $client = $this->client();
        $attendant = $this->attendant();
        $consultation = $this->activeConsultation($client, $attendant);

        $response = $this->heartbeat($client, $consultation, ['activity' => true])
            ->assertOk()
            ->assertJsonPath('status', ConsultationStatus::Active->value)
            ->assertJsonPath('presence.client.state', 'online')
            ->assertJsonPath('presence.attendant.state', 'offline')
            ->assertJsonPath('navigation_warning_seconds', 20)
            ->assertJsonPath('inactivity_timeout_seconds', 300)
            ->assertJsonPath('credits.balance_label', 'R$ 25,00')
            ->assertJsonStructure([
                'session_token',
                'presence' => [
                    'client' => ['state', 'last_seen_at'],
                    'attendant' => ['state', 'last_seen_at'],
                    'inactivity_timeout_seconds',
                    'presence_tolerance_seconds',
                    'reconnection_tolerance_seconds',
                ],
            ]);

        $token = $response->json('session_token');

        $this->assertIsString($token);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40,64}$/', $token);
        $this->assertStringContainsString(
            'return_consultation='.$consultation->id,
            (string) $response->json('credits_url'),
        );

        $this->assertDatabaseHas('consultation_presence_sessions', [
            'consultation_id' => $consultation->id,
            'user_id' => $client->id,
            'role' => 'client',
            'session_token' => $token,
            'disconnect_reason' => null,
        ]);

        $consultation->refresh();
        $this->assertTrue($consultation->last_activity_at->equalTo(now()));
        $this->assertTrue($consultation->client_last_activity_at->equalTo(now()));
        $this->assertNull($consultation->attendant_last_activity_at);
    }

    public function test_heartbeat_reuses_the_same_session_after_reconnection(): void
    {
        Carbon::setTestNow('2026-11-04 10:00:00');

        $client = $this->client();
        $consultation = $this->activeConsultation($client, $this->attendant());
        $token = $this->heartbeat($client, $consultation)->json('session_token');

        $this->actingAs($client)
            ->postJson(route('consultations.presence.leave', $consultation), [
                'session_token' => $token,
            ])
            ->assertOk()
            ->assertJsonPath('disconnected', true);

        $session = ConsultationPresenceSession::query()->firstOrFail();
        $this->assertNotNull($session->disconnected_at);
        $this->assertSame('user_left', $session->disconnect_reason);

        Carbon::setTestNow(now()->addSeconds(30));

        $this->heartbeat($client, $consultation, ['session_token' => $token])
            ->assertOk()
            ->assertJsonPath('session_token', $token)
            ->assertJsonPath('presence.client.state', 'online');

        $this->assertSame(1, ConsultationPresenceSession::query()->count());
        $this->assertNull($session->fresh()->disconnected_at);
        $this->assertNull($session->fresh()->disconnect_reason);
    }

    public function test_only_real_activity_touches_the_server_clock(): void
    {
        Carbon::setTestNow('2026-11-04 10:00:00');

        $client = $this->client();
        $consultation = $this->activeConsultation($client, $this->attendant());
        $consultation->forceFill([
            'last_activity_at' => now()->subMinutes(4),
            'client_last_activity_at' => now()->subMinutes(4),
        ])->save();

        $this->heartbeat($client, $consultation, ['activity' => false])
            ->assertOk()
            ->assertJsonPath('presence.last_activity_at', $consultation->last_activity_at->toIso8601String());

        $this->assertTrue($consultation->fresh()->last_activity_at->equalTo(now()->subMinutes(4)));

        Carbon::setTestNow(now()->addSecond());

        $this->heartbeat($client, $consultation, ['activity' => true])->assertOk();

        $this->assertTrue($consultation->fresh()->last_activity_at->equalTo(now()));
        $this->assertTrue($consultation->fresh()->client_last_activity_at->equalTo(now()));
    }

    public function test_heartbeat_rejects_malformed_and_foreign_session_tokens(): void
    {
        $client = $this->client();
        $attendant = $this->attendant();
        $consultation = $this->activeConsultation($client, $attendant);

        $this->heartbeat($client, $consultation, ['session_token' => 'token-invalido'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('session_token');

        $attendantToken = $this->heartbeat($attendant, $consultation)->json('session_token');

        $this->heartbeat($client, $consultation, ['session_token' => $attendantToken])
            ->assertForbidden();
    }

    public function test_heartbeat_rejects_non_participants_and_reports_finished_consultations(): void
    {
        $client = $this->client();
        $consultation = $this->activeConsultation($client, $this->attendant());
        $stranger = User::factory()->create();

        $this->heartbeat($stranger, $consultation)->assertForbidden();

        $consultation->forceFill([
            'status' => ConsultationStatus::Ended->value,
            'ended_at' => now(),
            'ended_reason' => ConsultationEndReason::ClientEnded->value,
        ])->save();

        /*
         * Consulta já finalizada não cria sessão: o batimento devolve o estado
         * final e a tela sai do atendimento com o motivo correto.
         */
        $this->heartbeat($client, $consultation)
            ->assertOk()
            ->assertJsonPath('status', ConsultationStatus::Ended->value)
            ->assertJsonPath('ended_reason', ConsultationEndReason::ClientEnded->value)
            ->assertJsonPath('session_token', null);

        $this->assertSame(0, ConsultationPresenceSession::query()->count());
    }

    public function test_leave_records_disconnection_without_ending_the_consultation(): void
    {
        $client = $this->client();
        $consultation = $this->activeConsultation($client, $this->attendant());
        $token = $this->heartbeat($client, $consultation)->json('session_token');

        $this->actingAs($client)
            ->postJson(route('consultations.presence.leave', $consultation), [
                'session_token' => $token,
            ])
            ->assertOk()
            ->assertJsonPath('disconnected', true);

        $this->assertSame(ConsultationStatus::Active, $consultation->fresh()->status);
        $this->assertSame('user_left', ConsultationPresenceSession::query()->firstOrFail()->disconnect_reason);
    }

    public function test_client_logout_ends_the_active_consultation_only_once(): void
    {
        $client = $this->client();
        $consultation = $this->activeConsultation($client, $this->attendant());
        $this->heartbeat($client, $consultation);

        $this->actingAs($client)->post(route('logout'))->assertRedirect('/');

        $consultation->refresh();

        $this->assertSame(ConsultationStatus::Ended, $consultation->status);
        $this->assertSame(ConsultationEndReason::ClientLoggedOut, $consultation->ended_reason);
        $this->assertNotNull($consultation->ended_at);
        $this->assertSame(
            0,
            ConsultationPresenceSession::query()->whereNull('disconnected_at')->count(),
        );

        app(ConsultationLifecycleManager::class)->endActiveForLogout($client);

        $this->assertSame(
            1,
            AuditLog::query()
                ->where('action', 'consultation.ended')
                ->where('subject_id', $consultation->id)
                ->count(),
        );
    }

    public function test_attendant_logout_ends_the_active_consultation_with_attendant_reason(): void
    {
        $client = $this->client();
        $attendant = $this->attendant();
        $consultation = $this->activeConsultation($client, $attendant);

        $this->actingAs($attendant)->post(route('logout'))->assertRedirect('/');

        $consultation->refresh();

        $this->assertSame(ConsultationStatus::Ended, $consultation->status);
        $this->assertSame(ConsultationEndReason::AttendantLoggedOut, $consultation->ended_reason);
    }

    public function test_inactivity_interruption_respects_reconnection_tolerance_and_is_audited(): void
    {
        Carbon::setTestNow('2026-11-04 10:00:00');

        $client = $this->client();
        $consultation = $this->activeConsultation($client, $this->attendant());
        $this->heartbeat($client, $consultation);

        $consultation->forceFill(['last_activity_at' => now()->subMinutes(5)->subSeconds(30)])->save();

        /* 5m30s < 5m de inatividade + 90s de tolerância de reconexão. */
        $this->artisan('consultations:interrupt-disconnected')
            ->expectsOutput('Consultas interrompidas: 0.')
            ->assertExitCode(0);

        $this->assertSame(ConsultationStatus::Active, $consultation->fresh()->status);

        Carbon::setTestNow(now()->addSeconds(61));

        $this->artisan('consultations:interrupt-disconnected')
            ->expectsOutput('Consultas interrompidas: 1.')
            ->assertExitCode(0);

        $consultation->refresh();

        $this->assertSame(ConsultationStatus::Interrupted, $consultation->status);
        $this->assertSame(ConsultationEndReason::Inactivity, $consultation->ended_reason);

        $session = ConsultationPresenceSession::query()->firstOrFail();
        $this->assertNotNull($session->disconnected_at);
        $this->assertSame('inactivity', $session->disconnect_reason);

        $audit = AuditLog::query()
            ->where('action', 'consultation.interrupted')
            ->where('subject_id', $consultation->id)
            ->firstOrFail();

        $this->assertSame('inactivity', $audit->metadata['reason']);
    }

    /**
     * O limite do comando vem de `settings`, ajustável pela administração, e não
     * do valor fixo do arquivo de configuração.
     */
    public function test_interruption_command_uses_the_timeout_saved_by_the_admin(): void
    {
        Carbon::setTestNow('2026-11-04 10:00:00');

        Setting::query()->create([
            'key' => 'consultation_inactivity_timeout_minutes',
            'value' => '7',
            'type' => 'integer',
        ]);
        Setting::query()->create([
            'key' => 'consultation_reconnection_tolerance_seconds',
            'value' => '45',
            'type' => 'integer',
        ]);

        $consultation = $this->activeConsultation($this->client(), $this->attendant());

        /* 7 min de inatividade + 45 s de tolerância: 7m30s ainda reconecta. */
        $consultation->forceFill(['last_activity_at' => now()->subMinutes(7)->subSeconds(30)])->save();

        $this->artisan('consultations:interrupt-disconnected')
            ->expectsOutput('Consultas interrompidas: 0.')
            ->assertExitCode(0);

        $this->assertSame(ConsultationStatus::Active, $consultation->fresh()->status);

        Carbon::setTestNow(now()->addSeconds(16));

        $this->artisan('consultations:interrupt-disconnected')
            ->expectsOutput('Consultas interrompidas: 1.')
            ->assertExitCode(0);

        $consultation->refresh();

        $this->assertSame(ConsultationStatus::Interrupted, $consultation->status);
        $this->assertSame(ConsultationEndReason::Inactivity, $consultation->ended_reason);
    }

    /**
     * Rede de segurança do agendador: mesmo sem o cron/`schedule:work` no ar,
     * a tela aberta não mantém a consulta "ativa" para sempre. Vencido o prazo
     * (inatividade + tolerância), o próprio batimento interrompe.
     */
    public function test_heartbeat_interrupts_the_consultation_when_the_inactivity_deadline_expires(): void
    {
        Carbon::setTestNow('2026-11-04 10:00:00');

        $client = $this->client();
        $consultation = $this->activeConsultation($client, $this->attendant(), [
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        /* 5 min de inatividade + 90 s de tolerância de reconexão = 6m30s. */
        Carbon::setTestNow('2026-11-04 10:06:29');

        $this->heartbeat($client, $consultation)
            ->assertOk()
            ->assertJsonPath('status', ConsultationStatus::Active->value);

        $this->assertSame(ConsultationStatus::Active, $consultation->fresh()->status);

        Carbon::setTestNow('2026-11-04 10:06:31');

        $this->heartbeat($client, $consultation)
            ->assertOk()
            ->assertJsonPath('status', ConsultationStatus::Interrupted->value)
            ->assertJsonPath('ended_reason', ConsultationEndReason::Inactivity->value)
            ->assertJsonPath('session_token', null);

        $consultation->refresh();

        $this->assertSame(ConsultationStatus::Interrupted, $consultation->status);
        $this->assertSame(ConsultationEndReason::Inactivity, $consultation->ended_reason);

        $session = ConsultationPresenceSession::query()->firstOrFail();
        $this->assertNotNull($session->disconnected_at);
        $this->assertSame('inactivity', $session->disconnect_reason);

        $this->assertSame(1, AuditLog::query()
            ->where('action', 'consultation.interrupted')
            ->where('subject_id', $consultation->id)
            ->count());
    }

    /**
     * Abrir a tela dedicada depois do prazo também finaliza: o usuário cai no
     * histórico/recibo em vez de entrar numa conversa que deveria ter acabado.
     */
    public function test_opening_the_workspace_after_the_inactivity_deadline_interrupts_the_consultation(): void
    {
        Carbon::setTestNow('2026-11-04 10:00:00');

        $client = $this->client();
        $consultation = $this->activeConsultation($client, $this->attendant(), [
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        Carbon::setTestNow('2026-11-04 10:07:00');

        $this->actingAs($client)
            ->get(route('consultations.workspace', $consultation))
            ->assertRedirect(route('consultations.show', $consultation))
            ->assertSessionHas(
                'status',
                'Este atendimento já foi encerrado. O histórico e o recibo continuam disponíveis na tela da consulta.',
            );

        $consultation->refresh();

        $this->assertSame(ConsultationStatus::Interrupted, $consultation->status);
        $this->assertSame(ConsultationEndReason::Inactivity, $consultation->ended_reason);
    }

    /**
     * Perfil do Hostoo: sem Redis, sem Reverb e sem worker permanente — cache e
     * fila no MySQL, eventos no canal `log` e nenhum `schedule:work`. O
     * encerramento por inatividade tem de acontecer do mesmo jeito, liquidando
     * os minutos vencidos na própria requisição; o cron apenas antecipa.
     */
    public function test_inactivity_interrupt_works_on_the_hostoo_profile_without_a_scheduler(): void
    {
        config([
            'cache.default' => 'database',
            'queue.default' => 'database',
            'broadcasting.default' => 'log',
        ]);

        Carbon::setTestNow('2026-11-04 10:00:00');

        $client = $this->client(2500);
        $consultation = $this->activeConsultation($client, $this->attendant(), [
            'started_at' => now(),
            'last_activity_at' => now(),
            'billed_minutes' => 0,
        ]);

        // 7min05s iniciados = 8 minutos vencidos, R$ 3,00 cada (R$ 24,00).
        Carbon::setTestNow('2026-11-04 10:07:05');

        // Nenhum `consultations:interrupt-disconnected` roda aqui: só o batimento.
        $this->heartbeat($client, $consultation)
            ->assertOk()
            ->assertJsonPath('status', ConsultationStatus::Interrupted->value)
            ->assertJsonPath('ended_reason', ConsultationEndReason::Inactivity->value)
            ->assertJsonPath('credits.balance_label', 'R$ 1,00');

        $consultation->refresh();

        $this->assertSame(ConsultationStatus::Interrupted, $consultation->status);
        $this->assertSame(8, (int) $consultation->billed_minutes);
        $this->assertSame(100, (int) Wallet::query()->where('user_id', $client->id)->value('balance_cents'));

        // O evento de estado fica na fila do MySQL até o cron chamar `queue:work`
        // e nada disso depende de Redis; o encerramento já está gravado.
        $this->assertGreaterThan(0, DB::table('jobs')->count());
    }

    public function test_admin_can_view_and_update_safety_thresholds_with_audit(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('admin.consultations.safety'))
            ->assertOk()
            ->assertSee('Limites de inatividade')
            ->assertSee('Tolerância de reconexão');

        $this->actingAs($admin)
            ->patch(route('admin.consultations.safety.update'), [
                'inactivity_timeout_minutes' => 7,
                'reconnection_tolerance_seconds' => 45,
            ])
            ->assertRedirect(route('admin.consultations.safety'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('settings', [
            'key' => 'consultation_inactivity_timeout_minutes',
            'value' => '7',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'consultation_reconnection_tolerance_seconds',
            'value' => '45',
        ]);

        $audit = AuditLog::query()
            ->where('action', 'consultation_safety.updated')
            ->where('actor_id', $admin->id)
            ->firstOrFail();

        $this->assertSame(7, $audit->metadata['after']['inactivity_timeout_minutes']);
        $this->assertSame(45, $audit->metadata['after']['reconnection_tolerance_seconds']);

        $safety = app(ConsultationSafety::class);
        $this->assertSame(420, $safety->inactivityTimeoutSeconds());
        $this->assertSame(465, $safety->interruptionThresholdSeconds());
    }

    public function test_safety_update_is_admin_only_and_validates_ranges(): void
    {
        $client = $this->client();

        $this->actingAs($client)
            ->patch(route('admin.consultations.safety.update'), [
                'inactivity_timeout_minutes' => 5,
                'reconnection_tolerance_seconds' => 90,
            ])
            ->assertForbidden();

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->from(route('admin.consultations.safety'))
            ->patch(route('admin.consultations.safety.update'), [
                'inactivity_timeout_minutes' => 0,
                'reconnection_tolerance_seconds' => 601,
            ])
            ->assertRedirect(route('admin.consultations.safety'))
            ->assertSessionHasErrors([
                'inactivity_timeout_minutes',
                'reconnection_tolerance_seconds',
            ]);

        $this->assertDatabaseMissing('settings', [
            'key' => 'consultation_inactivity_timeout_minutes',
        ]);
    }

    public function test_workspace_exposes_safety_markers_and_credit_shortcut_only_for_client(): void
    {
        $client = $this->client();
        $attendant = $this->attendant();
        $consultation = $this->activeConsultation($client, $attendant);
        $creditsUrl = route('client.credits.index', ['return_consultation' => $consultation->id]);

        $this->actingAs($client)
            ->get(route('consultations.workspace', $consultation))
            ->assertOk()
            ->assertSee('data-navigation-warning-seconds="20"', false)
            ->assertSee('data-inactivity-timeout-seconds="300"', false)
            ->assertSee('data-activity-ping-seconds="20"', false)
            ->assertSee('data-presence-role="client"', false)
            ->assertSee('data-presence-role="attendant"', false)
            ->assertSee('data-credit-balance', false)
            ->assertSee('data-credits-url="'.$creditsUrl.'"', false)
            ->assertSee('data-consultation-credits-link', false)
            ->assertSee('Comprar créditos')
            ->assertSee('R$ 25,00')
            ->assertViewHas('creditBalance', fn (array $balance): bool => $balance['balance_cents'] === 2500)
            ->assertViewHas('safety', fn (array $safety): bool => $safety['inactivity_timeout_minutes'] === 5
                && $safety['navigation_warning_seconds'] === 20);

        $this->actingAs($attendant)
            ->get(route('consultations.workspace', $consultation))
            ->assertOk()
            ->assertDontSee('data-consultation-credits-link', false)
            ->assertDontSee('Comprar créditos');
    }

    public function test_credit_purchase_returns_to_the_active_consultation_until_it_ends(): void
    {
        $client = $this->client();
        $consultation = $this->activeConsultation($client, $this->attendant());
        $workspaceUrl = route('consultations.workspace', $consultation);

        $this->actingAs($client)
            ->get(route('client.credits.index', ['return_consultation' => $consultation->id]))
            ->assertOk()
            ->assertSessionHas('credits.return_consultation', $consultation->id)
            ->assertSee('data-credit-return', false)
            ->assertSee('Voltar ao atendimento')
            ->assertSee('href="'.$workspaceUrl.'"', false);

        $payment = Payment::factory()->for($client)->create();

        $this->actingAs($client)
            ->get(route('client.credits.payments.show', $payment))
            ->assertOk()
            ->assertSee('data-credit-return', false)
            ->assertSee('Voltar ao atendimento')
            ->assertSee('href="'.$workspaceUrl.'"', false);

        app(ConsultationLifecycleManager::class)->end($client, $consultation);

        // O botão da carteira sai com a sessão; o aviso global some junto com a
        // consulta encerrada.
        $this->actingAs($client)
            ->get(route('client.credits.index'))
            ->assertOk()
            ->assertSessionMissing('credits.return_consultation')
            ->assertDontSee('data-credit-return', false)
            ->assertDontSee('Voltar ao atendimento');

        $this->actingAs($client)
            ->get(route('client.credits.payments.show', $payment))
            ->assertOk()
            ->assertDontSee('data-credit-return', false)
            ->assertDontSee('Voltar ao atendimento');
    }

    public function test_credit_return_ignores_consultations_that_do_not_belong_to_the_client(): void
    {
        $client = $this->client();
        $consultation = $this->activeConsultation($client, $this->attendant());

        $this->actingAs($client)
            ->get(route('client.credits.index', ['return_consultation' => $consultation->id]))
            ->assertSessionHas('credits.return_consultation', $consultation->id);

        $otherClient = $this->client();
        $foreign = $this->activeConsultation($otherClient, $this->attendant());

        $this->actingAs($client)
            ->get(route('client.credits.index', ['return_consultation' => $foreign->id]))
            ->assertOk()
            ->assertSessionMissing('credits.return_consultation')
            ->assertDontSee('data-credit-return', false)
            ->assertDontSee('href="'.route('consultations.workspace', $foreign).'"', false);
    }
}
