<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\CommissionEntryType;
use App\Enums\ConsultationStatus;
use App\Enums\LedgerEntryType;
use App\Enums\MessageType;
use App\Models\AttendantApplication;
use App\Models\AttendantProfile;
use App\Models\AuditLog;
use App\Models\CommissionLedgerEntry;
use App\Models\Consultation;
use App\Models\ConsultationMessage;
use App\Models\CreditLedgerEntry;
use App\Models\Setting;
use App\Models\TarotCard;
use App\Models\TarotDeck;
use App\Models\User;
use App\Models\Wallet;
use App\Support\AdminDashboardMetrics;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MvpIntegratedFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('tarot_private');

        Setting::query()->create(['key' => 'rate_credits_per_minute', 'value' => '1', 'type' => 'integer']);
        Setting::query()->create(['key' => 'credit_unit_price_cents', 'value' => '100', 'type' => 'integer']);
        Setting::query()->create(['key' => 'minimum_balance_minutes', 'value' => '1', 'type' => 'integer']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function deckPayload(): array
    {
        return [
            'name' => 'Baralho Integrado',
            'slug' => 'baralho-integrado',
            'description' => 'Baralho usado no teste integrado do MVP.',
            'is_active' => '1',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cardPayload(): array
    {
        return [
            'name' => 'A Estrela Integrada',
            'sort_order' => 1,
            'meaning_upright' => 'Esperança e renovação.',
            'meaning_reversed' => 'Desânimo temporário.',
            'is_active' => '1',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function applicationPayload(): array
    {
        return [
            'name' => 'Candidato Integrado',
            'email' => 'candidato.integrado@example.com',
            'phone' => '11999999999',
            'city' => 'São Paulo',
            'state' => 'SP',
            'experience_summary' => 'Atuo com Tarot há cinco anos com foco em acolhimento.',
            'message' => 'Conduzo leituras éticas, claras e respeitosas com o tempo da pessoa.',
            'specialties' => 'Tarot, Amor',
            'portfolio_url' => 'https://example.com/portfolio',
            'consent' => '1',
            'website' => '',
        ];
    }

    public function test_primary_mvp_flow_from_online_attendant_to_audited_commission(): void
    {
        Carbon::setTestNow('2026-10-21 12:00:00');

        $admin = User::factory()->admin()->create();
        $attendant = User::factory()->attendant()->create(['name' => 'Atendente Integrado']);
        $profile = AttendantProfile::factory()->offline()->for($attendant)->create([
            'display_name' => 'Atendente Integrado',
            'commission_rate_bps' => 2500,
        ]);

        $client = User::factory()->create(['name' => 'Cliente Integrado']);
        $wallet = Wallet::factory()->create([
            'user_id' => $client->id,
            'balance_cents' => 1000,
        ]);

        $this->actingAs($attendant)
            ->patch(route('attendant.availability.update'), ['availability' => 'online'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('online', $profile->fresh()->availability->value);

        $this->actingAs($client)
            ->get(route('attendants.show', $profile))
            ->assertOk()
            ->assertSee('Atendente Integrado');

        $this->actingAs($client)
            ->post(route('client.consultations.store'), ['attendant_id' => $attendant->id])
            ->assertRedirect()
            ->assertSessionHas('success');

        $consultation = Consultation::query()->latest('id')->firstOrFail();

        $this->assertSame(ConsultationStatus::Requested, $consultation->status);

        $this->actingAs($attendant)
            ->patch(route('attendant.consultations.accept', $consultation))
            ->assertRedirect(route('consultations.workspace', $consultation))
            ->assertSessionHas('success');

        $consultation->refresh();

        $this->assertSame(ConsultationStatus::Active, $consultation->status);
        $this->assertNotNull($consultation->started_at);
        $this->assertSame(2500, $consultation->commission_rate_bps);
        $this->assertSame(900, $wallet->fresh()->balance_cents);

        $this->actingAs($attendant)
            ->patch(route('attendant.consultations.accept', $consultation))
            ->assertRedirect();

        $this->assertSame(1, CreditLedgerEntry::query()
            ->where('type', LedgerEntryType::Debit->value)
            ->where('reference_id', $consultation->id)
            ->count());

        $deckResponse = $this->actingAs($admin)
            ->post(route('admin.decks.store'), $this->deckPayload());

        $deck = TarotDeck::query()->where('slug', 'baralho-integrado')->firstOrFail();

        $deckResponse->assertRedirect(route('admin.decks.cards.index', $deck));

        $this->actingAs($admin)
            ->post(route('admin.decks.cards.store', $deck), [
                ...$this->cardPayload(),
                'image' => UploadedFile::fake()->image('carta-integrada.png', 300, 450),
            ])
            ->assertRedirect(route('admin.decks.cards.index', $deck));

        $card = TarotCard::query()->where('name', 'A Estrela Integrada')->firstOrFail();

        Storage::disk('tarot_private')->assertExists($card->image_path);

        $this->actingAs($client)
            ->post(route('consultations.messages.store', $consultation), [
                'body' => 'Olá, atendente integrado!',
            ])
            ->assertRedirect();

        $this->actingAs($attendant)
            ->post(route('consultations.messages.store', $consultation), [
                'body' => 'Bem-vindo ao atendimento integrado.',
            ])
            ->assertRedirect();

        $this->actingAs($attendant)
            ->post(route('consultations.cards.store', $consultation), [
                'tarot_card_id' => $card->id,
            ])
            ->assertRedirect();

        $cardMessage = ConsultationMessage::query()
            ->where('type', MessageType::TarotCard->value)
            ->sole();

        $this->assertStringStartsWith(
            'consultation-cards/'.$consultation->id.'/',
            $cardMessage->image_path,
        );
        $this->assertSame('A Estrela Integrada', $cardMessage->card_name);
        Storage::disk('tarot_private')->assertExists($cardMessage->image_path);

        $this->actingAs($client)
            ->get(route('consultations.messages.image', [$consultation, $cardMessage]))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');

        $this->actingAs($attendant)
            ->get(route('consultations.messages.image', [$consultation, $cardMessage]))
            ->assertOk();

        $this->actingAs(User::factory()->create())
            ->get(route('consultations.messages.image', [$consultation, $cardMessage]))
            ->assertForbidden();

        $this->actingAs($admin)
            ->get(route('consultations.messages.image', [$consultation, $cardMessage]))
            ->assertForbidden();

        $this->actingAs($admin)
            ->patch(route('admin.decks.cards.status', [$deck, $card]), ['is_active' => '0'])
            ->assertRedirect();

        $this->assertFalse($card->fresh()->is_active);

        $this->actingAs($client)
            ->get(route('consultations.messages.image', [$consultation, $cardMessage]))
            ->assertOk();

        $this->actingAs($client)
            ->get(route('tarot.cards.image', $card))
            ->assertForbidden();

        $this->actingAs($attendant)
            ->post(route('consultations.cards.store', $consultation), [
                'tarot_card_id' => $card->id,
            ])
            ->assertSessionHasErrors('tarot_card_id');

        $this->assertSame(1, ConsultationMessage::query()
            ->where('type', MessageType::TarotCard->value)
            ->count());

        Carbon::setTestNow('2026-10-21 12:01:20');

        $this->artisan('consultations:charge-minutes')->assertExitCode(0);

        $this->assertSame(800, $wallet->fresh()->balance_cents);
        $this->assertSame(2, CreditLedgerEntry::query()
            ->where('type', LedgerEntryType::Debit->value)
            ->where('reference_id', $consultation->id)
            ->count());

        $this->actingAs($client)
            ->patch(route('consultations.end', $consultation))
            ->assertRedirect(route('consultations.show', $consultation))
            ->assertSessionHas('success');

        $consultation->refresh();

        $this->assertSame(ConsultationStatus::Ended, $consultation->status);
        $this->assertSame(2, $consultation->billed_minutes);
        $this->assertSame(800, $wallet->fresh()->balance_cents);

        $accruals = CommissionLedgerEntry::query()
            ->where('consultation_id', $consultation->id)
            ->where('type', CommissionEntryType::Accrual->value)
            ->get();

        $this->assertCount(1, $accruals);

        $accrual = $accruals->first();
        $this->assertSame(2500, $accrual->commission_rate_bps);
        $this->assertSame(200, $accrual->base_amount_cents);
        $this->assertSame(50, $accrual->commission_amount_cents);
        $this->assertSame('consultation:'.$consultation->id.':commission:accrual', $accrual->idempotency_key);

        $this->actingAs($client)
            ->patch(route('consultations.end', $consultation))
            ->assertRedirect();

        $this->assertSame(1, CommissionLedgerEntry::query()
            ->where('consultation_id', $consultation->id)
            ->count());

        $this->actingAs($client)
            ->get(route('client.history'))
            ->assertOk()
            ->assertSee('1min 20s')
            ->assertSee('R$ 2,00')
            ->assertSee('Concluída');

        $this->actingAs($attendant)
            ->get(route('attendant.history'))
            ->assertOk()
            ->assertSee('Cliente Integrado')
            ->assertSee('25%')
            ->assertSee('R$ 2,00')
            ->assertSee('R$ 0,50');

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertViewHas('metrics', fn (AdminDashboardMetrics $metrics): bool => $metrics->totalClients === 2
                && $metrics->activeClients === 2
                && $metrics->onlineAttendants === 1
                && $metrics->busyAttendants === 0
                && $metrics->activeConsultations === 0
                && $metrics->completedConsultations === 1
                && $metrics->confirmedRecharges === 0
                && $metrics->pendingApplications === 0
                && $metrics->commissionAccruedCents === 50
                && $metrics->commissionReversedCents === 0
                && $metrics->commissionNetCents === 50
                && $metrics->grossConsultationRevenueCents === 200
                && $metrics->netOperationalRevenueCents === 150);

        $this->actingAs($admin)
            ->patch(route('admin.commissions.rate.update', $attendant), ['commission_rate' => '50'])
            ->assertRedirect(route('admin.commissions.attendant', $attendant));

        $this->assertSame(5000, $profile->fresh()->commission_rate_bps);
        $this->assertSame(2500, $consultation->fresh()->commission_rate_bps);
        $this->assertSame(50, $accrual->fresh()->commission_amount_cents);

        $this->actingAs($admin)
            ->get(route('admin.audit.index', ['action' => 'attendant.commission_rate_updated']))
            ->assertOk()
            ->assertSee('attendant.commission_rate_updated');

        $this->actingAs($admin)
            ->get(route('consultations.show', $consultation))
            ->assertOk()
            ->assertSee('A conversa entre cliente e atendente é privada')
            ->assertDontSee('Olá, atendente integrado!');

        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->get(route('consultations.show', $consultation))
            ->assertForbidden();

        $this->actingAs($outsider)
            ->get(route('consultations.messages.index', $consultation))
            ->assertForbidden();

        $this->actingAs($client)
            ->post(route('consultations.cards.store', $consultation), [
                'tarot_card_id' => $card->id,
            ])
            ->assertForbidden();
    }

    public function test_public_application_and_admin_review_never_create_attendant_access(): void
    {
        $admin = User::factory()->admin()->create();
        $client = User::factory()->create();
        $usersBefore = User::query()->count();

        $this->get(route('work-with-us.index'))
            ->assertOk()
            ->assertSee('Trabalhe conosco');

        $this->post(route('work-with-us.store'), $this->applicationPayload())
            ->assertRedirect(route('work-with-us.index'))
            ->assertSessionHas('application_submitted');

        $application = AttendantApplication::query()
            ->where('email', 'candidato.integrado@example.com')
            ->sole();

        $this->assertSame(ApplicationStatus::Pending, $application->status);
        $this->assertNotNull($application->consent_at);
        $this->assertDatabaseMissing('users', ['email' => 'candidato.integrado@example.com']);
        $this->assertSame($usersBefore, User::query()->count());

        $this->get(route('admin.applications.index'))
            ->assertRedirect(route('login'));

        $this->actingAs($admin)
            ->get(route('admin.applications.index'))
            ->assertOk()
            ->assertSee('Candidato Integrado');

        $this->actingAs($admin)
            ->patch(route('admin.applications.start', $application), [
                'internal_note' => 'Nota interna confidencial da análise.',
            ])
            ->assertRedirect(route('admin.applications.show', $application));

        $this->assertSame(ApplicationStatus::UnderReview, $application->fresh()->status);
        $this->assertSame($admin->id, $application->fresh()->reviewed_by);

        $this->actingAs($admin)
            ->get(route('admin.applications.show', $application))
            ->assertOk()
            ->assertSee('Nota interna confidencial da análise.');

        $this->actingAs($admin)
            ->patch(route('admin.applications.approve', $application), [
                'internal_note' => 'Aprovado após entrevista.',
            ])
            ->assertRedirect(route('admin.applications.show', $application));

        $application->refresh();

        $this->assertSame(ApplicationStatus::Approved, $application->status);
        $this->assertNotNull($application->reviewed_at);
        $this->assertSame($usersBefore, User::query()->count());
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'attendant_application.approved',
            'subject_id' => $application->id,
        ]);

        $this->assertSame(1, AuditLog::query()
            ->where('action', 'attendant_application.approved')
            ->count());

        $this->get(route('work-with-us.index'))
            ->assertOk()
            ->assertDontSee('Nota interna confidencial da análise.')
            ->assertDontSee('Aprovado após entrevista.');

        $this->actingAs($client)
            ->get(route('admin.applications.index'))
            ->assertForbidden();
    }
}
