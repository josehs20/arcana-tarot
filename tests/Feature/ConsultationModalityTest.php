<?php

namespace Tests\Feature;

use App\Enums\ConsultationModality;
use App\Enums\ConsultationStatus;
use App\Enums\LedgerEntryType;
use App\Models\AttendantProfile;
use App\Models\AuditLog;
use App\Models\CommissionLedgerEntry;
use App\Models\Consultation;
use App\Models\CreditLedgerEntry;
use App\Models\Setting;
use App\Models\User;
use App\Models\Wallet;
use App\Support\ConsultationBillingManager;
use Carbon\Carbon;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Separacao dos atendimentos em texto, audio e video.
 *
 * Cobre exibicao de precos, escolha da modalidade, cobranca pelo preco da
 * modalidade, protecao contra adulteracao de preco, saldo insuficiente,
 * comissoes, historico e permissoes administrativas.
 */
class ConsultationModalityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::query()->create(['key' => 'credit_unit_price_cents', 'value' => '100', 'type' => 'integer']);
        Setting::query()->create(['key' => 'minimum_balance_minutes', 'value' => '1', 'type' => 'integer']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function client(int $balanceCents = 10_000): User
    {
        $client = User::factory()->create();
        Wallet::factory()->create([
            'user_id' => $client->getKey(),
            'balance_cents' => $balanceCents,
        ]);

        return $client;
    }

    /**
     * @param  array<int, string>  $modalities
     * @return array{0: User, 1: AttendantProfile}
     */
    private function attendant(array $modalities = ['text', 'audio', 'video']): array
    {
        $user = User::factory()->attendant()->create();
        $profile = AttendantProfile::factory()
            ->online()
            ->offering($modalities)
            ->for($user)
            ->withModalityPrices(['text' => 100, 'audio' => 200, 'video' => 400])
            ->create(['commission_rate_bps' => 3000]);

        return [$user, $profile];
    }

    private function requestConsultation(User $client, User $attendant, string $modality = 'text'): Consultation
    {
        $this->actingAs($client)
            ->post(route('client.consultations.store'), [
                'attendant_id' => $attendant->getKey(),
                'modality' => $modality,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        return Consultation::query()->latest('id')->firstOrFail();
    }

    private function accept(User $attendant, Consultation $consultation): Consultation
    {
        $this->actingAs($attendant)
            ->patch(route('attendant.consultations.accept', $consultation))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        return $consultation->refresh();
    }

    public function test_public_directory_and_profile_show_each_modality_with_server_prices(): void
    {
        [$attendant] = $this->attendant();

        $this->get(route('attendants.index'))
            ->assertOk()
            ->assertSee('Texto')
            ->assertSee('Áudio')
            ->assertSee('Vídeo')
            ->assertSee('R$ 1,00')
            ->assertSee('R$ 2,00')
            ->assertSee('R$ 4,00');

        $this->get(route('attendants.show', $attendant->attendantProfile))
            ->assertOk()
            ->assertSee('Modalidades e preços')
            ->assertSee('Conversa por mensagens, áudios gravados e cartas de Tarot pelo chat')
            ->assertSee('R$ 2,00')
            ->assertSee('R$ 4,00');
    }

    public function test_profile_shows_the_client_balance_and_the_minimum_to_start(): void
    {
        $client = $this->client(150);
        [$attendant] = $this->attendant();

        $this->actingAs($client)
            ->get(route('attendants.show', $attendant->attendantProfile))
            ->assertOk()
            ->assertSee('Seu saldo')
            ->assertSee('R$ 1,50')
            // Atributos que o JS usa para avisar o saldo por modalidade.
            ->assertSee('data-balance-cents="150"', false)
            ->assertSee('data-minimum-minutes="1"', false)
            ->assertSee('data-price-cents="100"', false)
            // Modal aberto por consultation-modalities.js antes do envio.
            ->assertSee('data-balance-modal', false)
            ->assertSee('data-balance-modal-minimum', false)
            ->assertSee('data-balance-modal-current', false)
            ->assertSee('Saldo insuficiente')
            ->assertSee('Comprar créditos')
            ->assertSee(route('client.credits.index'), false)
            ->assertDontSee('data-balance-warning', false);
    }

    public function test_client_can_request_audio_and_price_is_frozen_on_accept(): void
    {
        Carbon::setTestNow('2026-02-01 10:00:00');

        $client = $this->client();
        [$attendant] = $this->attendant();

        $consultation = $this->requestConsultation($client, $attendant, 'audio');

        $this->assertSame(ConsultationModality::Audio, $consultation->modality);
        $this->assertNull($consultation->price_per_minute_cents);

        $consultation = $this->accept($attendant, $consultation);

        $this->assertSame(ConsultationStatus::Active, $consultation->status);
        $this->assertSame(2, $consultation->rate_credits_per_minute);
        $this->assertSame(100, $consultation->credit_unit_price_cents);
        $this->assertSame(200, $consultation->price_per_minute_cents);
        $this->assertSame(200, $consultation->pricePerMinuteCents());
        $this->assertSame(1, $consultation->billed_minutes);

        $debit = CreditLedgerEntry::query()
            ->where('type', LedgerEntryType::Debit->value)
            ->where('reference_id', $consultation->getKey())
            ->sole();

        $this->assertSame(200, $debit->amount_cents);
        $this->assertSame('consultation:'.$consultation->getKey().':minute:1', $debit->idempotency_key);
    }

    public function test_request_is_rejected_when_attendant_does_not_offer_the_modality(): void
    {
        $client = $this->client();
        [$attendant] = $this->attendant(['text']);

        $this->actingAs($client)
            ->from(route('attendants.show', $attendant->attendantProfile))
            ->post(route('client.consultations.store'), [
                'attendant_id' => $attendant->getKey(),
                'modality' => 'audio',
            ])
            ->assertRedirect(route('attendants.show', $attendant->attendantProfile))
            ->assertSessionHasErrors('modality');

        $this->assertSame(0, Consultation::query()->count());
    }

    public function test_disabled_media_modality_cannot_be_requested_even_if_attendant_offers_it(): void
    {
        config(['consultation.modalities.video.enabled' => false]);

        $client = $this->client();
        [$attendant] = $this->attendant();

        $this->actingAs($client)
            ->from(route('attendants.show', $attendant->attendantProfile))
            ->post(route('client.consultations.store'), [
                'attendant_id' => $attendant->getKey(),
                'modality' => 'video',
            ])
            ->assertSessionHasErrors('modality');

        $this->assertSame(0, Consultation::query()->count());
    }

    public function test_browser_cannot_override_modality_price_or_credit_rate(): void
    {
        $client = $this->client();
        [$attendant] = $this->attendant();

        $this->actingAs($client)
            ->post(route('client.consultations.store'), [
                'attendant_id' => $attendant->getKey(),
                'modality' => 'video',
                'price_per_minute_cents' => 1,
                'rate_credits_per_minute' => 1,
                'credit_unit_price_cents' => 1,
                'billed_minutes' => 0,
            ])
            ->assertSessionHasNoErrors();

        $consultation = $this->accept($attendant, Consultation::query()->latest('id')->firstOrFail());

        $this->assertSame(ConsultationModality::Video, $consultation->modality);
        $this->assertSame(4, $consultation->rate_credits_per_minute);
        $this->assertSame(100, $consultation->credit_unit_price_cents);
        $this->assertSame(400, $consultation->price_per_minute_cents);
    }

    public function test_accept_requires_balance_for_the_chosen_modality(): void
    {
        $client = $this->client(1_000);
        [$attendant] = $this->attendant();

        $consultation = $this->requestConsultation($client, $attendant, 'audio');

        /*
         * Saldo gasto depois da solicitação: o aceite revalida o mínimo da
         * modalidade escolhida (áudio = 200 × 1 minuto) antes de cobrar.
         */
        Wallet::query()->where('user_id', $client->getKey())->update(['balance_cents' => 150]);

        $this->actingAs($attendant)
            ->from(route('attendant.dashboard'))
            ->patch(route('attendant.consultations.accept', $consultation))
            ->assertRedirect(route('attendant.dashboard'))
            ->assertSessionHasErrors('consultation');

        $consultation->refresh();

        $this->assertSame(ConsultationStatus::Requested, $consultation->status);
        $this->assertNull($consultation->price_per_minute_cents);
        $this->assertSame(0, CreditLedgerEntry::query()->count());
        $this->assertSame(150, (int) Wallet::query()->where('user_id', $client->getKey())->value('balance_cents'));
    }

    public function test_audio_minutes_are_billed_with_audio_price_and_commission_uses_billed_base(): void
    {
        Carbon::setTestNow('2026-02-01 12:00:00');

        $client = $this->client(1_000);
        [$attendant] = $this->attendant();

        $consultation = $this->requestConsultation($client, $attendant, 'audio');
        $consultation = $this->accept($attendant, $consultation);

        Carbon::setTestNow('2026-02-01 12:02:10');

        $this->artisan('consultations:charge-minutes')->assertExitCode(0);

        $billing = app(ConsultationBillingManager::class)->billDue($consultation->refresh());

        $this->assertSame(3, $billing->billedMinutes);
        $this->assertSame(0, $billing->chargedMinutes);

        $debits = CreditLedgerEntry::query()
            ->where('type', LedgerEntryType::Debit->value)
            ->where('reference_id', $consultation->getKey())
            ->orderBy('id')
            ->get();

        $this->assertCount(3, $debits);
        $this->assertSame([200, 200, 200], $debits->pluck('amount_cents')->all());

        Carbon::setTestNow('2026-02-01 12:02:30');

        $this->actingAs($client)
            ->patch(route('consultations.end', $consultation))
            ->assertRedirect();

        $consultation->refresh();

        $this->assertSame(ConsultationStatus::Ended, $consultation->status);
        $this->assertSame(1_000 - 600, (int) Wallet::query()->where('user_id', $client->getKey())->value('balance_cents'));
        $this->assertSame(600, $consultation->totalChargedCents());

        $commission = CommissionLedgerEntry::query()
            ->where('consultation_id', $consultation->getKey())
            ->where('type', 'accrual')
            ->sole();

        $this->assertSame(600, $commission->base_amount_cents);
        $this->assertSame(180, $commission->commission_amount_cents);
    }

    public function test_repeated_billing_and_ending_do_not_duplicate_charges_or_commission(): void
    {
        Carbon::setTestNow('2026-02-02 09:00:00');

        $client = $this->client();
        [$attendant] = $this->attendant();

        $consultation = $this->accept(
            $attendant,
            $this->requestConsultation($client, $attendant, 'video'),
        );

        Carbon::setTestNow('2026-02-02 09:01:30');

        $this->artisan('consultations:charge-minutes')->assertExitCode(0);
        $this->artisan('consultations:charge-minutes')->assertExitCode(0);

        $this->actingAs($client)->patch(route('consultations.end', $consultation))->assertRedirect();
        $this->actingAs($client)->patch(route('consultations.end', $consultation))->assertRedirect();

        $debits = CreditLedgerEntry::query()
            ->where('type', LedgerEntryType::Debit->value)
            ->where('reference_id', $consultation->getKey());

        $this->assertSame(2, $debits->count());
        $this->assertSame(800, (int) $debits->sum('amount_cents'));
        $this->assertSame(1, CommissionLedgerEntry::query()->where('consultation_id', $consultation->getKey())->count());
    }

    public function test_client_and_admin_histories_show_modality_price_duration_total_and_commission(): void
    {
        Carbon::setTestNow('2026-02-03 15:00:00');

        $client = $this->client();
        [$attendant] = $this->attendant();

        $consultation = $this->accept(
            $attendant,
            $this->requestConsultation($client, $attendant, 'audio'),
        );

        Carbon::setTestNow('2026-02-03 15:01:40');

        $this->actingAs($client)->patch(route('consultations.end', $consultation))->assertRedirect();

        $this->actingAs($client)
            ->get(route('client.history'))
            ->assertOk()
            ->assertSee('Áudio')
            ->assertSee('R$ 2,00')
            ->assertSee('Total cobrado');

        $this->actingAs($attendant)
            ->get(route('attendant.history'))
            ->assertOk()
            ->assertSee('Áudio')
            ->assertSee('R$ 2,00')
            ->assertSee('Comissão líquida');

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('admin.consultations.index'))
            ->assertOk()
            ->assertSee('Áudio')
            ->assertSee('Comissão')
            ->assertSee('R$ 1,20');
    }

    public function test_admin_can_manage_attendant_modalities_and_client_cannot(): void
    {
        $admin = User::factory()->admin()->create();
        $client = $this->client();
        [$attendant, $profile] = $this->attendant(['text']);

        $this->actingAs($admin)
            ->patch(route('admin.modalities.update', $attendant), [
                'offered_modalities' => ['text', 'audio'],
                'prices' => ['text' => 150, 'audio' => 250],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->actingAs($admin)
            ->get(route('admin.modalities.index'))
            ->assertOk()
            ->assertSee('Modalidades dos atendentes')
            ->assertSee($attendant->name)
            ->assertSee('Salvar modalidades e preços')
            ->assertSee('Preço por atendente');

        $this->assertSame(['text', 'audio'], $profile->refresh()->offeredModalityValues());
        $this->assertTrue($profile->offersModality(ConsultationModality::Audio));
        $this->assertFalse($profile->offersModality(ConsultationModality::Video));
        $this->assertSame(150, $profile->priceForModality(ConsultationModality::Text));
        $this->assertSame(250, $profile->priceForModality(ConsultationModality::Audio));

        $audit = AuditLog::query()->where('action', 'attendant.modalities_updated')->latest('id')->firstOrFail();

        $this->assertSame(100, $audit->metadata['prices_before']['text']);
        $this->assertSame(150, $audit->metadata['prices_after']['text']);
        $this->assertSame(250, $audit->metadata['prices_after']['audio']);

        $this->actingAs($client)
            ->patch(route('admin.modalities.update', $attendant), [
                'offered_modalities' => ['text', 'audio', 'video'],
                'prices' => ['text' => 150, 'audio' => 250, 'video' => 400],
            ])
            ->assertForbidden();
    }

    public function test_admin_must_inform_a_price_for_every_selected_modality(): void
    {
        $admin = User::factory()->admin()->create();
        [$attendant] = $this->attendant(['text']);

        $this->actingAs($admin)
            ->from(route('admin.modalities.index'))
            ->patch(route('admin.modalities.update', $attendant), [
                'offered_modalities' => ['text', 'audio'],
                'prices' => ['text' => 150],
            ])
            ->assertRedirect(route('admin.modalities.index'))
            ->assertSessionHasErrors('prices');

        $this->assertSame(['text'], $attendant->attendantProfile->refresh()->offeredModalityValues());
        $this->assertFalse($attendant->attendantProfile->offersModality(ConsultationModality::Audio));
    }

    public function test_modality_and_price_cannot_change_after_consultation_started(): void
    {
        Carbon::setTestNow('2026-02-04 08:00:00');

        $client = $this->client();
        [$attendant] = $this->attendant();

        $consultation = $this->accept(
            $attendant,
            $this->requestConsultation($client, $attendant, 'text'),
        );

        $this->expectException(DomainException::class);

        $consultation->forceFill([
            'modality' => ConsultationModality::Video->value,
            'price_per_minute_cents' => 1,
        ])->save();
    }

    /**
     * O áudio é um chat: texto digitado, áudio gravado e cartas do tarô no mesmo
     * painel, sem chamada ao vivo.
     */
    public function test_audio_consultations_expose_the_chat_with_the_voice_recorder(): void
    {
        Carbon::setTestNow('2026-02-05 11:00:00');

        $client = $this->client();
        [$attendant] = $this->attendant();

        $consultation = $this->accept(
            $attendant,
            $this->requestConsultation($client, $attendant, 'audio'),
        );

        $this->actingAs($client)
            ->get(route('consultations.show', $consultation))
            ->assertOk()
            ->assertSee('data-chat-voice-form', false)
            ->assertSee(route('consultations.messages.audio.store', $consultation))
            ->assertSee('Gravar áudio')
            ->assertSee('name="body"', false)
            ->assertDontSee('data-call-request', false)
            ->assertDontSee('data-call-hangup', false)
            ->assertDontSee('data-signal-url', false);

        $this->actingAs($attendant)
            ->get(route('consultations.show', $consultation))
            ->assertOk()
            ->assertSee('data-chat-voice-form', false)
            ->assertSee('Gravar áudio')
            ->assertDontSee('data-call-accept', false)
            ->assertDontSee('data-call-reject', false);

        // Texto digitado faz parte da conversa por áudio.
        $this->actingAs($client)
            ->post(route('consultations.messages.store', $consultation), [
                'body' => 'Mensagem escrita no atendimento por áudio.',
            ])
            ->assertRedirect();

        $this->assertSame(1, $consultation->messages()->count());
    }

    public function test_client_without_any_balance_cannot_even_request_a_consultation(): void
    {
        $client = $this->client(0);
        [$attendant] = $this->attendant();

        /*
         * O saldo é exigido na solicitação: sem crédito o pedido nem é criado,
         * então não chega ao atendente para ser aceito (ou recusado) depois.
         */
        $this->actingAs($client)
            ->from(route('attendants.show', $attendant->attendantProfile))
            ->post(route('client.consultations.store'), [
                'attendant_id' => $attendant->getKey(),
                'modality' => 'text',
            ])
            ->assertRedirect(route('attendants.show', $attendant->attendantProfile))
            ->assertSessionHasErrors('consultation');

        $this->assertSame(0, Consultation::query()->count());
        $this->assertSame(0, CreditLedgerEntry::query()->count());
        $this->assertSame(0, (int) Wallet::query()->where('user_id', $client->getKey())->value('balance_cents'));
    }
}
