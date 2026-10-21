<?php

namespace Tests\Feature;

use App\Enums\ConsultationStatus;
use App\Enums\MessageType;
use App\Enums\UserRole;
use App\Models\AttendantProfile;
use App\Models\Consultation;
use App\Models\ConsultationMessage;
use App\Models\CreditLedgerEntry;
use App\Models\TarotCard;
use App\Models\TarotDeck;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DataModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_role_is_cast_to_enum(): void
    {
        $user = User::factory()->attendant()->create();

        $this->assertSame(UserRole::Attendant, $user->role);
        $this->assertTrue($user->isAttendant());
    }

    public function test_attendant_profile_belongs_to_user(): void
    {
        $profile = AttendantProfile::factory()->create(['commission_rate_bps' => 3000]);

        $this->assertSame(3000, $profile->commission_rate_bps);
        $this->assertInstanceOf(User::class, $profile->user);
        $this->assertTrue($profile->user->isAttendant());
    }

    public function test_consultation_snapshots_commission_rate_as_integer(): void
    {
        $consultation = Consultation::factory()->create(['commission_rate_bps' => 2500]);

        $this->assertSame(2500, $consultation->commission_rate_bps);
        $this->assertSame(ConsultationStatus::Requested, $consultation->status);
        $this->assertIsInt($consultation->commission_rate_bps);
    }

    public function test_tarot_card_belongs_to_deck(): void
    {
        $deck = TarotDeck::factory()->create();
        $card = TarotCard::factory()->create(['deck_id' => $deck->id]);

        $this->assertTrue($card->deck->is($deck));
    }

    public function test_tarot_card_message_preserves_snapshot_fields(): void
    {
        $message = ConsultationMessage::factory()->tarotCard()->create();

        $this->assertSame(MessageType::TarotCard, $message->type);
        $this->assertNotNull($message->deck_name);
        $this->assertNotNull($message->card_name);
        $this->assertNotNull($message->image_path);
    }

    public function test_credit_ledger_entry_is_immutable(): void
    {
        $entry = CreditLedgerEntry::factory()->create(['amount_cents' => 1000]);

        $this->assertArrayNotHasKey('updated_at', $entry->getAttributes());
        $this->assertSame(1000, $entry->amount_cents);
    }

    public function test_wallet_belongs_to_user(): void
    {
        $wallet = Wallet::factory()->create();

        $this->assertInstanceOf(User::class, $wallet->user);
    }
}
