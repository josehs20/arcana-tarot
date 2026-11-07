<?php

namespace Tests\Feature;

use App\Enums\AttendantAvailability;
use App\Enums\AttendantRequestSound;
use App\Enums\CommissionEntryType;
use App\Enums\ConsultationEndReason;
use App\Enums\ConsultationStatus;
use App\Enums\LedgerEntryType;
use App\Enums\MessageType;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\AttendantApplication;
use App\Models\AttendantProfile;
use App\Models\AuditLog;
use App\Models\CommissionLedgerEntry;
use App\Models\Consultation;
use App\Models\ConsultationMessage;
use App\Models\CreditLedgerEntry;
use App\Models\Payment;
use App\Models\PaymentWebhookEvent;
use App\Models\Setting;
use App\Models\TarotCard;
use App\Models\TarotDeck;
use App\Models\User;
use App\Models\Wallet;
use App\Support\CommissionCalculator;
use App\Support\ConsultationPricing;
use App\Support\CreditWalletManager;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoData;
use Database\Seeders\TarotDeckSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class DemoSeedersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('tarot_private');
    }

    public function test_seeders_populate_an_empty_database_with_the_expected_dataset(): void
    {
        $this->seed();

        $this->assertSame(9, User::query()->count());
        $this->assertSame(1, User::query()->where('role', UserRole::Admin->value)->count());
        $this->assertSame(3, User::query()->where('role', UserRole::Attendant->value)->count());
        $this->assertSame(5, User::query()->where('role', UserRole::Client->value)->count());
        $this->assertSame(3, AttendantProfile::query()->count());
        $this->assertSame(3, AttendantProfile::query()
            ->where('request_sound', AttendantRequestSound::OlhaOGas->value)
            ->count());
        $this->assertSame(4, AttendantApplication::query()->count());
        $this->assertSame(4, Setting::query()->count());
        $this->assertSame('100', Setting::query()->where('key', 'credit_unit_price_cents')->value('value'));
        $this->assertSame('1', Setting::query()->where('key', 'minimum_balance_minutes')->value('value'));
        $this->assertSame('5', Setting::query()->where('key', 'consultation_inactivity_timeout_minutes')->value('value'));
        $this->assertSame('90', Setting::query()->where('key', 'consultation_reconnection_tolerance_seconds')->value('value'));
        $this->assertSame(5, TarotDeck::query()->count());
        $this->assertSame(284, TarotCard::query()->count());
        $this->assertSame(5, Wallet::query()->count());
        $this->assertSame(7, Payment::query()->count());
        $this->assertSame(5, Payment::query()->where('status', PaymentStatus::Confirmed->value)->count());
        $this->assertSame(1, Payment::query()->where('status', PaymentStatus::Pending->value)->count());
        $this->assertSame(1, Payment::query()->where('status', PaymentStatus::Expired->value)->count());
        $this->assertSame(2, Consultation::query()->count());
        $this->assertSame(6, ConsultationMessage::query()->count());
        $this->assertSame(2, CommissionLedgerEntry::query()->count());
        $this->assertSame(19, CreditLedgerEntry::query()->count());
    }

    public function test_demo_accounts_can_authenticate_with_the_documented_local_password(): void
    {
        $this->seed();

        $admin = DemoData::user(DemoData::ADMIN_EMAIL);
        $attendant = DemoData::user(DemoData::ATTENDANTS['marina']['email']);
        $client = DemoData::user(DemoData::CLIENTS['free']['email']);

        $this->assertTrue(Hash::check(DemoData::password(), $admin->password));
        $this->assertTrue(Hash::check(DemoData::password(), $attendant->password));
        $this->assertTrue(Hash::check(DemoData::password(), $client->password));

        foreach ([
            [$admin, 'admin.dashboard'],
            [$attendant, 'attendant.dashboard'],
            [$client, 'client.dashboard'],
        ] as [$user, $dashboard]) {
            $this->post('/login', [
                'email' => $user->email,
                'password' => DemoData::password(),
            ])->assertRedirect(route($dashboard));

            $this->assertAuthenticatedAs($user);
            $this->post('/logout')->assertRedirect('/');
        }
    }

    public function test_demo_admin_can_access_all_administrative_areas(): void
    {
        $this->seed();

        $admin = DemoData::user(DemoData::ADMIN_EMAIL);

        foreach ([
            'admin.dashboard',
            'admin.users.index',
            'admin.consultations.index',
            'admin.payments.index',
            'admin.finance.index',
            'admin.commissions.index',
            'admin.applications.index',
            'admin.decks.index',
            'admin.audit.index',
        ] as $route) {
            $this->actingAs($admin)->get(route($route))->assertOk();
        }
    }

    public function test_attendants_and_clients_cover_the_required_states(): void
    {
        $this->seed();

        $marina = DemoData::user(DemoData::ATTENDANTS['marina']['email']);
        $rafael = DemoData::user(DemoData::ATTENDANTS['rafael']['email']);
        $iris = DemoData::user(DemoData::ATTENDANTS['iris']['email']);

        $this->assertSame(AttendantAvailability::Online, $marina->attendantProfile->availability);
        $this->assertSame('online', $marina->attendantProfile->publicStatus());
        $this->assertSame(AttendantAvailability::Offline, $rafael->attendantProfile->availability);
        $this->assertSame('offline', $rafael->attendantProfile->publicStatus());

        // Cada perfil nasce com o preço por minuto da sua modalidade.
        $this->assertSame(
            DemoData::modalityPricePerMinuteCents('text'),
            $marina->attendantProfile->priceForModality('text'),
        );

        // Íris fica online, mas sem atendimento ativo: o seeder não abre
        // consulta em nome de uma conta que não está logada no site.
        $irisProfile = $iris->attendantProfile->load('activeConsultation');
        $this->assertSame(AttendantAvailability::Online, $irisProfile->availability);
        $this->assertNull($irisProfile->activeConsultation);
        $this->assertSame('online', $irisProfile->publicStatus());

        // O saldo mínimo agora depende do preço do atendente: usamos o preço
        // demonstrativo da modalidade mais barata como referência.
        $minimum = app(ConsultationPricing::class)
            ->minimumBalanceCentsFor(DemoData::modalityPricePerMinuteCents('text'));

        $free = DemoData::user(DemoData::CLIENTS['free']['email']);
        $withoutBalance = DemoData::user(DemoData::CLIENTS['no_balance']['email']);
        $withHistory = DemoData::user(DemoData::CLIENTS['history']['email']);

        $this->assertGreaterThanOrEqual($minimum, $free->wallet->balance_cents);
        $this->assertSame(0, $withoutBalance->wallet->balance_cents);
        $this->assertLessThan($minimum, $withoutBalance->wallet->balance_cents);
        $this->assertTrue($withHistory->clientConsultations()->where('status', ConsultationStatus::Ended->value)->exists());

        $this->assertSame(0, Consultation::query()->whereIn('status', ConsultationStatus::openValues())->count());
    }

    /**
     * Regressão: nenhuma conta semeada é chamada ao logar.
     *
     * O seeder não abre solicitação nem consulta ativa, então o feed do painel
     * (polling do modal, da sirene e da chamada) volta vazio para todos.
     */
    public function test_seeded_demo_accounts_are_not_called_by_open_consultations(): void
    {
        $this->seed();

        $emails = [
            DemoData::ATTENDANTS['marina']['email'],
            DemoData::ATTENDANTS['iris']['email'],
            DemoData::CLIENTS['free']['email'],
            DemoData::CLIENTS['history']['email'],
            DemoData::CLIENTS['no_balance']['email'],
            DemoData::CLIENTS['pending']['email'],
            DemoData::CLIENTS['active']['email'],
        ];

        foreach ($emails as $email) {
            $response = $this->actingAs(DemoData::user($email))
                ->getJson(route('panel.events'))
                ->assertOk();

            $response->assertJsonPath('requests', []);
            $response->assertJsonPath('consultation', null);
        }
    }

    public function test_attendant_applications_cover_all_review_states_without_creating_accounts(): void
    {
        $this->seed();

        $admin = DemoData::user(DemoData::ADMIN_EMAIL);

        $this->assertDatabaseHas('attendant_applications', ['email' => 'candidatura.pendente@arcana.local', 'status' => 'pending']);
        $this->assertDatabaseHas('attendant_applications', ['email' => 'candidatura.em-analise@arcana.local', 'status' => 'under_review']);
        $this->assertDatabaseHas('attendant_applications', ['email' => 'candidatura.aprovada@arcana.local', 'status' => 'approved']);
        $this->assertDatabaseHas('attendant_applications', ['email' => 'candidatura.rejeitada@arcana.local', 'status' => 'rejected']);

        $approved = AttendantApplication::query()->where('email', 'candidatura.aprovada@arcana.local')->sole();
        $rejected = AttendantApplication::query()->where('email', 'candidatura.rejeitada@arcana.local')->sole();
        $pending = AttendantApplication::query()->where('email', 'candidatura.pendente@arcana.local')->sole();

        $this->assertSame($admin->id, $approved->reviewed_by);
        $this->assertNotNull($approved->reviewed_at);
        $this->assertSame($admin->id, $rejected->reviewed_by);
        $this->assertNotNull($rejected->reviewed_at);
        $this->assertNull($pending->reviewed_by);
        $this->assertNull($pending->reviewed_at);

        foreach (['candidatura.pendente@arcana.local', 'candidatura.em-analise@arcana.local', 'candidatura.aprovada@arcana.local', 'candidatura.rejeitada@arcana.local'] as $email) {
            $this->assertDatabaseMissing('users', ['email' => $email]);
        }
    }

    public function test_wallets_are_projected_from_the_ledger_and_reference_test_payments_or_consultations(): void
    {
        $this->seed();

        $wallets = app(CreditWalletManager::class);

        foreach (User::query()->where('role', UserRole::Client->value)->get() as $client) {
            $this->assertNotNull($client->wallet);
            $this->assertSame($wallets->ledgerBalance($client->wallet), $client->wallet->balance_cents);
            $this->assertGreaterThanOrEqual(0, $client->wallet->balance_cents);
        }

        $this->assertSame(0, DemoData::user(DemoData::CLIENTS['no_balance']['email'])->wallet->balance_cents);
        $this->assertGreaterThan(0, DemoData::user(DemoData::CLIENTS['history']['email'])->wallet->balance_cents);

        $credits = CreditLedgerEntry::query()
            ->where('type', LedgerEntryType::Credit->value)
            ->get();

        $this->assertCount(5, $credits);

        foreach ($credits as $credit) {
            $this->assertSame(Payment::class, $credit->reference_type);
            $payment = Payment::query()->findOrFail($credit->reference_id);
            $this->assertSame(PaymentStatus::Confirmed, $payment->status);
            $this->assertSame('pix_sandbox', $payment->provider);
            $this->assertSame($payment->amount_cents, $credit->amount_cents);
            $this->assertSame($credit->idempotency_key, 'demo:wallet:'.$payment->idempotency_key);
        }

        foreach (CreditLedgerEntry::query()->where('type', LedgerEntryType::Debit->value)->get() as $debit) {
            $this->assertSame(Consultation::class, $debit->reference_type);
            $this->assertNotNull(Consultation::query()->find($debit->reference_id));
        }

        $this->assertSame(0, PaymentWebhookEvent::query()->count());
    }

    public function test_tarot_decks_have_cards_with_local_images_available_through_the_authorized_route(): void
    {
        $this->seed();

        $expectedCardsPerDeck = [
            'tarot-de-marseille' => 78,
            'lenormand' => 36,
            'rider-waite-smith' => 78,
            'baralho-de-52-cartas' => 52,
            'baralho-espanhol' => 40,
        ];

        $assetDirectories = [
            'tarot-de-marseille' => 'marseille',
            'lenormand' => 'lenormand',
            'rider-waite-smith' => 'rider-waite',
            'baralho-de-52-cartas' => 'baralho-52',
            'baralho-espanhol' => 'baralho-espanhol',
        ];

        $this->assertSame(count($expectedCardsPerDeck), TarotDeck::query()->count());
        $this->assertSame(array_sum($expectedCardsPerDeck), TarotCard::query()->count());

        foreach ($expectedCardsPerDeck as $deckSlug => $expectedCards) {
            $this->assertSame(
                $expectedCards,
                TarotCard::query()->whereHas('deck', fn ($query) => $query->where('slug', $deckSlug))->count(),
                'Contagem de cartas divergente no baralho '.$deckSlug,
            );
        }

        foreach (TarotDeck::query()->with('cards')->get() as $deck) {
            foreach ($deck->cards as $card) {
                $this->assertTrue($card->is_active);
                $this->assertSame('cards/'.$assetDirectories[$deck->slug].'/'.$card->slug.'.jpg', $card->image_path);
                $this->assertSame('image/jpeg', $card->image_mime);
                $this->assertNotSame('', (string) $card->meaning_upright);
                $this->assertNotSame('', (string) $card->meaning_reversed);
                $this->assertTrue(Storage::disk('tarot_private')->exists($card->image_path));

                $bytes = Storage::disk('tarot_private')->get($card->image_path);
                $this->assertSame(strlen($bytes), $card->image_size);
                $this->assertSame(hash('sha256', $bytes), $card->image_checksum);
                $this->assertSame("\xFF\xD8\xFF", substr($bytes, 0, 3));
            }
        }

        $admin = DemoData::user(DemoData::ADMIN_EMAIL);
        $card = TarotCard::query()->availableForSelection()->firstOrFail();

        $this->actingAs($admin)
            ->get(route('tarot.cards.image', $card))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_tarot_deck_seeder_keeps_an_administrator_replaced_image_and_removes_the_retired_demo_decks(): void
    {
        $this->seed();

        $retired = TarotDeck::query()->create([
            'name' => 'Baralho antigo',
            'slug' => 'arcana-essencial',
            'description' => 'Baralho fictício de uma versão anterior.',
            'is_active' => true,
        ]);

        $retiredCard = TarotCard::query()->create([
            'deck_id' => $retired->getKey(),
            'name' => 'O Mago',
            'slug' => 'o-mago',
            'sort_order' => 0,
            'image_path' => 'cards/demo-arcana-essencial-0-o-mago.png',
            'image_mime' => 'image/png',
            'image_size' => 8,
            'image_checksum' => hash('sha256', 'demo'),
            'is_active' => true,
        ]);

        Storage::disk('tarot_private')->put($retiredCard->image_path, 'demo');

        $card = TarotCard::query()
            ->whereHas('deck', fn ($query) => $query->where('slug', 'tarot-de-marseille'))
            ->where('slug', 'o-louco')
            ->sole();

        $replacement = 'cards/'.str_repeat('a', 40).'.jpg';
        Storage::disk('tarot_private')->put($replacement, file_get_contents(base_path('database/seeders/assets/tarot/marseille/o-louco.jpg')));

        $card->forceFill([
            'image_path' => $replacement,
            'image_mime' => 'image/jpeg',
            'image_size' => Storage::disk('tarot_private')->size($replacement),
            'image_checksum' => hash('sha256', Storage::disk('tarot_private')->get($replacement)),
        ])->saveQuietly();

        $this->app->make(TarotDeckSeeder::class)->run();

        $this->assertFalse(TarotDeck::query()->where('slug', 'arcana-essencial')->exists());
        $this->assertFalse(Storage::disk('tarot_private')->exists($retiredCard->image_path));
        $this->assertSame($replacement, $card->fresh()->image_path);
        $this->assertSame(78, TarotCard::query()->whereHas('deck', fn ($query) => $query->where('slug', 'tarot-de-marseille'))->count());
    }

    public function test_consultations_and_messages_preserve_lifecycle_participants_and_card_snapshots(): void
    {
        $this->seed();

        $historyClient = DemoData::user(DemoData::CLIENTS['history']['email']);
        $history = $historyClient->clientConsultations()->where('status', ConsultationStatus::Ended->value)->sole();
        $history->load('messages', 'commissionEntries');

        $this->assertSame(ConsultationEndReason::ClientEnded, $history->ended_reason);
        $this->assertSame(3000, $history->commission_rate_bps);
        $this->assertSame(13, $history->billed_minutes);
        $this->assertSame(780, $history->durationSeconds());
        $this->assertNotNull($history->ended_at);

        $outOfCredits = DemoData::user(DemoData::CLIENTS['no_balance']['email'])
            ->clientConsultations()
            ->where('status', ConsultationStatus::Ended->value)
            ->sole();

        $this->assertSame(ConsultationEndReason::OutOfCredits, $outOfCredits->ended_reason);
        $this->assertSame(1, $outOfCredits->billed_minutes);

        /*
         * O seeder não cria solicitações pendentes nem consultas ativas: o
         * atendimento é sempre iniciado pelo cliente autenticado no site.
         */
        $this->assertSame(0, Consultation::query()->whereIn('status', ConsultationStatus::openValues())->count());

        foreach (User::query()->where('role', UserRole::Client->value)->get() as $client) {
            $this->assertSame(0, $client->clientConsultations()->whereIn('status', ConsultationStatus::openValues())->count());
        }

        $this->assertSame(2, Consultation::query()->where('status', ConsultationStatus::Ended->value)->count());
        $this->assertSame(6, ConsultationMessage::query()->count());
        $this->assertSame(1, ConsultationMessage::query()->where('type', MessageType::TarotCard->value)->count());

        foreach (ConsultationMessage::query()->with('consultation', 'tarotCard')->get() as $message) {
            $consultation = $message->consultation;

            $this->assertTrue($consultation->hasParticipant($message->sender));

            if ($message->type === MessageType::TarotCard) {
                $this->assertSame($consultation->attendant_id, $message->sender_id);
                $this->assertNotNull($message->tarot_card_id);
                $this->assertNotNull($message->tarotCard);
                $this->assertSame($message->tarotCard->name, $message->card_name);
                $this->assertSame($message->tarotCard->deck->name, $message->deck_name);
                $this->assertStringStartsWith('consultation-cards/'.$consultation->id.'/', $message->image_path);
                $this->assertTrue(Storage::disk('tarot_private')->exists($message->image_path));
            }
        }
    }

    public function test_commissions_match_completed_consultations_and_preserve_the_historical_rate(): void
    {
        $this->seed();

        $calculator = app(CommissionCalculator::class);
        $entries = CommissionLedgerEntry::query()->with('consultation')->get();

        $this->assertCount(2, $entries);

        foreach ($entries as $entry) {
            $consultation = $entry->consultation;

            $this->assertSame(CommissionEntryType::Accrual, $entry->type);
            $this->assertNotNull($consultation);
            $this->assertContains($consultation->status, [ConsultationStatus::Ended, ConsultationStatus::Interrupted]);
            $this->assertSame($consultation->attendant_id, $entry->attendant_id);
            $this->assertSame($consultation->commission_rate_bps, $entry->commission_rate_bps);
            $this->assertSame(
                $entry->base_amount_cents,
                (int) CreditLedgerEntry::query()
                    ->where('reference_type', $consultation->getMorphClass())
                    ->where('reference_id', $consultation->id)
                    ->where('type', LedgerEntryType::Debit->value)
                    ->sum('amount_cents'),
            );
            $this->assertSame(
                $calculator->calculate($entry->base_amount_cents, $entry->commission_rate_bps),
                $entry->commission_amount_cents,
            );
        }

        $historyCommission = CommissionLedgerEntry::query()
            ->where('base_amount_cents', 1300)
            ->sole();

        DemoData::user(DemoData::ATTENDANTS['marina']['email'])->attendantProfile
            ->forceFill(['commission_rate_bps' => 5000])
            ->save();

        $this->assertSame(3000, $historyCommission->fresh()->commission_rate_bps);
        $this->assertSame(390, $historyCommission->fresh()->commission_amount_cents);
        $this->assertSame(0, CommissionLedgerEntry::query()
            ->whereNull('consultation_id')
            ->orWhereHas('consultation', fn ($query) => $query->where('status', '!=', ConsultationStatus::Ended->value))
            ->count());
    }

    public function test_repeated_seed_execution_does_not_duplicate_records_or_rehash_passwords(): void
    {
        $this->seed();

        $admin = DemoData::user(DemoData::ADMIN_EMAIL);
        $originalHash = $admin->password;
        $first = $this->demoSnapshot();

        $this->seed();

        $this->assertSame($first, $this->demoSnapshot());
        $this->assertSame($originalHash, $admin->fresh()->password);
        $this->assertTrue(Hash::check(DemoData::password(), $admin->fresh()->password));
    }

    public function test_demo_seeders_do_not_call_external_services(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        $this->seed();

        Http::assertNothingSent();
        $this->assertSame(7, Payment::query()->count());
        $this->assertTrue(Payment::query()->get()->every(fn (Payment $payment) => $payment->provider === 'pix_sandbox'));
        $this->assertTrue(Payment::query()->get()->every(fn (Payment $payment) => str_starts_with($payment->idempotency_key, 'demo:pix:')));
    }

    public function test_demo_seeders_are_blocked_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('bloqueados em produção');

        $this->app->make(DatabaseSeeder::class)->run();
    }

    /**
     * @return array<string, mixed>
     */
    private function demoSnapshot(): array
    {
        return [
            'users' => User::query()->count(),
            'profiles' => AttendantProfile::query()->count(),
            'applications' => AttendantApplication::query()->count(),
            'decks' => TarotDeck::query()->count(),
            'cards' => TarotCard::query()->count(),
            'wallets' => Wallet::query()->count(),
            'ledger' => CreditLedgerEntry::query()->count(),
            'payments' => Payment::query()->count(),
            'webhook_events' => PaymentWebhookEvent::query()->count(),
            'consultations' => Consultation::query()->count(),
            'messages' => ConsultationMessage::query()->count(),
            'commissions' => CommissionLedgerEntry::query()->count(),
            'settings' => Setting::query()->count(),
            'audit_logs' => AuditLog::query()->count(),
            'balances' => Wallet::query()
                ->join('users', 'users.id', '=', 'wallets.user_id')
                ->orderBy('users.email')
                ->pluck('wallets.balance_cents', 'users.email')
                ->all(),
            'tarot_files' => count(Storage::disk('tarot_private')->allFiles()),
        ];
    }
}
