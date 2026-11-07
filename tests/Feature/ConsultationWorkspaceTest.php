<?php

namespace Tests\Feature;

use App\Enums\ConsultationEndReason;
use App\Enums\ConsultationStatus;
use App\Events\ConsultationMessageSent;
use App\Events\ConsultationStateChanged;
use App\Models\AttendantProfile;
use App\Models\AuditLog;
use App\Models\Consultation;
use App\Models\ConsultationMessage;
use App\Models\Setting;
use App\Models\User;
use App\Models\Wallet;
use App\Support\ActiveConsultationResolver;
use App\Support\CommissionManager;
use App\Support\ConsultationBillingManager;
use App\Support\ConsultationLifecycleManager;
use App\Support\CreditWalletManager;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Tela dedicada de atendimento e avisos em tempo real.
 *
 * Cobre o contrato consumido pelos módulos `consultation-workspace`, `chat` e
 * `user-channel`: números calculados no servidor, fila de solicitações,
 * autorização dos canais privados e envio instantâneo de mensagens.
 */
class ConsultationWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('tarot_private');
        config(['broadcasting.default' => 'reverb']);
        Broadcast::forgetDrivers();
        require base_path('routes/channels.php');

        Setting::query()->create(['key' => 'credit_unit_price_cents', 'value' => '150', 'type' => 'integer']);
        Setting::query()->create(['key' => 'minimum_balance_minutes', 'value' => '1', 'type' => 'integer']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function clientWithBalance(int $balanceCents): User
    {
        $client = User::factory()->create();
        Wallet::factory()->create(['user_id' => $client->id, 'balance_cents' => 0]);

        $reference = AuditLog::query()->create([
            'action' => 'test.ledger',
            'metadata' => ['key' => 'workspace-credit'],
        ]);

        app(CreditWalletManager::class)->credit(
            $client,
            $balanceCents,
            'test:credit:'.Str::uuid(),
            $reference,
        );

        return $client;
    }

    private function onlineAttendant(): User
    {
        $attendant = User::factory()->attendant()->create();
        AttendantProfile::factory()
            ->online()
            ->for($attendant)
            ->withModalityPrices(['text' => 300])
            ->create(['commission_rate_bps' => 3000]);

        return $attendant;
    }

    private function activeConsultation(User $client, User $attendant): Consultation
    {
        return Consultation::factory()->active()->create([
            'client_id' => $client->id,
            'attendant_id' => $attendant->id,
            // Snapshots congelados no aceite: preço e comissão nunca são
            // recalculados com a configuração atual do sistema.
            'price_per_minute_cents' => 300,
            'credit_unit_price_cents' => 150,
            'billed_minutes' => 2,
            'commission_rate_bps' => 3000,
        ]);
    }

    private function authorizeChannel(User $user, string $channel): TestResponse
    {
        return $this->actingAs($user)->post('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => $channel,
        ]);
    }

    public function test_workspace_renders_server_calculated_numbers_for_both_participants(): void
    {
        Carbon::setTestNow('2026-02-11 12:00:00');

        $client = $this->clientWithBalance(1000);
        $attendant = $this->onlineAttendant();
        $consultation = $this->activeConsultation($client, $attendant);

        $consultation->forceFill(['started_at' => now()->subSeconds(90)])->save();

        $expectedMetrics = fn (array $metrics): bool => $metrics['consultation_id'] === $consultation->id
            && $metrics['status'] === ConsultationStatus::Active->value
            && $metrics['billed_minutes'] === 2
            && $metrics['price_per_minute_cents'] === 300
            && $metrics['price_per_minute_label'] === 'R$ 3,00'
            && $metrics['charged_cents'] === 600
            && $metrics['charged_label'] === 'R$ 6,00'
            && $metrics['charged_explanation'] === '2 minutos iniciados'
            && $metrics['next_refresh_in_seconds'] === 30
            // Saldo de R$ 10,00 ÷ R$ 3,00 por minuto = 3 minutos completos e
            // nenhum minuto em cobrança pendente; faltam 30 s para o minuto
            // corrente terminar, então o contador soma 3 min + 30 s.
            && $metrics['available_minutes'] === 3
            && $metrics['balance_cents'] === 1000
            && $metrics['balance_label'] === 'R$ 10,00'
            && $metrics['balance_explanation'] === 'Saldo da carteira · R$ 3,00 por minuto.'
            && $metrics['remaining_seconds'] === 210
            && $metrics['remaining_label'] === '03:30'
            && $metrics['remaining_explanation'] === 'Saldo de R$ 10,00 ÷ R$ 3,00 por minuto.';

        $this->actingAs($attendant)
            ->get(route('consultations.workspace', $consultation))
            ->assertOk()
            ->assertSee('data-consultation-workspace', false)
            ->assertSee('data-metrics-url="'.route('consultations.metrics', $consultation).'"', false)
            // O relógio saiu do topo: o cartão mostra o dinheiro que ainda
            // resta, já abatido o minuto em andamento.
            ->assertDontSee('Tempo em andamento')
            ->assertDontSee('data-consultation-timer', false)
            ->assertSee('Saldo do cliente')
            ->assertSee('data-metric="balance_label"', false)
            ->assertSee('R$ 10,00')
            ->assertSee('data-metric="balance_explanation"', false)
            // O aviso de inatividade encerra a consulta pelo próprio servidor.
            ->assertSee('data-end-url="'.route('consultations.end', $consultation).'"', false)
            ->assertSee('data-metric="charged_label"', false)
            ->assertSee('R$ 6,00')
            // O indicador de saldo virou contagem regressiva: o servidor manda
            // os segundos e o navegador apenas decrementa entre as buscas.
            ->assertSee('Tempo restante')
            ->assertSee('data-consultation-countdown', false)
            ->assertSee('data-remaining-seconds="210"', false)
            ->assertSee('data-metric="remaining_explanation"', false)
            ->assertSee('03:30')
            // Preço por minuto e o cartão de detalhes saíram da tela: os
            // números oficiais ficam nos indicadores do topo.
            ->assertDontSee('Preço por minuto')
            ->assertDontSee('data-metric="available_label"', false)
            ->assertSee('data-metric="commission_label"', false)
            ->assertSee('data-metric="commission_explanation"', false)
            ->assertSee('R$ 1,80')
            ->assertSee('estimativa até o encerramento.')
            ->assertSee('30%')
            ->assertViewHas(
                'metrics',
                fn (array $metrics): bool => $expectedMetrics($metrics)
                    && $metrics['commission_cents'] === 180
                    && $metrics['commission_label'] === 'R$ 1,80'
                    && $metrics['commission_is_estimate'] === true
                    && $metrics['commission_explanation'] === '30% de R$ 6,00 — estimativa até o encerramento.'
                    && $metrics['commission_rate_label'] === '30%',
            );

        $this->actingAs($client)
            ->get(route('consultations.workspace', $consultation))
            ->assertOk()
            ->assertSee('Saldo restante')
            ->assertViewHas(
                'metrics',
                fn (array $metrics): bool => $expectedMetrics($metrics)
                    && $metrics['commission_cents'] === null
                    && $metrics['commission_label'] === null
                    && $metrics['commission_is_estimate'] === false
                    && $metrics['commission_explanation'] === null,
            );
    }

    /**
     * Formulários que navegam de propósito dispensam o aviso nativo de saída.
     *
     * O guard `consultation-presence` mantém o `beforeunload` do navegador
     * armado enquanto a consulta está ativa. Encerrar a consulta e sair da
     * conta são ações pedidas pela própria pessoa — e o logout já encerra a
     * consulta no servidor — então esses formulários levam `data-allow-leave`
     * para não abrir o diálogo no meio da submissão.
     */
    public function test_intentional_navigation_forms_skip_the_leave_warning(): void
    {
        $client = $this->clientWithBalance(1000);
        $attendant = $this->onlineAttendant();
        $consultation = $this->activeConsultation($client, $attendant);

        /*
         * O atributo é procurado dentro do próprio `<form>` da ação: outros
         * links da tela (comprar créditos) também usam `data-allow-leave`.
         */
        $assertAllowLeaveForm = function (TestResponse $response, string $action): void {
            $response->assertOk();

            $this->assertMatchesRegularExpression(
                '/<form\b[^>]*action="'.preg_quote($action, '/').'"[^>]*\bdata-allow-leave\b/s',
                $response->getContent(),
                'O formulário de '.$action.' deve declarar a saída intencional (data-allow-leave).',
            );
        };

        $assertAllowLeaveForm(
            $this->actingAs($client)->get(route('consultations.workspace', $consultation)),
            route('consultations.end', $consultation),
        );

        $assertAllowLeaveForm(
            $this->actingAs($attendant)->get(route('consultations.workspace', $consultation)),
            route('consultations.end', $consultation),
        );

        // Sair da conta encerra a consulta no servidor: a saída é esperada.
        $assertAllowLeaveForm(
            $this->actingAs($client)->get(route('client.dashboard')),
            route('logout'),
        );
    }

    public function test_workspace_sends_users_back_while_the_consultation_is_not_active(): void
    {
        $client = User::factory()->create();
        $attendant = $this->onlineAttendant();

        $requested = Consultation::factory()->create([
            'client_id' => $client->id,
            'attendant_id' => $attendant->id,
        ]);

        $this->actingAs($client)
            ->get(route('consultations.workspace', $requested))
            ->assertRedirect(route('consultations.show', $requested))
            ->assertSessionHas('status');

        $ended = Consultation::factory()->active()->create([
            'client_id' => User::factory()->create()->id,
            'attendant_id' => $attendant->id,
            'status' => ConsultationStatus::Ended->value,
            'started_at' => now()->subMinutes(5),
            'ended_at' => now(),
            'ended_reason' => ConsultationEndReason::ClientEnded->value,
        ]);

        $this->actingAs($attendant)
            ->get(route('consultations.workspace', $ended))
            ->assertRedirect(route('consultations.show', $ended))
            ->assertSessionHas('status');
    }

    public function test_workspace_is_forbidden_for_users_outside_the_consultation(): void
    {
        $client = $this->clientWithBalance(1000);
        $attendant = $this->onlineAttendant();
        $consultation = $this->activeConsultation($client, $attendant);

        $this->actingAs(User::factory()->create())
            ->get(route('consultations.workspace', $consultation))
            ->assertForbidden();

        // O administrador enxerga a consulta, mas a tela de atendimento é
        // exclusiva de quem está atendendo ou sendo atendido.
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('consultations.workspace', $consultation))
            ->assertRedirect(route('consultations.show', $consultation));
    }

    public function test_metrics_endpoint_returns_official_numbers_and_protects_the_commission(): void
    {
        Carbon::setTestNow('2026-02-11 12:00:00');

        $client = $this->clientWithBalance(1000);
        $attendant = $this->onlineAttendant();
        $consultation = $this->activeConsultation($client, $attendant);

        $consultation->forceFill(['started_at' => now()->subSeconds(45)])->save();

        $this->actingAs($attendant)
            ->getJson(route('consultations.metrics', $consultation))
            ->assertOk()
            ->assertJsonPath('billed_minutes', 2)
            ->assertJsonPath('price_per_minute_cents', 300)
            ->assertJsonPath('charged_cents', 600)
            ->assertJsonPath('charged_label', 'R$ 6,00')
            ->assertJsonPath('commission_cents', 180)
            ->assertJsonPath('commission_label', 'R$ 1,80')
            ->assertJsonPath('commission_is_estimate', true)
            ->assertJsonPath('commission_rate_label', '30%')
            ->assertJsonPath('elapsed_seconds', 45)
            ->assertJsonPath('next_refresh_in_seconds', 15)
            ->assertJsonPath('available_minutes', 3)
            ->assertJsonPath('balance_cents', 1000)
            ->assertJsonPath('balance_label', 'R$ 10,00')
            // Contador de dinheiro: 2 minutos já debitados + 3 que o saldo
            // ainda paga (= 5 minutos comprados) menos os 45 s percorridos.
            // Contar só o minuto em andamento prendia a tela em 00:00 enquanto
            // o comando de cobrança estava atrasado e havia saldo.
            ->assertJsonPath('remaining_seconds', 255)
            ->assertJsonPath('remaining_label', '04:15')
            ->assertJsonPath('remaining_explanation', 'Saldo de R$ 10,00 ÷ R$ 3,00 por minuto.');

        $this->actingAs($client)
            ->getJson(route('consultations.metrics', $consultation))
            ->assertOk()
            ->assertJsonPath('charged_cents', 600)
            ->assertJsonPath('commission_cents', null)
            ->assertJsonPath('commission_is_estimate', false)
            ->assertJsonPath('commission_rate_label', null);

        $this->actingAs(User::factory()->create())
            ->getJson(route('consultations.metrics', $consultation))
            ->assertForbidden();
    }

    /**
     * Entre o limite do minuto e a execução de `consultations:charge-minutes`
     * o cartão não pode ficar parado: o minuto já iniciado é projetado pelo
     * servidor e marcado como cobrança em processamento.
     */
    public function test_workspace_projects_the_minute_in_progress_until_the_charge_command_runs(): void
    {
        Carbon::setTestNow('2026-02-11 12:00:00');

        $client = $this->clientWithBalance(1000);
        $attendant = $this->onlineAttendant();
        $consultation = $this->activeConsultation($client, $attendant);

        // Primeiro minuto cobrado no aceite; 90 s andados já iniciam o segundo
        // e o comando agendado ainda não registrou o débito.
        $consultation->forceFill([
            'billed_minutes' => 1,
            'started_at' => now()->subSeconds(90),
        ])->save();

        $this->actingAs($attendant)
            ->getJson(route('consultations.metrics', $consultation))
            ->assertOk()
            ->assertJsonPath('elapsed_minutes', 2)
            ->assertJsonPath('billed_minutes', 1)
            ->assertJsonPath('pending_minutes', 1)
            ->assertJsonPath('charge_pending', true)
            ->assertJsonPath('charged_cents', 600)
            ->assertJsonPath('charged_explanation', '2 minutos iniciados · cobrança em processamento')
            ->assertJsonPath('remaining_seconds', 150)
            ->assertJsonPath('remaining_label', '02:30')
            // O saldo exibido já desconta o minuto que está sendo cobrado:
            // R$ 10,00 na carteira − R$ 3,00 do minuto em andamento.
            ->assertJsonPath('balance_cents', 700)
            ->assertJsonPath('balance_label', 'R$ 7,00')
            ->assertJsonPath('balance_explanation', 'Já descontado o minuto em andamento · R$ 3,00 por minuto.');

        $this->actingAs($attendant)
            ->get(route('consultations.workspace', $consultation))
            ->assertOk()
            ->assertSee('data-metric="charged_explanation"', false)
            ->assertSee('2 minutos iniciados · cobrança em processamento')
            ->assertSee('data-metric="balance_label"', false)
            ->assertSee('R$ 7,00')
            ->assertSee('Já descontado o minuto em andamento · R$ 3,00 por minuto.');
    }

    /**
     * Sem saldo para o próximo minuto o servidor não vai debitar: a projeção
     * para no que já foi cobrado, o contador chega a zero e a consulta é
     * encerrada — inclusive sem o comando agendado, para que ninguém converse
     * de graça quando o scheduler está parado.
     */
    public function test_remaining_time_never_promises_a_minute_the_wallet_cannot_pay(): void
    {
        Carbon::setTestNow('2026-02-11 12:00:00');

        $client = User::factory()->create();
        Wallet::factory()->create(['user_id' => $client->id, 'balance_cents' => 0]);
        $attendant = $this->onlineAttendant();
        $consultation = $this->activeConsultation($client, $attendant);

        $consultation->forceFill(['started_at' => now()->subSeconds(150)])->save();

        $this->actingAs($attendant)
            ->getJson(route('consultations.metrics', $consultation))
            ->assertOk()
            ->assertJsonPath('available_minutes', 0)
            ->assertJsonPath('remaining_seconds', 0)
            ->assertJsonPath('remaining_label', '00:00')
            ->assertJsonPath('pending_minutes', 0)
            ->assertJsonPath('charge_pending', false)
            ->assertJsonPath('charged_cents', 600)
            ->assertJsonPath('charged_explanation', '2 minutos iniciados');

        // Abrir a tela de atendimento confere o saldo: a consulta é encerrada
        // por falta de créditos e o participante vai para o histórico/recibo.
        $this->actingAs($attendant)
            ->get(route('consultations.workspace', $consultation))
            ->assertRedirect(route('consultations.show', $consultation));

        $consultation->refresh();

        $this->assertSame(ConsultationStatus::Ended, $consultation->status);
        $this->assertSame(ConsultationEndReason::OutOfCredits, $consultation->ended_reason);
        $this->assertSame(600, $consultation->totalChargedCents());
    }

    /**
     * O contador não pode voltar para o começo do minuto quando a cobrança
     * ainda não foi registrada: entre uma busca e outra ele precisa continuar
     * descendo. Era esse salto que prendia o cartão em torno de dois minutos e
     * impedia a contagem de chegar a zero.
     */
    public function test_remaining_time_keeps_counting_down_across_the_minute_boundary(): void
    {
        Carbon::setTestNow('2026-02-11 12:00:00');

        // R$ 7,00 na carteira: sobra do saldo depois do primeiro débito.
        $client = $this->clientWithBalance(700);
        $attendant = $this->onlineAttendant();
        $consultation = $this->activeConsultation($client, $attendant);

        $consultation->forceFill([
            'billed_minutes' => 1,
            'started_at' => now()->subSeconds(58),
        ])->save();

        $before = $this->actingAs($attendant)
            ->getJson(route('consultations.metrics', $consultation))
            ->assertOk()
            ->assertJsonPath('pending_minutes', 0)
            ->json();

        // 62 s: o segundo minuto começou e o comando de cobrança ainda não
        // registrou o débito, então o minuto segue projetado.
        Carbon::setTestNow(now()->addSeconds(4));

        $after = $this->actingAs($attendant)
            ->getJson(route('consultations.metrics', $consultation))
            ->assertOk()
            ->assertJsonPath('pending_minutes', 1)
            ->json();

        $this->assertSame(122, $before['remaining_seconds']);
        $this->assertSame(118, $after['remaining_seconds']);
        $this->assertLessThan($before['remaining_seconds'], $after['remaining_seconds']);

        // O saldo do cartão também desconta o minuto em andamento: R$ 7,00 −
        // R$ 3,00 = R$ 4,00, o mesmo valor que a cobrança vai registrar.
        $this->assertSame(400, $after['balance_cents']);
    }

    public function test_attendant_commission_accumulates_during_the_consultation_and_yields_to_the_ledger(): void
    {
        Carbon::setTestNow('2026-02-11 12:00:00');

        $client = $this->clientWithBalance(1000);
        $attendant = $this->onlineAttendant();

        $consultation = Consultation::factory()->active()->create([
            'client_id' => $client->id,
            'attendant_id' => $attendant->id,
            'price_per_minute_cents' => 300,
            'credit_unit_price_cents' => 150,
            'billed_minutes' => 0,
            'commission_rate_bps' => 3000,
            'started_at' => now()->subSeconds(90),
        ]);

        // Antes do primeiro débito não há base no razão, mas o valor já é uma
        // estimativa — e ela acompanha a projeção do minuto em andamento.
        $this->actingAs($attendant)
            ->getJson(route('consultations.metrics', $consultation))
            ->assertOk()
            ->assertJsonPath('billed_minutes', 0)
            ->assertJsonPath('pending_minutes', 2)
            ->assertJsonPath('charged_cents', 600)
            ->assertJsonPath('commission_cents', 180)
            ->assertJsonPath('commission_is_estimate', true);

        // Minutos cobrados viram comissão na hora: o razão só recebe o
        // lançamento no encerramento, o cartão não pode ficar zerado até lá.
        app(ConsultationBillingManager::class)->billDue($consultation, now());

        $this->actingAs($attendant)
            ->getJson(route('consultations.metrics', $consultation))
            ->assertOk()
            ->assertJsonPath('billed_minutes', 2)
            ->assertJsonPath('charged_cents', 600)
            ->assertJsonPath('commission_cents', 180)
            ->assertJsonPath('commission_label', 'R$ 1,80')
            ->assertJsonPath('commission_is_estimate', true);

        // No encerramento o valor do razão assume e passa a ser oficial.
        app(ConsultationLifecycleManager::class)->end($attendant, $consultation->fresh());

        $this->actingAs($attendant)
            ->getJson(route('consultations.metrics', $consultation->fresh()))
            ->assertOk()
            ->assertJsonPath('commission_cents', 180)
            ->assertJsonPath('commission_is_estimate', false)
            ->assertJsonPath('commission_explanation', '30% — valor consolidado no encerramento.');

        // Estorno total continua prevalecendo sobre qualquer estimativa.
        $commissions = app(CommissionManager::class);
        $accrual = $commissions->accrualFor($consultation->fresh());

        $this->assertNotNull($accrual);

        $commissions->reverse($accrual, User::factory()->admin()->create(), 'Estorno solicitado no teste.');

        $this->actingAs($attendant)
            ->getJson(route('consultations.metrics', $consultation->fresh()))
            ->assertOk()
            ->assertJsonPath('commission_cents', 0)
            ->assertJsonPath('commission_label', 'R$ 0,00')
            ->assertJsonPath('commission_is_estimate', false);
    }

    public function test_pending_requests_endpoint_lists_the_queue_in_arrival_order_with_decision_urls(): void
    {
        $attendant = $this->onlineAttendant();

        $first = Consultation::factory()->create([
            'client_id' => User::factory()->create(['name' => 'Cliente Antigo'])->id,
            'attendant_id' => $attendant->id,
            'created_at' => now()->subMinutes(4),
        ]);

        $second = Consultation::factory()->audio()->create([
            'client_id' => User::factory()->create(['name' => 'Cliente Novo'])->id,
            'attendant_id' => $attendant->id,
            'created_at' => now()->subMinute(),
        ]);

        $this->actingAs($attendant)
            ->getJson(route('attendant.requests.pending'))
            ->assertOk()
            ->assertJsonCount(2, 'requests')
            ->assertJsonPath('requests.0.id', $first->id)
            ->assertJsonPath('requests.0.client_name', 'Cliente Antigo')
            ->assertJsonPath('requests.0.status', ConsultationStatus::Requested->value)
            ->assertJsonPath('requests.0.accept_url', route('attendant.consultations.accept', $first))
            ->assertJsonPath('requests.0.reject_url', route('attendant.consultations.reject', $first))
            ->assertJsonPath('requests.0.workspace_url', route('consultations.workspace', $first))
            ->assertJsonPath('requests.1.id', $second->id)
            ->assertJsonPath('requests.1.modality', 'audio');

        // A fila não cresce com consultas já decididas.
        $first->forceFill(['status' => ConsultationStatus::Cancelled->value])->saveQuietly();

        $this->actingAs($attendant)
            ->getJson(route('attendant.requests.pending'))
            ->assertOk()
            ->assertJsonCount(1, 'requests')
            ->assertJsonPath('requests.0.id', $second->id);

        // O mesmo endpoint é exclusivo do painel do atendente.
        $this->actingAs(User::factory()->create())
            ->getJson(route('attendant.requests.pending'))
            ->assertForbidden();
    }

    public function test_user_channel_authorization_is_limited_to_its_owner(): void
    {
        $attendant = $this->onlineAttendant();

        $this->authorizeChannel($attendant, 'private-user.'.$attendant->id)
            ->assertOk()
            ->assertJsonStructure(['auth']);

        $this->authorizeChannel(User::factory()->create(), 'private-user.'.$attendant->id)
            ->assertForbidden();

        $attendant->update(['is_active' => false]);

        $this->authorizeChannel($attendant, 'private-user.'.$attendant->id)
            ->assertForbidden();
    }

    public function test_accept_returns_the_workspace_redirect_and_warns_both_participants(): void
    {
        $client = $this->clientWithBalance(1000);
        $attendant = $this->onlineAttendant();

        $consultation = Consultation::factory()->create([
            'client_id' => $client->id,
            'attendant_id' => $attendant->id,
        ]);

        Event::fake([ConsultationStateChanged::class]);

        $this->actingAs($attendant)
            ->patchJson(route('attendant.consultations.accept', $consultation))
            ->assertOk()
            ->assertJsonPath('redirect', route('consultations.workspace', $consultation))
            ->assertJsonPath('consultation.status', ConsultationStatus::Active->value);

        $this->assertSame(ConsultationStatus::Active, $consultation->fresh()->status);

        Event::assertDispatchedTimes(ConsultationStateChanged::class, 1);
        Event::assertDispatched(ConsultationStateChanged::class, function (ConsultationStateChanged $event) use ($client, $attendant, $consultation): bool {
            $channels = array_map(fn ($channel): string => $channel->name, $event->broadcastOn());

            $this->assertSame($consultation->id, $event->consultationId);
            $this->assertSame('consultation.state', $event->broadcastAs());
            $this->assertSame('active', $event->consultation['status']);
            $this->assertContains('private-consultation.'.$consultation->id, $channels);
            $this->assertContains('private-user.'.$client->id, $channels);
            $this->assertContains('private-user.'.$attendant->id, $channels);

            return true;
        });

        // A fila do atendente fica vazia assim que o aceite é confirmado.
        $this->actingAs($attendant)
            ->getJson(route('attendant.requests.pending'))
            ->assertOk()
            ->assertJsonCount(0, 'requests');
    }

    public function test_attendant_cannot_accept_a_second_consultation_while_busy(): void
    {
        $attendant = $this->onlineAttendant();
        $busyClient = $this->clientWithBalance(1000);
        $waitingClient = $this->clientWithBalance(1000);

        $this->activeConsultation($busyClient, $attendant);

        $waiting = Consultation::factory()->create([
            'client_id' => $waitingClient->id,
            'attendant_id' => $attendant->id,
        ]);

        $this->actingAs($attendant)
            ->patchJson(route('attendant.consultations.accept', $waiting))
            ->assertStatus(422)
            ->assertJsonValidationErrors('consultation');

        $this->assertSame(ConsultationStatus::Requested, $waiting->fresh()->status);
        $this->assertSame(1000, (int) Wallet::query()->where('user_id', $waitingClient->id)->value('balance_cents'));
    }

    public function test_instant_message_send_returns_the_persisted_message_and_broadcasts_it(): void
    {
        $client = $this->clientWithBalance(1000);
        $attendant = $this->onlineAttendant();
        $consultation = $this->activeConsultation($client, $attendant);

        Event::fake([ConsultationMessageSent::class]);

        $this->actingAs($client)
            ->postJson(route('consultations.messages.store', $consultation), ['body' => 'Oi, tudo bem?'])
            ->assertCreated()
            ->assertJsonPath('message.body', 'Oi, tudo bem?')
            ->assertJsonPath('message.sender_id', $client->id)
            ->assertJsonPath('consultation.status', ConsultationStatus::Active->value);

        $this->assertDatabaseHas('consultation_messages', [
            'consultation_id' => $consultation->id,
            'sender_id' => $client->id,
            'body' => 'Oi, tudo bem?',
        ]);

        Event::assertDispatched(ConsultationMessageSent::class, function (ConsultationMessageSent $event) use ($consultation): bool {
            $this->assertSame($consultation->id, $event->consultationId);
            $this->assertSame('private-consultation.'.$consultation->id, $event->broadcastOn()[0]->name);
            $this->assertSame('consultation.message', $event->broadcastAs());
            $this->assertSame('Oi, tudo bem?', $event->message['body']);

            return true;
        });

        // Envio sem JavaScript continua funcionando pelo formulário HTML.
        $this->actingAs($attendant)
            ->post(route('consultations.messages.store', $consultation), ['body' => 'Tudo ótimo!'])
            ->assertRedirect(route('consultations.show', $consultation).'#chat');
    }

    public function test_consultation_channel_authorization_is_limited_to_the_two_participants(): void
    {
        $client = $this->clientWithBalance(1000);
        $attendant = $this->onlineAttendant();
        $consultation = $this->activeConsultation($client, $attendant);

        $channel = 'private-consultation.'.$consultation->id;

        $this->authorizeChannel($client, $channel)
            ->assertOk()
            ->assertJsonStructure(['auth']);

        $this->authorizeChannel($attendant, $channel)
            ->assertOk()
            ->assertJsonStructure(['auth']);

        // Nem outro usuário autenticado nem o administrador ouvem a conversa.
        $this->authorizeChannel(User::factory()->create(), $channel)
            ->assertForbidden();

        $this->authorizeChannel(User::factory()->admin()->create(), $channel)
            ->assertForbidden();

        // Conta desativada perde o acesso mesmo sendo participante.
        $client->update(['is_active' => false]);

        $this->authorizeChannel($client, $channel)
            ->assertForbidden();
    }

    public function test_simultaneous_accepts_keep_a_single_active_consultation_and_charge_once(): void
    {
        $attendant = $this->onlineAttendant();
        $firstClient = $this->clientWithBalance(1000);
        $secondClient = $this->clientWithBalance(1000);

        $first = Consultation::factory()->create([
            'client_id' => $firstClient->id,
            'attendant_id' => $attendant->id,
        ]);

        $second = Consultation::factory()->create([
            'client_id' => $secondClient->id,
            'attendant_id' => $attendant->id,
        ]);

        // Dois aceites quase simultâneos: o primeiro trava o atendente.
        $this->actingAs($attendant)
            ->patchJson(route('attendant.consultations.accept', $first))
            ->assertOk();

        $this->actingAs($attendant)
            ->patchJson(route('attendant.consultations.accept', $second))
            ->assertStatus(422)
            ->assertJsonValidationErrors('consultation');

        $this->assertSame(ConsultationStatus::Active, $first->fresh()->status);
        $this->assertSame(ConsultationStatus::Requested, $second->fresh()->status);

        $this->assertSame(
            1,
            Consultation::query()
                ->where('attendant_id', $attendant->id)
                ->where('status', ConsultationStatus::Active->value)
                ->count(),
        );

        // Só a consulta aceita cobrou o primeiro minuto.
        $this->assertSame(700, (int) Wallet::query()->where('user_id', $firstClient->id)->value('balance_cents'));
        $this->assertSame(1000, (int) Wallet::query()->where('user_id', $secondClient->id)->value('balance_cents'));

        $this->assertSame(
            1,
            AuditLog::query()
                ->where('action', 'consultation.accepted')
                ->where('subject_id', $first->id)
                ->count(),
        );
    }

    public function test_workspace_reload_restores_the_conversation_and_clears_the_unread_notice(): void
    {
        $client = $this->clientWithBalance(1000);
        $attendant = $this->onlineAttendant();
        $consultation = $this->activeConsultation($client, $attendant);

        ConsultationMessage::factory()->create([
            'consultation_id' => $consultation->id,
            'sender_id' => $client->id,
            'body' => 'Primeira mensagem',
        ]);

        ConsultationMessage::factory()->create([
            'consultation_id' => $consultation->id,
            'sender_id' => $attendant->id,
            'body' => 'Segunda mensagem',
        ]);

        ConsultationMessage::factory()->create([
            'consultation_id' => $consultation->id,
            'sender_id' => $client->id,
            'body' => 'Terceira mensagem',
        ]);

        $firstLoad = $this->actingAs($client)
            ->get(route('consultations.workspace', $consultation))
            ->assertOk()
            ->assertSee('data-chat-timeline', false)
            ->assertSee('data-feed-url="'.route('consultations.messages.index', $consultation).'"', false);

        $firstLoad->assertSeeInOrder(['Primeira mensagem', 'Segunda mensagem', 'Terceira mensagem']);

        // A mensagem do atendente ainda não lida aparece no aviso da visita.
        $firstLoad->assertSee('1 mensagem nova foi recebida');

        // Recarregar a tela (queda de rede, F5) mantém o histórico e a leitura
        // já registrada, sem repetir o aviso de mensagens novas.
        $this->actingAs($client)
            ->get(route('consultations.workspace', $consultation))
            ->assertOk()
            ->assertSeeInOrder(['Primeira mensagem', 'Segunda mensagem', 'Terceira mensagem'])
            ->assertDontSee('mensagem nova foi recebida');

        $this->assertSame(
            3,
            ConsultationMessage::query()->where('consultation_id', $consultation->id)->count(),
        );
    }

    public function test_attendant_panel_exposes_the_realtime_queue_hooks(): void
    {
        $attendant = $this->onlineAttendant();

        $this->actingAs($attendant)
            ->get(route('attendant.dashboard'))
            ->assertOk()
            ->assertSee('data-user-channel', false)
            ->assertSee('data-user-id="'.$attendant->id.'"', false)
            ->assertSee('data-pending-url="'.route('attendant.requests.pending').'"', false)
            ->assertSee('data-workspace-url="'.route('consultations.workspace', ['consultation' => '__ID__']).'"', false)
            ->assertSee('data-requests-list', false)
            ->assertSee('data-requests-empty', false)
            ->assertSee('data-pending-count', false)
            ->assertSee('data-request-modal', false)
            ->assertSee('data-request-accept', false)
            ->assertSee('data-request-reject', false);
    }

    /**
     * O modal de aceite/recusa (com a sirene) vem do layout do painel, então
     * precisa existir em toda tela do atendente — não só no dashboard.
     */
    public function test_request_modal_follows_the_attendant_across_panel_screens(): void
    {
        $client = $this->clientWithBalance(1000);
        $attendant = $this->onlineAttendant();
        $consultation = $this->activeConsultation($client, $attendant);

        $assertHooks = function (TestResponse $response) use ($attendant): void {
            $response
                ->assertOk()
                ->assertSee('data-user-channel', false)
                ->assertSee('data-user-id="'.$attendant->id.'"', false)
                ->assertSee('data-pending-url="'.route('attendant.requests.pending').'"', false)
                ->assertSee('data-workspace-url="'.route('consultations.workspace', ['consultation' => '__ID__']).'"', false)
                ->assertSee('data-request-modal', false)
                ->assertSee('data-request-accept', false)
                ->assertSee('data-request-reject', false);
        };

        $assertHooks($this->actingAs($attendant)->get(route('attendant.history')));
        $assertHooks($this->actingAs($attendant)->get(route('consultations.show', $consultation)));
        $assertHooks($this->actingAs($attendant)->get(route('consultations.workspace', $consultation)));
        $assertHooks($this->actingAs($attendant)->get(route('attendant.dashboard')));
    }

    /**
     * O cliente também precisa do canal fora do dashboard (é ele que o leva à
     * tela de atendimento quando o aceite acontece), mas nunca do modal de
     * resposta nem da fila de solicitações.
     */
    public function test_client_panel_keeps_the_user_channel_without_the_request_modal(): void
    {
        $client = $this->clientWithBalance(1000);

        $this->actingAs($client)
            ->get(route('client.dashboard'))
            ->assertOk()
            ->assertSee('data-user-channel', false)
            ->assertSee('data-user-id="'.$client->id.'"', false)
            ->assertSee('data-workspace-url="'.route('consultations.workspace', ['consultation' => '__ID__']).'"', false)
            ->assertDontSee('data-pending-url', false)
            ->assertDontSee('data-request-modal', false);

        $this->actingAs($client)
            ->get(route('client.history'))
            ->assertOk()
            ->assertSee('data-user-channel', false)
            ->assertDontSee('data-request-modal', false);
    }

    /**
     * A administração fica fora do canal `user.{id}`: a autorização do canal e
     * a política de acesso à conversa não incluem o admin (D-023/Q-06).
     */
    public function test_admin_panel_does_not_subscribe_to_the_user_channel(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee('data-user-channel', false)
            ->assertDontSee('data-request-modal', false);
    }

    /**
     * Aviso "Voltar ao atendimento": quem está com uma consulta ativa e navega
     * para fora do workspace continua vendo o caminho de volta (e o lembrete de
     * que os créditos seguem sendo descontados).
     */
    public function test_active_consultation_banner_follows_the_user_outside_the_workspace(): void
    {
        $client = $this->clientWithBalance(1000);
        $attendant = $this->onlineAttendant();
        $consultation = $this->activeConsultation($client, $attendant);
        $attendantName = $attendant->attendantProfile->display_name;

        /**
         * O aviso é montado com trechos de texto separados por quebras de linha
         * no template, então cada parte é verificada isoladamente.
         *
         * @param  list<string>  $expectedLines
         */
        $assertBanner = function (TestResponse $response, array $expectedLines) use ($consultation): void {
            $response
                ->assertOk()
                ->assertSee('data-consultation-return', false)
                ->assertSee('data-consultation-return-link', false)
                ->assertSee('href="'.route('consultations.workspace', $consultation).'"', false)
                ->assertSee('Atendimento #'.$consultation->id.' em andamento')
                ->assertSee('Voltar ao atendimento');

            foreach ($expectedLines as $expectedLine) {
                $response->assertSee($expectedLine);
            }
        };

        $assertBanner($this->actingAs($client)->get(route('client.dashboard')), [
            'Você está em atendimento com',
            $attendantName,
            'Os créditos continuam sendo descontados até o encerramento.',
        ]);

        $assertBanner($this->actingAs($client)->get(route('client.history')), [
            'Você está em atendimento com',
            $attendantName,
        ]);

        $assertBanner($this->actingAs($client)->get(route('consultations.show', $consultation)), [
            'Você está em atendimento com',
            $attendantName,
        ]);

        $assertBanner($this->actingAs($attendant)->get(route('attendant.history')), [
            'Você está atendendo',
            $client->name,
        ]);

        // Dentro do workspace o aviso é redundante: a própria tela já avisa.
        $this->actingAs($client)
            ->get(route('consultations.workspace', $consultation))
            ->assertOk()
            ->assertDontSee('data-consultation-return', false);

        $this->actingAs($attendant)
            ->get(route('consultations.workspace', $consultation))
            ->assertOk()
            ->assertDontSee('data-consultation-return', false);

        // Administração e terceiros nunca veem o aviso de uma consulta alheia.
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('consultations.show', $consultation))
            ->assertOk()
            ->assertDontSee('data-consultation-return', false);

        $this->actingAs(User::factory()->create())
            ->get(route('client.dashboard'))
            ->assertOk()
            ->assertDontSee('data-consultation-return', false);

        // Encerrada a consulta, o aviso desaparece de todas as telas.
        $consultation->forceFill([
            'status' => ConsultationStatus::Ended->value,
            'ended_at' => now(),
        ])->save();

        $this->actingAs($client)
            ->get(route('client.dashboard'))
            ->assertOk()
            ->assertDontSee('data-consultation-return', false);

        $this->actingAs($attendant)
            ->get(route('attendant.history'))
            ->assertOk()
            ->assertDontSee('data-consultation-return', false);
    }

    /**
     * O resolvedor é a única fonte do aviso: consulta do próprio usuário, ativa,
     * nunca para administração, contas desativadas ou terceiros.
     */
    public function test_active_consultation_resolver_only_returns_the_users_own_active_consultation(): void
    {
        $resolver = app(ActiveConsultationResolver::class);
        $client = $this->clientWithBalance(1000);
        $attendant = $this->onlineAttendant();

        $this->assertNull($resolver->forUser(null));
        $this->assertNull($resolver->forUser($client));

        $consultation = $this->activeConsultation($client, $attendant);

        $this->assertSame($consultation->id, $resolver->forUser($client)?->getKey());
        $this->assertSame($consultation->id, $resolver->forUser($attendant)?->getKey());

        $this->assertNull($resolver->forUser(User::factory()->create()));
        $this->assertNull($resolver->forUser(User::factory()->admin()->create()));

        $client->forceFill(['is_active' => false])->save();
        $this->assertNull($resolver->forUser($client->fresh()));

        $client->forceFill(['is_active' => true])->save();
        $consultation->forceFill([
            'status' => ConsultationStatus::Ended->value,
            'ended_at' => now(),
        ])->save();

        $this->assertNull($resolver->forUser($client->fresh()));
        $this->assertNull($resolver->forUser($attendant->fresh()));
    }
}
